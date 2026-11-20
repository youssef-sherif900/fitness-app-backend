# PRD Addendum v1.1 — Backend

Proposed text for the next revision of `Fitness App Backend PRD.txt`. It records
behaviour that exists in the code but is not described by the PRD, corrects statements
the implementation intentionally diverges from, and lists decisions the product owner
must make. Nothing here changes code.

Status: **proposed**, 2026-11-20. Companion to [prd-alignment.md](prd-alignment.md).

---

## A. Requirement amendments

### AUTH-2 — Social sign-in (replace the wording)

> Sign in with Apple and Google through a single endpoint,
> `POST /api/v1/auth/social`, with body `{provider: "apple"|"google", id_token,
> device_id, name?}`. The server verifies the provider ID token against its published
> JWKS (issuer and audience checked), links by provider subject or verified email, and
> returns the same session payload as email/password login. Provider-specific routes
> are not required.

### AUTH-1 — Session policy (add)

> Access tokens expire after 60 minutes and refresh tokens after 60 days. Only one
> token pair per `device_id` is valid: issuing a new session for a device revokes the
> previous pair. `GET /auth/sessions` lists devices; `DELETE /auth/sessions/{deviceId}`
> revokes one. A password reset revokes every device.

### AUTH-4 / PRIV-1 — Deletion window (clarify)

> `DELETE /me` immediately revokes tokens and soft-deletes the account (202). A
> scheduled `PurgeExpiredData` job hard-deletes the account and its storage prefix
> (`users/{id}/…`) after `FITNESS_ACCOUNT_DELETION_DAYS` (default 30). The export is a
> JSON document containing the user row, profile, goals, targets, food logs, saved
> meals, weights, measurements, workouts and check-ins.

### PROF-3 — Guardrails (add acceptance criteria)

> The server rejects a computed daily target below `FITNESS_MIN_DAILY_KCAL` (default
> 1200) with `target_below_minimum`, and a weekly loss above
> `FITNESS_MAX_WEEKLY_LOSS_PCT` (default 1.0 % of body weight) with
> `weekly_loss_rate_too_high`. Violations return 422 and nothing is persisted. Track
> who reviewed the values and when; the defaults are placeholders.

### LOG-3 — Summaries (define deficit)

> `GET /logs/summary?from&to` returns per-day intake and per-meal totals plus the
> current target. `burn_kcal` is `null` until an energy-expenditure model exists;
> therefore **"deficit" means intake vs target**, not intake vs burn. Any UI that shows
> a deficit must label it that way.

### BODY-1 — Scope split (amend)

> Phase 1: weigh-ins (`GET/POST/PATCH/DELETE /body/weights`) and the EWMA trend with
> `slope_kg_per_week`. Body measurements are a separate requirement (see B-2), not
> part of BODY-1.

### CHK-1 / NUT-1 — Check-in contract (replace)

> A weekly job runs for clients with an active goal at their local Monday 06:00. It
> evaluates the previous 14 days and stores one check-in per (user, week) containing:
> expected vs actual weekly trend, weigh-in count, adherence, and a proposed target
> change with a human-readable reason. Users with an active trainer link or a
> trainer-sourced target get a **pending proposal**, never an automatic change. The
> API must expose the proposal for trainer approval/override.

### TRN-2 — Where generation happens (clarify)

> Program **generation** (split selection, exercise selection, sets/reps/RPE) is
> implemented in the mobile client. The backend is responsible for validation,
> versioning, templates, publishing, assignment, and progression/deload proposals. A
> server-side generator is out of scope for v1.

### SYNC-1 — Offline contract (replace)

> v1 uses per-resource idempotency rather than a batch endpoint: `POST` bodies carry a
> client-generated `client_uuid`, unique per user, and replaying a write returns the
> original row. Clients pull a rolling window (logs: last 21 days; weights: full
> history) and reconcile by `server_id`/`client_uuid`. Deletions are soft and are not
> discoverable across devices in v1; a tombstone or cursor feed is required before
> multi-device editing is advertised. A batch `/sync` endpoint and `sync_cursors` API
> remain future work.

### MEDIA-1 — Phase split (amend)

> Phase 1 (implemented): `POST /attachments` validates kind/mime/size, reserves an
> attachment row in `pending`, and returns a signed PUT URL; `Idempotency-Key` makes
> retries safe. Phase 4 (not implemented): upload callback, FFmpeg processing,
> thumbnails, waveforms, signed playback.

## B. New requirements (implemented, previously undocumented)

| New ID | Pri | Requirement |
|---|---|---|
| FOOD-4 | P1 | `GET /foods/popular?limit` returns the most-logged foods of the last 90 days across all users, filtered to foods visible to the caller, backfilled with verified global foods (1–20 results). |
| LOG-4 | P0 | Quick add: a log entry may be created without `food_id`/`serving_id` by sending `name`, `kcal` and optional macros. Catalog entries ignore client macros and recompute from the serving. |
| LOG-5 | P0 | Editing `qty` scales the stored nutrient snapshot by `newQty/oldQty` so historical logs remain a faithful copy of what was eaten. |
| WKT-3 | P0 | `GET /workouts/exercises/{id}/next-set-suggestion` returns a suggested weight/reps/RPE from the active program snapshot, falling back to the last completed top set, and `null` when neither exists. |
| WKT-4 | P1 | Weekly volume is counted as **working sets**, not tonnage, and is attributed to every primary/secondary muscle of the exercise, so per-muscle totals may exceed the session total. |
| TRN-3 | P1 | Program versioning: every accepted structural edit increments `programs.version`; publishing snapshots that version into `program_versions.metadata`; workouts started from a version keep that prescription. |
| AUTH-5 | P1 | Device sessions: list and revoke per device (moved out of AUTH-1 for testability). |
| SYS-1 | P0 | All errors use `{"error": {code, message, details}}`. Domain error codes (`INVALID_STATE`, `VERSION_CONFLICT`, `IDEMPOTENCY_CONFLICT`, `NOT_ELIGIBLE`, guardrail codes) must map to their documented HTTP status. *(Currently not true — see alignment §3.)* |

## C. Open decisions (blocking a clean v1.1)

1. **Energy model.** Add workout/activity burn (wearable or MET-based) or keep deficit
   defined as intake vs target. Affects LOG-3, WKT-1, the dashboard and the PRD's
   headline promise.
2. **Body measurements.** Implement `measurements` CRUD now (small) or defer and
   remove it from BODY-1.
3. **Check-in approval surface.** Trainer approval is a P0 requirement; choose between
   `POST /checkins/{id}/apply` and automatic application with a 7-day undo.
4. **Trainer consent model.** Define scopes (`nutrition`, `workouts`, `body`, `photos`,
   `plan_editing`), the invite lifetime, and what `access_audit` records before
   implementing `TrainerAccess`.
5. **Guardrail ownership.** Name the qualified nutrition professional, review date and
   the approved min-kcal/max-loss values. Until then, do not market the numbers as
   medically reviewed.
6. **Sync scope.** Decide whether v1 promises multi-device deletion visibility. If not,
   state the single-device assumption in the PRD and in-app.
7. **Media processing host.** FFmpeg worker inside Compose vs external service;
   determines the MEDIA-1 phase-4 design.
8. **Subscription enforcement.** Which endpoints are free vs paid, and where the
   entitlement check lives (middleware vs per-controller).

## D. Acceptance-test additions

The PRD should require these tests before a requirement is marked done:

- Target math golden values + both guardrail failures.
- EWMA trend with gaps and duplicate same-day weigh-ins.
- Adaptive hold/apply matrix and the trainer-managed pending path.
- Food-log replay (200, same row), qty scaling, copy, summary cache invalidation.
- Program create → edit (version bump) → stale edit (409) → publish snapshot → assign.
- Workout start from a program day expands items in order; replay is idempotent.
- Set type validation matrix, duplicate set number, idempotency conflict.
- Complete → PR event persisted; edit/delete a set → PR recomputed.
- Authorization: another user's workout/program/food returns 404.
- Check-in job creates exactly one row per (user, week) and never changes a
  trainer-managed target.

## E. Text to append verbatim to the PRD

> **APPENDIX A — IMPLEMENTATION ADDENDUM (v1.1, 2026-11-20)**
>
> This appendix records where the delivered system intentionally differs from the
> requirements above. The code is the source of truth. In summary: social sign-in uses
> one `/auth/social` endpoint; "deficit" means intake versus target because no burn
> model exists; body measurements and progress photos are deferred; program generation
> is client-side; offline sync is per-resource idempotency, not a batch endpoint;
> check-in proposals require a trainer approval API that is not yet built; media
> processing, chat, push notifications, subscriptions and trainer consent scopes are
> not implemented in this release. Guardrail values remain placeholders pending
> nutrition-professional review. A new `SYS-1` requirement makes the documented error
> envelope and status mapping mandatory.
