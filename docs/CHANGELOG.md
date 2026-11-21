# Changelog

Documentation and behaviour changes for this repository. This project does not yet
ship release tags; entries are grouped by date and branch. Add an entry to the top of
the relevant section in every MR.

Format: date · branch · change. Behaviour changes must also update
[nutrition-system.md](nutrition-system.md), [workout-system.md](workout-system.md) or
[api-endpoints.md](api-endpoints.md).

## Unreleased

### Documentation

- **2026-11-20 · `docs/nutrition-training-systems`** — Added the engineering handbook:
  [README](README.md), [nutrition-system](nutrition-system.md),
  [workout-system](workout-system.md), [api-endpoints](api-endpoints.md),
  [prd-alignment](prd-alignment.md), [prd-addendum-v1.1](prd-addendum-v1.1.md) and
  this changelog. Documents the code as found on `main` (`e285be0`) and adds
  implementation addenda to both PRDs.

### Findings recorded (not fixed in this change)

- Training runtime is non-functional: five services in `app/Services/Training/`
  declare `namespace App\Services;` and are skipped by Composer's autoloader. See
  [workout-system.md §9.1](workout-system.md#91-psr-4-namespace-mismatch--all-training-endpoints-fail).
- Workout start reads removed columns (`assigned_to`, `program_version`, `snapshot`)
  and writes a missing `planned` column.
  See [§9.2](workout-system.md#92-workoutcontrollerstore-uses-removed-columns) and
  [§9.4](workout-system.md#94-workout_exercisesplanned-does-not-exist).
- `pr_events` is written with `achieved_at` but the column is `occurred_at`, so
  completing a record-setting workout rolls back.
  See [§9.6](workout-system.md#96-pr_events-column-mismatch-breaks-workout-completion).
- Progression apply/history cannot persist (namespace, `assigned_to` and a
  payload/table mismatch). See [§9.5](workout-system.md#95-progression-applyhistory-cannot-persist).
- `RunWeeklyCheckin` writes columns that do not exist on `checkins`, so the adaptive
  target job fails. See [nutrition-system.md §4](nutrition-system.md#4-adaptive-weekly-adjustment-nut1).
- `ApiException` is not mapped in `ApiErrors`, so documented 409/422 domain errors
  render as 500. See [api-endpoints.md §1](api-endpoints.md#1-response-envelopes).
- The test suite cannot start: `TrainingTest` uses Pest syntax but Pest is not
  installed. `docker compose --profile test run --rm --build tests` aborts with
  `Call to undefined function it()`. See [§9.8](workout-system.md#98-the-test-suite-cannot-start).
- `TrainerAccess::canEditPlan`/`canAssignTo` always return `false`, blocking trainer
  assignment and plan editing. See [§8](workout-system.md#8-authorization-rules).

### Fixed

- _(none in this change — documentation only)_

## 2026-10 (pre-documentation baseline)

Summarised from migrations and code present on `main`; no tags exist.

- Nutrition: food catalog with trigram search, barcode lookup, custom foods, servings,
  reports, idempotent food logs, quick add, copy day/meal, saved meals, cached
  day/range summaries, target calculator with guardrails, target history and
  recalculation, EWMA weight trend, adaptive target service and weekly check-in job
  (job broken by schema drift).
- Training: exercise catalog, versioned programs, templates, publish/assign/current,
  workout sessions, sets with type validation and idempotency, PR recalculation,
  weekly volume, progression preview, next-set suggestion (runtime broken by namespace
  and schema drift).
- Platform: Sanctum tokens with refresh rotation and per-device revoke, JWKS social
  verification, signed attachment upload reservation, account export/delete with a
  30-day purge, scheduler and queue workers in Docker Compose.
- Added `programs.version` (`2026_10_27_000001_add_version_to_programs`) and
  `exercises.slug` (`2026_11_01_000001_add_slug_to_exercises`).
