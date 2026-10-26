<?php

namespace App\Ai\Agents\Cycle;

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
 * Structured-output agent that evaluates how the athlete performed the
 * exercises of the outgoing cycle and recommends each one's progression for
 * cycle N+1: sets, rep range, load, RPE and rest. It never chooses exercises —
 * {@see CyclePlannerService::planNextCycle()} clones the outgoing cycle's days
 * and exercises itself and asks this agent about the performed ones of ONE
 * training day per call, addressed as `(day, exercise)` slots — a short answer
 * a strict-JSON provider can validate, and a failure costs one day, not the
 * week.
 *
 * `#[MaxTokens(2500)]`: one short entry per slot, at most ~8 per day.
 * Reasoning models spend the same budget on thinking, so keep each
 * `rationale` to one sentence. Same strict-mode schema constraints as
 * {@see CyclePlannerAgent::schema()}.
 */
#[Timeout(30)]
#[MaxTokens(2500)]
final class CycleProgressionAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
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
            PROMPT;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $root = [
            'progressions' => $schema->array()
                ->description('Exactly one entry per listed slot.')
                ->items($this->object($schema, [
                    'day' => $schema->integer()->description('The slot\'s day number, as listed in the prompt.'),
                    'exercise' => $schema->integer()->description('The slot\'s exercise number within its day, as listed in the prompt.'),
                    'sets' => $schema->integer(),
                    'rep_min' => $schema->integer(),
                    'rep_max' => $schema->integer(),
                    'target_weight_kg' => $schema->number()->description('Target load in kilograms.'),
                    'target_rpe' => $schema->number()->nullable()->description('Target RPE 0-10, or null.'),
                    'rest_seconds' => $schema->integer(),
                    'rationale' => $schema->string()->description('One short sentence.'),
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
