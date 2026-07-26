# M6a — Approval spine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generalize M3.6's attendance-only approval into a reusable single-step request spine with two scope-filtered approval queues, and give it its first full browser UI — proven end to end with attendance adjustments.

**Architecture:** Backend keeps the existing single-step `requests` spine and adds three seams: a `RequestEffect` interface dispatched by `RequestType` (so `ApproveRequest` stops hard-depending on attendance), two scoped pending-queue queries (`/team/approvals`, `/office/approvals`), and a type-agnostic `/requests/*` read/decision route surface (submission stays type-specific). Frontend adds the envelope-aware data layer, the file-a-correction form off the M3.5 calendar, a `/me/requests` list, and the two queue screens with one reusable `<RequestCard>` and optimistic approve/reject confined to the queue.

**Tech Stack:** Laravel 13 / PHP 8.5 / PostgreSQL 18 (Pest, real Postgres) · Next 16 / React 19 / TypeScript / `@tanstack/react-query` / Carbon tokens (Vitest + Testing Library).

## Global Constraints

- `declare(strict_types=1);` at the top of every PHP file in `app/` and `tests/` (arch-enforced).
- Worked time is integer minutes, money integer centavos, multipliers integer basis points — never floats.
- Calendar dates on the wire are `YYYY-MM-DD` strings, never `Date` objects.
- Success is always `{"data": ...}`, errors always `{"error": ...}`. Never both.
- One system action = one route = one controller = one Action class. Actions take an Input DTO / domain args, return a domain object, never touch HTTP. Controllers are `final` and invokable.
- Domain layer is framework-agnostic — no `config()`, no facades — **but** the ORM/Eloquent and `EmployeeScope` are allowed in Domain (the carve-out `RequestAuthority`/`EmployeeScope` already use).
- Tests run against real PostgreSQL, never SQLite.
- **404-not-403:** an out-of-scope or self-directed decision must look exactly like a nonexistent request (`ModelNotFoundException`), never a 403 — no existence leak.
- **The append-only `attendance_logs` ledger is never mutated.** A correction is a new row (add) or an annulment row (void); amend is both. M6a does not change this — approval still routes through the existing `ApplyAttendanceAdjustment` + M5 recompute path.
- **Single-step machine, keep `pending`.** No `draft`/`submitted`/`manager_approved`/`hr_approved` — those land in M6b. The `requests.state` CHECK stays `('pending','approved','rejected','cancelled')`.
- **No migration in M6a.** The `requests.type` CHECK stays `('attendance_adjustment')`.
- Frontend: every query key comes from `src/lib/keys.ts`; every request goes through `src/lib/api.ts` (the envelope client); colors/spacing/type come from `carbon.css` `var(--*)` tokens, never raw hex/px.
- Commit messages carry no attribution trailers (no `Co-Authored-By`, `Generated with`, session URL). Message body only.

---

## File Structure

**Backend — new**
- `app/Domain/Requests/RequestEffect.php` — the effect interface (`applyOnApproval(Request, string): void`).
- `app/Actions/Requests/Effects/AttendanceAdjustmentEffect.php` — implements it, delegates to `ApplyAttendanceAdjustment`.
- `app/Actions/Requests/RequestEffectFactory.php` — maps `RequestType` → `RequestEffect`.
- `app/Domain/Requests/ApprovalQueues.php` — `directReportsOf(User): Builder`, `hrOfficesOf(User): Builder`.
- `app/Http/Controllers/Requests/TeamApprovalsController.php`, `OfficeApprovalsController.php`.
- `app/Http/Controllers/Requests/{ListMineController,ShowController,ApproveController,RejectController,CancelController,DownloadAttachmentController}.php` — relocated from `Attendance/Adjustments/`.

**Backend — modified**
- `app/Actions/Requests/ApproveRequest.php` — depend on `RequestEffectFactory`, not `ApplyAttendanceAdjustment`.
- `routes/api.php` — add `/requests/*`, `/team/approvals`, `/office/approvals`; remove the old `/attendance/adjustments/{pending,{request}...}` read/decision routes; keep `POST /attendance/adjustments`.
- `tests/Arch/ConventionsTest.php` — drop the relocated controllers from the Attendance-scope exemption list.

**Backend — deleted**
- `app/Http/Controllers/Attendance/Adjustments/{ListPending,ListMine,Show,Approve,Reject,Cancel,DownloadAttachment}Controller.php` (relocated to `Requests/`; `SubmitController` stays).

**Frontend — new**
- `src/lib/keys.ts` — add `requests` key group (modify).
- `src/lib/api.ts` — add `Request`/`RequestDetail` wire types + `api.requests.*` + `api.adjustments.submit` (modify).
- `src/hooks/useMyRequests.ts`, `useSubmitCorrection.ts`, `useDecideRequest.ts`, `useApprovalQueue.ts` (+ `.test.tsx` each).
- `src/components/domain/RequestCard.tsx` (+ test) — renders any request type.
- `src/components/domain/CorrectionForm.tsx` (+ test) — the file-a-correction form.
- `src/app/(app)/me/requests/page.tsx` — my-requests list.
- `src/app/(app)/team/approvals/page.tsx`, `src/app/(app)/office/approvals/page.tsx` — the two queues.

**Frontend — modified**
- `src/app/(app)/me/attendance/page.tsx` — a "Request correction" entry point.
- `src/components/SideNav.tsx` — `ROUTES.me` gains `/me/requests`; `ROUTES.team` gains `/team/approvals`; `ROUTES.office` gains `/office/approvals` (+ `SideNav.test.tsx`).

**Docs / scripts**
- `scripts/e2e-requests.sh` (new), `docs/03-api.md`, `docs/06-roadmap.md`, `docs/features.md` (modify).

---

## Task 1: The `RequestEffect` seam — dispatch approval effects by type

**Files:**
- Create: `backend/app/Domain/Requests/RequestEffect.php`
- Create: `backend/app/Actions/Requests/Effects/AttendanceAdjustmentEffect.php`
- Create: `backend/app/Actions/Requests/RequestEffectFactory.php`
- Modify: `backend/app/Actions/Requests/ApproveRequest.php`
- Test: `backend/tests/Feature/Requests/RequestEffectDispatchTest.php`

**Interfaces:**
- Consumes: `App\Models\Request`, `App\Domain\Requests\RequestType`, existing `App\Actions\Attendance\ApplyAttendanceAdjustment::apply(Request, string): void`.
- Produces:
  - `interface RequestEffect { public function applyOnApproval(Request $request, string $approverUserId): void; }`
  - `RequestEffectFactory::for(RequestType $type): RequestEffect` (throws for an unmapped type).
  - `ApproveRequest::__construct(RequestEffectFactory $effects)` — no longer depends on `ApplyAttendanceAdjustment` directly.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Requests/RequestEffectDispatchTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Requests\RequestEffectFactory;
use App\Domain\Requests\RequestEffect;
use App\Domain\Requests\RequestType;

it('resolves the attendance-adjustment effect for its type', function (): void {
    $effect = app(RequestEffectFactory::class)->for(RequestType::AttendanceAdjustment);

    expect($effect)->toBeInstanceOf(RequestEffect::class);
});
```

- [ ] **Step 2: Run it — expect failure**

Run: `docker compose -f compose.dev.yml exec -T -e DB_DATABASE=hris_test --user hris api php -d memory_limit=512M ./vendor/bin/pest tests/Feature/Requests/RequestEffectDispatchTest.php`
Expected: FAIL — `RequestEffectFactory` / `RequestEffect` do not exist.

- [ ] **Step 3: Create the interface and effect**

`backend/app/Domain/Requests/RequestEffect.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Requests;

use App\Models\Request;

/**
 * The side effect an approved request applies, inside ApproveRequest's transaction and row
 * lock. One implementation per RequestType — attendance adjustment today; leave and
 * overtime add their own in M6b/M6c without touching the approval path. A framework-
 * agnostic contract (Domain); the implementations live in the Actions layer, where writing
 * to models belongs.
 */
interface RequestEffect
{
    public function applyOnApproval(Request $request, string $approverUserId): void;
}
```

`backend/app/Actions/Requests/Effects/AttendanceAdjustmentEffect.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Requests\Effects;

use App\Actions\Attendance\ApplyAttendanceAdjustment;
use App\Domain\Requests\RequestEffect;
use App\Models\Request;

/** The attendance-adjustment effect: delegates to the existing add/void/amend action. */
final class AttendanceAdjustmentEffect implements RequestEffect
{
    public function __construct(private readonly ApplyAttendanceAdjustment $apply) {}

    public function applyOnApproval(Request $request, string $approverUserId): void
    {
        $this->apply->apply($request, $approverUserId);
    }
}
```

- [ ] **Step 4: Create the factory**

`backend/app/Actions/Requests/RequestEffectFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Requests;

use App\Actions\Requests\Effects\AttendanceAdjustmentEffect;
use App\Domain\Requests\RequestEffect;
use App\Domain\Requests\RequestType;
use LogicException;

/**
 * Maps a RequestType to its RequestEffect, resolved from the container so each effect gets
 * its own dependencies injected. An unmapped type is a programming error — a request type
 * reached approval with no effect wired — never a silent no-op approve.
 */
final class RequestEffectFactory
{
    public function for(RequestType $type): RequestEffect
    {
        return match ($type) {
            RequestType::AttendanceAdjustment => app(AttendanceAdjustmentEffect::class),
        };
    }
}
```

- [ ] **Step 5: Run the test — expect pass**

Run the command from Step 2. Expected: PASS.

- [ ] **Step 6: Refactor `ApproveRequest` onto the factory (behavior-preserving)**

Modify `backend/app/Actions/Requests/ApproveRequest.php`: replace the constructor dependency and the hardcoded call.

```php
// use App\Actions\Attendance\ApplyAttendanceAdjustment;   // remove
use App\Actions\Requests\RequestEffectFactory;

final class ApproveRequest
{
    public function __construct(private readonly RequestEffectFactory $effects) {}

    // ...inside execute(), replacing the `$this->applyAttendanceAdjustment->apply(...)` line:
    $this->effects->for($locked->type)->applyOnApproval($locked, $approver->id);
}
```

Leave the class docblock's explanation of the lock/ordering intact; update its "For an attendance adjustment that is ApplyAttendanceAdjustment" sentence to "The effect is resolved by type via RequestEffectFactory."

- [ ] **Step 7: Run the approval regression suite — expect pass**

Run: `docker compose -f compose.dev.yml exec -T -e DB_DATABASE=hris_test --user hris api php -d memory_limit=512M ./vendor/bin/pest tests/Feature/Attendance/AdjustmentTransitionsTest.php tests/Feature/Requests`
Expected: PASS — every existing approve/reject/cancel test still green (behavior unchanged), plus the new dispatch test.

- [ ] **Step 8: Run the arch suite — expect pass**

Run: `docker compose -f compose.dev.yml exec -T -e DB_DATABASE=hris_test --user hris api php -d memory_limit=512M ./vendor/bin/pest tests/Arch`
Expected: PASS — the Domain interface stays framework-agnostic; `ApproveRequest` is still final and HTTP-free.

- [ ] **Step 9: Commit**

```bash
git add backend/app/Domain/Requests/RequestEffect.php backend/app/Actions/Requests backend/tests/Feature/Requests/RequestEffectDispatchTest.php
git commit -m "Requests: dispatch approval effects by type via RequestEffectFactory"
```

---

## Task 2: Two scope-filtered approval queues

**Files:**
- Create: `backend/app/Domain/Requests/ApprovalQueues.php`
- Create: `backend/app/Http/Controllers/Requests/TeamApprovalsController.php`
- Create: `backend/app/Http/Controllers/Requests/OfficeApprovalsController.php`
- Modify: `backend/routes/api.php`
- Delete: `backend/app/Http/Controllers/Attendance/Adjustments/ListPendingController.php` (+ its route)
- Test: `backend/tests/Feature/Requests/ApprovalQueuesTest.php`

**Interfaces:**
- Consumes: `App\Models\{Request,User,Employee}`, `App\Domain\Requests\RequestState`.
- Produces:
  - `ApprovalQueues::directReportsOf(User $user): Builder` — pending requests whose requester's `current_reports_to_id` = the user's employee id, excluding the user's own.
  - `ApprovalQueues::hrOfficesOf(User $user): Builder` — pending requests whose requester's `current_office_id` ∈ the user's `hrAdminOffices`, excluding the user's own.
  - Routes `GET /team/approvals`, `GET /office/approvals` → `RequestResource` collections.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Requests/ApprovalQueuesTest.php`. Use `Request::factory()` / employee+user factories; a request is `type=attendance_adjustment`, `state=pending`. Cover: a manager's `/team/approvals` returns only their direct reports' pending requests; an HR admin's `/office/approvals` returns only their office's; neither returns the actor's own; a decided (approved) request leaves both; a user who is both a manager and HR sees the request in both queues.

```php
<?php

declare(strict_types=1);

use App\Domain\Requests\RequestState;
use App\Models\Request;

use function Pest\Laravel\getJson;

it('shows a manager only their direct reports pending requests', function (): void {
    // Build: office, manager (employee+user), a direct report, an unrelated employee.
    [$manager, $report, $stranger] = makeManagerReportStranger();   // support helper (see below)

    $mine = Request::factory()->for($report, 'employee')->create(['state' => RequestState::Pending]);
    Request::factory()->for($stranger, 'employee')->create(['state' => RequestState::Pending]);

    $res = actingAs($manager->user)->getJson('/api/v1/team/approvals')->assertOk();

    expect(collect($res->json('data'))->pluck('id')->all())->toBe([$mine->id]);
});
```

Add a `makeManagerReportStranger()` (and `makeHrAdmin`) helper to `backend/tests/Feature/Requests/support.php` (function_exists-guarded, `require_once`'d — the same pattern `tests/Feature/Compute/support.php` uses), wiring `current_reports_to_id` / `current_office_id` / `hrAdminOffices()->attach(...)` through the real factories. Write the remaining cases (hr office, not-self, decided-leaves, both-hats) in the same file.

- [ ] **Step 2: Run it — expect failure**

Run: `... pest tests/Feature/Requests/ApprovalQueuesTest.php`
Expected: FAIL — routes `/team/approvals`, `/office/approvals` do not exist (404).

- [ ] **Step 3: Create `ApprovalQueues`**

`backend/app/Domain/Requests/ApprovalQueues.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Requests;

use App\Models\Employee;
use App\Models\Request;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The two scoped views of the pending queue. Each is a subset of the in-scope-minus-self
 * set RequestAuthority::canDecide accepts — /team by the org chart (direct reports),
 * /office by HR office membership — so leave and overtime appear in them automatically
 * (no type filter). The two queues are VIEWS, not a new authority: canDecide is unchanged.
 *
 * @return Builder<Request>
 */
final class ApprovalQueues
{
    public static function directReportsOf(User $user): Builder
    {
        $selfEmployeeId = $user->employee?->id;

        $reportIds = Employee::query()
            ->where('current_reports_to_id', $selfEmployeeId)
            ->pluck('id');

        return self::pending()
            ->whereIn('employee_id', $reportIds)
            ->where('employee_id', '!=', $selfEmployeeId);
    }

    public static function hrOfficesOf(User $user): Builder
    {
        $officeIds = $user->hrAdminOffices()->pluck('offices.id')->all();

        $memberIds = Employee::query()
            ->whereIn('current_office_id', $officeIds)
            ->pluck('id');

        return self::pending()
            ->whereIn('employee_id', $memberIds)
            ->where('employee_id', '!=', $user->employee?->id);
    }

    /** @return Builder<Request> */
    private static function pending(): Builder
    {
        return Request::query()->where('state', RequestState::Pending)->latest();
    }
}
```

Note: `current_reports_to_id = null` (a user with no employee) yields no reports — an empty queue, which is correct.

- [ ] **Step 4: Create the two controllers**

`backend/app/Http/Controllers/Requests/TeamApprovalsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Requests;

use App\Domain\Requests\ApprovalQueues;
use App\Http\Resources\RequestResource;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The manager queue: pending requests from the actor's direct reports. Scope enforced by
 *  ApprovalQueues::directReportsOf (an out-of-scope request simply isn't in the set). */
final class TeamApprovalsController
{
    public function __invoke(HttpRequest $http): AnonymousResourceCollection
    {
        return RequestResource::collection(ApprovalQueues::directReportsOf($http->user())->get());
    }
}
```

`OfficeApprovalsController` mirrors it with `ApprovalQueues::hrOfficesOf`.

- [ ] **Step 5: Wire the routes; remove the old combined queue**

In `backend/routes/api.php`, inside the `auth:sanctum` group, add:

```php
Route::get('/team/approvals', TeamApprovalsController::class);
Route::get('/office/approvals', OfficeApprovalsController::class);
```

Remove the `GET /attendance/adjustments/pending` route and the `ListPendingAdjustmentsController` import; delete `app/Http/Controllers/Attendance/Adjustments/ListPendingController.php`. Delete the old pending-queue test (or fold its assertions into `ApprovalQueuesTest`) — grep for `adjustments/pending` in `tests/` and migrate those cases.

- [ ] **Step 6: Run the queue test — expect pass**

Run: `... pest tests/Feature/Requests/ApprovalQueuesTest.php`
Expected: PASS — all five cases.

- [ ] **Step 7: Run the full request/attendance suites + arch — expect pass**

Run: `... pest tests/Feature/Requests tests/Feature/Attendance tests/Arch`
Expected: PASS (no lingering reference to the removed `/pending` route).

- [ ] **Step 8: Commit**

```bash
git add backend/app/Domain/Requests/ApprovalQueues.php backend/app/Http/Controllers/Requests backend/routes/api.php backend/tests/Feature/Requests
git rm backend/app/Http/Controllers/Attendance/Adjustments/ListPendingController.php
git commit -m "Requests: split the pending queue into /team and /office scoped views"
```

---

## Task 3: Generalize the read/decision surface to `/requests/*`

**Files:**
- Create: `backend/app/Http/Controllers/Requests/{ListMineController,ShowController,ApproveController,RejectController,CancelController,DownloadAttachmentController}.php` (relocated).
- Modify: `backend/routes/api.php`, `backend/tests/Arch/ConventionsTest.php`, the request feature tests (URL changes).
- Delete: the six relocated controllers under `Attendance/Adjustments/` (keep `SubmitController`).

**Interfaces:**
- Consumes: the existing `ApproveRequest`/`RejectRequest`/`CancelRequest` actions, `RequestResource`, `RequestAuthority`, the existing `ApproveAdjustmentRequest`/`RejectAdjustmentRequest` FormRequests (moved or kept — see Step 3).
- Produces the route surface:
  - `GET /requests` (mine), `GET /requests/{request}` (show), `GET /requests/{request}/attachment`
  - `POST /requests/{request}/approve|reject|cancel`
  - `POST /attendance/adjustments` (submit) — **unchanged**.

- [ ] **Step 1: Write/adjust the failing test**

Copy `tests/Feature/Attendance/AdjustmentTransitionsTest.php`'s decision cases into `tests/Feature/Requests/RequestDecisionsTest.php` (or rename in place) hitting the new URLs: `POST /api/v1/requests/{id}/approve`, `.../reject`, `.../cancel`, `GET /api/v1/requests`, `GET /api/v1/requests/{id}`, `.../attachment`. Keep every assertion identical (404 out-of-scope, 409 already-decided, 422 missing reject note, requester-only cancel, byte-identical ledger after approve — the last already covered by the recompute path).

- [ ] **Step 2: Run it — expect failure**

Run: `... pest tests/Feature/Requests/RequestDecisionsTest.php`
Expected: FAIL — `/requests/*` routes 404.

- [ ] **Step 3: Relocate the six controllers**

`git mv` each of `ListMine`, `Show`, `Approve`, `Reject`, `Cancel`, `DownloadAttachment` from `app/Http/Controllers/Attendance/Adjustments/` to `app/Http/Controllers/Requests/`, updating the `namespace` line to `App\Http\Controllers\Requests`. Their bodies are unchanged (they already delegate to the actions and `RequestResource`). The FormRequests (`ApproveAdjustmentRequest`, `RejectAdjustmentRequest`) may stay where they are — they only carry `authorize(): true` + reject-note rules; leave their class names as-is to minimize churn, or rename to `Approve/RejectRequestRequest` if the reviewer prefers (either is fine; keep the choice consistent across both).

- [ ] **Step 4: Rewire routes**

In `routes/api.php`: update the six imports to the `Requests\` namespace; register:

```php
Route::get('/requests', ListMineController::class);
Route::get('/requests/{request}', ShowController::class);
Route::get('/requests/{request}/attachment', DownloadAttachmentController::class);
Route::post('/requests/{request}/approve', ApproveController::class);
Route::post('/requests/{request}/reject', RejectController::class);
Route::post('/requests/{request}/cancel', CancelController::class);
```

Remove the old `GET/POST /attendance/adjustments/{request}...`, `GET /attendance/adjustments` (list-mine) routes and their imports. **Keep** `POST /attendance/adjustments` → `SubmitController`.

- [ ] **Step 5: Update the arch exemption**

In `backend/tests/Arch/ConventionsTest.php`, the Attendance-scope guard exempts `ApproveController` (and possibly others) by name/path. The relocated controllers are no longer under the `Attendance` namespace the guard scans — remove them from the exemption list. Confirm no arch rule now scans `Http/Controllers/Requests` for an Attendance-specific check; the generic "controllers are final invokable" rule still applies and the relocated controllers already satisfy it.

- [ ] **Step 6: Run the request suite + arch — expect pass**

Run: `... pest tests/Feature/Requests tests/Feature/Attendance tests/Arch`
Expected: PASS — decisions work on `/requests/*`; submit still on `/attendance/adjustments`; arch green.

- [ ] **Step 7: Grep for stragglers**

Run: `grep -rn "attendance/adjustments/" backend/ | grep -v "POST\|SubmitController\|routes/api.php:.*adjustments'"` — confirm nothing still points a decision/read at the old path (tests, docs comments).

- [ ] **Step 8: Commit**

```bash
git add -A backend/app/Http/Controllers backend/routes/api.php backend/tests
git commit -m "Requests: move the read/decision surface to a type-agnostic /requests/* resource"
```

---

## Task 4: Frontend data layer — types, client, keys, hooks

**Files:**
- Modify: `frontend/web/src/lib/api.ts`, `frontend/web/src/lib/keys.ts`
- Create: `frontend/web/src/hooks/useMyRequests.ts` (+ `.test.tsx`), `useSubmitCorrection.ts` (+ `.test.tsx`), `useApprovalQueue.ts` (+ `.test.tsx`), `useDecideRequest.ts` (+ `.test.tsx`)

**Interfaces:**
- Produces (wire types + client, verified against `RequestResource` and `SubmitController`):

```ts
export type RequestState = 'pending' | 'approved' | 'rejected' | 'cancelled'
export type RequestType = 'attendance_adjustment'
export type AdjustmentOperation = 'add' | 'void' | 'amend'

export type RequestDetail = {
  operation: AdjustmentOperation
  target_log_id: string | null
  direction: PunchDirection | null
  punched_at: string | null // ISO8601
}

export type RequestRecord = {
  id: string
  type: RequestType
  state: RequestState
  note: string
  employee_id: string
  detail: RequestDetail | null
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  has_attachment: boolean
}

export type CorrectionInput = {
  operation: AdjustmentOperation
  note: string
  target_log_id?: string
  direction?: PunchDirection
  punched_at?: string // ISO8601
  attachment?: File | null
}
```

- `api.requests`: `mine()`, `get(id)`, `approve(id)`, `reject(id, note)`, `cancel(id)`, `teamApprovals()`, `officeApprovals()`; `api.adjustments.submit(input)` (multipart).
- `keys.requests`: `mine()`, `detail(id)`, `teamApprovals()`, `officeApprovals()`, and `all()` prefix.

- [ ] **Step 1: Add the keys (write the key first, tests reference it)**

In `src/lib/keys.ts` add:

```ts
  requests: {
    all: () => ['requests'] as const,
    mine: () => ['requests', 'mine'] as const,
    detail: (id: string) => ['requests', 'detail', id] as const,
    teamApprovals: () => ['requests', 'team-approvals'] as const,
    officeApprovals: () => ['requests', 'office-approvals'] as const,
  },
```

- [ ] **Step 2: Add wire types + client methods**

In `src/lib/api.ts`, add the types above, then extend the `api` object:

```ts
  requests: {
    mine: () => request<RequestRecord[]>('/requests'),
    get: (id: string) => request<RequestRecord>(`/requests/${id}`),
    teamApprovals: () => request<RequestRecord[]>('/team/approvals'),
    officeApprovals: () => request<RequestRecord[]>('/office/approvals'),
    approve: (id: string) => request<RequestRecord>(`/requests/${id}/approve`, { method: 'POST' }),
    reject: (id: string, decision_note: string) =>
      request<RequestRecord>(`/requests/${id}/reject`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ decision_note }),
      }),
    cancel: (id: string) => request<RequestRecord>(`/requests/${id}/cancel`, { method: 'POST' }),
  },
  adjustments: {
    // Multipart: build FormData and DO NOT set Content-Type — the browser must set the
    // multipart boundary itself. `request` only adds Accept + Authorization, so a FormData
    // body passes through with the right content type. Matches SubmitController's fields.
    submit: (input: CorrectionInput) => {
      const form = new FormData()
      form.set('operation', input.operation)
      form.set('note', input.note)
      if (input.target_log_id !== undefined) form.set('target_log_id', input.target_log_id)
      if (input.direction !== undefined) form.set('direction', input.direction)
      if (input.punched_at !== undefined) form.set('punched_at', input.punched_at)
      if (input.attachment) form.set('attachment', input.attachment)
      return request<RequestRecord>('/attendance/adjustments', { method: 'POST', body: form })
    },
  },
```

- [ ] **Step 3: Write the hooks + their tests**

Mirror `usePunch.ts` / `useShiftTemplates.test.tsx`. `useMyRequests` (query on `keys.requests.mine()`), `useApprovalQueue('team'|'office')` (query on the matching key). `useSubmitCorrection` (mutation → `api.adjustments.submit`, on success invalidate `keys.requests.mine()` and `keys.attendance.all()`). `useDecideRequest` — see Task 7 for the optimistic queue variant; here provide the non-optimistic `cancel`/`reject`/`approve` used by `/me/requests` (invalidate by prefix). Each hook gets a `.test.tsx` that renders it via a `QueryClientProvider` wrapper (copy the wrapper from `useShiftTemplates.test.tsx`) and asserts it calls the right `api` method (mock `@/lib/api`) and invalidates the right key.

- [ ] **Step 4: Run the hook tests — expect pass**

Run: `docker compose -f compose.dev.yml exec -T --user node web sh -c 'npm test -- src/hooks/useMyRequests src/hooks/useSubmitCorrection src/hooks/useApprovalQueue src/hooks/useDecideRequest'`
Expected: PASS.

- [ ] **Step 5: Typecheck**

Run: `docker compose -f compose.dev.yml exec -T --user node web sh -c 'npm run typecheck'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/web/src/lib/keys.ts frontend/web/src/lib/api.ts frontend/web/src/hooks
git commit -m "Requests (web): wire types, envelope client, keys, and request hooks"
```

---

## Task 5: File-a-correction form off the attendance calendar

**Files:**
- Create: `frontend/web/src/components/domain/CorrectionForm.tsx` (+ `CorrectionForm.test.tsx`)
- Modify: `frontend/web/src/app/(app)/me/attendance/page.tsx` (a "Request correction" entry point)

**Interfaces:**
- Consumes: `useSubmitCorrection`, `useMyAttendance` (for the selected day's punches, to populate the target-punch picker for void/amend), `CorrectionInput`, tier-1 Carbon primitives (`Button`, `TextInput`, `Select`, `InlineNotification`).
- Produces: `<CorrectionForm date={string} punches={AttendanceLog[]} onDone={() => void} />`.

- [ ] **Step 1: Write the failing component test**

`CorrectionForm.test.tsx` (Testing Library + Vitest, mock `@/hooks/useSubmitCorrection`): (a) selecting `add` shows direction + time fields and hides the target-punch picker; (b) selecting `void` shows the target-punch picker (options = the day's punches) and hides time; (c) submitting `void` with a chosen punch and a note calls the mutation with `{ operation: 'void', target_log_id, note }`; (d) submit is disabled until the required fields for the chosen operation are present.

- [ ] **Step 2: Run it — expect failure**

Run: `... npm test -- CorrectionForm`
Expected: FAIL — component missing.

- [ ] **Step 3: Build `CorrectionForm`**

A controlled form: an operation `<Select>` (add/void/amend); for void/amend a target-punch `<Select>` whose options are `punches` (labelled by direction + local time via the existing `Duration`/time helpers); for add/amend a direction `<Select>` and a time `<TextInput type="time">` combined with `date` into an ISO8601 `punched_at`; a required note `<TextInput>`; an `<input type="file" accept=".pdf,.jpg,.jpeg,.png">`. Validate per-operation required fields (mirror `SubmitAdjustmentRequest`'s `required_if`), disable submit until valid, surface an `ApiError` via `<InlineNotification>`. On success call `onDone()`. All styling via `carbon.css` tokens.

- [ ] **Step 4: Integrate into `/me/attendance`**

In the attendance page, the selected-day detail panel gains a "Request correction" `Button` that reveals `<CorrectionForm date={selectedDate} punches={thatDaysPunches} onDone={closeAndToast} />`. Reuse the existing `selectedDate` state and the month's punch data already loaded.

- [ ] **Step 5: Run the component test + typecheck — expect pass**

Run: `... npm test -- CorrectionForm && ... npm run typecheck`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/web/src/components/domain/CorrectionForm.tsx frontend/web/src/components/domain/CorrectionForm.test.tsx "frontend/web/src/app/(app)/me/attendance/page.tsx"
git commit -m "Requests (web): file a correction from the attendance calendar"
```

---

## Task 6: My-requests page

**Files:**
- Create: `frontend/web/src/app/(app)/me/requests/page.tsx` (+ a colocated or `__tests__` test)

**Interfaces:**
- Consumes: `useMyRequests`, `useDecideRequest` (cancel), `RequestCard` (Task 7 — but this task may render a simpler inline row and adopt `RequestCard` when Task 7 lands; to avoid a cross-task dependency, render a self-contained row here and leave the shared card to the queues).

- [ ] **Step 1: Write the failing page test**

Render the page with a mocked `useMyRequests` returning one pending + one approved request; assert both render with their state; assert the pending one shows a "Withdraw" button and the approved one does not; clicking Withdraw calls the cancel mutation with the id.

- [ ] **Step 2: Run it — expect failure.** Run: `... npm test -- me/requests`. Expected: FAIL.

- [ ] **Step 3: Build the page**

A `SectionHeader` + a list of the employee's requests: type label, a state `Tag`, the note, the decision note if decided, and for a `pending` one a "Withdraw" `Button` → cancel mutation (invalidate `keys.requests.mine()`). Loading → `Skeleton`; empty → `EmptyState` ("You haven't filed any requests."). Tokens only.

- [ ] **Step 4: Run test + typecheck — expect pass.**

- [ ] **Step 5: Commit**

```bash
git add "frontend/web/src/app/(app)/me/requests"
git commit -m "Requests (web): my-requests list with withdraw"
```

---

## Task 7: Approval queues + `<RequestCard>` + optimistic decide

**Files:**
- Create: `frontend/web/src/components/domain/RequestCard.tsx` (+ test)
- Create: `frontend/web/src/app/(app)/team/approvals/page.tsx`, `frontend/web/src/app/(app)/office/approvals/page.tsx` (+ a shared queue test)
- Modify: `frontend/web/src/hooks/useDecideRequest.ts` — add the optimistic queue mutation.

**Interfaces:**
- `<RequestCard request={RequestRecord} onApprove={() => void} onReject={(note: string) => void} pending={boolean} />` — renders any type via a per-type summary (attendance adjustment: "Add IN at 08:00", "Void the 18:00 OUT", etc.), the requester note, an attachment link when `has_attachment`, and Approve / Reject (reject opens a required-note field).
- The optimistic mutation: on approve/reject, `onMutate` cancels the queue query, snapshots it, optimistically removes the decided card; `onError` rolls back; `onSettled` invalidates the queue key. **Confined to the queue** (per the spec) — `/me/requests` stays non-optimistic.

- [ ] **Step 1: Write the failing tests**

(a) `RequestCard.test.tsx`: renders the attendance summary + note; Approve calls `onApprove`; Reject requires a note before it calls `onReject(note)`. (b) `approvals.test.tsx`: with a mocked queue hook returning two requests and a spied `queryClient`, approving one **optimistically removes it immediately** (before the promise resolves), and on a rejected mutation promise the card **reappears** (rollback).

- [ ] **Step 2: Run — expect failure.**

- [ ] **Step 3: Build `RequestCard`** — pure presentational, tokens only, a small `summarize(request)` for the attendance-adjustment detail; a per-type `switch` on `request.type` so leave/OT slot in later.

- [ ] **Step 4: Build the optimistic mutation** in `useDecideRequest.ts`:

```ts
export function useQueueDecision(queueKey: readonly unknown[]) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (v: { id: string; action: 'approve' } | { id: string; action: 'reject'; note: string }) =>
      v.action === 'approve' ? api.requests.approve(v.id) : api.requests.reject(v.id, v.note),
    onMutate: async (v) => {
      await qc.cancelQueries({ queryKey: queueKey })
      const prev = qc.getQueryData<RequestRecord[]>(queueKey)
      qc.setQueryData<RequestRecord[]>(queueKey, (old) => (old ?? []).filter((r) => r.id !== v.id))
      return { prev }
    },
    onError: (_e, _v, ctx) => { if (ctx?.prev) qc.setQueryData(queueKey, ctx.prev) },
    onSettled: () => { void qc.invalidateQueries({ queryKey: queueKey }) },
  })
}
```

- [ ] **Step 5: Build the two pages** — each calls `useApprovalQueue('team'|'office')` + `useQueueDecision(keys.requests.teamApprovals()|officeApprovals())`, renders a list of `<RequestCard>`; loading `Skeleton`, empty `EmptyState` ("Nothing awaiting your approval.").

- [ ] **Step 6: Run tests + typecheck — expect pass.**

- [ ] **Step 7: Commit**

```bash
git add frontend/web/src/components/domain/RequestCard.tsx frontend/web/src/components/domain/RequestCard.test.tsx "frontend/web/src/app/(app)/team" "frontend/web/src/app/(app)/office/approvals" frontend/web/src/hooks/useDecideRequest.ts
git commit -m "Requests (web): approval queues with a shared RequestCard and optimistic decide"
```

---

## Task 8: Nav wiring, e2e, docs

**Files:**
- Modify: `frontend/web/src/components/SideNav.tsx` (+ `SideNav.test.tsx`)
- Create: `scripts/e2e-requests.sh`
- Modify: `docs/03-api.md`, `docs/06-roadmap.md`, `docs/features.md`

- [ ] **Step 1: Nav test first**

In `SideNav.test.tsx`, assert `navEntriesFor` gives every user a `me` group containing `/me/requests`; a `has_reports` user a `team` group containing `/team/approvals`; an `hr_offices` user an `office` group containing `/office/approvals`.

- [ ] **Step 2: Run — expect failure.**

- [ ] **Step 3: Wire `ROUTES`** in `SideNav.tsx`:

```ts
  me: [
    { href: '/me/attendance', label: 'Attendance' },
    { href: '/me/requests', label: 'My requests' },
  ],
  team: [{ href: '/team/approvals', label: 'Approvals' }],
  office: [
    { href: '/office/holidays', label: 'Holidays' },
    { href: '/office/schedules', label: 'Schedules' },
    { href: '/office/approvals', label: 'Approvals' },
  ],
```

- [ ] **Step 4: Run the nav test + full web suite + typecheck + build**

Run: `docker compose -f compose.dev.yml exec -T --user node web sh -c 'npm test && npm run typecheck && npm run build'`
Expected: PASS.

- [ ] **Step 5: Write `scripts/e2e-requests.sh`**

Mirror `scripts/e2e-recompute.sh` / `e2e-adjustments.sh`: log in as `employee.manila`, `POST /attendance/adjustments` (an `add` for a missing punch on a seeded incomplete day), capture the request id; assert it appears in the manager's `GET /team/approvals` and the office HR's `GET /office/approvals`; log in as `manager.manila`, `POST /requests/{id}/approve`; assert the day's summary recomputed (the added punch now pairs) and the original `attendance_logs` rows are unchanged (byte-identical count/ids for pre-existing rows). Run it live against the dev stack.

- [ ] **Step 6: Docs**

`docs/03-api.md`: document the `/requests/*` resource + `/team/approvals` + `/office/approvals`, and that `POST /attendance/adjustments` is the (type-specific) submit; mark M6a shipped. `docs/06-roadmap.md`: M6a done, M6b (leave) next, note the deferred multi-step machine. `docs/features.md`: a new "Filing and approving requests (M6a)" section (file a correction in the browser; my requests; the two approval queues; single-step; the sysadmin narrowing).

- [ ] **Step 7: Full gate**

Run backend (`... pest`) and web (`npm test && typecheck && build`) suites; confirm green.

- [ ] **Step 8: Commit**

```bash
git add frontend/web/src/components/SideNav.tsx frontend/web/src/components/SideNav.test.tsx scripts/e2e-requests.sh docs/03-api.md docs/06-roadmap.md docs/features.md
git commit -m "Requests: nav wiring, e2e-requests.sh, and docs; M6a complete"
```

---

## Self-review — spec coverage

- Per-type effect dispatch → **Task 1**. Two scoped queues → **Task 2**. `/requests/*` generalization, submit stays type-specific → **Task 3**. Frontend data layer → **Task 4**. File-a-correction vertical → **Task 5** (form) + **Task 6** (my requests). Queues + `<RequestCard>` + optimistic-confined-to-queue → **Task 7**. Nav + e2e + docs → **Task 8**.
- Single-step / keep `pending` / no migration / no `type` CHECK change → honored (no schema task).
- 404-not-403, append-only ledger, recompute-on-approval → unchanged (Task 1 is behavior-preserving; Task 3 keeps every M3.6 assertion, moved to new URLs).
- Sysadmin gets no queue → falls out of `ApprovalQueues` (no reports, no HR offices) — asserted in Task 2's cases; documented in Task 8.
- Optimistic updates confined to the queue → Task 7's `useQueueDecision`; `/me/requests` uses the plain cancel (Task 6).
```
