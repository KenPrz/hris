# M4c — Pay Rules (design)

> The third and final slice of the configuration spine (M4a holidays, M4b schedules). Makes
> the DOLE pay multipliers **admin-editable, effective-dated data** instead of hardcoded
> constants, floored by law. M5's compute engine is the first reader and stamps each
> `daily_attendance_summary` with the `rule_version_id` that produced it. **M4c computes no
> pay.**

## Why this exists

Today the pay multipliers — how much a worked hour is worth on a holiday, a rest day, in
overtime, at night — are hardcoded in `app/Domain/Pay/PayMultiplier.php` (the M1 DOLE
matrix). Changing a rate needs an engineer and a deploy. But:

1. **The Labor Code rates are minimums; a company may pay above them** (a 250% holiday as a
   perk), and DOLE reissues advisories. That is a runtime business decision, not a deploy.
2. **Rates change and history must be preserved** — a 2026 shift stays paid at 2026's rate
   after 2027's is set. Rates must be effective-dated rows, like `employment_records` and
   M4b's assignments.

`docs/04-backend-conventions.md` already forward-declares this: pay multipliers live in
`pay_rules` (effective-dated rows), the statutory floors live in `config/hris.php` ("the
Labor Code sets them, not an admin"), and each `daily_attendance_summary` records a
`rule_version_id`.

## Decisions locked in brainstorming

1. **Company-wide, System-Admin-only.** One effective-dated matrix for the whole company;
   `pay_rules` carries no `office_id`. Edited only by a System Admin (hence the
   `/admin/pay-rules` path). A new sysadmin gate, **not** `OfficeScope`.
2. **Immutable effective-dated versions.** Each pay rule is a complete, immutable snapshot
   of the whole matrix, effective from a date. A rate change is a *new* version, never an
   edit. The version with the greatest `effective_from ≤ worked-date` wins. `rule_version_id`
   on a summary therefore resolves to the exact rates used, forever. Append-only, mirroring
   punches / `employment_records` / M4b assignments.
3. **The statutory floor lives in `config/hris.php`** (honoring `docs/04`); a write is
   **refused, not warned**, if any configured multiplier is below its floor.
4. **`403`, not `404`, for a non-sysadmin.** The 404-not-403 discipline hides *which scoped
   resources exist* (enumeration); `pay_rules` is a company singleton with nothing to
   enumerate, so an unauthorized caller gets an honest `403 forbidden`.

## Global constraints (inherited, non-negotiable)

- Laravel 13 / PHP 8.5 / PostgreSQL 18 backend; Next 16 / React 19 / TS / Tailwind v4 frontend.
- `declare(strict_types=1);` every PHP file in `app/`+`tests/`. Actions final, invokable
  controllers, actions own their transaction and never touch HTTP; Domain is HTTP-agnostic
  and framework-agnostic.
- **String columns + PHP backed enums + CHECK; never native PG enums.** `DayType` (existing)
  is the enum here.
- **Integer basis points only, never a float** (the `BasisPoints` rule). 100% = `10000` bp.
- **Calendar dates on the wire are `YYYY-MM-DD` strings.**
- uuid v7 PKs, uuid FKs, uuid activity_log morph. Every write activity-logged.
- **Never `env()` outside `config/`** — the floors are read via `config('hris.pay_floors')`,
  never `env()`.
- Success `{data:…}` / error `{error:…}`, closed envelope. Domain refusals are
  `DomainException` subclasses.
- Frontend: token-only styling (no raw hex outside `carbon.css`); `font: var(--t-*)` +
  `--ls-*`; `'use client'`; `import type`; no `enum`.
- Tests run against **real PostgreSQL, never SQLite.**
- Commit messages carry **no attribution trailers.**

---

## Section 1 — Data model

A version is a complete, immutable snapshot, structured like `PayMultiplier` (`[day_type]`
rows + a few scalars).

### `pay_rules` (the effective-dated version — company-wide, no office_id)
- `id` uuid v7 PK, `effective_from` date **unique**, `overtime_ordinary_bp`,
  `overtime_premium_bp`, `night_diff_bp` (integer bp), `note` text nullable, `created_by`
  uuid FK (nullOnDelete), timestamps.
- CHECK: the three scalar bp columns `>= 0`.
- `LogsActivity` (log name `pay_rule`, uuid subject). **Immutable — no update path.**

### `pay_rule_day_rates` (the per-day-type rows — child of a version)
- `id` uuid v7 PK, `pay_rule_id` uuid FK (cascade on delete), `day_type` (`DayType` text +
  CHECK over the 5 cases), `worked_bp`, `worked_rest_bp`, `unworked_bp` (integer bp),
  timestamps.
- `unique(pay_rule_id, day_type)`.
- CHECK: the three bp columns `>= 0`.
- The create action validates **all 5 DayTypes present exactly once** (completeness, like
  M4b's 7 weekdays).

A version holds the full matrix: `worked_bp`/`worked_rest_bp` = `WORKED_BASE[day_type][is_rest]`
(10 values), `unworked_bp` = `UNWORKED[day_type]` (5), plus the 3 scalars — so M5 reads only
`pay_rules`, never the hardcoded constants. Resolution: greatest `effective_from ≤ worked-date`.

---

## Section 2 — The statutory floor

The DOLE minimum matrix moves into `config/hris.php` as `pay_floors` — the same values
`PayMultiplier` encodes today:

```php
'pay_floors' => [
    'worked' => [ // [day_type => [not_rest_bp, rest_bp]]
        'ordinary' => [10000, 13000],
        'special_working' => [10000, 13000],
        'special_non_working' => [13000, 15000],
        'regular_holiday' => [20000, 26000],
        'double_regular_holiday' => [30000, 39000],
    ],
    'unworked' => [ // [day_type => bp]
        'ordinary' => 0, 'special_working' => 0, 'special_non_working' => 0,
        'regular_holiday' => 10000, 'double_regular_holiday' => 20000,
    ],
    'overtime_ordinary' => 12500,
    'overtime_premium' => 13000,
    'night_diff' => 11000,
],
```

A pure Domain comparator `App\Domain\Pay\StatutoryFloor` takes a proposed version's values +
the floor matrix and returns the list of violating cells (each: which multiplier, the
proposed bp, the floor bp). It is framework-agnostic — it receives the floor matrix, never
reads config itself. The `CreatePayRule` action reads `config('hris.pay_floors')` at the
boundary and hands it in; a non-empty violation list throws `PayRateBelowFloor`.

`PayMultiplier`'s constants are **untouched this milestone** — they still back M1's compute
unit tests. M5 reconciles the two (compute reading `config` floors + `pay_rules` rates); a
guard test asserts `config('hris.pay_floors')` matches the DOLE minimums so they cannot
drift below law. This duplication is a deliberate, recorded seam, not a defect.

---

## Section 3 — Authority & endpoints

**Authority:** `is_system_admin` only, enforced by a policy/gate. A non-sysadmin gets `403
forbidden` (nothing to enumerate — see decision 4). All endpoints under `/admin/pay-rules`.

```
GET    /admin/pay-rules              # list versions, effective_from desc, each with its 5 day_rates
POST   /admin/pay-rules              # { effective_from, overtime_ordinary_bp, overtime_premium_bp,
                                      #   night_diff_bp, day_rates:[5 × {day_type, worked_bp,
                                      #   worked_rest_bp, unworked_bp}] } → 201
  → 400 validation_failed            # bad shape, or day_rates ≠ the 5 DayTypes exactly
  → 403 forbidden                    # not a System Admin
  → 409 conflict                     # a version already exists on that effective_from
  → 422 pay_rate_below_floor         # a cell is below its statutory floor; details lists each violation
GET    /admin/pay-rules/{payRule}    # one version + its day_rates → 200; 403 non-admin; 404 unknown id
DELETE /admin/pay-rules/{payRule}    # 204; 403 non-admin
```

**No `PATCH`** — versions are immutable. `POST` creates the version row + 5 day-rate rows in
one transaction, floor-validated atomically. `PayRuleResource`: `{id, effective_from,
overtime_ordinary_bp, overtime_premium_bp, night_diff_bp, note, day_rates:[{day_type,
worked_bp, worked_rest_bp, unworked_bp} ordered by day_type]}`.

Domain exceptions mirror M4a/M4b shapes:
- `PayRuleExists` — `409 conflict` (`pay_rule_exists`), duplicate `effective_from`; caught
  from the unique violation via a lock-then-check (mirror `CreateHoliday`), details
  `{effective_from}`.
- `PayRateBelowFloor` — `422` (`pay_rate_below_floor`), `details.violations` = the offending
  cells, each `{ multiplier, proposed_bp, floor_bp }`.

`DELETE` is unrestricted in M4c (nothing reads `pay_rules` yet). When M5 adds
`daily_attendance_summaries.rule_version_id` as an FK, deleting a *consumed* version is
refused there — recorded as the forward-guard, not built now.

---

## Section 4 — The `/admin/pay-rules` screen

The first Admin-scope screen. `navEntriesFor` already pushes the Admin group for
`is_system_admin`; M4c populates `ROUTES.admin = [{ href: '/admin/pay-rules', label: 'Pay
rules' }]` (exactly as M4b populated Office). A **matrix editor**, not a calendar:

- The **currently-effective version** as a read-only matrix (5 DayType rows × worked /
  worked-on-rest / unworked, + the 3 scalars), values shown as percentages via a `bp ↔ %`
  helper (`10000 bp = 100%`, a browser mirror in the `money.ts`/`duration.ts` style).
- A **version-history** list (effective dates, newest first).
- A **"New version"** form: an effective date + editable cells for the whole matrix, a
  **client-side floor hint** per cell (shows the statutory minimum, flags a below-floor
  entry before submit), and **server-side enforcement** — a `422 pay_rate_below_floor`
  surfaces each violating cell inline; a `409` surfaces "a version already exists on that
  date."

Frontend surface: `keys.payRules`, `api.payRules.{list,create,get,delete}`, a `usePayRules`
hook + create/delete mutations, a `lib/basisPoints.ts` (`bpToPercent`/`percentToBp`), the
screen. Nav shows only for `is_system_admin`.

---

## Section 5 — Testing

**Backend (real Postgres):**
- **Floor validation, table-driven:** each cell *below* floor → `422` naming that cell in
  `details.violations`; *at* floor → accepted; *above* → accepted. Cover every multiplier
  (the 10 worked, 5 unworked, 3 scalars).
- **Completeness:** `day_rates` not exactly the 5 DayTypes → `400`.
- **Duplicate `effective_from` → `409` `pay_rule_exists`** (lock-then-check, not a raw 500).
- **Immutability:** no PATCH route resolves (assert the route is absent / 405).
- **Authority:** a non-sysadmin (HR admin, plain employee) → `403` on every endpoint; a
  sysadmin → success.
- **Schema:** the `>= 0` CHECKs, the `unique(effective_from)`, `unique(pay_rule_id, day_type)`,
  cascade delete of day-rates. Live-constraint style (mirror `HolidaySchemaTest`).
- **Floor-config guard:** `config('hris.pay_floors')` equals the DOLE statutory minimums
  (so a careless config edit can't drop the floor below law).
- **`LogsActivity`:** create logs the version (uuid subject, causer).

**Frontend:** the matrix renders the effective version; the new-version form shows floor
hints and surfaces a `422`'s violating cells; the `bp↔%` helper; the Admin/Pay-rules nav
shows for a sysadmin and is absent for a non-sysadmin.

**`scripts/e2e-pay-rules.sh`** (mirror `e2e-holidays.sh`), run live: a sysadmin
(`sysadmin@hris.test`) creates a floor-valid version, lists it, a below-floor write `422`s,
a duplicate date `409`s, a non-admin (`hr.manila@hris.test`) `403`s, and the activity log
names the causer + uuid subject.

**Seeder:** seed one default `pay_rules` version = the statutory floor matrix, effective an
early date, so M5 has a version to read and the screen is non-empty on `make dev`.

---

## Done when

A System Admin creates a 2026 version with regular-holiday-worked at 250% (above the 200%
floor) → accepted and logged; the same at 150% → refused `422 pay_rate_below_floor` naming
that cell; a duplicate `effective_from` → `409`; a non-sysadmin → `403`; the version is
immutable (no edit path). **No pay is computed** — the version is the input M5 reads on a
worked date to stamp `rule_version_id`. `RecomputeRange` remains M5's. Full suite green
(backend + arch + frontend), `e2e-pay-rules.sh` passes live. **M4 (the configuration spine)
is complete.**
