# Full Codebase Audit — 2026-07-24 (pre-M4 checkpoint)

Seven parallel adversarial auditors (auth/RBAC, attendance ledger, adjustments, M1 pay
matrix, schema/arch-guards, frontend, test-quality+docs) over `main` as built (M0–M3.6
backend + M3.5 frontend). Significant findings independently reproduced against the running
stack before inclusion. Backend 267 tests + 17 arch, frontend 189 — all green.

**Verdict:** The system is well-built and the highest-stakes parts are sound. There is
**one confirmed Critical** (an existence-enumeration leak reachable by any authenticated
user) plus two sibling Important leaks of the same root cause, and a cluster of latent /
prep-for-M4 items. No wrong pay multiplier, no ledger-mutation path, no broken auth, no
false security claim in the docs.

---

## 🔴 The enumeration cluster — one root cause, three endpoints (CONFIRMED LIVE — **FIXED**)

> **Status: fixed** on branch `fix-enumeration-leaks` and verified live (all three now return
> byte-identical responses for a real vs. a fabricated subject). #1: `failedAuthorization()`
> → 404 on the two subject FormRequests. #2: dropped the unscoped `exists:employees,id` (the
> controller already 404s a null/out-of-scope employee). #3: scoped `target_log_id`'s
> `exists` to the submitter's own logs. Leak-closure tests assert response *equality*
> (real ≡ fake) and are proven to bite.


**Root cause:** existence checks (route-model binding, or `exists:` validation rules) run
**before** the authority/scope check, so the *status code itself* (403 vs 404 vs 400)
distinguishes a real record from a fabricated one — the exact org-chart leak the project's
404-not-403 rule exists to prevent. The codebase gets this right for employee *reads*
(`ShowEmployeeController`) and the adjustment *transitions* (`RejectAdjustmentRequest`
deliberately defers validation for this reason) — it just wasn't applied to these write
routes.

### 1. CRITICAL — any authenticated employee can enumerate any employee record company-wide
`routes/api.php:81-82` → `ProvisionUserController` / `RecordEmploymentController`; auth is
only `is_system_admin` in `ProvisionUserRequest.php:13` / `RecordEmploymentRequest.php:13`.
Laravel's `SubstituteBindings` resolves `{employee}` from the DB **before**
`FormRequest::authorize()` runs, so a real id binds then 403s, a fake id 404s at binding.
Reproduced live as `employee.manila@hris.test` (a plain, non-admin, non-HR employee):
`POST /admin/employees/<real>/user` → **403**, `/<fake>/user` → **404** (same for
`/employment`). UUIDv7 ids are time-ordered, so ids from a known window are guessable.
**No privilege required.**
**Fix:** for any admin route with a subject in the URL, check eligibility in the controller
against validated input and 404 uniformly (mirror `ManualPunchController`'s intended
pattern), or gate a bare not-admin check as early middleware that 404s the subject case.

### 2. IMPORTANT — HR admin can enumerate any employee's existence via manual-punch
`ManualPunchRequest.php:27` — `exists:employees,id` (unscoped) runs before
`ManualPunchController`'s office-scope check. Reproduced live as `hr.manila@hris.test`
(Manila only) with a full valid payload: real out-of-scope Cebu employee → **404
not_found**, fabricated id → **400 validation_failed**.
**Fix:** drop `exists:employees,id`; let the controller's null-safe `find()` 404 uniformly.

### 3. IMPORTANT — submit-adjustment target is unscoped: existence oracle + queue griefing
`SubmitAdjustmentRequest.php:22` — `target_log_id` uses unscoped `exists:attendance_logs,id`,
and `SubmitAttendanceAdjustment` stores it without checking ownership; ownership is only
re-checked at approval (`ApplyAttendanceAdjustment::assertAnnullable`, which correctly 422s
and rolls back). Reproduced live: an employee submitted a `void` against another employee's
real log id → **201 Created** (a pending row persisted), fabricated id → **400**. Impact:
a mild log-id existence oracle, and any employee can flood a manager's approval queue with
requests guaranteed to 422. No privilege escalation (approval-time re-check closes it).
**Fix:** scope the `target_log_id` check to the submitter's own logs and 422 at submit time.

---

## 🟠 Real gaps — latent today, fix before they bite

### 4. IMPORTANT (corroborated by 3 auditors) — single-writer arch guards under-cover write forms
`tests/Arch/ConventionsTest.php` — the three grep guards (`attendance_logs`,
`attendance_annulments`, employment cache columns) match Eloquent/query-builder shapes but
**miss** raw-SQL (`DB::statement(`, `DB::insert(`, `DB::update(`), a bare builder
`->insert(`/`->insertGetId(`, and `*Quietly`/`forceDelete`/`increment`. A file with such a
write still passes the "mentions the table" gate but trips no pattern. No such writer exists
today (grep-verified), so the append-only invariant holds — but the guards promise more than
they deliver. **Fix:** add those patterns to all three guards.

### 5. CRITICAL-but-latent (known, slated for M4) — `activity_log.subject_id` is a bigint morph
`2026_07_23_084313_create_activity_log_table.php:17` — `nullableMorphs('subject')` = bigint,
against uuidv7-keyed subject tables (`causer_id` two lines down was correctly `uuidUuidMorphs`).
M2 knowingly deferred this "to M4 when activitylog gets wired." Nothing uses `LogsActivity`
yet, so it's inert — but it breaks the first time any model logs activity, which is exactly
what M4 does. **Fix (in M4):** `nullableUuidMorphs('subject')`.

### 6. IMPORTANT — `employment_records.employment_type` has no enum / cast / CHECK
`2026_07_24_000003_...:29` — plain `text`, no `EmploymentType` enum, no model cast,
`RecordEmploymentRequest` validates it only as `string`. Docs cite this column as *the*
example of the "text + PHP enum + CHECK" pattern — the pattern was never applied. M5's pay
engine will branch on this value; typos land permanently in an append-only history table.
**Fix before M5:** add the enum + cast + `Rule::in()` + mirrored CHECK.

### 7. IMPORTANT — `crypto.randomUUID()` crashes punch on a plain-HTTP LAN deployment
`frontend/web/src/hooks/usePunch.ts:38` — `crypto.randomUUID` exists only in a secure
context (HTTPS/localhost). A plain-`http://10.x` on-prem rollout (plausible for a PH SME
before TLS) makes it `undefined`, so every punch throws with only a generic error to show.
**Fix:** `crypto.randomUUID?.() ?? fallbackUuidV4()`, with a test stubbing the missing API.

### 8. IMPORTANT (defense-in-depth) — `is_system_admin` is mass-assignable
`User.php` / `Employee.php` use `$guarded = []`. Every current `::create()` passes a narrow
explicit array (safe), but the single most powerful flag in the system (bypasses both
`Gate::before` and `EmployeeScope`) is one `User::create($request->validated())` away from
self-granted admin. **Fix:** `$guarded = ['is_system_admin']` (or an explicit `$fillable`).

---

## 🟡 Hygiene, prep, and docs

- **`requests.decision_note` "required on rejection"** is app-only, no DB `CHECK` backstop (schema).
- **`strict_types` arch guard** only scans `app/` — CLAUDE.md claims `app/` **and** `tests/`; migrations get no arch coverage at all. Everything currently complies. (schema / docs)
- **`BasisPoints` has no comparison operator** — needed for M4's statutory-floor validation (its own docblock promises it). (pay / M4 prep)
- **Rounding duplicated** — `BasisPoints::divideRoundHalfUp` is a byte-identical copy of `Money::fraction`'s rule; CLAUDE.md's "all rounding through `Money::fraction`" is technically untrue. Deliberate, low-risk (real chains land on whole basis points), but two copies to keep in sync. (pay)
- **Idempotency same-new-key race** → uncaught unique-violation → 500 instead of 409. No double-write, self-heals on retry. (ledger — previously tracked)
- **Stale roadmap counts** — "165 frontend tests" (now 189); "266 backend" in the M3.6 block vs "267" in M3.5. (docs — from the polish PRs)
- **`ReadAttendanceTest:59`** asserts bare `404` without the `error.code` its siblings all check. (test)
- **No rate-limit** on `POST /attendance/adjustments` (10 MB attachments) → storage/queue flooding nuisance. (adjustments)
- **`ManualPunchRequest.punched_at`** has no upper bound (`"tomorrow"`/`"+3 years"` pass). (ledger)
- **`Money::multipliedBy`/`allocateByRatios`** lack the overflow guard `fraction()` has (dormant — both unused today). (pay)
- **`sessions.user_id`** has no FK (stock Laravel, ephemeral). (schema)
- **`usePunch` `retry: 1`** retries deterministic 4xx too (harmless — same idempotency key). (frontend)
- **Stale test comment** in `MediaLibraryTest` understates now-strong coverage. (test)

---

## ✅ Verified sound (calibration — what held up under adversarial review)

- **The DOLE premium matrix is cell-by-cell legally correct** — independently re-derived
  against the Labor Code, including the two hardest cells (special-non-working-rest-day flat
  150%, and the 371.8% worst-case stack). Art.82 exemption is a genuinely non-defaultable
  parameter.
- **Append-only ledger, single writer, UTC instant, no self-backdating** — no combination of
  endpoints lets an employee backdate their own punch; the stored instant is pinned at the DB
  layer, not just the cast.
- **Adjustment approval** — in-scope-minus-self authority, 404→409→422 ordering with no leak,
  effect atomicity under lock, private attachment access, and the concurrent same-target fix
  are all correct and backed by **genuine two-OS-process** concurrency tests.
- **Login non-enumeration** (constant-shape, dummy-hash), **`EmployeeScope` bracketing**
  (real `WHERE (1=0)` empty guard, no scope-leak OR), **frontend token confinement**, and
  **day-shift-safe dates** all verified.
- **Docs are honest** — no surviving RBAC over-claim, and no doc makes a security/behavioral
  claim the code doesn't back. Concurrency and arch tests are the real kind, not theatre.

---

## Recommended order before M4

1. **Fix the enumeration cluster (#1–#3) now** — it's a live, confirmed security leak, small
   and self-contained, and #1 needs no privilege. This is the only "fix immediately" item.
2. Fold **#5 (activity_log uuid morph)** and **#6 (employment_type enum+CHECK)** into M4/M5
   where they naturally belong.
3. Close **#4 (arch-guard write forms)** and **#8 (`$guarded`)** as cheap defense-in-depth.
4. Sweep the 🟡 hygiene list opportunistically (the stale roadmap counts are mine to fix).
