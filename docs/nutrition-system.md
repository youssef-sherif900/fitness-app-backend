# Nutrition, Body & Targets — System Guide

This page documents the nutrition half of the backend: food data, logging, summaries,
weight trend, calorie/macro targets and the weekly adaptive adjustment. It is written
for a developer (or AI assistant) who needs to change the system without breaking the
offline contract clients rely on.

Related: [api-endpoints.md](api-endpoints.md), [workout-system.md](workout-system.md),
[prd-alignment.md](prd-alignment.md).

## 1. Ownership map

| Concern | Code |
|---|---|
| Food catalog + custom foods + reports | `backend/app/Http/Controllers/Api/FoodController.php` |
| Food log CRUD, copy, summaries | `backend/app/Http/Controllers/Api/LogController.php` |
| Day/range totals + Redis cache | `backend/app/Services/Nutrition/DaySummaryService.php` |
| Targets CRUD | `backend/app/Http/Controllers/Api/NutritionController.php` |
| Profile/goal save + target recalculation | `backend/app/Http/Controllers/Api/MeController.php` |
| Target math (pure) | `backend/app/Services/Nutrition/TargetCalculator.php` |
| Target persistence + trainer-managed guard | `backend/app/Services/Nutrition/TargetService.php` |
| Adaptive weekly proposal | `backend/app/Services/Nutrition/AdaptiveTargetService.php` |
| Weight trend (EWMA) | `backend/app/Services/Nutrition/TrendService.php` |
| Weekly check-in job | `backend/app/Jobs/RunWeeklyCheckin.php` |
| Weigh-ins | `backend/app/Http/Controllers/Api/BodyController.php` |
| Saved meals | `backend/app/Http/Controllers/Api/SavedMealController.php` |
| Tunables | `backend/config/fitness.php` |

## 2. Data model

```
users ──1:1── profiles
  │             (birth_date, sex, height_cm, weight_kg, activity_level,
  │              diet_preferences, allergies)
  ├──1:N── goals             (type lose|maintain|gain, target_weight_kg,
  │                           weekly_rate_kg, is_active)
  ├──1:N── targets           (kcal, protein_g, carbs_g, fat_g, source auto|trainer,
  │                           reason, effective_from)   ← append-only history
  ├──1:N── weights           (value_kg, measured_at, measured_on, client_uuid, soft deletes)
  ├──1:N── food_logs         (snapshot of one eaten item)
  ├──1:N── saved_meals       (name, json items[])
  └──1:N── measurements      (type, value_cm, measured_on)  ← table only, no API
foods ──1:N── food_servings  (label, grams, kcal, protein_g, carbs_g, fat_g, fiber_g,
                              is_default)
foods ──1:N── food_reports   (user_id, reason, status open)
```

Design rules that matter:

- **Nutrients live on servings, not foods.** A food row has no kcal. Every log picks a
  `serving_id`; the server multiplies its nutrients by `qty`.
- **Logs are snapshots.** `food_logs` copies `name`, `kcal`, `protein_g`, `carbs_g`,
  `fat_g` at write time. Editing a catalog food later must not rewrite history.
- **Global vs custom foods.** `foods.owner_id IS NULL` means a shared/curated food
  (read-only for clients); `owner_id = user` means a custom food the user can edit.
  Search and lookup only ever see global + the caller's own foods.
- **Targets are append-only.** A new target row is inserted when the calculation
  changes; the "current" target is the newest by `effective_from`/`created_at`.
- **Weights and logs are soft-deleted.** Rows stay as tombstones for replay safety;
  normal queries exclude them.

`food_logs` columns worth knowing: `client_uuid` (unique per user), `meal`
(`breakfast|lunch|dinner|snack`), `qty` (decimal 8,3), `logged_on` (date in the
user's timezone), `logged_at` (timestamp).

## 3. Calorie and macro targets

`TargetCalculator` is a pure class: no DB, no framework, so it is unit-tested against
golden values.

```
BMR  = 10*kg + 6.25*cm - 5*age + (male ? +5 : -161)      Mifflin–St Jeor
TDEE = BMR * activity_factor
delta_kcal = weekly_rate_kg * 7700 / 7
lose:     kcal = TDEE - delta_kcal
gain:     kcal = TDEE + delta_kcal
maintain: kcal = TDEE
```

Then macros:

```
protein_g = round(1.8 * kg)                 (config: macros.protein_g_per_kg)
fat_g     = round(kcal * 0.25 / 9)          (config: macros.fat_pct)
carbs_g   = max(0, round((kcal - protein*4 - fat*9) / 4))
```

Activity factors: sedentary 1.2, light 1.375, moderate 1.55, active 1.725,
very_active 1.9 (`config/fitness.php`).

**Worked example** — male, born 1996-01-15, 180 cm, 80 kg, moderate, lose 0.5 kg/week:

```
BMR  = 800 + 1125 - 150 + 5 = 1780
TDEE = 1780 * 1.55          = 2759
delta= 0.5 * 7700 / 7       = 550
kcal = 2209
protein = 144 g (576 kcal), fat = 61 g (549 kcal), carbs = 271 g (1084 kcal)
```

### Guardrails (PROF-3)

Enforced before anything is written; violations return 422 with a domain code.

| Guard | Default | Config / env |
|---|---|---|
| Minimum daily kcal | 1200 | `guardrails.min_daily_kcal`, `FITNESS_MIN_DAILY_KCAL` |
| Max weekly loss | 1.0 % of body weight | `guardrails.max_weekly_loss_pct`, `FITNESS_MAX_WEEKLY_LOSS_PCT` |
| Weekly rate input | 0–2 kg accepted by validation | `MeController::updateProfile` |

Codes: `target_below_minimum`, `weekly_loss_rate_too_high`
(`App\Exceptions\GuardrailViolation` → `ApiErrors`).

> These numbers are deliberately configurable placeholders. The PRD requires sign-off
> by a qualified nutrition professional before launch; changing them is a product
> decision, not a refactor.

### Target lifecycle

1. `PUT /me/profile` validates the profile + goal, computes macros **before** saving,
   then in one transaction: upserts `profiles`, deactivates old goals, inserts the new
   goal, updates `users` preferences, and (unless a trainer manages targets) inserts a
   `targets` row with `source = auto`.
2. `GET /nutrition/targets/current` returns the newest target.
3. `GET /nutrition/targets` returns up to 50 history rows, newest first.
4. `POST /nutrition/targets/recalculate` recomputes from the stored profile/goal. If the
   current target is `auto` and the macros are identical, it returns the existing row
   instead of creating a no-op history entry. Trainer-managed targets return `null`.
5. Deleting/changing the goal later always creates a new active goal; old goals stay for
   history.

`targets.source` is `auto` or `trainer`. `TargetService::isTrainerManaged()` looks only
at the **current** target, so a trainer-set target blocks automatic changes until a new
target is stored.

## 4. Adaptive weekly adjustment (NUT-1)

`AdaptiveTargetService::propose()` is pure decision logic; `RunWeeklyCheckin` supplies
the inputs and decides whether to apply.

Inputs over the previous `window_days` (14) ending the day before the check-in week:

- **expected** weekly kg: `-weekly_rate_kg` for lose, `+weekly_rate_kg` for gain, 0 for
  maintain.
- **actual** weekly kg: slope of the smoothed weight trend (`TrendService::weeklySlope`).
- **weigh_ins**: number of weight rows in the window.
- **adherence**: logged days / window days.

Decision tree:

| Condition | Result |
|---|---|
| `weigh_ins < 4` or no trend slope | Hold, reason asks for more weigh-ins |
| `adherence < 0.60` | Hold, reason says logging was too sparse |
| Otherwise | `raw = -(actual - expected) * 7700 / 7 * 0.5`, clamped to ±150 kcal, rounded to 5 |
| Clamped step would push below `min_daily_kcal` | Hold at the safe floor |
| Final step `< 25` kcal | Hold, "on track" |
| Else | Apply, with a plain-language reason including both rates and the step |

`applied = true` only when a real change is proposed. Every proposal stores a reason;
the API never silently changes a target.

Application rules in `RunWeeklyCheckin`:

- Users with an **active trainer link** (`trainer_clients.status = active`) or a
  `trainer`-sourced current target get a **pending** proposal; the target is not
  changed. Trainer approval UI/API does not exist yet.
- Everyone else gets a new `auto` target. Protein stays fixed; the kcal delta is spread
  proportionally over carbs and fat (`scaleMacros`).
- The check-in is idempotent on `(user_id, week_start)`; a re-run with metrics already
  present does nothing.

Scheduling: `routes/console.php` dispatches `checkins:dispatch` hourly; it queues the
job for clients with an active goal whose local time is Monday 06:xx.

> **Known defect:** the job writes `week_start`, `metrics`, `proposed_changes` and
> `status`, but the `checkins` table (migration `2026_10_01_000003_training_checkins`)
> defines `checkin_date`, `weight_kg`, `waist_cm`, `body_fat_percentage`, `notes`,
> `measurements`, `photos`. The job will fail until the schema and model are aligned.
> See [workout-system.md §9](workout-system.md#9-known-defects-in-this-snapshot).

## 5. Weight trend (BODY-1)

`TrendService` computes an exponentially weighted moving average with gap adjustment:

```
trend_0 = first weight of the series
trend_t = trend_{t-1} + a_eff * (weight_t - trend_{t-1})
a_eff   = 1 - (1 - alpha)^gapDays        alpha = 0.1
```

- Multiple weigh-ins on one date: the latest timestamp wins (callers pass rows ordered
  by `measured_at`).
- Gaps are handled so a weekly weigher sees the same smoothing as a daily weigher.
- `weeklySlope = (last.trend - first.trend) / days * 7`, `null` for fewer than 2 points.

`GET /body/weights?from&to` returns:

```json
{
  "data": {
    "entries": [ { "id": "...", "value_kg": 79.4, "measured_on": "2026-11-20", "...": "..." } ],
    "trend":   [ { "date": "2026-11-14", "weight": 80.1, "trend": 80.05 } ],
    "slope_kg_per_week": -0.42
  }
}
```

The query fetches 30 extra days before `from` so the trend is warmed up, but only
returns points inside the requested range. `measured_on` is derived from `measured_at`
in the user's timezone.

Weigh-in writes are idempotent on `(user_id, client_uuid)` and accept at most +1 day in
the future. Deletes are soft.

## 6. Food catalog

### Search (`GET /foods?q=`)

- `q` is required, 2–100 chars.
- Case-insensitive `ILIKE '%q%'` over `name` and `brand`, backed by the
  `foods_name_trgm` GIN index (`pg_trgm`).
- Optional `verified=true|false`.
- Cursor pagination; the controller returns `{data, next_cursor}` (clients must follow
  `next_cursor`, not page numbers).
- Visible rows: global foods + the caller's custom foods.

### Popular (`GET /foods/popular?limit=`)

Two-stage suggestion list for empty states:

1. The most-logged foods across all non-deleted logs in the last 90 days
   (global aggregate, not per-user), limited to visible foods.
2. If fewer than `limit`, fill with verified global foods alphabetically.

`limit` is clamped to 1–20 (default 8). No pagination.

### Barcode (`GET /foods/barcode/{code}`)

- `code` must be 6–14 digits, otherwise 422.
- Global foods are cached for 1 hour; a miss falls back to a fresh lookup among the
  caller's custom foods (so a barcode the user just created resolves immediately).
- 404 when nothing matches.

### Custom foods and reports

- `POST /foods` creates an owned food with 1–10 servings; the first serving becomes
  `is_default`; `verified` is always `false`; `source = custom`.
- `PATCH /foods/{id}` only works on your own custom food (global foods are read-only,
  other users' foods are invisible). Replacing servings deletes and recreates them.
- `POST /foods/{id}/report` stores a `food_reports` row (`status = open`). There is no
  moderation API yet.
- `fiber_g` is stored and accepted but not included in log snapshots or totals.

## 7. Logging, copy, summaries

### Create (`POST /logs`) — LOG-1

One row per eaten item.

```json
{
  "client_uuid": "uuid",
  "meal": "breakfast",
  "qty": 2,
  "logged_at": "2026-11-20T07:30:00Z",
  "food_id": "uuid",
  "serving_id": "uuid"
}
```

Or a quick add with `name`, `kcal`, `protein_g`, `carbs_g`, `fat_g` and no food ids.

Rules:

- `client_uuid` is required and unique per user. Replaying returns the existing row with
  **200** (created returns **201**); a concurrent duplicate is caught by the unique
  index and resolved the same way.
- `logged_on` may be sent explicitly (`Y-m-d`); otherwise it is derived from
  `logged_at` in the user's timezone.
- Catalog nutrients are recomputed server-side from the serving; client-sent macros are
  ignored when `food_id` is present. Quick adds keep the client's snapshot.
- The day's summary cache is invalidated after a write.

### Update (`PATCH /logs/{id}`)

Accepts `meal`, `qty`, `logged_on`. When `qty` changes, the stored snapshot is scaled by
`newQty / oldQty` (works for quick adds too). Old and new day caches are invalidated.
Note: changing only the meal does **not** move the item across days — send `logged_on`.

### Delete (`DELETE /logs/{id}`)

Soft delete, 204. The row remains as a tombstone. No endpoint currently exposes
tombstones, so an offline client cannot discover deletions made on another device.

### Copy (`POST /logs/copy`)

Body: `from_date`, `to_date`, optional `meal` (source filter) and `to_meal` (override).
Every source row is duplicated with a new `client_uuid` and the same snapshot. Returns
the created rows (201). This is server-side only; the mobile client implements copy
locally and syncs each row as a create.

### Summaries (`GET /logs/summary?from&to`) — LOG-3

Both dates are required (`Y-m-d`), max 62 days.

```json
{
  "data": {
    "days": [
      { "date": "2026-11-20",
        "intake": { "kcal": 1840.5, "protein_g": 132.0, "carbs_g": 190.2, "fat_g": 58.4 },
        "meals": { "breakfast": { "items": 3, "kcal": 520.0, "...": 0 } } }
    ],
    "target": { "kcal": 2209, "protein_g": 144, "carbs_g": 271, "fat_g": 61, "source": "auto" },
    "burn_kcal": null
  }
}
```

- Each day is cached in Redis for 1 hour under `daysum:{user}:{date}` and invalidated
  on every log write that touches that date.
- `burn_kcal` is intentionally `null`: there is no activity/workout energy model yet, so
  the app must not pretend it knows calories burned.
- "Deficit" is therefore **intake vs target**, not intake vs burn.

`GET /logs?date=` returns one day's rows; `GET /logs?from=&to=` returns up to 1000 rows
ordered by `logged_at`. Clients use this for the rolling sync window.

## 8. Saved meals (LOG-2)

`saved_meals` stores `name` plus a JSON array of items. There is no join table.

Item shape: `{food_id?, serving_id?, name, qty, meal?, kcal, protein_g?, carbs_g?, fat_g?}`.
Values are the **logged totals for that item**, not per-serving nutrients, so relogging
is a matter of writing the snapshot (or one create per item from the client).

Endpoints: list, create, show, patch, delete under `/nutrition/saved-meals`. All are
scoped to the owner. The client logs a saved meal by issuing one `POST /logs` per item.

## 9. Offline sync contract (nutrition portion)

The mobile client keeps SQLite as the source of truth for the day and drains a queue.
The server must preserve these properties:

1. **Idempotent creates.** Same `client_uuid` → same row, 200, never a duplicate.
2. **Snapshots are stable.** Editing catalog data must not mutate past logs.
3. **Server-derived fields.** `logged_on`/`measured_on` honour the user's timezone;
   clients should still send the date they displayed.
4. **Retryable failures.** 5xx, 429 and 401 (after refresh) mean "retry later"; 4xx
   other than 408/429 means "permanent, show it to the user". The client marks the queue
   item failed and continues.
5. **Reads are reconcilable.** The client pulls a rolling window (last 21 days) plus the
   full weight history and upserts by `server_id` or `client_uuid`, skipping rows that
   still have a pending local change.

There is **no batch `/sync` endpoint**. `sync_cursors`, `messages` and `subscriptions`
tables exist, but batch pull/push is not implemented (the PRD's SYNC-1 is currently met
by per-resource idempotency instead).

## 10. Tests

| Test | Covers |
|---|---|
| `tests/Unit/TargetCalculatorTest.php` | Golden BMR/TDEE/macros and guardrail failures |
| `tests/Unit/TrendAndAdaptiveTest.php` | EWMA smoothing, slope, adaptive holds/steps/caps |
| `tests/Feature/FoodLogTest.php` | Replay idempotency, snapshot scaling, copy, summary, ownership |
| `tests/Feature/FoodPopularTest.php` | Popular ordering + verified fallback |
| `tests/Feature/ProfileTargetsTest.php` | Profile validation, guardrails, target history, trainer-managed guard |
| `tests/Feature/SavedMealTest.php` | Saved-meal CRUD + ownership |
| `tests/Feature/BodyWeightTest.php` | Weigh-in idempotency, trend response, edit/delete |
| `tests/Feature/AuthTest.php` | Register/login/refresh/abilities/sessions |

Missing coverage: adaptive job execution (only the pure service is tested), the
`checkins` persistence path, meal-plan generation (not implemented), and any
cross-device deletion discovery.

## 11. Gaps and follow-ups

| Item | Status | Notes |
|---|---|---|
| Text/voice/photo food parsing (FOOD-3) | Not implemented | No AI/parse endpoint; the client offers search + barcode + quick add |
| Meal plans, swaps, grocery list (NUT-2) | Not implemented | No tables or routes |
| Measurements API (BODY-2 partial) | Table only | `measurements` exists; no controller/routes |
| Progress photos | Not implemented | `attachments` is generic; no check-in photo flow |
| Fiber in totals | Stored, unused | Add to `DaySummaryService` only with a client contract change |
| Check-in read/approve API | Not implemented | Proposals are stored but cannot be listed or approved |
| Adaptive job schema | **Broken** | See §4 |
| Cross-device deletions | Not exposed | Soft deletes exist; no tombstone feed |
| Timezone edge cases | Partial | `logged_on` is user-tz, but `logged_at` ordering and summaries use dates |

## 12. Change checklist

When touching nutrition code:

- [ ] Update the migration **and** the model **and** any job/controller that writes the
      column in the same MR (the check-in defect is exactly this failure mode).
- [ ] Keep the idempotency keys intact; add a replay test for new write endpoints.
- [ ] If a formula or guardrail changes, update §3, the PRD addendum and the golden
      tests together.
- [ ] If a response shape changes, update the mobile client docs
      (`fitness-app-expo/docs/nutrition-system.md`) in the same release.
- [ ] Add a line to `docs/CHANGELOG.md`.
