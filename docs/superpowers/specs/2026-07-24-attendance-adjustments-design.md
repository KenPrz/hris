# Attendance Adjustments & the Request/Approval Subsystem — Design

Date: 2026-07-24

The first request/approval feature: an employee files an attendance adjustment to correct a
missed or wrong punch, and their manager or an HR admin approves it. On approval, the
correction is applied to the append-only ledger — a new punch, or an annulment that
supersedes a wrong one, or both.

This milestone also builds the **shared request/approval subsystem** — the `requests` spine,
the state machine, the approval-authority rule, and the attachment plumbing — that **leave**
and **overtime** will reuse. Only the attendance-adjustment type is wired now; the others are
additive later.

## Where this fits

Four milestones precede it: M0 skeleton, M1 pay primitives, M2 schema/auth/RBAC, M3
timekeeping ingestion. M3 built the append-only `attendance_logs` ledger and `RecordPunch`,
its single writer. M3 deliberately deferred self-correction of one's own punch to a request
flow — this is that flow. It sits after M3 and before the configuration spine and the compute
engine; it does not depend on them.

## Decisions settled in brainstorming

| Decision | Choice | Why |
| --- | --- | --- |
| Approval shape | Single approval — manager OR HR | Matches "report to or HR approves"; a missed-punch fix doesn't need two sign-offs |
| Correction scope | Add, void, or amend a punch | The user wants full correction power, not just add |
| Void/amend vs append-only | Supersede via annulment records | The raw ledger is never mutated — M3's hard invariant is preserved |
| Attachment storage | RustFS (S3-compatible), via `spatie/laravel-medialibrary` | Object storage from the start; Media Library handles the file/model plumbing |
| Draft state | None — direct submit | You "apply"; a save-without-submit state is YAGNI until asked for |

## 1. The shared request subsystem

The spine leave and overtime plug into later. Built generic; only `attendance_adjustment` is
wired now.

```
requests
  id             uuid pk (uuidv7)
  type           text CHECK (type IN ('attendance_adjustment'))   -- widens per added type
  employee_id    uuid → employees          -- the requester
  state          text CHECK (state IN ('pending','approved','rejected','cancelled'))
  note           text NOT NULL             -- required
  decided_by     uuid nullable → users     -- the approver / rejecter
  decided_at     timestamptz nullable
  decision_note  text nullable             -- required on rejection
  created_at, updated_at
```

**State machine** — minimal, no `draft`:

```
pending ──approve──▶ approved    (terminal)
   │
   ├────reject────▶ rejected     (terminal; decision_note required)
   │
   └────cancel────▶ cancelled    (terminal; requester only, while pending)
```

The foundation spec's two-step `manager_approved → hr_approved` chain is **not** built here —
attendance adjustment is single-approval. The `requires_hr_step` flag it described becomes
relevant when leave/OT arrive; the state machine here is the one-step case, and a second step
is an additive change then, not a reshape.

**Per-type detail is a 1:1 table**, not a jsonb blob — typed and queryable (the POS discipline):

```
attendance_adjustment_details
  request_id     uuid pk → requests (1:1, cascade)
  operation      text CHECK (operation IN ('add','void','amend'))
  target_log_id  uuid nullable → attendance_logs   -- the punch voided/amended; null for 'add'
  direction      text nullable CHECK (direction IN ('in','out'))  -- add/amend: corrected punch
  punched_at     timestamptz nullable                             -- add/amend: corrected time, UTC
```

When leave and overtime come, each gets its own detail table on the same `requests` spine,
the same state machine, and the same approval queue.

## 2. The three operations and the annulment model

`attendance_logs` stays append-only — the raw punch is never edited or deleted (M3's
invariant, enforced by an arch guard). Corrections *supersede* rather than mutate.

**A new append-only table records a void:**

```
attendance_annulments
  id                 uuid pk (uuidv7)
  attendance_log_id  uuid → attendance_logs   -- the punch being superseded
  request_id         uuid → requests          -- the approved adjustment that did it
  created_at         timestamptz
  unique (attendance_log_id)                   -- a punch is annulled at most once
```

The original punch row is never touched. The **effective ledger** the compute engine (M5)
reads is `attendance_logs` whose `id` has no annulment. An added punch *is* an
`attendance_logs` row, so it needs nothing special to be included.

**What each operation does on approval, in one transaction:**

| Operation | Effect |
| --- | --- |
| `add` | `RecordPunch` writes the missed punch — new `PunchSource::Adjustment`, `recorded_by` = the approver, `punched_at` from the detail |
| `void` | one `attendance_annulments` row pointing at `target_log_id` |
| `amend` | annul `target_log_id` **and** `RecordPunch` the corrected punch — a void-plus-add pair |

**Two single-writer disciplines**, mirroring `RecordPunch`:

- `attendance_annulments` is written by exactly one action, arch-guarded, and append-only.
- The adjustment-added punch still goes through `RecordPunch` — the ledger keeps its one writer.

**Validation at approval time, not just submission** — the world moves between filing and
approving. For `void`/`amend`, the target punch must still exist, belong to the requester, and
not already be annulled. The `unique(attendance_log_id)` backstops a double-void race under a
row lock.

**One deliberate simplification:** an adjustment-added punch snapshots the employee's
**current** office (consistent with M3's manual entry). If the employee transferred between the
missed punch's date and the adjustment, the historical office would be more correct — M2's
`EmploymentResolver` could supply "the office on date D." Current-office is treated as good
enough for v1 (adjustments are normally filed days after the miss, before any transfer);
historical-office resolution is a future refinement, not built before the compute engine needs
it.

## 3. Approval authority and transitions

**Submitting.** An employee files an adjustment for **their own** attendance:
`POST /attendance/adjustments` with the operation, target/direction/time, a required note, and
an optional file. State starts `pending`. A user with no employee record → `not_an_employee`
(422).

**Approval authority — M2's scope, minus self.** An approver may act on a request iff the
**requester is in their `EmployeeScope`** *and* they are not the requester. That single rule
yields exactly the intended set:

- the requester's **manager** (their `current_reports_to_id`) — reports are in a manager's scope,
- an **HR admin** over the requester's office,
- a **system admin**,
- but **never the requester** (no self-approval — the manual-punch separation of duties).

One approval finalizes it. This is the identical boundary the app already enforces, so there is
nothing new to reason about; an out-of-scope request is `404`, never leaking that it exists.

**Transitions** — each its own invokable action, one route:

```
POST /attendance/adjustments/{request}/approve   authorized approver → run the effect, state=approved
POST /attendance/adjustments/{request}/reject     authorized approver → state=rejected, decision_note required
POST /attendance/adjustments/{request}/cancel     requester, only while pending → state=cancelled
```

Approve/reject/cancel are **type-agnostic** on the shared `requests` spine; approval dispatches
to the type's effect (`ApplyAttendanceAdjustment` does the `RecordPunch`/annulment work). Leave
and overtime register their own effect later without touching the state machine or the queue.

**Concurrency.** Approve locks the request row (`lockForUpdate`) and refuses if it is no longer
`pending` — two approvers, or an approve racing a cancel, resolve to one winner, exactly like
M2's employment-change and cutoff locking.

**Reads:**

```
GET /attendance/adjustments             my own requests (as requester)
GET /attendance/adjustments/pending      my approval queue (pending requests I may act on)
GET /attendance/adjustments/{request}     one request (requester or authorized approver; else 404)
GET /attendance/adjustments/{request}/attachment   scoped media download
```

## 4. Attachments: RustFS + Media Library

The one piece of new infrastructure.

**RustFS in the dev stack.** A `rustfs` service in `compose.dev.yml` — image `rustfs/rustfs`,
S3 API on `:9000`, console on `:9001`, `RUSTFS_ACCESS_KEY`/`RUSTFS_SECRET_KEY`, a data volume
writable by uid 10001 — plus a one-shot bucket-create step so the app has a bucket to write to.
Host ports offset from the sibling POS repo, as elsewhere.

**Backend wiring.**

- `composer require spatie/laravel-medialibrary league/flysystem-aws-s3-v3`.
- An `attachments` disk in `config/filesystems.php` using the **`s3` driver** pointed at RustFS:
  `endpoint` = the rustfs service, `use_path_style_endpoint => true`, bucket + key/secret + a
  dummy region — env-driven, so production swaps to real S3 with no code change.
- Publish Media Library's migration and **switch `media.model_id` to a uuid morph** — the exact
  M2 cascade gotcha (a bigint morph key against our uuidv7 models throws on attach).
- The `Request` model → `HasMedia` + `InteractsWithMedia`, a **single-file** `attachment`
  collection on the `attachments` disk, `acceptsMimeTypes(['application/pdf','image/jpeg','image/png'])`,
  max ~10 MB.

**Upload and download.**

- Submit accepts an optional `attachment` file; the FormRequest validates type and size; on
  store, `->addMediaFromRequest('attachment')->toMediaCollection('attachment')`.
- Download is a **scoped endpoint** — checks the viewer may see the request (requester or
  authorized approver, else 404), then streams the media. RustFS stays private; no direct object
  URLs.

## 5. Testing and done-when

Real Postgres, a faked disk for flow tests, one live RustFS round-trip in the e2e.

- **The three effects:** `add` → a new `attendance_logs` row (`source: adjustment`,
  `recorded_by` = approver); `void` → an `attendance_annulments` row, original punch untouched;
  `amend` → both. A test proving the effective ledger (logs minus annulments) reflects each.
- **Approval authority:** a manager approves their report's request; an HR admin approves their
  office's; a system admin any; the requester cannot approve their own; an out-of-scope approver
  → 404.
- **State machine:** `pending → approved/rejected/cancelled`; reject requires a `decision_note`;
  cancel is requester-only and pending-only; a decided request refuses further transitions.
- **Approval-time validation:** a `void`/`amend` whose target was deleted, isn't the requester's,
  or is already annulled → refused at approval.
- **Concurrency:** two approvers on one request → exactly one wins (two real connections);
  approve-vs-cancel likewise.
- **Attachment:** upload on submit; scoped download (unauthorized viewer → 404, never the file);
  mime/size rejection; Media Library writing to the RustFS-backed disk.
- **Arch guards:** `attendance_annulments` has one writer and is append-only; adjustment-added
  punches route through `RecordPunch`.
- `scripts/e2e-adjustments.sh` against the live stack (real RustFS): file an `add` with an
  attachment → manager approves → the punch appears in the ledger and the attachment downloads;
  file a `void` → HR approves → the punch drops from the effective read while its raw row
  remains; attempt self-approval → refused.

**Done when:** an employee files a missed-punch adjustment with a note and an attachment; their
manager or HR approves; the punch appears in the ledger via `RecordPunch` and the effective read
reflects it; a `void` adjustment, approved, removes a punch from the effective ledger while the
raw row stays; self-approval is refused; and the attachment downloads only for those who may see
the request.

## Non-goals

Leave and overtime request types (this milestone builds the shared spine; they are additive
later). The two-step manager→HR chain (single approval now). Historical-office resolution for a
back-dated added punch. Un-annulling a punch (a mistaken void is corrected by a new adjustment,
not an undo). Any pay computation — the effective ledger is defined here; the compute engine
consumes it in M5.

## Open questions

None blocking. One to note for M5: the compute engine must read the **effective** ledger
(`attendance_logs` minus `attendance_annulments`), not the raw table — this is the single most
important thing for the engine to get right about attendance, and it is recorded here so it is
not rediscovered mid-engine.
