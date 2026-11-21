# Fitness App Backend — Engineering Handbook

Living documentation for the Laravel API behind the fitness app. It explains what the
system does **today**, how the nutrition and training domains are wired, where the
implementation intentionally deviates from the PRD, and which parts are still missing.

| | |
|---|---|
| Repository | `fitness-app-backend` (this repo) |
| API root | `http://localhost:8080/api/v1` |
| App code | `backend/` (Laravel project) |
| Docs last verified | 2026-11-20, against `main` @ `e285be0` |
| Scope of this handbook | Nutrition, body metrics, training, workouts, cross-cutting API rules |
| PRD | `Fitness App Backend PRD.txt` v1.0 (see [prd-alignment.md](prd-alignment.md)) |

> **Source of truth.** When this handbook and the code disagree, the code wins. The
> authoritative files are `backend/routes/api.php`, `backend/database/migrations/`,
> `backend/config/fitness.php`, `backend/config/training.php`, and `backend/tests/`.
> `api-routes.md` in the project root is an older Postman-derived reference and does
> **not** match the implementation (see [api-endpoints.md](api-endpoints.md#differences-from-the-old-api-routesmd)).

## 1. What this service is

A Laravel JSON API for a calorie-deficit fitness app:

- **Nutrition** — food catalog search (trigram + barcode), per-serving nutrients,
  custom foods, idempotent food logging, saved meals, day/range summaries, weight
  trend, calculated calorie/macro targets, weekly adaptive adjustment.
- **Training** — exercise catalog, versioned programs, publishing and assignment,
  workout sessions with sets, personal records, weekly volume, progression previews,
  next-set suggestions.
- **Coaching / chat / media / billing** — the database schema and some scaffolding
  exist, but almost none of it is exposed over the API yet. Treat these as planned,
  not shipped.

## 2. Stack and runtime

| Layer | Choice |
|---|---|
| Language / framework | PHP 8.3, Laravel 12 |
| Database | PostgreSQL 16 (`pg_trgm` extension for food search) |
| Cache / queue | Redis 7 |
| Auth | Laravel Sanctum personal access tokens, two abilities: `access` and `refresh` |
| Object storage | SeaweedFS (S3 API) with signed uploads |
| WebSocket | Laravel Reverb (no chat routes yet) |
| HTTP | Nginx in front of PHP-FPM |
| Mail (dev) | Mailpit, `--profile dev` |
| Containers | Docker Compose (`docker-compose.yml`) |

Compose services: `postgres`, `redis`, `seaweedfs`, `bucket-init`, `migrate`, `app`,
`reverb`, `queue`, `media`, `scheduler`, `nginx`, `mailpit`, `tests`.

| URL | What |
|---|---|
| `http://localhost:8080/api/v1` | API |
| `http://localhost:8080/api/v1/health` | DB + Redis + storage health |
| `http://localhost:8080/docs` | Generated OpenAPI UI (Scramble) |
| `ws://localhost:8081` | Reverb |
| `http://localhost:8025` | Mailpit (`docker compose --profile dev up`) |

Demo accounts (password `password`): `trainer@example.com`, `alice@example.com`,
`bob@example.com`.

## 3. Run it

```bash
cp .env.example .env          # a ready-made .env also ships in the repo
docker compose up --build     # first build takes a few minutes
docker compose exec app php artisan migrate --seed
```

Shortcuts: `make up`, `make down`, `make reset` (wipes volumes), `make fresh`
(migrate:fresh + seed), `make logs`, `make shell`, `make test`.

Tests run against real PostgreSQL (`fitness_test`) and Redis:

```bash
docker compose --profile test run --rm --build tests
```

The suite covers the calorie math, trend/adaptive logic, food-log idempotency, profile
guardrails, saved meals, auth, body weight and one workout-set replay case. It does
**not** yet cover programs, publishing, assignments, workout completion, PRs or
progression — the areas with the most runtime risk.

## 4. Repository layout

```
fitness-backend/
├── backend/                    Laravel application
│   ├── app/
│   │   ├── Http/Controllers/Api/    one controller per resource
│   │   ├── Jobs/                    weekly check-in, data purge
│   │   ├── Models/                  UUID Eloquent models
│   │   ├── Services/
│   │   │   ├── Nutrition/           target math, trend, adaptive, summaries
│   │   │   ├── Training/            programs, sets, records, progression
│   │   │   ├── Auth/                Apple/Google token verification
│   │   │   └── Storage/             signed uploads
│   │   └── Support/                 ApiResponse, ApiException, errors
│   ├── config/                 fitness.php, training.php
│   ├── database/               migrations + seeders
│   ├── routes/                 api.php, console.php, channels.php
│   └── tests/                  Pest feature + unit tests
├── docker/                     PHP, Nginx, worker images
├── docker-compose.yml
└── docs/                       this handbook
```

## 5. Documentation map

| Read this | When you need |
|---|---|
| [nutrition-system.md](nutrition-system.md) | Anything about calories, macros, foods, logs, weights, targets, adaptive changes |
| [workout-system.md](workout-system.md) | Programs, exercises, sessions, sets, PRs, progression, suggestions |
| [api-endpoints.md](api-endpoints.md) | The real endpoint list, payload rules, error/pagination conventions |
| [prd-alignment.md](prd-alignment.md) | What the PRD asks for vs what exists, with requirement IDs |
| [prd-addendum-v1.1.md](prd-addendum-v1.1.md) | Text to fold into the next PRD revision |
| [CHANGELOG.md](CHANGELOG.md) | Doc and behaviour change history |

## 6. Cross-cutting conventions

- **Base URL** `/api/v1`. Success payloads are wrapped in `{"data": ...}`; collections
  use cursor pagination (`{"data": [...], "meta": {...}}` via `ApiResponse::page`).
- **Errors** share one shape: `{"error": {"code", "message", "details"}}`
  (`App\Http\Errors\ApiErrors`). Domain errors use `ApiException`; validation uses
  Laravel's 422 shape.
- **Auth** — `Authorization: Bearer <access token>`; access tokens carry the `access`
  ability, refresh tokens only the `refresh` ability. Refresh happens at
  `POST /auth/refresh`.
- **Idempotency** — offline-capable writes (`food_logs`, `weights`, `workout_sets`,
  `workouts`) take a client-generated UUID and have a unique `(user_id, client_uuid)`
  (or `(workout_id, client_uuid)` for sets). Replaying returns the original row with
  200 instead of duplicating.
- **Time** — food logs derive `logged_on` from `logged_at` in the user's timezone
  (`users.timezone`, default UTC). Clients should send `logged_at` with an offset.
- **Configuration over constants** — nutrition and training limits live in
  `config/fitness.php` and `config/training.php`, most overridable through env.
- **Dates** — `Y-m-d` for calendar fields, ISO-8601 for timestamps.

## 7. Current status (2026-11-20)

| Area | State |
|---|---|
| Auth, profile, targets, weights, food search/logging, saved meals | Implemented and tested |
| Adaptive weekly targets | Implemented in a job, but the `checkins` schema does not match the job (see [workout-system.md §9](workout-system.md#9-known-defects-in-this-snapshot)) |
| Programs (create/edit/publish/assign/current) | Implemented; no tests |
| Workout sessions & sets | Implemented; one happy-path test only; start/complete contain schema bugs |
| Personal records & weekly volume | Implemented; PR event insert uses the wrong column |
| Progression preview/apply | Preview logic exists; controller and persistence do not run (namespace + schema bugs) |
| Trainer roster | Read-only list of linked clients (`GET /trainer/clients`) |
| Trainer plan editing / assignment | Blocked: `TrainerAccess` always returns `false` |
| Chat, media pipeline, push, subscriptions, batch sync | Schema only; no routes |
| Measurements, progress photos, meal plans | Schema / not implemented |

The defects that block end-to-end upgrade flows are listed with file and line
references in [workout-system.md §9](workout-system.md#9-known-defects-in-this-snapshot).
Fixing them is tracked separately from this documentation change.

## 8. Working agreement for changes

1. One branch per change; open a merge request for review.
2. Update the matching doc page **in the same MR** as the behaviour change, and add a
   line to [CHANGELOG.md](CHANGELOG.md).
3. Any change to formulas, guardrails, payloads or table columns must also update
   [prd-alignment.md](prd-alignment.md) when it changes a PRD requirement.
4. Add a feature test for every new endpoint and every bug fix. Prefer Pest style
   (`tests/Feature/*.php`) and assert the JSON envelope, not only status codes.
