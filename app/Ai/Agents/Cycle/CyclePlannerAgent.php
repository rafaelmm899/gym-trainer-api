<?php

namespace App\Ai\Agents\Cycle;

use App\Enums\Shared\MuscleGroup;
use App\Services\Cycle\CyclePlannerService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;
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

            HARD REQUIREMENTS — the response is rejected otherwise:
            - `days` MUST contain EXACTLY 5 entries. Not 3, not 4, not 6 — five.
            - Every day MUST contain between 3 and 6 entries in `exercises`.
            - Every exercise MUST set a numeric `target_weight_kg` in KILOGRAMS,
              estimated from the athlete's experience level and notes. Never omit
              it; never use 0 for a loaded lift (a genuine bodyweight move may
              use a small positive number or the athlete's bodyweight).
            - `rep_min` <= `rep_max`; both >= 1. `sets` >= 1. `rest_seconds` > 0.
            - `target_rpe` is 0–10, or null.
            - Every value in a day's `focus_muscle_groups` and every exercise's
              `primary_muscle_group` MUST be one of: {$groups}
              (`primary_muscle_group` may also be null).

            Guidance:
            - Order the 5 days as the athlete should train them; order exercises
              within each day.
            - Respect the athlete's available days per week, session length, goal,
              and any injuries or preferences in their notes.
            - Give a short `split_rationale` for the week, a `day_rationale` per
              day, and a `rationale` per exercise.
            PROMPT;
    }

    /**
     * The exact numeric bounds (5 days, 3–6 exercises, rep ranges, RPE 0–10) are
     * NOT encoded here — strict `json_schema` providers (OpenAI, Groq) reject
     * `minItems` / `minimum` and demand every property be listed in `required`.
     * The bounds live in {@see instructions()} and are enforced by
     * {@see CyclePlannerService}; the schema only fixes the shape and types.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $root = [
            'split_rationale' => $schema->string()
                ->description('Why the muscle groups are split across the 5 days this way.'),
            'days' => $schema->array()
                ->min(5)
                ->max(5)
                ->description('Exactly 5 training days, in training order.')
                ->items($this->object($schema, [
                    'label' => $schema->string()->description('Short name for the day, e.g. "Chest" or "Lower A".'),
                    'focus_muscle_groups' => $schema->array()
                        ->min(1)
                        ->items($schema->string()->enum(MuscleGroup::values())),
                    'day_rationale' => $schema->string(),
                    'exercises' => $schema->array()->min(3)->max(6)->items($this->object($schema, [
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
