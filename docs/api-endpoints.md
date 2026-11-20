# API Endpoints — Current Implementation

Generated from `backend/routes/api.php` (the source of truth) and the controllers it
points at. Last verified 2026-11-20.

- Base path: `/api/v1`
- All routes except `GET /health` and the public `auth/*` group require
  `Authorization: Bearer <access token>` **and** the token ability `access`.
- Every authenticated group is rate limited with `throttle:api`; `auth/*` uses the
  stricter `throttle:auth`; uploads use `throttle:uploads`.
- Dates are `Y-m-d`; timestamps are ISO-8601.
- See [README.md](README.md#6-cross-cutting-conventions) for the envelope and error
  shape.

## 1. Response envelopes

Three shapes are in use:

| Shape | Produced by | Example |
|---|---|---|
| `{"data": <object|array|null>}` | `ApiResponse::one`, most controllers | single resource |
| `{"data": [...], "meta": {"next_cursor": "...", "has_more": true}}` | `ApiResponse::page` | cursor-paginated lists |
| Laravel's raw cursor paginator | `GET /foods` only | `{"data": [...], "next_cursor": "...", "prev_cursor": null, "per_page": 25, ...}` |

`per_page` defaults to 20 and is clamped to 1–100 on `ApiResponse::page` routes;
`GET /foods` is fixed at 25. Follow `meta.next_cursor` (or `next_cursor` for foods),
never page numbers.

Errors:

```json
{"error": {"code": "validation_failed", "message": "...", "details": {"field": ["..."]}}}
```

Codes currently produced: `validation_failed` (422), `unauthenticated` (401),
`forbidden` (403), `not_found` (404), `rate_limited` (429), `http_error` (other
HTTP exceptions), `target_below_minimum` / `weekly_loss_rate_too_high` (422, from
`GuardrailViolation`).

> **Defect (verified):** `App\Support\ApiException` is thrown by program, workout
> and set code with codes such as `INVALID_STATE`, `VERSION_CONFLICT`,
> `IDEMPOTENCY_CONFLICT`, `NOT_ELIGIBLE` and statuses 400/409/422, but
> `ApiErrors::register()` has no `instanceof ApiException` branch. Because it
> extends `RuntimeException` rather than `HttpExceptionInterface`, those errors are
> rendered by Laravel's generic handler as **500** with a stack trace in debug mode.
> The documented statuses in this page describe the intent; add the mapping before
> relying on them.

## 2. Health

| Method | Path | Notes |
|---|---|---|
| GET | `/health` | Checks DB, Redis and object storage. Public. |

## 3. Auth

Public (`throttle:auth`):

| Method | Path | Body | Returns |
|---|---|---|---|
| POST | `/auth/register` | `email`, `password` (8–128), `name?`, `device_id`, `is_trainer?` | 201 session |
| POST | `/auth/login` | `email`, `password`, `device_id` | 200 session |
| POST | `/auth/social` | `provider` (`apple|google`), `id_token`, `device_id`, `name?` | 200 session |
| POST | `/auth/forgot-password` | `email` | 202 always-generic message |
| POST | `/auth/reset-password` | `token`, `email`, `password` | 200 |
| GET | `/auth/email/verify/{id}/{hash}` | signed URL from the email | 200 `{verified: true}` |
| POST | `/auth/refresh` | — (requires a **refresh** token) | 200 new session |

Authenticated (access token):

| Method | Path | Notes |
|---|---|---|
| POST | `/auth/logout` | Revokes this device's tokens, 204 |
| POST | `/auth/email/resend` | 202, no-op when already verified |
| GET | `/auth/sessions` | `[{device_id, last_used_at, created_at}]` |
| DELETE | `/auth/sessions/{deviceId}` | Revoke one device, 204 |

Session payload:

```json
{"data": {
  "user": {"id": "...", "email": "...", "name": "...", "is_client": true,
           "is_trainer": false, "email_verified_at": null},
  "access_token": "1|...", "access_expires_at": "2026-11-20T13:00:00Z",
  "refresh_token": "2|...", "refresh_expires_at": "2027-01-19T12:00:00Z",
  "device_id": "uuid"
}}
```

Rules that matter:

- Access tokens live 60 minutes, refresh tokens 60 days.
- Creating a session for a device **deletes that device's previous tokens**, so one
  session per `device_id` and refresh is effectively single-use rotation.
- `register` sends the verification email; the API does not require a verified email
  to log in.
- The PRD expects `/auth/oauth/apple` and `/auth/oauth/google`; the implementation
  uses one `/auth/social` endpoint with a `provider` field.
- The mobile client sends `token` instead of `id_token` in some call sites — see
  `fitness-app-expo/docs/api-integration.md`. The server requires `id_token`.

## 4. Me / profile / privacy

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/me` | — | user fields + `profile`, `goal`, `target` |
| PUT | `/me/profile` | full profile + goal (below) | Recomputes target; 422 on guardrail |
| GET | `/me/export` | — | JSON export of the caller's data (PRIV-1) |
| DELETE | `/me` | — | Soft-deletes the user and purges/anonymises personal data (AUTH-4) |

`PUT /me/profile` body:

```json
{
  "birth_date": "1996-01-15", "sex": "male", "height_cm": 180, "weight_kg": 80,
  "activity_level": "moderate",
  "diet_preferences": ["halal"], "allergies": ["peanuts"],
  "goal": {"type": "lose", "target_weight_kg": 75, "weekly_rate_kg": 0.5},
  "timezone": "Africa/Cairo", "units": "metric", "locale": "en", "name": "Youssef"
}
```

Validation: age 13+, birth date after 1900, height 90–250 cm, weight 30–350 kg,
`goal.type` in `lose|maintain|gain`, `weekly_rate_kg` 0–2. Everything is required
except the nullable arrays and the last four optional user preference fields.

## 5. Nutrition targets

| Method | Path | Notes |
|---|---|---|
| GET | `/nutrition/targets/current` | newest target or `null` |
| GET | `/nutrition/targets` | up to 50 history rows, newest first |
| POST | `/nutrition/targets/recalculate` | recompute from stored profile/goal; `meta.trainer_managed` when it returns `null` |

Target row: `{id, user_id, kcal, protein_g, carbs_g, fat_g, source (auto|trainer), reason, effective_from, created_at}`.

## 6. Saved meals

| Method | Path | Notes |
|---|---|---|
| GET | `/nutrition/saved-meals` | up to 100, newest first |
| POST | `/nutrition/saved-meals` | `name`, `items[1..50]` → 201 |
| GET | `/nutrition/saved-meals/{id}` | owner only |
| PATCH | `/nutrition/saved-meals/{id}` | `name?`, `items?` |
| DELETE | `/nutrition/saved-meals/{id}` | 204 |

Item: `{food_id?, serving_id?, name, qty, meal?, kcal, protein_g?, carbs_g?, fat_g?}`.
Unknown keys are stripped server-side. Values are logged totals, not per-serving.

## 7. Foods

| Method | Path | Query / body | Notes |
|---|---|---|---|
| GET | `/foods` | `q` (2–100, required), `verified?` | trigram search; raw cursor paginator, 25/page |
| GET | `/foods/popular` | `limit` (1–20, default 8) | most-logged 90 days, verified fallback |
| GET | `/foods/barcode/{code}` | 6–14 digits | global then own foods; 404 if none |
| POST | `/foods` | name, brand?, barcode?, `servings[1..10]` | 201, owner = caller, `source=custom` |
| GET | `/foods/{id}` | — | visible foods only |
| PATCH | `/foods/{id}` | same fields as create, all optional | own custom foods only |
| POST | `/foods/{id}/report` | `reason` | records a report, 201 |

Serving: `{label, grams, kcal, protein_g, carbs_g, fat_g, fiber_g?, is_default?}`.
Search and lookup only see global foods (`owner_id IS NULL`) plus the caller's own.

## 8. Food logs

| Method | Path | Query / body | Notes |
|---|---|---|---|
| GET | `/logs` | `date` **or** `from`+`to` | max 1000 rows, ordered by `logged_at` |
| GET | `/logs/summary` | `from` **and** `to`, ≤ 62 days | per-day intake, meals, target, `burn_kcal: null` |
| POST | `/logs` | see below | idempotent on `client_uuid`, 201/200 |
| POST | `/logs/copy` | `from_date`, `to_date`, `meal?`, `to_meal?` | 201, server-side duplicates |
| PATCH | `/logs/{id}` | `meal?`, `qty?`, `logged_on?` | scales the snapshot when `qty` changes |
| DELETE | `/logs/{id}` | — | soft delete, 204 |

Create bodies (catalog item vs quick add):

```json
{"client_uuid":"uuid","meal":"breakfast","qty":2,"logged_at":"2026-11-20T07:30:00Z",
 "food_id":"uuid","serving_id":"uuid"}
{"client_uuid":"uuid","meal":"snack","qty":1,"logged_on":"2026-11-20",
 "name":"Banana","kcal":105,"protein_g":1.3,"carbs_g":27,"fat_g":0.4}
```

`food_id` and `serving_id` are required together. `name`/`kcal` are required only
for quick adds. Catalog macros are recomputed server-side and client values ignored.

## 9. Body weights

| Method | Path | Query / body | Notes |
|---|---|---|---|
| GET | `/body/weights` | `from`, `to` | `{entries, trend[], slope_kg_per_week}` |
| POST | `/body/weights` | `client_uuid`, `value_kg` (20–500), `measured_at?` | 201/200 idempotent |
| PATCH | `/body/weights/{id}` | `value_kg?`, `measured_at?` | recomputes `measured_on` |
| DELETE | `/body/weights/{id}` | — | soft delete, 204 |

`measured_at` may not be more than one day in the future. `measured_on` is derived in
the user's timezone. The trend query warms up with 30 days before `from`.

## 10. Attachments (media upload reservation)

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `/attachments` | `kind` (`image|voice|video`), `mime`, `size` | 201 `{data: attachment, upload: signed PUT}` |

Idempotent when the `Idempotency-Key` header is repeated within 24 h. Limits: image
10 MB (jpeg/png/webp), voice 20 MB (m4a/aac/mp3/webm/ogg/mp4), video
`fitness.media.video_max_mb`. The processing/validation step does not exist; rows stay
`pending`.

## 11. Trainer

| Method | Path | Notes |
|---|---|---|
| GET | `/trainer/clients` | links where the caller is the trainer: client, status, scopes |

No invite/accept/revoke endpoints, no roster detail, no chat.

## 12. Exercises

| Method | Path | Query / body | Notes |
|---|---|---|---|
| GET | `/exercises` | `scope` (`all|global|mine`), `muscle`, `equipment`, `movement_pattern`, `exercise_type`, `search` | cursor paginated, name order, archived hidden |
| POST | `/exercises` | name, primary_muscles[≥1], movement_pattern, exercise_type, description?, instructions?, secondary_muscles?, equipment? | 201, owner = caller |
| GET | `/exercises/{id}` | — | visible only |
| PATCH | `/exercises/{id}` | any create field | own custom exercises only |
| DELETE | `/exercises/{id}` | — | archives if referenced, else hard deletes |

`muscle` matches primary or secondary. There is no `media` input or output contract
yet; the mobile app maps exercise slugs to bundled illustrations itself.

## 13. Program templates

| Method | Path | Query | Notes |
|---|---|---|---|
| GET | `/training/templates` | `goal`, `experience_level`, `days_per_week`, `equipment` | global templates, `days_count` included |
| POST | `/training/templates/{id}/copy` | `name?` | clones into caller's drafts, copies only visible exercises |

## 14. Programs

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/training/programs` | `status?`, `scope` (`mine|assigned|all`) | non-template programs |
| POST | `/training/programs` | metadata + `days[]` | 201 draft, `version = 1` |
| GET | `/training/programs/{id}` | — | owner or assigned client |
| PATCH | `/training/programs/{id}` | metadata | does **not** bump `version` |
| PUT | `/training/programs/{id}/days` | `expected_version`, `days[]`, `replace?` | 409 on stale version; bumps `version` |
| POST | `/training/programs/{id}/publish` | — | creates/reuses the version snapshot, sets `active` |
| POST | `/training/programs/{id}/archive` | — | `status = archived`, history kept |
| POST | `/training/programs/{id}/assignments` | `client_id`, `start_date` | program must be published; self-assign only today |
| GET | `/training/programs/{id}/assignments` | — | cursor paginated |
| PATCH | `/training/assignments/{id}` | `status` | `active↔paused`, close to `completed|cancelled` |
| GET | `/training/programs/current` | — | assignment wins, else newest active own program |

Create body:

```json
{
  "name": "Upper/Lower 4x", "description": "Off-season", "goal": "hypertrophy",
  "experience_level": "intermediate", "days_per_week": 4,
  "days": [{
    "name": "Upper A", "day_number": 1,
    "items": [{"exercise_id": "uuid", "position": 1, "sets": 4,
      "rep_range": {"min": 6, "max": 10}, "rest_seconds": 120,
      "target_rpe": 8, "weight_kg": 60,
      "progression_rule": {"type": "double_progression", "increment": 2.5}}]
  }]
}
```

`GET .../current` returns
`{assignment|null, program|null, days[], program_snapshot}`; `data: null` when the
user has no active assignment and no active program.

## 15. Progression

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/training/programs/{programId}/days/{dayId}/progression` | — | per-item proposal + reason |
| POST | `/training/programs/{programId}/days/{dayId}/progression/apply` | `expected_version`, `exercise_id` | updates prescription, bumps version, logs change |
| GET | `/training/programs/{programId}/progression-history` | — | paginated change log |

**Apply and history are non-functional in this snapshot** — see
[workout-system.md §9.5](workout-system.md#95-progression-applyhistory-cannot-persist).

## 16. Workouts

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/workouts` | `status?`, `from?`, `to?`, `program_id?` | cursor paginated, newest first |
| POST | `/workouts` | `client_uuid`, `program_id?`, `program_version?`, `program_day_id?`, `started_at?` | idempotent; expands the day from the snapshot |
| GET | `/workouts/{id}` | — | `exercises.exercise`, `exercises.sets` |
| PATCH | `/workouts/{id}` | `notes?`, `started_at?` | in-progress only |
| POST | `/workouts/{id}/exercises` | `exercise_id`, `position?` | in-progress only |
| PUT | `/workouts/{id}/exercises/order` | `exercise_ids[]` (exact permutation) | in-progress only |
| POST | `/workouts/{id}/complete` | `finished_at?` | idempotent; records PRs; returns summary |
| POST | `/workouts/{id}/cancel` | — | in-progress only |
| POST | `/workouts/{id}/sets` | `client_uuid`, `workout_exercise_id`, `set_number`, data | in-progress only, idempotent |
| PATCH | `/workouts/{id}/sets/{setId}` | data fields only | works on completed workouts, recomputes PRs |
| DELETE | `/workouts/{id}/sets/{setId}` | — | 204, recomputes PRs |

Exercise history / helpers:

| Method | Path | Notes |
|---|---|---|
| GET | `/workouts/exercises/{exerciseId}/history` | completed sessions containing the exercise |
| GET | `/workouts/exercises/{exerciseId}/last-performance` | last completed performance or `null` |
| GET | `/workouts/exercises/{exerciseId}/next-set-suggestion` | query `program_id?`, `program_day_id?`; `data: null` when no history/program |

> Start (`POST /workouts`) currently fails: it reads `assigned_to`, `program_version`
> and `snapshot` (real columns: `client_id`, `program_version_id`, `metadata`) and
> writes a non-existent `planned` column. See
> [workout-system.md §9.2–9.4](workout-system.md#92-workoutcontrollerstore-uses-removed-columns).

## 17. Progress

| Method | Path | Query | Notes |
|---|---|---|---|
| GET | `/progress/personal-records` | `exercise_id?`, `record_type?`, `from?`, `to?` | current bests, cursor paginated |
| GET | `/progress/exercises/{id}/personal-records` | — | current bests for one exercise |
| GET | `/progress/exercises/{id}/personal-records/history` | — | PR events, newest first |
| GET | `/progress/weekly-volume` | `week_start`, `week_end`, `exercise_id?`, `muscle_group?` | working sets, per-muscle breakdown |
| GET | `/progress/weekly-volume/history` | `from?`, `to?`, `muscle_group?`, `cursor?` | per-week pages, default last 12 weeks |

Record types: `max_weight`, `max_reps`, `estimated_1rm` (Epley, 1–10 reps, weighted
exercises only). Volume counts only non-warmup sets in completed workouts.

## 18. Endpoint inventory

Counted from `routes/api.php`: **71 routes** under `/api/v1` — 7 public auth/health,
64 authenticated. By area: auth 11, me/profile 4, nutrition targets 3, saved meals 5,
foods 7, logs 6, weights 4, attachments 1, trainer 1, exercises 5, templates 2,
programs 11, progression 3, workouts 14, progress 5.

## 19. Differences from the old `api-routes.md`

The root `api-routes.md` is an older Postman export. Treat it as historical:

| Old doc | Actual |
|---|---|
| `/auth/oauth/apple`, `/auth/oauth/google` | `POST /auth/social` |
| `/foods/search` | `GET /foods?q=` |
| `/logs/day`, `/logs/range` | `GET /logs?date=` / `?from&to` |
| `/targets` | `/nutrition/targets*` |
| `/workouts/{id}/sets` only | plus update/delete, complete, cancel, reorder |
| Batch `/sync` | not implemented; per-resource idempotency only |
| Pagination by page number | cursor pagination |
| `/measurements`, `/photos`, `/meal-plans`, `/chat`, `/notifications`, `/subscriptions` | not implemented (some schema only) |

## 20. When adding an endpoint

1. Add the route inside the `v1` + `auth:sanctum` group in `routes/api.php`.
2. Return `ApiResponse::one`/`ApiResponse::page` so clients keep one envelope.
3. Throw `ApiException` **only after** it is mapped in `ApiErrors` (see §1) — or use
   `abort()`/`ValidationException` today.
4. Add a feature test with the JSON envelope asserted.
5. Update this page and `docs/CHANGELOG.md` in the same MR.
