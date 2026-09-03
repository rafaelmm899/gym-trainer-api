<?php

namespace App\Ai\Agents\Cycle;

use App\Enums\Shared\MuscleGroup;
use App\Services\Cycle\CyclePlannerService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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
 */
#[Timeout(60)]
final class CyclePlannerAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        $groups = implode(', ', MuscleGroup::values());

        return <<<PROMPT
            You are a strength and hypertrophy coach building the FIRST training
            week of a new routine for one athlete.

            Rules:
            - Produce EXACTLY 5 training days, ordered as the athlete should train
              them.
            - Each day has 3 to 6 exercises, ordered.
            - Weights are in KILOGRAMS. Always set a concrete `target_weight_kg`
              for every exercise, estimated from the athlete's experience level
              and notes — never omit it and never use 0 for a loaded lift.
            - `rep_min` must be less than or equal to `rep_max`. `sets` is at
              least 1. `rest_seconds` is a positive number of seconds.
            - `target_rpe` is on the 0–10 RPE scale, or null if you do not want to
              prescribe one.
            - `focus_muscle_groups` for each day and `primary_muscle_group` for
              each exercise MUST be chosen from: {$groups}.
            - Respect the athlete's available days per week, session length,
              goal, and any injuries or preferences in their notes.
            - Give a short `split_rationale` for the whole week, a `day_rationale`
              for each day, and a `rationale` for each exercise.
            PROMPT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'split_rationale' => $schema->string()
                ->description('Why the muscle groups are split across the 5 days this way.'),
            'days' => $schema->array()
                ->min(5)
                ->max(5)
                ->description('Exactly 5 training days, in training order.')
                ->items($schema->object([
                    'label' => $schema->string()->description('Short name for the day, e.g. "Chest" or "Lower A".'),
                    'focus_muscle_groups' => $schema->array()
                        ->min(1)
                        ->items($schema->string()->enum(MuscleGroup::values())),
                    'day_rationale' => $schema->string(),
                    'exercises' => $schema->array()
                        ->min(3)
                        ->max(6)
                        ->items($schema->object([
                            'name' => $schema->string()->description('Exercise name, free text.'),
                            'primary_muscle_group' => $schema->string()->enum(MuscleGroup::values())->nullable(),
                            'sets' => $schema->integer()->min(1),
                            'rep_min' => $schema->integer()->min(1),
                            'rep_max' => $schema->integer()->min(1),
                            'target_weight_kg' => $schema->number()->min(0),
                            'target_rpe' => $schema->number()->min(0)->max(10)->nullable(),
                            'rest_seconds' => $schema->integer()->min(0),
                            'rationale' => $schema->string(),
                        ])),
                ])),
        ];
    }
}
