# Training & Workouts — System Guide

This page covers everything between an exercise in the catalog and a completed,
scored workout: programs, versions, assignments, sessions, sets, personal records,
weekly volume, progression and next-set suggestions.

Read [nutrition-system.md](nutrition-system.md) for the calorie/weight side and
[api-endpoints.md](api-endpoints.md) for the exact route list.

> **Status warning (2026-11-20).** The training module has the right shape and the
> database design is solid, but it does **not run end to end** in this snapshot:
> five service classes are in the wrong PHP namespace and several controllers write
> columns that do not exist. §9 lists every defect with file and line. The test suite
> cannot run either (Pest missing), so CI currently gives no signal. Treat §9 as the
> fix list before building any client work on top of these endpoints.

## 1. Ownership map

| Concern | Code |
|---|---|
| Exercise catalog | `ExerciseController`, `Exercise`, `ExerciseSeeder` |
| Program CRUD, template copy, publish, assign, current | `ProgramController` |
| Program day/item writing, version snapshot | `Services/Training/ProgramService` |
| Workout session lifecycle | `WorkoutController` |
| Sets | `WorkoutSetController`, `Services/Training/SetService` |
| Personal records | `Services/Training/RecordService` |
| Last performance | `Services/Training/PerformanceService` |
| Progression preview/apply | `ProgressionController`, `Services/Training/ProgressionService` |
| Next-set suggestion | `Services/Training/SuggestionService` |
| Volume and record history | `ProgressController` |
| Enums and limits | `config/training.php` |
| Trainer permission hooks | `Services/Training/TrainerAccess` (stub) |

## 2. Domain model

```
exercises ──< program_items >── program_days ──< programs
     │                                              │
     │                                         program_versions  (immutable snapshots)
     │                                              │
     │                                          program_assignments (trainer → client)
     │
workouts ──< workout_exercises ──< workout_sets >── personal_records
   │                                   │
   └──────────── pr_events ────────────┘
                       │
                progression_changes
```

### Tables (migration `2026_10_26_000001_sync_training_tables`)

| Table | Key columns | Notes |
|---|---|---|
| `exercises` | name, description, instructions (jsonb), primary/secondary muscles (jsonb), equipment (jsonb), movement_pattern, exercise_type, media, owner_id, is_public, archived_at | `owner_id NULL` = global/shared, `media` is declared `string` but cast to `array` (see §9.9) |
| `programs` | owner_id, name, goal, experience_level, days_per_week, status, is_template, published_version, `version` (added later) | `version` increments on day edits |
| `program_versions` | program_id, version_number, created_by, status, metadata (jsonb), published_at | Immutable published snapshot; uniqueness on (program_id, version_number) |
| `program_days` | program_id, day_number, name, notes, program_version_id (unused) | unique (program_id, day_number) |
| `program_items` | program_day_id, exercise_id, position, sets, rep_min, rep_max, rest_seconds, target_rpe, weight_kg, progression_rule (jsonb) | unique (program_day_id, position) |
| `program_assignments` | program_id, program_version_id, trainer_id, client_id, starts_on, ends_on, status | unique-free; one active assignment per client is enforced in code |
| `workouts` | user_id, program_id, program_version_id, program_day_id, program_assignment_id, client_uuid, status, started_at, finished_at, duration_seconds, notes, soft deletes | unique (user_id, client_uuid) |
| `workout_exercises` | workout_id, exercise_id, position, notes | unique (workout_id, position); **no `planned` column** |
| `workout_sets` | workout_id, workout_exercise_id, user_id, exercise_id, client_uuid, set_number, weight_kg, reps, rpe, duration_seconds, distance_meters, is_warmup, completed_at | unique (workout_id, client_uuid); one set number per exercise |
| `personal_records` | user_id, exercise_id, record_type (max_weight/max_reps/estimated_1rm), value, workout_id, workout_set_id, achieved_at | current best per (user, exercise, type) |
| `pr_events` | user_id, exercise_id, record_type, previous_value, new_value, workout_id, workout_set_id, **occurred_at** | one event per (workout, exercise, type) |
| `progression_changes` | user_id, program_assignment_id, program_item_id, exercise_id, triggered_by_workout_id, change_type, before_values, after_values, reason, changed_at | logging table — **does not match its controller** |

The full day/item structure is stored twice by design: live rows (`program_days`,
`program_items`) for editing, and a JSON snapshot in `program_versions.metadata` for
sessions. A workout started today keeps the prescription it was started with even if
the coach edits the plan tomorrow.

## 3. Exercise catalog

### Enumerations (`config/training.php`)

- **Muscles**: chest, back, lats, traps, front/side/rear deltoids, biceps, triceps,
  forearms, abs, obliques, lower_back, quadriceps, hamstrings, glutes, calves,
  adductors, abductors.
- **Equipment**: barbell, dumbbell, kettlebell, cable_machine, machine, bench,
  pullup_bar, bands, bodyweight, smith_machine, other.
- **Movement patterns**: horizontal/vertical push, horizontal/vertical pull, squat,
  hinge, lunge, carry, core, isolation, other.
- **Exercise types**: weighted, bodyweight, assisted, duration, distance,
  bodyweight_reps.
- **Progression types**: double_progression, fixed_increment, deload, none.

The seeder ships ~50 exercises with a `RepDB` slug map (`exercise_slugs`) used by the
mobile app to pick bundled illustrations. The API does not resolve those slugs to media
URLs; media is a client concern for now.

### Endpoints

- `GET /exercises` — filters `scope` (`all` default, `global`, `mine`), `muscle`,
  `equipment`, `movement_pattern`, `exercise_type`, `search`; cursor paginated,
  ordered by name. Archived exercises are hidden. `muscle` matches primary **or**
  secondary.
- `POST /exercises` — creates a custom exercise owned by the caller. Required: name,
  primary_muscles (min 1), movement_pattern, exercise_type. There is no `media` input.
- `GET /exercises/{id}` — visible exercises only (global or own).
- `PATCH /exercises/{id}`, `DELETE` — own custom exercises only. Delete archives the
  row when it is referenced by a program item or workout; otherwise it is hard-deleted.

## 4. Program lifecycle

```
draft ──publish──▶ active ──archive──▶ archived
  │                  │
  └── edit days ─────┘  (version += 1, new snapshot on next publish)
```

### 4.1 Create — `POST /training/programs`

```json
{
  "name": "Upper/Lower 4x",
  "description": "Off-season block",
  "goal": "hypertrophy",
  "experience_level": "intermediate",
  "days_per_week": 4,
  "days": [
    {
      "name": "Upper A",
      "day_number": 1,
      "items": [
        { "exercise_id": "uuid", "position": 1, "sets": 4,
          "rep_range": { "min": 6, "max": 10 }, "rest_seconds": 120,
          "target_rpe": 8, "weight_kg": 60,
          "progression_rule": { "type": "double_progression", "increment": 2.5 } }
      ]
    }
  ]
}
```

Validation: 1–14 days; day numbers distinct; 0–40 items per day; item positions
unique inside a day; 1–30 sets; rep min/max 1–200 with max ≥ min; rest ≤ 600 s; RPE
1–10; weight 0–1000; progression rule type from config. Every `exercise_id` must be
visible to the caller and not archived. The program is created as `draft`, `version = 1`,
`owner_id = caller`.

There is **no server-side program generator**. The mobile app's wizard generates the
split and prescriptions locally and posts the result here (see
`fitness-app-expo/docs/workout-system.md`).

### 4.2 Edit days — `PUT /training/programs/{id}/days`

Requires `expected_version`; mismatch returns `409 VERSION_CONFLICT` with
`details.current_version`. Days/items may carry existing `id`s to update in place;
omitted rows are deleted (`replace = true`). Writing increments `programs.version`.
Archived programs cannot be edited. This is the only operation that optimistically
locks.

`PATCH /training/programs/{id}` edits metadata only and does **not** increment
`version` (a deliberate simplification, but it means a metadata-only change cannot be
published as a new snapshot).

### 4.3 Publish — `POST /training/programs/{id}/publish`

- Rejects archived programs and programs with no exercise.
- `firstOrCreate` a `program_versions` row for the **current** `version`, storing
  `metadata = snapshotArray()` and `status = published`.
- Sets `programs.status = active`, `published_version = version`.
- Returns the full program (days + items + exercise), not the version row.

Snapshot shape:

```json
{ "program": { "id": "...", "name": "...", "goal": "...", "...": "..." },
  "version": 3,
  "days": [ { "id": "...", "day_number": 1, "name": "Upper A",
              "items": [ { "id": "...", "exercise_id": "...", "position": 1,
                           "sets": 4, "rep_min": 6, "rep_max": 10,
                           "rest_seconds": 120, "target_rpe": "8.0",
                           "weight_kg": "60.00", "progression_rule": { "...": "..." } } ] } ] }
```

Because publish is keyed on `version`, republishing without a version bump returns the
existing snapshot. Always `PUT .../days` (which bumps) before publishing an edit.

### 4.4 Templates

- `GET /training/templates?goal&experience_level&days_per_week&equipment` — global
  template programs (`is_template = true`), cursor paginated with `days_count`.
- `POST /training/templates/{id}/copy` — clones a template into the caller's drafts,
  copying only exercises the caller can see. Optionally renames.

### 4.5 Assign — `POST /training/programs/{id}/assignments`

```json
{ "client_id": "uuid", "start_date": "2026-11-20" }
```

- The program must be `active` and have a `published_version`.
- The caller must own the program (or pass `TrainerAccess::canEditPlan`).
- Assigning to someone else requires `TrainerAccess::canAssignTo`, which always
  returns `false` today, so **only self-assignment works**.
- Any existing active assignment for that client is cancelled first; the new one is
  created with the published `program_version_id`.
- `start_date` must be today or later (no backdating).
- `PATCH /training/assignments/{id}` moves `active ↔ paused` and closes to
  `completed`/`cancelled`; the same one-active-per-client rule applies.

### 4.6 Current — `GET /training/programs/current`

A coach assignment wins: returns `{assignment, program, days, program_snapshot}`.
Otherwise returns the caller's most recently updated active self-owned program with
`assignment: null`. `null` when there is neither.

## 5. Workout session lifecycle

```
POST /workouts ──▶ in_progress ──POST /complete──▶ completed
                        │
                        └──POST /cancel──▶ cancelled
```

### 5.1 Start

```json
{ "client_uuid": "uuid", "program_id": "uuid", "program_version": 3, "program_day_id": "uuid" }
```

- Idempotent on `(user_id, client_uuid)`: a replay returns the existing workout (200).
- Without a program, an empty session is created (free workout).
- With a program, the server resolves the version (request → active assignment →
  `published_version`), loads the snapshot and expands the chosen day's items into
  `workout_exercises`, ordered by item position.
- `started_at` defaults to now; more than 5 minutes in the future is rejected.

Intended expansion (once §9.2/§9.4 are fixed): each `workout_exercises` row should
carry the planned prescription (`sets`, rep range, rest, RPE, weight, progression rule)
so the client can render the session offline and `complete` can report planned vs done.
The schema has no column for it today.

### 5.2 During the session

- `PATCH /workouts/{id}` — notes and `started_at`.
- `POST /workouts/{id}/exercises` — add an exercise mid-session (in-progress only).
  `position` is optional; default is appended. Intended behaviour is to shift later
  rows down; see §9.3 (unique constraint).
- `PUT /workouts/{id}/exercises/order` — body `exercise_ids` must contain exactly the
  session's exercises, each once. Also affected by §9.3.
- `POST /workouts/{id}/sets` — see §6.
- `PATCH/DELETE /workouts/{id}/sets/{setId}` — correct or delete a set. Deleting or
  editing a set in a **completed** workout recomputes records.

### 5.3 Complete

`POST /workouts/{id}/complete` with optional `finished_at`.

- Cancelled → `INVALID_STATE`.
- Already completed → returns the summary again (idempotent).
- Duration must be ≥ 0 and ≤ `training.max_workout_hours` (8).
- In one transaction: set `completed`, `finished_at`, `duration_seconds`, then record
  PRs for every non-warmup exercise.
- Response:

```json
{ "data": {
    "workout": { "...": "..." },
    "summary": { "completed_working_sets": 14, "planned_sets": 16, "uncompleted_planned_sets": 2 },
    "new_records": [ { "record_type": "estimated_1rm", "new_value": 82.5, "...": "..." } ]
} }
```

`planned_sets` depends on the missing `planned` data (§9.4), so it is always 0 today.

### 5.4 Cancel

`POST /workouts/{id}/cancel` — only from `in_progress`; completed workouts cannot be
cancelled. Cancelled workouts are read-only for notes and sets.

### 5.5 Read

- `GET /workouts?status&from&to&program_id` — cursor paginated, newest first.
- `GET /workouts/{id}` — session with `exercises.exercise` and `exercises.sets`.
- `GET /workouts/exercises/{exerciseId}/history` — completed sessions containing that
  exercise, sets filtered to it.
- `GET /workouts/exercises/{exerciseId}/last-performance` — last completed performance
  (`null` if none).
- `GET /workouts/exercises/{exerciseId}/next-set-suggestion?program_id&program_day_id`
  — see §7.

## 6. Sets

### Validation by exercise type (`SetService::validateForType`)

| exercise_type | Required | Rejected |
|---|---|---|
| weighted, assisted | `weight_kg`, `reps` | — |
| bodyweight, bodyweight_reps | `reps` | — |
| duration | `duration_seconds` | — |
| distance | `distance_meters` | — |

Rep-based working sets need `reps ≥ 1` unless `is_warmup = true`. Rep-based types
reject `duration_seconds`/`distance_meters`. Ranges: weight 0–1000 kg, reps 0–1000,
RPE 1–10, duration ≤ 86 400 s, distance ≤ 1 000 000 m.

### Create — `POST /workouts/{id}/sets`

```json
{ "client_uuid": "uuid", "workout_exercise_id": "uuid", "set_number": 1,
  "weight_kg": 60, "reps": 10, "rpe": 8, "is_warmup": false }
```

Server fills `user_id`, `workout_id` and `exercise_id` from the workout exercise.
Set numbers must be unique per workout exercise. Replaying the same `client_uuid`:

- identical payload → returns the existing set, **200**;
- different payload → **409 `IDEMPOTENCY_CONFLICT`**;
- same uuid used on a different workout → conflict.

Only in-progress workouts accept new sets (`INVALID_STATE`). Returns 201 when created.

### Update/delete

`PATCH` accepts the data fields only (ids and set number are prohibited); the merged
row is re-validated by type. `DELETE` returns 204. Both work on completed workouts and
recompute PRs; both reject cancelled workouts.

## 7. Records, volume, progression, suggestions

### Personal records (`RecordService`)

Current bests are recomputed from source sets (never incremented blindly):

- `max_weight`: heaviest non-warmup set with reps ≥ 1.
- `max_reps`: most reps (ties broken by weight).
- `estimated_1rm`: Epley `weight * (1 + reps/30)`, only for 1–10 reps
  (`training.e1rm_max_reps`). Weighted exercises only.
- `bodyweight_reps` gets `max_reps` only.

On completion, `processWorkout` compares before/after and inserts a `pr_events` row for
improvements, unique per (workout, exercise, record_type).

Read endpoints:

- `GET /progress/personal-records?exercise_id&record_type&from&to`
- `GET /progress/exercises/{id}/personal-records`
- `GET /progress/exercises/{id}/personal-records/history`

### Weekly volume (`ProgressController`)

Volume is counted in **working sets**, not tonnage, and only for completed workouts.

- `GET /progress/weekly-volume?week_start&week_end&exercise_id&muscle_group`
  returns `total_working_sets` (unique sets) plus per-muscle counts. One set can count
  for several muscles, so the per-muscle numbers do not sum to the total.
- `GET /progress/weekly-volume/history?from&to&muscle_group&cursor` pages by week
  (default last 12 weeks), with a base64 cursor.

Dates are resolved in the user's timezone (`users.timezone`) using
`(completed_at AT TIME ZONE tz)::date`.

### Progression (`ProgressionService`)

Preview — `GET /training/programs/{id}/days/{dayId}/progression` returns one entry per
item:

```json
{ "exercise_id": "...", "current_prescription": { "sets": 4, "rep_min": 6, "rep_max": 10, "weight_kg": 60 },
  "proposed_prescription": { "...": "..." }, "rule": "double_progression",
  "reason": "All prescribed working sets reached the upper rep target...",
  "requires_approval": false }
```

Rules:

| Rule | Proposal |
|---|---|
| `double_progression` | +increment (default 2.5 kg) when **all** working sets reach `rep_max` at/below the RPE target; otherwise hold with an explanation |
| `fixed_increment` | +increment when all sets reach `rep_min` within RPE |
| `deload` | −reduction_percent (default 10 %), rounded to 0.5 kg, sets × multiplier; flagged for approval |
| `none` | no change |

Missing inputs (no history, no weight) produce a hold reason, never a guess.

Apply — `POST .../progression/apply` requires `expected_version` and `exercise_id`,
updates the item's weight/sets, bumps the program version, republishes when the program
was published, and logs a `progression_changes` row. **This endpoint cannot work in the
current snapshot** (§9.5): wrong namespace, wrong columns and a mismatch between the
controller payload and the table.

### Next-set suggestion (`SuggestionService`)

Uses the active program snapshot when available; otherwise the last completed
performance.

- With a prescription: suggested weight = planned weight (fallback: top set weight);
  suggested reps = best reps at that weight + 1, capped at `rep_max`; target RPE from
  the item.
- Without a prescription: repeats the top set's weight/reps/RPE.
- No history and no program → `data: null`.

## 8. Authorization rules

| Action | Rule |
|---|---|
| Read/write own workout | `workouts.user_id = caller`; others get 404 |
| Read program | owner, or assigned client (`active`/`paused`) |
| Edit/publish/archive/assign program | owner, or `TrainerAccess::canEditPlan` (always false today) |
| Assign to another client | `TrainerAccess::canAssignTo` (always false today) |
| Set writes | must belong to the caller's workout, and the workout must be in progress |
| Custom exercise edit/delete | `owner_id = caller`; global exercises are read-only |
| Trainer roster | `GET /trainer/clients` returns links for the calling trainer |

`TrainerAccess` is an intentional stub, not an oversight: the coaching flow
(invites/consent scopes, COACH-1) has no API yet, so there is no safe way to decide
whether a trainer may edit a client's plan. Do not "fix" it by returning true without
implementing scopes and the audit log.

## 9. Known defects in this snapshot

These are verified against the code, not guesses. Fix them before client integration.

### 9.1 PSR-4 namespace mismatch — **all training endpoints fail**

`app/Services/Training/` contains five classes that declare `namespace App\Services;`:

- `PerformanceService.php`
- `ProgressionService.php`
- `RecordService.php`
- `SetService.php`
- `SuggestionService.php`

Composer reports this during image build:

```
Class App\Services\SetService located in ./app/Services/Training/SetService.php
does not comply with psr-4 autoloading standard (rule: App\ => ./app). Skipping.
```

`App\Services\SetService` resolves to `app/Services/SetService.php`, which does not
exist, so method injection in `WorkoutSetController`, `WorkoutController::complete`,
`ProgressionController` and `SuggestionService` throws *Class not found*. In practice,
writes to sets, workout completion, progression and suggestions all return 500.

**Fix:** change those five files to `namespace App\Services\Training;` and update the
imports:

- `WorkoutController`: `use App\Services\Training\{PerformanceService, ProgramService, RecordService, SuggestionService};`
- `WorkoutSetController`: `use App\Services\Training\{RecordService, SetService};`
- `ProgressionController`: `use App\Services\Training\{ProgramService, ProgressionService};`

`ProgramService` and `TrainerAccess` already use the correct namespace.

### 9.2 `WorkoutController::store` uses removed columns

Lines 45–48 and 62:

| Code | Actual column |
|---|---|
| `ProgramAssignment::where('assigned_to', $u->id)` | `client_id` |
| `->value('program_version')` | `program_version_id` (UUID; map to `version_number`) |
| `ProgramVersion::where('version', $version)->value('snapshot')` | `where('version_number', $version)->value('metadata')` |
| `'program_version' => $version` on `Workout::create` | `program_version_id` (UUID of the version row) |

Starting a workout from a program therefore throws a SQL error (or reports "unknown
version"), and the intended version fallback chain never runs.

### 9.3 `addExercise` / `reorder` violate the position unique key

`workout_exercises` has `unique(workout_id, position)`. `addExercise` runs
`where('position', '>=', $pos)->increment('position')`, which collides with the row
already at `$pos`, and `reorder` writes 1..n one row at a time while other rows still
hold those positions. Both fail with a unique violation.

**Fix:** shift through a temporary offset inside the transaction, e.g. set affected
rows to `position = position + 1000`, then write final values, or drop the DB
constraint and enforce contiguity in code (less safe). Add tests for insert-in-middle
and full reversal.

### 9.4 `workout_exercises.planned` does not exist

`store` writes `'planned' => $item` and `complete` reads `$e->planned['sets']`, but the
table has no `planned` column. This is a second failure in the start path, and it makes
`summary.planned_sets` meaningless.

**Fix:** add `jsonb('planned')->nullable()` in a new migration (do not edit the old
one) and include it in `$fillable`/casts if needed, or remove the writes and derive
planned sets from the program snapshot by `program_day_id`.

### 9.5 Progression apply/history cannot persist

`ProgressionController` has three independent problems:

1. It imports `App\Services\ProgramService` (see §9.1).
2. It uses `program_assignments.assigned_to` and updates `program_version` (should be
   `client_id`, `program_version_id`; and the value must be a version UUID, not the
   integer version number).
3. `ProgressionChange::create([...])` sends `program_id`, `program_day_id`,
   `program_version`, `rule`, `previous`, `new`, `actor_id`, while the table has
   `user_id`, `program_assignment_id`, `program_item_id`, `exercise_id`,
   `triggered_by_workout_id`, `change_type`, `before_values`, `after_values`, `reason`,
   `changed_at`. `history()` also filters on the non-existent `program_id`.

**Fix:** decide on the canonical shape (the migration is the better one), map
`before_values`/`after_values` JSON, set `change_type = rule`, `changed_at = now()`,
and query history through `program_items`/`program_days` joins since the table has no
program id. Alternatively add `program_id`/`program_day_id` to the table in a new
migration if that matches the intended read model.

### 9.6 `pr_events` column mismatch breaks workout completion

`RecordService::processWorkout` inserts `'achieved_at' => ...`, and `PrEvent` casts
`achieved_at`, but the table column is `occurred_at`. Any completed workout that sets a
new record throws inside the completion transaction, so the workout is rolled back.
`ProgressController::recordHistory` also orders by `achieved_at` and will fail.

**Fix:** rename one side. Prefer `occurred_at` in the database and map it in the model
(`protected $casts = ['occurred_at' => 'datetime']`), updating the insert and the order
clause. `personal_records.achieved_at` is correct — do not confuse the two.

### 9.7 Weekly check-in job writes missing columns

`RunWeeklyCheckin` uses `week_start`, `metrics`, `proposed_changes`, `status`; the
`checkins` table has `checkin_date`, `weight_kg`, `waist_cm`, `body_fat_percentage`,
`notes`, `measurements`, `photos`. The job fails on its first `firstOrCreate`. See
[nutrition-system.md §4](nutrition-system.md#4-adaptive-weekly-adjustment-nut1).

### 9.8 The test suite cannot start

`php artisan test` aborts with:

```
Message: Call to undefined function it()
Location: /var/www/html/tests/Feature/TrainingTest.php:11
```

`TrainingTest` is written in Pest style, but Pest is not installed by the image build
(the repo has no `composer.json`; the Dockerfile creates a Laravel skeleton and adds
packages). No test runs at all, including the nutrition tests.

**Fix:** either add `pestphp/pest` (and `pestphp/pest-plugin-laravel`) to the Dockerfile
`composer require` list plus a `tests/Pest.php`, or rewrite `TrainingTest` as a PHPUnit
class extending `ApiTestCase`. Then add feature tests for program create/publish/assign
and the full workout flow. Until this is green, the PRD's G5 goal is unmet.

### 9.9 Minor: `exercises.media` type mismatch

The migration declares `string('media')`; the model casts `media` to `array`. Reading
returns `null` for a plain path, and writing an array can exceed 255 chars. The API
never accepts `media` and the seeder never sets it, so impact is low. When media URLs
land, migrate the column to `jsonb` (or `text`) in a new migration.

### 9.10 Minor: metadata edits do not bump the version

`PATCH /training/programs/{id}` updates name/goal/etc. without incrementing `version`,
while `PUT .../days` does. Publishing after a metadata-only edit reuses the existing
snapshot. Decide whether metadata belongs in the snapshot; if yes, bump on every
accepted edit.

## 10. Test coverage

| Area | Covered? |
|---|---|
| Exercise catalog CRUD/visibility | One Pest case (cannot run, §9.8) |
| Program create/update/publish/assign/current | None |
| Workout start/reorder/cancel | None |
| Set replay/idempotency | One Pest case (cannot run) |
| Set type validation and conflicts | None |
| PR calculation and e1RM | One Pest case (cannot run) |
| Weekly volume SQL | None |
| Progression rules | None (service has no unit test) |
| Suggestion fallbacks | None |

Add, at minimum:

1. `ProgramTest` — create with days, version conflict on stale edit, publish snapshot
   content, assign to self, one-active-assignment rule, client cannot edit others.
2. `WorkoutTest` — start from program day expands exercises in order; replay is
   idempotent; add-in-middle/reorder; complete computes duration, planned vs done and
   records; cancel rules.
3. `SetTest` — per-type validation matrix, duplicate set number, replay hash conflict.
4. `ProgressTest` — PR recalc after edit/delete, volume by muscle and timezone.

## 11. Change checklist

- [ ] Keep `program_versions.metadata` self-contained: a workout must be startable from
      a snapshot without joining live program rows.
- [ ] Bump `programs.version` on every accepted structural edit.
- [ ] Never write a column without checking the migration; training code has a history
      of drift (`assigned_to`, `snapshot`, `planned`, `achieved_at`).
- [ ] Any change to `workout_sets` semantics affects PRs, volume and progression —
      update all three docs and add regression tests.
- [ ] Keep the client contract in sync (`fitness-app-expo/docs/workout-system.md`) when
      payloads change.
- [ ] Add a line to `docs/CHANGELOG.md`.
