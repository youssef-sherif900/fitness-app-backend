# PRD Alignment — Backend

Compares `Fitness App Backend PRD.txt` (v1.0) with the code as of 2026-11-20.
Status legend:

| Status | Meaning |
|---|---|
| **Done** | Endpoint(s) exist, behaviour matches the requirement, tested |
| **Done\*** | Implemented but with a verified defect or an intentional deviation |
| **Partial** | Some acceptance criteria met, others missing |
| **Schema only** | Tables/models exist, no API |
| **Missing** | Nothing in the codebase |

Summary: **12 of 33 P0 requirements are Done**, 6 more are Done\* or Partial in the
core nutrition/training flow, and 11 P0 requirements (coaching, chat, sync, billing,
push) are not implemented. The MVP is therefore a complete *solo* nutrition + training
app with a broken training runtime, not the coaching product the PRD describes.

## 1. Requirement matrix

### Auth and account

| ID | Pri | Status | Evidence / gap |
|---|---|---|---|
| AUTH-1 Register/login, tokens, refresh, per-device revoke | P0 | **Done** | `AuthController`; 60-min access, 60-day refresh, one token pair per `device_id`; `GET/DELETE /auth/sessions`. `tests/Feature/AuthTest.php` |
| AUTH-2 Apple + Google sign-in | P0 | **Done** | `POST /auth/social` + `JwksSocialVerifier` (JWKS, issuer and audience checks). Route shape differs from the PRD's `/auth/oauth/{provider}`; client must send `id_token` |
| AUTH-3 Email verification + password reset | P0 | **Done** | Signed verification URL, resend, generic forgot-password, reset revokes all tokens. Verification is not enforced at login (deliberate) |
| AUTH-4 Account deletion with retention window | P0 | **Done** | `DELETE /me` soft-deletes + revokes tokens (202); `PurgeExpiredData` hard-deletes after `fitness.account_deletion_days` (30) and removes the user's storage prefix |

### Profile, targets, guardrails

| ID | Pri | Status | Evidence / gap |
|---|---|---|---|
| PROF-1 Profile + goal | P0 | **Done** | `PUT /me/profile`, `GET /me`; all fields from the PRD are stored |
| PROF-2 Documented calorie/macro formula | P0 | **Done** | Mifflin–St Jeor × activity factor in `TargetCalculator`; golden unit tests; config-overridable |
| PROF-3 Server-side safety guardrails | P0 | **Partial** | Min daily kcal and max weekly loss are enforced (422) and configurable, but values are **placeholders** awaiting nutrition-professional sign-off (PRD explicitly requires this). No upper kcal cap, no rate check for gain beyond validation |

### Food and logging

| ID | Pri | Status | Evidence / gap |
|---|---|---|---|
| FOOD-1 Search, barcode, servings/nutrients | P0 | **Done** | `GET /foods` (trigram), `/foods/barcode/{code}`, `/foods/{id}` with servings. Popular list added beyond the PRD |
| FOOD-2 Custom foods, report, verified flag | P0 | **Done** | Create/edit own foods, `POST /foods/{id}/report`, `verified` filter. No moderation queue or report status API |
| FOOD-3 Text/voice/photo food parsing | P1 | **Missing** | No provider interface, no parse endpoint, no voice pipeline |
| LOG-1 Food log CRUD, idempotent UUIDs | P0 | **Done** | `POST/PATCH/DELETE /logs`, unique `(user_id, client_uuid)`, replay returns 200. Soft delete leaves tombstones (no tombstone feed) |
| LOG-2 Copy meal/day, saved meals/recipes | P0 | **Done** | `POST /logs/copy`, `/nutrition/saved-meals` CRUD. Optional beyond the PRD: quick add, undo delete on the client |
| LOG-3 Daily/range summaries incl. burn + deficit | P0 | **Partial** | Intake, per-meal and target macros are implemented and cached. `burn_kcal` is hard-coded `null`; there is no energy-expenditure model, so "deficit" is intake vs target only |

### Body and adaptive nutrition

| ID | Pri | Status | Evidence / gap |
|---|---|---|---|
| BODY-1 Weight entries + smoothed trend + measurements | P0 | **Partial** | Weights + EWMA trend + weekly slope implemented and tested. **Body measurements have a table (`measurements`) but no API** |
| BODY-2 Progress photos | P1 | **Missing** | Only the generic attachment reservation exists; no photo entity, gallery or comparison |
| NUT-1 Weekly adaptive adjustment with visible reason | P0 | **Done\*** | `AdaptiveTargetService` (14-day window, ≥4 weigh-ins, adherence ≥60 %, ±150 kcal, floor 1200, damping 0.5) and pure unit tests pass. **The job that applies it cannot run**: `RunWeeklyCheckin` writes `week_start`/`metrics`/`proposed_changes`/`status`, which do not exist on `checkins` |
| NUT-2 Meal plans, swaps, one-tap logging, grocery list | P1 | **Missing** | No tables, no routes |

### Training

| ID | Pri | Status | Evidence / gap |
|---|---|---|---|
| TRN-1 Exercise library, filters, media, custom exercises | P0 | **Done** | `/exercises` CRUD, muscle/equipment/pattern/type filters, custom ownership. **Media is not served by the API**; the client maps RepDB slugs to bundled images. `exercises.media` is a `string` column cast to `array` |
| TRN-2 Rule-based generation, templates, progression/deload | P0 | **Partial** | Global template programs + copy exist; progression and deload rules exist in `ProgressionService`. **No server-side generator** (the app generates locally) and **progression apply/history cannot persist** (namespace + column mismatch) |
| WKT-1 Sessions/sets, history, last performance, next-set suggestion | P0 | **Done\*** | Routes and logic exist, but **starting a workout fails**: `WorkoutController::store` reads `assigned_to`/`program_version`/`snapshot` and writes a missing `planned` column. Set writes and completion also fail because five services are in the wrong namespace |
| WKT-2 Personal records + weekly volume per muscle | P0 | **Done\*** | Epley e1RM, PR recomputation, weekly working-set volume with per-muscle breakdown. **PR event insert writes `achieved_at`; the column is `occurred_at`**, so completing a workout that sets a record rolls back |

### Check-ins and coaching

| ID | Pri | Status | Evidence / gap |
|---|---|---|---|
| CHK-1 Weekly check-in job; trainer approve/override | P0 | **Partial** | Scheduler dispatches hourly per user timezone; job logic is written. Blocked by the `checkins` schema mismatch. **No read/approve/override API**, so a stored proposal can never be accepted |
| COACH-1 Invite code/link, scoped consent, pause/revoke | P0 | **Schema only** | `trainer_clients` + `access_audit` tables. Only `GET /trainer/clients` exists |
| COACH-2 Roster, client overview, alerts | P0 | **Partial** | `GET /trainer/clients` lists links (status + scopes). No client overview, no alerts/inactivity detection |
| COACH-3 Trainer assigns programs/targets; targets protected | P0 | **Partial** | The "auto adjustment must not overwrite trainer targets" guard **is** implemented (`TargetService::isTrainerManaged`, adaptive proposals stored pending). Assigning to a client is blocked: `TrainerAccess::canAssignTo()` always returns `false`. No target-override API |
| COACH-4 Comments + reusable templates | P0 | **Missing** | No comment model or routes (program templates exist but are not coach-comment templates) |
| COACH-5 Trainer access audit log viewable by client | P0 | **Schema only** | `access_audit` table; nothing writes or reads it |

### Chat, media, sync, billing, push

| ID | Pri | Status | Evidence / gap |
|---|---|---|---|
| CHAT-1..3, CHAT-5 | P0 | **Missing** | `messages`/`conversations` tables and Reverb wiring exist; **no chat routes** |
| CHAT-4 Typing/presence | P1 | **Missing** | — |
| MEDIA-1 Signed uploads, validation, FFmpeg, thumbnails, waveform, playback | P0 | **Partial** | `POST /attachments` reserves a row and returns a signed PUT URL, idempotent via header. No validation callback, no FFmpeg/thumbnail/waveform, no signed playback endpoint; rows stay `pending` |
| SYNC-1 Batch push/pull with cursor + conflict rules | P0 | **Partial** | No batch endpoint and no `sync_cursors` API. The PRD's intent is partly met by per-resource idempotency (logs, weights, sets) and a client-side rolling pull window. Cross-device deletions are invisible |
| SUB-1 RevenueCat webhook + free tier | P0 | **Schema only** | `subscriptions` table; no webhook, no entitlement check |
| NOTIF-1 Expo push tokens, preferences, quiet hours | P0 | **Missing** | No table, no routes, no sender |

### Privacy

| ID | Pri | Status | Evidence / gap |
|---|---|---|---|
| PRIV-1 Export all data; delete all data | P0 | **Done** | `GET /me/export` (JSON dump of user-owned tables) and `DELETE /me` + purge job. Export misses attachments content (only rows) and chat (none exists) |

## 2. Goals (G1–G5)

| Goal | Verdict |
|---|---|
| G1 Accurate calorie/macro/weight-trend data | Met for math and trend; "deficit" cannot include burn until an energy model exists |
| G2 Adaptive nutrition and training programs | Nutrition logic written but job broken; training adaptation (progression) written but not runnable |
| G3 Consent-based coaching + realtime chat + media | Not met: consent scopes, chat, media processing absent |
| G4 One-command startup | Met (`docker compose up --build`), with caveats: the image builds a Laravel skeleton and the repo has no `composer.json`/lock of its own, so builds depend on network and package drift |
| G5 Automated tests protect math, authz, sync | **Not met**: the suite cannot start (`Call to undefined function it()` — Pest is not installed). Only unit-level nutrition math is trustworthy |

## 3. Verified defects that change PRD status

| Defect | Affected IDs | Reference |
|---|---|---|
| Five training services declare `namespace App\Services` instead of `App\Services\Training` | WKT-1, WKT-2, TRN-2 | [workout-system.md §9.1](workout-system.md#91-psr-4-namespace-mismatch--all-training-endpoints-fail) |
| Workout start uses `assigned_to`, `program_version`, `snapshot` | WKT-1 | [§9.2](workout-system.md#92-workoutcontrollerstore-uses-removed-columns) |
| `workout_exercises` has no `planned` column | WKT-1 | [§9.4](workout-system.md#94-workout_exercisesplanned-does-not-exist) |
| `pr_events.achieved_at` vs `occurred_at` | WKT-2 | [§9.6](workout-system.md#96-pr_events-column-mismatch-breaks-workout-completion) |
| `ProgressionChange` payload does not match the table | TRN-2 | [§9.5](workout-system.md#95-progression-applyhistory-cannot-persist) |
| `checkins` schema does not match `RunWeeklyCheckin` | NUT-1, CHK-1 | [nutrition-system.md §4](nutrition-system.md#4-adaptive-weekly-adjustment-nut1) |
| `ApiException` is not mapped, so 400/409/422 domain errors render as 500 | WKT-1, TRN-2, COACH-3 | [api-endpoints.md §1](api-endpoints.md#1-response-envelopes) |
| Pest missing from the image | G5 | [workout-system.md §9.8](workout-system.md#98-the-test-suite-cannot-start) |

## 4. PRD statements that no longer match the product

These are candidates for the addendum; the code is the source of truth.

1. **AUTH-2 route shape** — PRD implies provider-specific endpoints; implementation is
   `POST /auth/social` with `provider`, `id_token`.
2. **LOG-3 "burn, deficit"** — the app intentionally shows intake vs target. Either
   add an energy model or amend the PRD to define "deficit" as intake vs target.
3. **BODY-1 "body measurements"** — present in the PRD but not in the API; either
   implement `GET/POST /body/measurements` or move it to Phase 2.
4. **TRN-2 "rule-based program generation"** — generation is client-side
   (`fitness-app-expo/src/features/training/generate.ts`); the backend stores and
   versions programs. State where generation belongs.
5. **SYNC-1 "batch upload/pull"** — not implemented. The current contract is
   per-resource idempotency plus a client-driven rolling window; document that, or
   schedule the batch endpoint.
6. **CHK-1 "trainer can approve or override"** — no API; the check-in job also cannot
   persist. Needs both a schema fix and an approval surface.
7. **COACH-3 "trainer assigns programs"** — blocked by the `TrainerAccess` stub;
   the target-protection half of the requirement is already live.
8. **PROF-3 guardrail values** — placeholders. The PRD's review requirement is
   outstanding; record the reviewer and date before launch.
9. **MEDIA-1** — only the upload reservation exists. The PRD's "server validation,
   FFmpeg, thumbnails, waveform, signed playback" is a Phase 4 deliverable.

## 5. What to build next (recommended order)

1. **Make training run** — fix the namespace, `planned` column, workout start,
   progression payload, `pr_events` column, and map `ApiException`. (One MR, WKT-1/2,
   TRN-2.)
2. **Restore CI** — add Pest or convert `TrainingTest`; add program/workout/set tests.
   (G5.)
3. **Fix check-ins** — align `checkins` schema with the job, then expose
   `GET /checkins` + `POST /checkins/{id}/apply` for trainer approval. (NUT-1, CHK-1.)
4. **Coach consent** — invite/accept/revoke + scopes + audit writes, then real
   `TrainerAccess`. (COACH-1/2/3/5.)
5. **Measurements + photos** — small, high-value, unblocks progress screens.
   (BODY-1/BODY-2.)
6. **Chat + media pipeline + push + billing** — the remaining P0 block, in that order
   (chat needs media and push to be useful).

## 6. How to keep this page honest

- Change a requirement's status only with a linked test or a linked defect.
- Every PR that adds/removes a requirement updates this table and
  [prd-addendum-v1.1.md](prd-addendum-v1.1.md).
- Add the row to [CHANGELOG.md](CHANGELOG.md) with the date and commit.
