<?php

namespace App\Ai\Agents\Recommendation;

use App\Ai\Agents\Cycle\CyclePlannerAgent;
use App\Enums\Recommendation\RecommendationAction;
use App\Services\Recommendation\SessionAnalystService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Structured-output agent that analyzes one just-completed training session
 * and, for every exercise trained that day, decides a next-time target: load,
 * sets, rep range, an action, and an explanation.
 *
 * Wrapped by {@see SessionAnalystService}, which
 * builds the prompt from the session's sets plus each exercise's baseline
 * (its prior recommendation, or failing that its cycle-day prescription) and
 * validates the result. One call covers every exercise trained in the
 * session — never one call per exercise (`docs/product-context.md` §5).
 *
 * `#[Timeout(30)]` / `#[MaxTokens(2000)]`: far smaller than
 * {@see CyclePlannerAgent}'s 60 s / 7000 tokens — a
 * session-close analysis is a handful of short per-exercise recommendations,
 * not a full 5-day plan.
 */
#[Timeout(30)]
#[MaxTokens(2000)]
final class SessionAnalystAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        $actions = implode(', ', RecommendationAction::values());

        return <<<PROMPT
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
            PROMPT;
    }

    /**
     * Strict-mode compatible, matching {@see CyclePlannerAgent}:
     * every property is `required()` (a logically-optional field stays
     * required but nullable — none are, here) and every object sets
     * `additionalProperties: false`.
     *
     * No `minItems`/`maxItems` on `recommendations`: as with the cycle planner,
     * providers in strict `json_schema` mode don't use array-length keywords to
     * constrain decoding, only to validate after the fact — the exact count is
     * enforced by {@see SessionAnalystService},
     * which rejects a response whose length doesn't match the exercises given.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $root = [
            'recommendations' => $schema->array()
                ->description('One recommendation per exercise listed in the prompt, in the same order.')
                ->items($this->object($schema, [
                    'target_weight_kg' => $schema->number()->description('Suggested load in kilograms for next time.'),
                    'target_sets' => $schema->integer()->description('Suggested number of sets for next time.'),
                    'target_rep_min' => $schema->integer(),
                    'target_rep_max' => $schema->integer(),
                    'action' => $schema->string()->enum(RecommendationAction::values()),
                    'explanation' => $schema->string(),
                ])),
        ];

        foreach ($root as $property) {
            $property->required();
        }

        return $root;
    }

    /**
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
