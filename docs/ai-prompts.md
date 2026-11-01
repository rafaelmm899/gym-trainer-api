# AI prompts: what the models are actually sent

Three agents (`app/Ai/Agents/`, built on `laravel/ai`) talk to the provider.
Each call has two parts:

- **System instructions**: fixed text in the agent's `instructions()`.
- **User prompt**: built per call by the wrapping Service from database data.

The response is **structured output**: the agent's `schema()` is sent as a strict
JSON schema and the Service validates the result again before anything is
written. Values in the examples below are illustrative; the wording is copied
from the code. If you change a prompt in code, update this page.

| Agent | Wrapped by | Called from | Timeout | Max tokens | One call covers |
|---|---|---|---|---|---|
| `CyclePlannerAgent` | `CyclePlannerService::planFirstCycle` | `RoutineCreateAction` (sync, in the request) | 60 s | 7000 | the whole first week (5 days) |
| `CycleProgressionAgent` | `CyclePlannerService::progressDay` | `CycleDayProgressAction` (queued job) | 30 s | 2500 | the performed exercises of **one day** |
| `SessionAnalystAgent` | `SessionAnalystService::analyze` | `SessionAnalyzeAction` (queued job) | 30 s | 4000 | **one session** |

Design rules that show up in every prompt:

- **Units are always kilograms**, stated in the prompt and in the schema.
- **The model never sees database ids.** Slots are addressed by position
  (`day 2, exercise 3`) and sessions' exercises by order, and the Service maps the
  answer back. A model cannot attach advice to the wrong row by inventing an id.
- **Hard requirements are repeated in the prompt and re-checked in PHP.** The
  schema cannot carry array-length rules (Groq's strict mode rejects them), so
  counts are enforced by the prompt and by the Service.
- **Free text is passed through untouched**: the athlete's `notes`, the routine
  `hint`, a session `note` and per-set notes go in verbatim.

---

## 1. First cycle: `CyclePlannerAgent`

Triggered by `POST /routines`. Plans 5 days with a full prescription per
exercise.

### System instructions (verbatim)

`{$groups}` is `chest, back, quads, hamstrings, glutes, shoulders, biceps,
triceps, calves, core` (the `MuscleGroup` enum).

```text
You are a strength and hypertrophy coach building the first training
week of a new routine for an athlete: build the plan from their
profile, goal and notes alone.

HARD REQUIREMENTS — the response is rejected otherwise:
- `days` MUST contain EXACTLY 5 entries. Not 3, not 4, not 6 — five.
- The prompt gives an exercises-per-day range. Pick ONE integer N
  inside that range and give EVERY one of the 5 days exactly N
  `exercises` — same count on all five, never fewer, never more. A
  day with 2 exercises when the range is 6–8 fails the whole plan.
- Every exercise MUST set a numeric `target_weight_kg` in KILOGRAMS,
  estimated from the athlete's experience level and notes. Never omit
  it; never use 0 for a loaded lift (a genuine bodyweight move may
  use a small positive number or the athlete's bodyweight).
- `rep_min` <= `rep_max`; both >= 1. `sets` >= 1. `rest_seconds` > 0.
- `target_rpe` is 0–10, or null.
- Every value in a day's `focus_muscle_groups` and every exercise's
  `primary_muscle_group` MUST be one of: {$groups}
  (`primary_muscle_group` may also be null).
- RECOVERY: two back-to-back days MUST NOT share any muscle group.
  Day N and day N+1 must have disjoint `focus_muscle_groups`, and no
  `primary_muscle_group` may appear on both. A muscle group trained
  on day N is not trained again before day N+3 (train quads on day 1
  → next quads day 4 at the earliest). Isolate the split cleanly.

Guidance:
- Fill the athlete's session — roughly one working exercise per
  10–15 minutes of session time, within the range the prompt gives.
- Prefer 3–4 working sets for the main compound lifts and 2–3 for
  accessories.
- Order the 5 days as the athlete should train them; order exercises
  within each day.
- Watch the overlap compound lifts create: heavy pressing loads the
  shoulders and triceps, rows and deadlifts load the back and
  hamstrings — keep those off the day next to a dedicated shoulder,
  arm, back or hamstring day.
- Respect the athlete's available days per week, session length, goal,
  and any injuries or preferences in their notes.
- Give a short `split_rationale` for the week, a `day_rationale` per
  day, and a `rationale` per exercise.
```

### User prompt (example)

Built by `CyclePlannerService::buildPrompt`. The exercises-per-day range comes
from `config/training.php` for the athlete's experience level (here
intermediate: 4–6). The `Notes` and `Hint` lines appear only when filled.

```text
Build the first training week for this athlete.

Athlete profile:
- Experience level: intermediate
- Available days per week: 5
- Target session length: 60 minutes
- Notes: Mild left shoulder impingement, avoid overhead barbell pressing. Only has dumbbells and a bench at home on weekends.

Routine:
- Goal: hypertrophy
- Hint: Push/pull/legs style, legs twice if possible

Return exactly 5 training days. All weights are in kilograms.
Prescribe between 4 and 6 exercises on EVERY day (this athlete's experience level); use the higher end for longer sessions.
Pick ONE count in that range and use it on all 5 days — a day with fewer than 4 exercises makes the whole plan invalid.
```

### Response shape (example, abridged to one day)

```json
{
  "split_rationale": "Upper/lower split that keeps shoulders away from the chest day to protect the left shoulder.",
  "days": [
    {
      "label": "Quads and calves",
      "focus_muscle_groups": ["quads", "calves"],
      "day_rationale": "Heavy knee-dominant work first in the week.",
      "exercises": [
        {
          "name": "Barbell back squat",
          "primary_muscle_group": "quads",
          "sets": 4,
          "rep_min": 6,
          "rep_max": 8,
          "target_weight_kg": 80,
          "target_rpe": 8,
          "rest_seconds": 150,
          "rationale": "Main compound lift for the day."
        }
      ]
    }
  ]
}
```

What happens next: `CyclePlannerService::mapPlan` checks the day count, the
exercise count per day against the configured range, `rep_min ≤ rep_max`,
non-negative weight, RPE 0–10 and known muscle groups. Any violation throws
`CycleGenerationException` (HTTP 502 `AI_GENERATION_FAILED`) and nothing is
saved. Exercise names are free text here and are turned into catalogue rows by
`ExerciseCatalogService::resolve`.

---

## 2. Next cycle, one day: `CycleProgressionAgent`

Triggered by `CycleDayProgressionJob`, once per trained day of a cycle being
generated. The exercises are fixed; the model only changes load and volume.

### System instructions (verbatim)

```text
You are a strength and hypertrophy coach reviewing a training day
an athlete just finished. The prompt lists the exercise slots of
that day they performed, each with its current prescription, what they actually
did (average load and reps, highest RPE, weight trend, plateau
signal) and, when there is one, a recommendation from a previous
session analysis.

Decide the prescription for the same slot next week. Evaluate the
real performance first: an athlete who beat the target earns more
load, volume or reps; one who missed it, whose RPE was at the
ceiling, or whose trend is down or flat with a plateau signal
should hold or deload. Use the recommendation as a strong hint,
but real performance wins when they disagree.

HARD REQUIREMENTS — the response is rejected otherwise:
- Never add, remove, replace or reorder exercises. You only
  progress the slots you are given.
- Return exactly one progression per listed slot, referenced by the
  same `day` and `exercise` numbers the prompt uses. No extra
  slots, no missing slots, no duplicates.
- `target_weight_kg` is a number in KILOGRAMS on every slot; never
  omit it.
- `sets` >= 1. `rep_min` and `rep_max` >= 1 and `rep_min` <=
  `rep_max`. `rest_seconds` >= 0. `target_rpe` is 0-10, or null.

Guidance:
- Progress conservatively: small, safe steps (a little load, one
  rep, or one set at a time — not several at once).
- Change only what the evidence supports; keep the other fields as
  they are.
- Give a `rationale` of ONE short sentence per slot explaining the
  change (or why it holds).
```

### User prompt (example)

Built by `buildProgressionPrompt` plus one `slotLine` per **performed** slot of
the day. Each line has: current prescription, the real average from the logged
sets, the weight trend (`up`, `down`, `flat` or `insufficient_data`), a
`plateau signal` flag (trend down or flat) and, if the exercise has an active
recommendation, that recommendation with its explanation. Slots the athlete did
not perform are not listed (they are copied unchanged).

```text
Review the training week this athlete just finished and progress each exercise listed below. The exercises stay exactly the same; you only decide sets, rep range, load, RPE and rest.

Athlete profile:
- Experience level: intermediate
- Available days per week: 5
- Target session length: 60 minutes
- Notes: Mild left shoulder impingement, avoid overhead barbell pressing.

Routine:
- Goal: hypertrophy
- Hint: Push/pull/legs style, legs twice if possible

Slots to progress (day, exercise), all weights in kilograms:
- day 1, exercise 1 — Barbell back squat: prescribed 4x6-8 at 80.00kg, RPE 8.0; actual 80.00kg avg x 8.0 reps (max RPE 8.5); trend: up; recommendation: advance_weight — 82.50kg, 4x6-8 — You hit all four sets at 8 reps, so add a little load.
- day 1, exercise 3 — Leg press: prescribed 3x10-12 at 160.00kg; actual 160.00kg avg x 10.0 reps (max RPE 9.5); trend: flat, plateau signal

Return exactly one progression per listed slot, using the same day and exercise numbers.
```

### Response shape (example)

```json
{
  "progressions": [
    {
      "day": 1,
      "exercise": 1,
      "sets": 4,
      "rep_min": 6,
      "rep_max": 8,
      "target_weight_kg": 82.5,
      "target_rpe": 8,
      "rest_seconds": 150,
      "rationale": "Hit every rep last week, so add 2.5 kg."
    },
    {
      "day": 1,
      "exercise": 3,
      "sets": 3,
      "rep_min": 10,
      "rep_max": 12,
      "target_weight_kg": 160,
      "target_rpe": 8,
      "rest_seconds": 120,
      "rationale": "Plateaued with RPE near the ceiling, so hold the load and aim for more reps."
    }
  ]
}
```

`mapProgressions` rejects a response that progresses a slot that was not asked
about, progresses one twice, or leaves one out. On the **last** failed attempt
the Action does not fail the cycle: it takes the exercise's active
recommendation instead (`progressionsFromRecommendations`) and marks the day
`fallback`.

---

## 3. Session analysis: `SessionAnalystAgent`

Triggered by `SessionAnalysisJob` after a session is completed. One call covers
every exercise trained in the session.

### System instructions (verbatim)

`{$actions}` is `advance_weight, hold, add_reps, add_set, deload,
technique_focus` (the `RecommendationAction` enum).

```text
You are a strength and hypertrophy coach reviewing ONE training
session an athlete just finished. The prompt lists every exercise
trained that day, each with its baseline (the target the athlete
was training towards — either their prior recommendation for that
exercise, or the day's original prescription if this is the first
time, or no baseline at all for a first-ever free-session exercise)
and the sets actually executed.

For EVERY exercise listed, decide the target for the NEXT time the
athlete trains it:
- `action` MUST be exactly one of: {$actions}
  - `advance_weight`: the athlete handled the baseline comfortably —
    increase `target_weight_kg`.
  - `add_reps` / `add_set`: increase `target_rep_min`/`target_rep_max`
    or `target_sets` instead of load.
  - `hold`: repeat the same weight/sets/reps — the athlete did not
    (fully) meet the baseline, or data is too thin to progress.
  - `deload`: reduce `target_weight_kg` — signs of excessive fatigue
    or repeated failure to meet the baseline.
  - `technique_focus`: repeat the baseline unchanged, but note in
    `explanation` that the focus next time is form, not load.
- `target_weight_kg`, `target_sets`, `target_rep_min` and
  `target_rep_max` are ALWAYS required and numeric — every
  recommendation is a complete, usable prescription, even for
  `hold` or `technique_focus` (repeat the baseline's numbers rather
  than omitting them). `target_rep_min` <= `target_rep_max`.
- When there is no baseline at all, decide conservatively from
  today's sets alone (typically `hold` unless performance clearly
  signals more).
- `explanation` is one short sentence a gym app shows the athlete,
  referencing what they actually did (e.g. "You hit all 4x10 at
  20kg — let's push to 12 reps next time.").

HARD REQUIREMENT: `recommendations` MUST contain EXACTLY one entry
per exercise listed in the prompt, IN THE SAME ORDER. Never fewer,
never more, never reordered.
```

### User prompt (example)

Built by `SessionAnalystService::buildPrompt`. The `Session note` and `Perceived
effort` lines appear only when present. The baseline sentence depends on what the
exercise has: an existing recommendation, else the day's prescription, else
"first time" (three variants shown below). Sets are numbered, with RPE and the
per-set note when present. Exercises appear in the order they were first
trained.

```text
Analyze this completed training session and decide a next-time target for every exercise below.
Session note: Felt tired, slept badly.
Perceived effort for the whole session: 4/5.

Exercise: Barbell back squat
Previous recommendation for this exercise in this routine: 80.00kg, 4 sets, 6-8 reps (last action: hold).
Sets performed this session:
1. 80.00kg x 8 reps (RPE 7.5)
2. 80.00kg x 8 reps (RPE 8.0)
3. 80.00kg x 7 reps (RPE 9.0)
4. 80.00kg x 6 reps (RPE 9.5) — note: grindy

Exercise: Leg press
Today's original prescription (no prior recommendation yet): 3 sets, 10-12 reps, target 160.00kg.
Sets performed this session:
1. 160.00kg x 12 reps
2. 160.00kg x 12 reps
3. 160.00kg x 11 reps

Exercise: Walking lunges
No baseline — first time this exercise is trained in this routine.
Sets performed this session:
1. 20.00kg x 12 reps
2. 20.00kg x 12 reps
```

### Response shape (example)

```json
{
  "recommendations": [
    {
      "target_weight_kg": 80,
      "target_sets": 4,
      "target_rep_min": 6,
      "target_rep_max": 8,
      "action": "hold",
      "explanation": "You slowed down to 6 reps at RPE 9.5 on the last set, so repeat 80 kg before adding load."
    },
    {
      "target_weight_kg": 165,
      "target_sets": 3,
      "target_rep_min": 10,
      "target_rep_max": 12,
      "action": "advance_weight",
      "explanation": "You reached 12 reps on the first two sets, so add 5 kg."
    },
    {
      "target_weight_kg": 20,
      "target_sets": 2,
      "target_rep_min": 10,
      "target_rep_max": 12,
      "action": "hold",
      "explanation": "First time on this movement, so keep the same load and build consistency."
    }
  ]
}
```

The Service matches results to exercises **by array position**, never by a
model-provided identifier. A wrong count, a missing field, an unknown `action`
or `rep_min > rep_max` raises `SessionAnalysisException`, the job retries (30 s,
then 120 s), and after the last failure `analysis_state` becomes `failed`.

---

## Where each piece of prompt data comes from

| Prompt data | Source |
|---|---|
| Experience level, days per week, session minutes, notes | `athlete_profiles` |
| Goal, hint | `routines` (routine goal, not the profile goal) |
| Exercises-per-day range | `config/training.php` by experience level (`CYCLE_EXERCISES_PER_DAY_*`) |
| Prescribed sets, reps, weight, RPE | `day_exercises` of the outgoing cycle |
| Actual average load, reps, max RPE, trend, plateau signal | `ProgressionSummaryService` from `set_logs` (pure PHP, no AI) |
| Active recommendation per exercise | `exercise_recommendations` with status `active` |
| Baseline in session analysis | existing recommendation, else `day_exercises`, else none |
| Session note, perceived effort, set notes | the session and its `set_logs` |

## Changing a prompt safely

1. Edit `instructions()` (rules) or the Service's `build*Prompt` (data).
2. If the output shape changes, change the agent's `schema()` **and** the
   Service's mapping/validation together.
3. Keep every hard requirement enforced in PHP as well; the model can ignore the
   text.
4. Mind the token budget. On a rate-limited tier the first-cycle call already
   consumes most of the minute's allowance (see the note on `CyclePlannerAgent`).
5. Tests use fake agent responses and never hit a provider; update the fixtures
   next to the prompt change.
6. Update this page.

## Not covered yet

All prompts are in English and carry no locale; the AI-written fields
(`rationale`, `explanation`, `split_rationale`) are therefore English. See
`docs/design/exercise-catalog-i18n-wod-design.md` for the planned language
setting.
