# Fitness App Backend (Laravel)

Implements the Backend PRD v1.0: Laravel 12, PostgreSQL 16, Redis 7, Laravel Reverb, SeaweedFS (S3), FFmpeg worker, Nginx, all in Docker Compose.

## Run

```bash
cp .env.example .env     # optional for local use: a ready-made .env is included
docker compose up --build
```

First build downloads Laravel and packages (a few minutes). After that, cold start to healthy is fast.

| What | URL |
|---|---|
| API | http://localhost:8080/api/v1 |
| API docs (OpenAPI, Scramble) | http://localhost:8080/docs |
| Health (db, redis, storage) | http://localhost:8080/api/v1/health |
| WebSocket (Reverb) | ws://localhost:8081 |
| Mailpit (`--profile dev`) | http://localhost:8025 |

Demo logins (password `password`): `trainer@example.com`, `alice@example.com`, `bob@example.com`.

`make up | down | reset | test | logs | shell | fresh`

## Tests

```bash
docker compose --profile test run --rm --build tests
```

Runs against real PostgreSQL (database `fitness_test`, created automatically) and Redis.

## Phone uploads

Signed URLs use `AWS_PUBLIC_ENDPOINT`. For a real phone set it (and `REVERB_HOST`) to `http://<your-LAN-IP>:8333`, then `docker compose up -d`.

## Layout

```
docker-compose.yml        all services (section 9)
docker/                   php (app + media targets), nginx, seaweedfs s3.json, bucket-init
backend/                  OVERLAY copied over a fresh Laravel skeleton at image build time
  app/Services/Nutrition  TargetCalculator, TrendService, AdaptiveTargetService, TargetService, DaySummaryService
  app/Http/Controllers/Api
  app/Jobs                RunWeeklyCheckin, PurgeExpiredData
  database/migrations     full schema for every table in the PRD
  tests/                  unit (golden values, trend, adaptive) + feature (auth, targets, logs, weights)
```

The Dockerfile runs `composer create-project laravel/laravel`, installs packages, then copies `backend/` over it. If you prefer a normal Laravel repo, run that once locally, copy the overlay in, and commit the result.

## Status against the PRD

Done: AUTH-1..4 (social sign-in untested against real providers), PROF-1..3, FOOD-1..2, LOG-1..3, BODY-1, NUT-1 (service + weekly job), CHK-1 (job; approval endpoints pending), PRIV-1, health, rate limits, error shape, OpenAPI, schema for all tables, MEDIA-1 first half (signed upload URLs), private channel authorization for chat.

Not done yet (next phases): TRN/WKT (exercise, program, workout endpoints), COACH-1..5 endpoints and policies, CHAT message endpoints + push, FFmpeg ProcessMedia job, SYNC-1, SUB-1, NOTIF-1, FOOD-3, BODY-2, NUT-2, Horizon, Larastan/CI.

## Values to review before launch

`config/fitness.php` guardrails (min kcal 1200, max loss 1.0% body weight/week), macro split, and the 7700 kcal/kg constant are placeholders. The PRD requires a qualified nutrition professional to set them.
