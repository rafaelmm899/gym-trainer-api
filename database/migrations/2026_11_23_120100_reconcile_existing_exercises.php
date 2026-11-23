<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Reconciles the exercises already in the database with the dataset catalogue,
     * from `database/catalog/exercise-reconciliation.json` (approved mapping of
     * the 75 production exercises): `enrich` rows get their dataset `external_id`
     * (`exercises:import` fills the rest), `merge` rows are folded into another
     * exercise with their history repointed, `manual` and `untouched` rows are
     * left alone. Finally the slug of every exercise whose name has a Unicode
     * hyphen is recomputed with the current rule (the hyphen used to be dropped).
     *
     * Self-contained on purpose (query builder only, no app classes): a migration
     * must keep working when the app changes. One transaction, all or nothing:
     * if the database partly matches the mapping, or a merge would collide, it
     * throws and nothing changes. A database that matches no entry (development,
     * tests) skips the reconciliation and only gets its slugs repaired.
     */
    public function up(): void
    {
        $entries = json_decode(
            (string) file_get_contents(database_path('catalog/exercise-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        DB::transaction(function () use ($entries): void {
            $this->reconcile($entries);
            $this->repairSlugs();
        });
    }

    /**
     * The deleted duplicates cannot be restored: take a backup before deploying.
     */
    public function down(): void
    {
        //
    }

    /**
     * @param  list<array{id: int, name: string, action: string, external_id: ?string, merge_into_id: ?int}>  $entries
     */
    private function reconcile(array $entries): void
    {
        $names = DB::table('exercises')->whereIn('id', array_column($entries, 'id'))->pluck('name', 'id');

        $matching = array_filter($entries, fn (array $entry): bool => ($names[$entry['id']] ?? null) === $entry['name']);

        if ($matching === []) {
            return;
        }

        $mismatched = array_filter($entries, fn (array $entry): bool => ! in_array($entry, $matching, true));

        if ($mismatched !== []) {
            $list = implode('; ', array_map(fn (array $entry): string => "#{$entry['id']} '{$entry['name']}'", $mismatched));

            throw new RuntimeException("The exercises do not match the reconciliation mapping: {$list}.");
        }

        $merges = array_filter($entries, fn (array $entry): bool => $entry['action'] === 'merge');

        foreach ($merges as $merge) {
            $this->guardMerge($merge['id'], $merge['merge_into_id']);
        }

        foreach ($merges as $merge) {
            $this->merge($merge['id'], $merge['merge_into_id']);
        }

        foreach ($entries as $entry) {
            if ($entry['action'] === 'enrich') {
                DB::table('exercises')->where('id', $entry['id'])->update(['external_id' => $entry['external_id']]);
            }
        }
    }

    private function guardMerge(int $loser, int $winner): void
    {
        $collides = DB::table('set_logs as a')
            ->join('set_logs as b', 'b.session_id', '=', 'a.session_id')
            ->where('a.exercise_id', $loser)->where('b.exercise_id', $winner)
            ->exists()
            || DB::table('exercise_recommendations as a')
                ->join('exercise_recommendations as b', fn ($join) => $join
                    ->on('b.user_id', '=', 'a.user_id')->on('b.routine_id', '=', 'a.routine_id'))
                ->where('a.exercise_id', $loser)->where('b.exercise_id', $winner)
                ->exists()
            || DB::table('day_exercises as a')
                ->join('day_exercises as b', 'b.cycle_day_id', '=', 'a.cycle_day_id')
                ->where('a.exercise_id', $loser)->where('b.exercise_id', $winner)
                ->exists();

        if ($collides) {
            throw new RuntimeException("Exercise #{$loser} cannot be merged into #{$winner}: both appear in the same session, recommendation or cycle day.");
        }
    }

    private function merge(int $loser, int $winner): void
    {
        foreach (['day_exercises', 'set_logs', 'exercise_recommendations'] as $table) {
            DB::table($table)->where('exercise_id', $loser)->update(['exercise_id' => $winner]);
        }

        DB::table('exercises')->where('id', $loser)->delete();
    }

    private function repairSlugs(): void
    {
        foreach (DB::table('exercises')->orderBy('id')->get(['id', 'name', 'slug']) as $exercise) {
            if (preg_match('/[\x{2010}-\x{2015}]/u', $exercise->name) !== 1) {
                continue;
            }

            $slug = Str::slug(Str::ascii(preg_replace('/[\x{2010}-\x{2015}]/u', '-', $exercise->name)));

            if ($slug === $exercise->slug) {
                continue;
            }

            if (DB::table('exercises')->where('slug', $slug)->where('id', '!=', $exercise->id)->exists()) {
                throw new RuntimeException("The slug '{$slug}' of exercise #{$exercise->id} is already used by another exercise.");
            }

            DB::table('exercises')->where('id', $exercise->id)->update(['slug' => $slug]);
        }
    }
};
