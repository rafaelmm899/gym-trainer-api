<?php

namespace App\Ai\Agents\Cycle;

use App\Enums\Shared\MuscleGroup;
use App\Services\Cycle\CyclePlannerService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Structured-output agent that plans a routine's first weekly cycle: a five-day
 * split with a full prescription (sets, rep range, target weight, RPE, rest) and
 * a rationale per day and per exercise.
 *
 * Wrapped by {@see CyclePlannerService}, which builds the
 * prompt from the athlete profile + routine goal/hint and validates the result.
 * Runs on the default provider (`config('ai.default')`) and its configured text
 * model (`config('ai.providers.<driver>.models.text.default')`, set from the
 * `AI_PROVIDER_MODEL` env var); the 60 s timeout bounds the worst case for the
 * synchronous create request.
 *
 * `#[MaxTokens(7000)]` because a full 5-day plan (6–8 exercises a day, each with
 * a rationale) plus the reasoning the recovery/isolation rule triggers runs to
 * ~6k completion tokens. Without an explicit cap OpenAI-compatible providers
 * apply a small default and truncate the JSON mid-plan (`finish_reason: length`),
 * which then fails schema validation or yields fewer than 5 days. 7000 clears the
 * generation with ~1k headroom; note that prompt + cap (~7.7k) then nearly fills
 * Groq's free-tier 8k tokens-per-minute budget, so there is room for roughly one
 * request per minute and none for a retry — a busier setup needs the Groq dev
 * tier or a larger model.
 */
#[Timeout(60)]
#[MaxTokens(7000)]
final class CyclePlannerAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        $groups = implode(', ', MuscleGroup::values());

        return <<<PROMPT
            You are a strength and hypertrophy coach building the FIRST training
            week of a new routine for one athlete.

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
            PROMPT;
    }

    /**
     * Strict-mode compatible: every object lists all its properties in
     * `required` (via {@see object()}) and sets `additionalProperties: false`;
     * a logically-optional field stays required but nullable.
     *
     * No array-length keywords (`minItems`/`maxItems`): Groq's `json_schema`
     * strict mode validates them *after* generation but does not use them to
     * constrain decoding, so a model that emits 1 day instead of 5 gets a hard
     * provider 400 (`json_validate_failed`) instead of a plan we can reject
     * ourselves. The day count and the exercises-per-day range are enforced in
     * the prompt and in {@see CyclePlannerService}.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $root = [
            'split_rationale' => $schema->string()
                ->description('Why the muscle groups are split across the 5 days this way.'),
            'days' => $schema->array()
                ->description('Exactly 5 training days, in training order.')
                ->items($this->object($schema, [
                    'label' => $schema->string()->description('Short name for the day, e.g. "Chest" or "Lower A".'),
                    'focus_muscle_groups' => $schema->array()
                        ->items($schema->string()->enum(MuscleGroup::values())),
                    'day_rationale' => $schema->string(),
                    'exercises' => $schema->array()->items($this->object($schema, [
                        'name' => $schema->string()->description('Exercise name, free text.'),
                        'primary_muscle_group' => $schema->string()->enum(MuscleGroup::values())->nullable()
                            ->description('One of the listed muscle groups, or null.'),
                        'sets' => $schema->integer(),
                        'rep_min' => $schema->integer(),
                        'rep_max' => $schema->integer(),
                        'target_weight_kg' => $schema->number()->description('Target load in kilograms.'),
                        'target_rpe' => $schema->number()->nullable()->description('Target RPE 0-10, or null.'),
                        'rest_seconds' => $schema->integer(),
                        'rationale' => $schema->string(),
                    ])),
                ])),
        ];

        foreach ($root as $property) {
            $property->required();
        }

        return $root;
    }

    /**
     * A strict object: no extra properties, and every property listed in
     * `required` (nullable is how a property is made optional). This is what
     * OpenAI / Groq `json_schema` mode demands.
     *
     * @param  array<string, Type>  $properties
     */
    private function object(JsonSchema $schema, array $properties): ObjectType
    {
        foreach ($properties as $property) {
            $property->required();
        }

        return $schema->object($properties)->withoutAdditionalProperties();
    }
}
