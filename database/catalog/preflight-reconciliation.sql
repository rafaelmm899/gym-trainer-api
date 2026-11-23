-- Preflight for the migration 2026_11_23_120100_reconcile_existing_exercises.
-- READ ONLY: it runs inside a read-only transaction and changes nothing.
-- Generated from database/catalog/exercise-reconciliation.json; regenerate it if the mapping changes.
-- Run it against production BEFORE deploying and read every section:
--   psql "$PROD_URL" -f database/catalog/preflight-reconciliation.sql
BEGIN READ ONLY;

\echo
\echo '== 1. Last migrations applied (expect 2026_11_01_120100_backfill_day_exercise_id_on_set_logs on top)'
SELECT migration FROM migrations ORDER BY id DESC LIMIT 3;

\echo
\echo '== 2. Mapping entries that do NOT match by id and name (expect 0 rows; any row aborts the migration)'
WITH m(id, name) AS (VALUES
    (1, 'Barbell Bench Press'),
    (2, 'Incline Dumbbell Press'),
    (3, 'Seated Overhead Press'),
    (4, 'Lateral Raises'),
    (5, 'Close‑Grip Bench Press'),
    (6, 'Triceps Rope Pushdown'),
    (7, 'Front Squat'),
    (8, 'Leg Extension'),
    (9, 'Deadlift'),
    (10, 'Bent‑Over Barbell Row'),
    (11, 'Pull‑Ups'),
    (12, 'Seated Cable Row'),
    (13, 'Barbell Hip Thrust'),
    (14, 'Lying Leg Curl'),
    (15, 'Standing Calf Raise'),
    (16, 'Dumbbell Hammer Curl'),
    (17, 'Bulgarian Split Squat'),
    (18, 'Cable Pull‑Through'),
    (19, 'Hip Abduction Machine'),
    (20, 'Reverse Hyperextension'),
    (21, 'Hanging Leg Raise'),
    (22, 'Cable Woodchopper'),
    (23, 'Plank'),
    (24, 'Russian Twist'),
    (25, 'Dumbbell Flat Press'),
    (26, 'Chest Dips'),
    (27, 'Arnold Press'),
    (28, 'Face Pull'),
    (29, 'Incline Close‑Grip Bench'),
    (30, 'Overhead Triceps Extension'),
    (31, 'Hack Squat'),
    (32, 'Rack Pulls'),
    (33, 'T‑Bar Row'),
    (34, 'Chin‑Ups'),
    (35, 'Straight‑Arm Pulldown'),
    (36, 'Romanian Deadlift'),
    (37, 'Good Mornings'),
    (38, 'Donkey Calf Raise'),
    (39, 'Seated Calf Press'),
    (40, 'Dumbbell Bench Press'),
    (41, 'Dumbbell Shoulder Press'),
    (42, 'Dumbbell Incline Fly'),
    (43, 'Dumbbell Lateral Raise'),
    (44, 'One‑Arm Dumbbell Row'),
    (45, 'Bent‑Over Reverse Fly'),
    (46, 'Standing Dumbbell Biceps Curl'),
    (47, 'Hammer Curl'),
    (48, 'Renegade Row (bodyweight core focus)'),
    (49, 'Goblet Squat'),
    (50, 'Dumbbell Romanian Deadlift'),
    (51, 'Dumbbell Walking Lunge'),
    (52, 'Standing Dumbbell Calf Raise'),
    (53, 'Dumbbell Hip Bridge'),
    (54, 'Dumbbell Russian Twist'),
    (55, 'Dumbbell Front Raise'),
    (56, 'Upright Row'),
    (57, 'Triceps Kickback'),
    (58, 'Dumbbell Deadlift'),
    (59, 'Dumbbell Pull‑Over'),
    (60, 'Concentration Curl'),
    (61, 'Alternating Dumbbell Row'),
    (62, 'One-Arm Dumbbell Row'),
    (63, 'Renegade Row'),
    (64, 'Bench Press'),
    (65, 'Overhead Press'),
    (66, 'Dumbbell Fly'),
    (67, 'Triceps Pushdown'),
    (68, 'Lateral Raise'),
    (69, 'Lat Pulldown'),
    (70, 'Seated Row'),
    (71, 'Dumbbell Curl'),
    (72, 'Back Squat'),
    (73, 'Leg Press'),
    (74, 'Walking Lunges'),
    (75, 'Hip Thrust')
)
SELECT m.id, m.name AS expected_name, e.name AS actual_name
FROM m LEFT JOIN exercises e ON e.id = m.id
WHERE e.name IS DISTINCT FROM m.name
ORDER BY m.id;

\echo
\echo '== 3. Exercises that are not in the mapping (created since the copy; they are left as they are)'
SELECT id, name, slug FROM exercises WHERE id NOT IN (1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 70, 71, 72, 73, 74, 75) ORDER BY id;

\echo
\echo '== 4. Merge collisions: a winner and the exercises merged into it must never share a session, a (user, routine) or a cycle day (each count must be 0; any other value aborts the migration)'
WITH members(exercise_id, winner) AS (VALUES
    (25, 25),
    (40, 25),
    (4, 4),
    (43, 4),
    (68, 4),
    (16, 16),
    (47, 16),
    (13, 13),
    (53, 13),
    (44, 44),
    (62, 44),
    (1, 1),
    (64, 1),
    (6, 6),
    (67, 6),
    (12, 12),
    (70, 12),
    (46, 46),
    (71, 46),
    (51, 51),
    (74, 51)
)
SELECT 'sessions with sets of two exercises that merge together' AS check, count(*) AS found FROM (
    SELECT m.winner, s.session_id FROM set_logs s JOIN members m ON m.exercise_id = s.exercise_id
    GROUP BY m.winner, s.session_id HAVING count(DISTINCT s.exercise_id) > 1) t
UNION ALL
SELECT 'routines with recommendations for two that merge together', count(*) FROM (
    SELECT m.winner, r.user_id, r.routine_id FROM exercise_recommendations r JOIN members m ON m.exercise_id = r.exercise_id
    GROUP BY m.winner, r.user_id, r.routine_id HAVING count(DISTINCT r.exercise_id) > 1) t
UNION ALL
SELECT 'cycle days prescribing two that merge together', count(*) FROM (
    SELECT m.winner, d.cycle_day_id FROM day_exercises d JOIN members m ON m.exercise_id = d.exercise_id
    GROUP BY m.winner, d.cycle_day_id HAVING count(DISTINCT d.exercise_id) > 1) t;

\echo
\echo '== 5. Repaired slug already used by an exercise that stays (expect 0 rows; any row aborts the migration). Covers every name with a Unicode hyphen, including exercises created after the mapping'
WITH hyphenated AS (
    SELECT id, name,
           trim(both '-' FROM regexp_replace(lower(regexp_replace(name, ('[' || chr(8208) || '-' || chr(8213) || ']'), '-', 'g')), '[^a-z0-9]+', '-', 'g')) AS new_slug
    FROM exercises
    WHERE name ~ ('[' || chr(8208) || '-' || chr(8213) || ']')
)
SELECT h.id, h.name, h.new_slug, e.id AS taken_by
FROM hyphenated h JOIN exercises e ON e.slug = h.new_slug AND e.id <> h.id
WHERE e.id NOT IN (40, 43, 47, 53, 62, 64, 67, 68, 70, 71, 74);  -- the merged rows are deleted before slugs are repaired

\echo
\echo '== 6. Rows the merge will repoint (day_exercises / set_logs / recommendations)'
SELECT
    (SELECT count(*) FROM day_exercises WHERE exercise_id IN (40, 43, 47, 53, 62, 64, 67, 68, 70, 71, 74)) AS day_exercises,
    (SELECT count(*) FROM set_logs WHERE exercise_id IN (40, 43, 47, 53, 62, 64, 67, 68, 70, 71, 74)) AS set_logs,
    (SELECT count(*) FROM exercise_recommendations WHERE exercise_id IN (40, 43, 47, 53, 62, 64, 67, 68, 70, 71, 74)) AS recommendations;

\echo
\echo '== 7. Totals BEFORE the deploy (save them: after it, exercises drops by 11 plus the imported ones; the other four must not change)'
SELECT
    (SELECT count(*) FROM exercises) AS exercises,
    (SELECT count(*) FROM day_exercises) AS day_exercises,
    (SELECT count(*) FROM set_logs) AS set_logs,
    (SELECT count(*) FROM exercise_recommendations) AS recommendations,
    (SELECT count(*) FROM training_sessions) AS training_sessions;

ROLLBACK;
