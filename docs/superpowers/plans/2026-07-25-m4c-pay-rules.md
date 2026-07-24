# M4c — Pay Rules Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the DOLE pay multipliers admin-editable, effective-dated, immutable `pay_rules` versions floored by `config('hris.pay_floors')` — the rates M5's compute engine will read and stamp onto each daily summary as `rule_version_id`. Computes no pay.

**Architecture:** A `pay_rules` version (company-wide, effective-dated, immutable) + `pay_rule_day_rates` child rows (one per `DayType`). A pure `StatutoryFloor` Domain comparator refuses any write below the code-owned floor. Sysadmin-only endpoints under `/admin/pay-rules` (a `403`-for-non-admin gate, NOT the office 404 discipline — a company singleton has nothing to enumerate). A `/admin/pay-rules` matrix-editor screen. Completes M4.

**Tech Stack:** Laravel 13 / PHP 8.5 / PostgreSQL 18 · Next 16 / React 19 / TS / Tailwind v4 · Pest (real Postgres) · Vitest.

**Mirror M4a/M4b (merged on `main`) for boilerplate**, changing only the stated delta:
- Model + LogsActivity: `backend/app/Models/Holiday.php`, `ShiftTemplate.php`
- Live-constraint schema test: `backend/tests/Feature/Schema/HolidaySchemaTest.php`, `ShiftTemplateSchemaTest.php`
- Duplicate-as-clean-domain-error (lock-then-check): `backend/app/Actions/Holidays/CreateHoliday.php`, `backend/app/Exceptions/Domain/HolidayExists.php`, `EmploymentRecordExists.php`
- Resource: `backend/app/Http/Resources/HolidayResource.php`, `ShiftTemplateResource.php`
- Invokable controller + action: `backend/app/Http/Controllers/Office/Holidays/*.php`
- The pay primitives this floors: `backend/app/Domain/Pay/PayMultiplier.php` (the statutory matrix), `BasisPoints.php`, `backend/app/Domain/Pay/DayType.php`
- Frontend: `frontend/web/src/lib/keys.ts`, `lib/api.ts`, `hooks/useHolidays.ts`, `components/SideNav.tsx` (`ROUTES.admin`, `navEntriesFor` already pushes `admin` for `is_system_admin`), the `/office/holidays/page.tsx` scaffold, `lib/money.ts`/`duration.ts` (helper style).

## Global Constraints

- `declare(strict_types=1);` every PHP file in `app/`+`tests/`. Actions final, take an Input DTO, own their transaction, never touch HTTP. Controllers final + invokable. Domain HTTP- and framework-agnostic.
- **String columns + PHP backed enums + CHECK; never native PG enums.** `DayType` is the enum (`ordinary`, `special_working`, `special_non_working`, `regular_holiday`, `double_regular_holiday`).
- **Integer basis points only, never a float.** 100% = `10000` bp. Non-negative.
- **Calendar dates on the wire are `YYYY-MM-DD` strings.**
- uuid v7 PKs (`->default(DB::raw('uuidv7()'))`), uuid FKs; models use `HasUuids` + `newUniqueId()`→`Str::uuid7()->toString()` + `uniqueIds()`. activity_log morph uuid.
- **Never `env()` outside `config/`.** Floors are read via `config('hris.pay_floors')`, and only at the action boundary — the Domain comparator receives the floor matrix as an argument.
- Success `{data:…}` / error `{error:…}`, closed envelope. Domain refusals are `DomainException` subclasses.
- **Authority is `is_system_admin`, and a non-admin gets `403 forbidden`** (a company singleton — nothing to enumerate, so NOT the 404 discipline). Reads (`show`) still `404` an unknown id for an admin.
- Frontend: token-only styling (no raw hex outside `carbon.css`); `font: var(--t-*)` + `--ls-*` (except `--t-card-title`); `'use client'`; `import type`; no `enum`; no unused locals.
- Tests run against **real PostgreSQL, never SQLite.** Two suites: `./vendor/bin/pest` and `./vendor/bin/pest --testsuite=Arch`.
- **Commit messages carry no attribution trailers.**

---

### Task 1: `config/hris.php` pay-floor matrix + drift guard

**Files:**
- Modify: `backend/config/hris.php` (add `pay_floors`)
- Test: `backend/tests/Feature/Pay/PayFloorConfigTest.php`

**Interfaces:**
- Produces: `config('hris.pay_floors')` — `['worked' => [dayType => [notRestBp, restBp]], 'unworked' => [dayType => bp], 'overtime_ordinary' => bp, 'overtime_premium' => bp, 'night_diff' => bp]`.

- [ ] **Step 1: Failing test** asserting the config equals the DOLE statutory minimums — the same values `PayMultiplier` encodes, so the floor cannot silently drift below law.

```php
<?php
declare(strict_types=1);

use App\Domain\Pay\DayType;

it('pins the statutory pay floors to the Labor Code minimums', function (): void {
    $floors = config('hris.pay_floors');

    expect($floors['worked'][DayType::Ordinary->value])->toBe([10000, 13000])
        ->and($floors['worked'][DayType::SpecialWorking->value])->toBe([10000, 13000])
        ->and($floors['worked'][DayType::SpecialNonWorking->value])->toBe([13000, 15000])
        ->and($floors['worked'][DayType::RegularHoliday->value])->toBe([20000, 26000])
        ->and($floors['worked'][DayType::DoubleRegularHoliday->value])->toBe([30000, 39000])
        ->and($floors['unworked'][DayType::Ordinary->value])->toBe(0)
        ->and($floors['unworked'][DayType::SpecialWorking->value])->toBe(0)
        ->and($floors['unworked'][DayType::SpecialNonWorking->value])->toBe(0)
        ->and($floors['unworked'][DayType::RegularHoliday->value])->toBe(10000)
        ->and($floors['unworked'][DayType::DoubleRegularHoliday->value])->toBe(20000)
        ->and($floors['overtime_ordinary'])->toBe(12500)
        ->and($floors['overtime_premium'])->toBe(13000)
        ->and($floors['night_diff'])->toBe(11000);
});
```

- [ ] **Step 2: Run it, expect failure** (`config('hris.pay_floors')` is null): `./vendor/bin/pest tests/Feature/Pay/PayFloorConfigTest.php`

- [ ] **Step 3: Add `pay_floors` to `config/hris.php`.** Append inside the returned array (do not use `env()` — these are law, hardcoded literals). Comment that they mirror `PayMultiplier`'s constants and that M5 will make `PayMultiplier` read them.

```php
// The DOLE statutory pay-rate FLOORS (Arts. 86-94), in integer basis points. The Labor
// Code sets these, not an admin; a pay_rules write is refused below any of them. These are
// the same minimums PayMultiplier encodes today — M5 reconciles PayMultiplier to read these.
'pay_floors' => [
    'worked' => [
        'ordinary' => [10000, 13000],
        'special_working' => [10000, 13000],
        'special_non_working' => [13000, 15000],
        'regular_holiday' => [20000, 26000],
        'double_regular_holiday' => [30000, 39000],
    ],
    'unworked' => [
        'ordinary' => 0,
        'special_working' => 0,
        'special_non_working' => 0,
        'regular_holiday' => 10000,
        'double_regular_holiday' => 20000,
    ],
    'overtime_ordinary' => 12500,
    'overtime_premium' => 13000,
    'night_diff' => 11000,
],
```

- [ ] **Step 4: Run test → PASS. Step 5: Commit.**
```bash
git add backend/config/hris.php backend/tests/Feature/Pay/PayFloorConfigTest.php
git commit -m "Pay rules: statutory floor matrix in config, pinned to the Labor Code minimums"
```

---

### Task 2: `pay_rules` + `pay_rule_day_rates` schema & models

**Files:**
- Create: `backend/database/migrations/2026_07_29_000001_create_pay_rules_table.php`, `backend/app/Models/PayRule.php`, `backend/app/Models/PayRuleDayRate.php`
- Test: `backend/tests/Feature/Schema/PayRuleSchemaTest.php`

**Interfaces:**
- Produces: `PayRule` (hasMany `dayRates`; effective_from date; 3 scalar bp columns; LogsActivity `pay_rule`). `PayRuleDayRate` (belongsTo payRule; casts `day_type`→`DayType`).

- [ ] **Step 1: Failing schema test** (mirror `HolidaySchemaTest`/`ShiftTemplateSchemaTest` live-constraint style): a version persists with 5 day-rate rows; `unique(effective_from)` rejects a duplicate; `unique(pay_rule_id, day_type)` rejects a duplicate day-type; negative bp rejected (the `>= 0` CHECKs on both tables); day-rate rows cascade on version delete; `day_type` outside the 5 enum values rejected.

```php
<?php
declare(strict_types=1);

use App\Domain\Pay\DayType;
use App\Models\PayRule;
use App\Models\PayRuleDayRate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function payRule(string $effectiveFrom = '2026-01-01'): PayRule {
    return PayRule::create([
        'effective_from' => $effectiveFrom, 'overtime_ordinary_bp' => 12500,
        'overtime_premium_bp' => 13000, 'night_diff_bp' => 11000,
    ]);
}

it('stores a version with its day rates and casts day_type', function (): void {
    $rule = payRule();
    PayRuleDayRate::create(['pay_rule_id' => $rule->id, 'day_type' => DayType::RegularHoliday,
        'worked_bp' => 20000, 'worked_rest_bp' => 26000, 'unworked_bp' => 10000]);
    $rate = $rule->dayRates()->sole();
    expect($rate->day_type)->toBe(DayType::RegularHoliday)->and($rate->worked_bp)->toBe(20000);
});

it('rejects a second version on the same effective_from', function (): void {
    payRule('2026-01-01');
    expect(fn () => payRule('2026-01-01'))->toThrow(QueryException::class);
});

it('rejects a duplicate day_type within a version', function (): void {
    $rule = payRule();
    PayRuleDayRate::create(['pay_rule_id' => $rule->id, 'day_type' => DayType::Ordinary,
        'worked_bp' => 10000, 'worked_rest_bp' => 13000, 'unworked_bp' => 0]);
    expect(fn () => PayRuleDayRate::create(['pay_rule_id' => $rule->id, 'day_type' => DayType::Ordinary,
        'worked_bp' => 10000, 'worked_rest_bp' => 13000, 'unworked_bp' => 0]))->toThrow(QueryException::class);
});

it('rejects negative basis points', function (): void {
    expect(fn () => DB::table('pay_rules')->insert(['id' => Str::uuid7()->toString(),
        'effective_from' => '2026-02-01', 'overtime_ordinary_bp' => -1, 'overtime_premium_bp' => 13000,
        'night_diff_bp' => 11000, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

it('cascades day-rate deletion when a version is deleted', function (): void {
    $rule = payRule();
    PayRuleDayRate::create(['pay_rule_id' => $rule->id, 'day_type' => DayType::Ordinary,
        'worked_bp' => 10000, 'worked_rest_bp' => 13000, 'unworked_bp' => 0]);
    $rule->delete();
    expect(PayRuleDayRate::count())->toBe(0);
});
```

- [ ] **Step 2: Run, expect failure.**

- [ ] **Step 3: Migration** `2026_07_29_000001_create_pay_rules_table.php`:

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pay_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->date('effective_from')->unique();
            $table->integer('overtime_ordinary_bp');
            $table->integer('overtime_premium_bp');
            $table->integer('night_diff_bp');
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE pay_rules ADD CONSTRAINT pay_rules_bp_nonneg_check CHECK (overtime_ordinary_bp >= 0 AND overtime_premium_bp >= 0 AND night_diff_bp >= 0)');

        Schema::create('pay_rule_day_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('pay_rule_id')->constrained()->cascadeOnDelete();
            $table->text('day_type');
            $table->integer('worked_bp');
            $table->integer('worked_rest_bp');
            $table->integer('unworked_bp');
            $table->timestamps();
            $table->unique(['pay_rule_id', 'day_type']);
        });
        DB::statement("ALTER TABLE pay_rule_day_rates ADD CONSTRAINT pay_rule_day_rates_day_type_check CHECK (day_type IN ('ordinary','special_working','special_non_working','regular_holiday','double_regular_holiday'))");
        DB::statement('ALTER TABLE pay_rule_day_rates ADD CONSTRAINT pay_rule_day_rates_bp_nonneg_check CHECK (worked_bp >= 0 AND worked_rest_bp >= 0 AND unworked_bp >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_rule_day_rates');
        Schema::dropIfExists('pay_rules');
    }
};
```

- [ ] **Step 4: Models.** `PayRule.php` — `HasUuids` + `LogsActivity` (logOnly `effective_from`/`overtime_ordinary_bp`/`overtime_premium_bp`/`night_diff_bp`/`note`, logOnlyDirty, `useLogName('pay_rule')`), cast `effective_from`→date + the 3 bp→integer, `dayRates(): HasMany`, `newUniqueId`/`uniqueIds`. `PayRuleDayRate.php` — `HasUuids`, cast `day_type`→`DayType::class` + the 3 bp→integer, `payRule(): BelongsTo`, no activity log (rides its parent). Mirror `ShiftTemplate.php`/`ShiftTemplateDay.php`.

- [ ] **Step 5: Schema test → PASS. Step 6: Arch suite. Step 7: Commit.**
```bash
git commit -m "Pay rules: pay_rules versions + per-day-type rates, unique effective_from, nonneg bp"
```

---

### Task 3: `StatutoryFloor` comparator + floor/duplicate domain exceptions

**Files:**
- Create: `backend/app/Domain/Pay/StatutoryFloor.php`, `backend/app/Domain/Pay/FloorViolation.php`, `backend/app/Exceptions/Domain/PayRateBelowFloor.php`, `backend/app/Exceptions/Domain/PayRuleExists.php`
- Test: `backend/tests/Unit/Domain/Pay/StatutoryFloorTest.php`

**Interfaces:**
- Produces: `StatutoryFloor::violations(array $proposed, array $floors): array` returning a list of `FloorViolation`. `$proposed` shape: `['worked' => [dayType => [notRestBp, restBp]], 'unworked' => [dayType => bp], 'overtime_ordinary' => bp, 'overtime_premium' => bp, 'night_diff' => bp]` (same shape as `config('hris.pay_floors')`). `FloorViolation` public readonly: `string $multiplier`, `int $proposedBp`, `int $floorBp`. `PayRateBelowFloor` (422 `pay_rate_below_floor`, `details.violations`), `PayRuleExists` (409 `pay_rule_exists`, `details.effective_from`).

- [ ] **Step 1: Failing table-driven test.** Below floor on any cell → a violation naming it; at floor → none; above → none. Cover a worked cell, an unworked cell, and a scalar.

```php
<?php
declare(strict_types=1);

use App\Domain\Pay\StatutoryFloor;

function floors(): array {
    return config('hris.pay_floors'); // the real floor matrix (Task 1)
}

/** A proposed matrix exactly at floor. */
function atFloor(): array {
    $f = floors();
    return ['worked' => $f['worked'], 'unworked' => $f['unworked'],
        'overtime_ordinary' => $f['overtime_ordinary'], 'overtime_premium' => $f['overtime_premium'],
        'night_diff' => $f['night_diff']];
}

it('reports no violations when every cell is at or above floor', function (): void {
    expect(StatutoryFloor::violations(atFloor(), floors()))->toBe([]);
    $above = atFloor(); $above['worked']['regular_holiday'] = [25000, 30000];
    expect(StatutoryFloor::violations($above, floors()))->toBe([]);
});

it('reports a worked cell below floor, naming it', function (): void {
    $p = atFloor(); $p['worked']['regular_holiday'] = [15000, 26000]; // 150% < 200% floor
    $v = StatutoryFloor::violations($p, floors());
    expect($v)->toHaveCount(1)
        ->and($v[0]->multiplier)->toBe('worked.regular_holiday.not_rest')
        ->and($v[0]->proposedBp)->toBe(15000)->and($v[0]->floorBp)->toBe(20000);
});

it('reports an unworked cell and a scalar below floor', function (): void {
    $p = atFloor();
    $p['unworked']['regular_holiday'] = 5000;   // < 10000 floor
    $p['night_diff'] = 10000;                    // < 11000 floor
    $keys = array_map(fn ($x) => $x->multiplier, StatutoryFloor::violations($p, floors()));
    expect($keys)->toContain('unworked.regular_holiday')->toContain('night_diff');
});
```

- [ ] **Step 2: Run, expect failure. Step 3: `FloorViolation`** (readonly value object). **Step 4: `StatutoryFloor`** — pure, static `violations(array $proposed, array $floors): array`; walk worked (both rest states, key `worked.<dayType>.<not_rest|rest>`), unworked (`unworked.<dayType>`), and the 3 scalars (`overtime_ordinary`/`overtime_premium`/`night_diff`); append a `FloorViolation` wherever `proposed < floor`. No config read, no HTTP — receives both matrices. **Step 5: The two exceptions** (mirror `HolidayExists`): `PayRuleExists(string $effectiveFrom)` → 409; `PayRateBelowFloor(array $violations)` → 422, `details()` returns `['violations' => array_map(fn (FloorViolation $v) => ['multiplier' => $v->multiplier, 'proposed_bp' => $v->proposedBp, 'floor_bp' => $v->floorBp], $violations)]`.

- [ ] **Step 6: Test → PASS. Step 7: Arch. Step 8: Commit.**
```bash
git commit -m "Pay rules: StatutoryFloor comparator + below-floor/duplicate domain errors"
```

---

### Task 4: Sysadmin gate + list + create endpoints

**Files:**
- Create: controllers `backend/app/Http/Controllers/Admin/PayRules/{ListController,CreateController}.php`; requests `backend/app/Http/Requests/CreatePayRuleRequest.php`; action `backend/app/Actions/PayRules/CreatePayRule.php` (+ `CreatePayRuleInput.php`); resource `backend/app/Http/Resources/PayRuleResource.php`; a gate registration for `pay-rules.manage` (in `App\Providers\AppServiceProvider` or the existing auth provider — check where Gates/Policies are registered)
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Admin/PayRuleReadWriteTest.php`

**Interfaces:**
- Consumes: `PayRule`, `PayRuleDayRate`, `StatutoryFloor`, `PayRateBelowFloor`, `PayRuleExists`, `config('hris.pay_floors')`.
- Produces: `GET /admin/pay-rules` → `PayRuleResource[]`; `POST /admin/pay-rules` → 201. `PayRuleResource`: `{id, effective_from, overtime_ordinary_bp, overtime_premium_bp, night_diff_bp, note, day_rates:[{day_type, worked_bp, worked_rest_bp, unworked_bp} ordered by day_type]}`.

- [ ] **Step 1: Failing feature test.** Cover: a sysadmin creates a floor-valid version (201, 5 day-rates echoed, activity logged); a **non-sysadmin (HR admin) → 403** on both list and create; a below-floor cell → **422 `pay_rate_below_floor`** with the cell in `details.violations`; a duplicate `effective_from` → **409 `pay_rule_exists`**; `day_rates` not exactly the 5 DayTypes → **400**; list returns versions effective_from desc.

Seed a sysadmin and an HR admin (mirror how `HolidayReadWriteTest` builds users; a sysadmin is `User::factory()->create(['is_system_admin' => true])` — verify the column/flag name against `User`). A full valid `day_rates` payload is the 5 DayTypes at floor (helper).

```php
it('lets a system admin create a floor-valid version, and logs it', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $res = $this->postJson('/api/v1/admin/pay-rules', validPayRulePayload('2026-01-01'))->assertCreated();
    expect($res->json('data.day_rates'))->toHaveCount(5);
    expect(\Spatie\Activitylog\Models\Activity::where('subject_id', $res->json('data.id'))->exists())->toBeTrue();
});

it('forbids a non-system-admin (403, not 404 — nothing to enumerate)', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => false]));
    $this->getJson('/api/v1/admin/pay-rules')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->postJson('/api/v1/admin/pay-rules', validPayRulePayload('2026-01-01'))->assertStatus(403);
});

it('refuses a below-floor cell with 422 pay_rate_below_floor naming it', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $payload = validPayRulePayload('2026-01-01');
    // set regular_holiday worked_bp below the 20000 floor
    $payload['day_rates'] = collect($payload['day_rates'])->map(function (array $r) {
        if ($r['day_type'] === 'regular_holiday') { $r['worked_bp'] = 15000; }
        return $r;
    })->all();
    $this->postJson('/api/v1/admin/pay-rules', $payload)
        ->assertStatus(422)->assertJsonPath('error.code', 'pay_rate_below_floor')
        ->assertJsonPath('error.details.violations.0.multiplier', 'worked.regular_holiday.not_rest');
});

it('409s a duplicate effective_from', function (): void {
    Sanctum::actingAs(User::factory()->create(['is_system_admin' => true]));
    $this->postJson('/api/v1/admin/pay-rules', validPayRulePayload('2026-01-01'))->assertCreated();
    $this->postJson('/api/v1/admin/pay-rules', validPayRulePayload('2026-01-01'))
        ->assertStatus(409)->assertJsonPath('error.code', 'pay_rule_exists');
});
```
(Define `validPayRulePayload($date)` in the test file — Pest helpers are file-scoped — returning `{effective_from, overtime_ordinary_bp:12500, overtime_premium_bp:13000, night_diff_bp:11000, day_rates:[the 5 DayTypes at their floor values]}`.)

- [ ] **Step 2: Run, expect failure.**

- [ ] **Step 3: The gate.** Register a `pay-rules.manage` gate returning `$user->is_system_admin` (find where the app registers Gates — likely `AppServiceProvider::boot` or a dedicated provider; mirror the existing authorization setup). The controllers call `Gate::authorize('pay-rules.manage')` (which throws `AuthorizationException` → the envelope maps it to `403 forbidden`). Confirm the envelope's `AccessDeniedHttpException` handler yields `error.code = forbidden` (it does — `ApiErrorEnvelope`).

- [ ] **Step 4: `CreatePayRuleRequest`** — shape only: `effective_from` required date; `overtime_ordinary_bp`/`overtime_premium_bp`/`night_diff_bp` required integer min:0; `day_rates` required array size:5; `day_rates.*.day_type` required in the 5 values; `day_rates.*.worked_bp`/`worked_rest_bp`/`unworked_bp` required integer min:0. A `withValidator` after-hook: the 5 `day_type`s are exactly the DayType set (no dup, no missing) — else `errors->add`.

- [ ] **Step 5: `CreatePayRule` action.** Read floors at the boundary (the controller passes them in via the Input DTO, OR the action reads `config('hris.pay_floors')` — since an action is app-layer, not Domain, it MAY read config; prefer the controller reads config and passes it, keeping the action's inputs explicit). Build the proposed matrix from the input; call `StatutoryFloor::violations($proposed, $floors)`; if non-empty throw `PayRateBelowFloor($violations)`. Then, in one `DB::transaction`, lock-then-check the duplicate `effective_from` (mirror `CreateHoliday`'s lock — here there's no parent row to lock, so pre-check `PayRule::where('effective_from', …)->exists()` inside the transaction and throw `PayRuleExists`; the `unique(effective_from)` index is the backstop), create the `pay_rules` row, insert the 5 `pay_rule_day_rates`, return the version with `dayRates`. Set `created_by` from the actor (thread `actorId` in the Input DTO — do NOT ship a null `created_by`; this was an M4b lesson).

- [ ] **Step 6: `PayRuleResource`** (day_rates ordered by day_type). **Step 7: Controllers** — `ListController` (`Gate::authorize('pay-rules.manage')`, `PayRule::with('dayRates')->orderByDesc('effective_from')->get()`), `CreateController` (`Gate::authorize`, read `config('hris.pay_floors')`, call the action, 201). **Step 8: Routes** — a new `Route::prefix('admin')` group (or the existing one if present) under `auth:sanctum`:
```php
Route::get('/admin/pay-rules', ListController::class);
Route::post('/admin/pay-rules', CreateController::class);
```

- [ ] **Step 9: Test → PASS. Step 10: Arch. Step 11: Commit.**
```bash
git commit -m "Pay rules: sysadmin-gated list + create, floor-validated, duplicate is 409"
```

---

### Task 5: Show + delete endpoints (and immutability)

**Files:**
- Create: controllers `backend/app/Http/Controllers/Admin/PayRules/{ShowController,DeleteController}.php`
- Modify: `backend/routes/api.php`
- Test: extend `backend/tests/Feature/Admin/PayRuleReadWriteTest.php`

**Interfaces:**
- Produces: `GET /admin/pay-rules/{payRule}` → one version + day_rates; `DELETE /admin/pay-rules/{payRule}` → 204.

- [ ] **Step 1: Failing tests** — show returns a version with its 5 day-rates for a sysadmin; show `404`s an unknown id (for a sysadmin — an admin may enumerate versions, so a plain 404 is fine here, no byte-identical concern); show/delete → **403** for a non-sysadmin; delete → 204 and the row (and its day-rates) are gone; **no PATCH route exists** (`$this->patchJson('/api/v1/admin/pay-rules/'.$rule->id, [])->assertStatus(405)` — versions are immutable).

- [ ] **Step 2–5:** `ShowController` (`Gate::authorize`, route-bound `{payRule}` with `->with('dayRates')`, return resource), `DeleteController` (`Gate::authorize`, `$payRule->delete()`, 204). Routes `GET/DELETE /admin/pay-rules/{payRule}`. Do NOT add a PATCH route (immutability). `declare(strict_types=1)`, final invokable controllers.

- [ ] **Step 6: Test → PASS. Step 7: Arch. Step 8: Commit.**
```bash
git commit -m "Pay rules: show + delete; versions are immutable (no PATCH)"
```

---

### Task 6: Frontend — keys, api, `basisPoints.ts`, `usePayRules`

**Files:**
- Modify: `frontend/web/src/lib/keys.ts`, `frontend/web/src/lib/api.ts`
- Create: `frontend/web/src/lib/basisPoints.ts` (+ `basisPoints.test.ts`), `frontend/web/src/hooks/usePayRules.ts` (+ `usePayRules.test.tsx`)

**Interfaces:**
- Produces: `keys.payRules = { all: () => ['pay-rules'] as const }`; wire types `PayRuleDayRate`, `PayRule`, `PayRuleCreateInput`; `api.payRules.{list(), create(body), get(id), delete(id)}`; `bpToPercent`/`percentToBp`; `usePayRules()` + `useCreatePayRule`/`useDeletePayRule`.

- [ ] **Step 1: `basisPoints.ts` + failing test** — `bpToPercent(10000) === 100`, `bpToPercent(13000) === 130`, `bpToPercent(12500) === 125`; `percentToBp(100) === 10000`, `percentToBp(130) === 13000`; round-trips. Integer bp in; percent may be fractional (12500 → 125). Decide and test the display precision (e.g. `bpToPercent` returns a number; the screen formats). Keep pure.
- [ ] **Step 2: `keys.ts`** — add `payRules: { all: () => ['pay-rules'] as const }`.
- [ ] **Step 3: `api.ts`** — wire types (verified against `PayRuleResource`): `PayRuleDayRate = { day_type: DayType; worked_bp: number; worked_rest_bp: number; unworked_bp: number }`; `PayRule = { id; effective_from; overtime_ordinary_bp; overtime_premium_bp; night_diff_bp; note: string | null; day_rates: PayRuleDayRate[] }`; `PayRuleCreateInput = { effective_from; overtime_ordinary_bp; overtime_premium_bp; night_diff_bp; day_rates: PayRuleDayRate[] }`. Reuse the existing `DayType` union (check `api.ts` / where M4a's `DayType` lives). `api.payRules.{list(),create(body),get(id),delete(id)}` — paths WITHOUT `/api/v1`; mirror `api.holidays.*`.
- [ ] **Step 4: `usePayRules.ts`** — `usePayRules()` query on `keys.payRules.all()`; `useCreatePayRule`/`useDeletePayRule` mutations invalidating `keys.payRules.all()`. Mirror `useHolidays.ts`.
- [ ] **Step 5: Tests** (mirror `useHolidays.test.tsx` + a lib test): the hook hits the key/URL; a create/delete invalidates; the `bp↔%` round-trips.
- [ ] **Step 6: `npm test && npm run typecheck && npm run lint`. Step 7: Commit** `git commit -m "Pay rules(web): keys, api, basis-point helpers, query hooks"`.

---

### Task 7: Frontend — `/admin/pay-rules` matrix editor + Admin nav

**Files:**
- Create: `frontend/web/src/app/(app)/admin/pay-rules/page.tsx`
- Modify: `frontend/web/src/components/SideNav.tsx` (`ROUTES.admin`)
- Test: `frontend/web/src/app/(app)/admin/pay-rules/pay-rules.test.tsx`, extend `SideNav.test.tsx`

**Interfaces:**
- Consumes: `usePayRules` + mutations, `bpToPercent`/`percentToBp`, `useSession().is_system_admin`, `Dialog`/`Select`/`TextInput`/`Button`/`InlineNotification`, `config`-mirrored floor hints (ship the floor matrix as a frontend const, or read from a version — simplest: a small `PAY_FLOOR_PERCENT` const in the page mirroring the config floors, used only for the client-side hint; the server is the authority).

- [ ] **Step 1: Failing tests** — the Admin **Pay rules** nav entry shows for a sysadmin and is absent for a non-sysadmin (extend `SideNav.test.tsx`); the screen renders the currently-effective version's matrix as percentages; a "New version" Dialog with an effective-date + editable cells submits `api.payRules.create` and invalidates; a **422 `pay_rate_below_floor`** response surfaces the violating cell(s) inline (mock the api to reject once). Mock `next/navigation` + fetch/api per the holidays screen-test harness.
- [ ] **Step 2–4:** implement. Reuse the `/office/holidays/page.tsx` scaffold (AppShell, SectionHeader, loading/error via Skeleton/InlineNotification). No office picker (company-wide). The matrix editor: a table of the 5 DayTypes × (worked / worked-on-rest / unworked) + the 3 scalars, read-only for the effective version and editable in the New-version Dialog; a client-side floor hint per cell (from `PAY_FLOOR_PERCENT`); on submit map percent→bp (`percentToBp`) and POST; render a returned `422`'s `details.violations` against the offending cells. A version-history list (effective dates desc). Add `ROUTES.admin = [{ href: '/admin/pay-rules', label: 'Pay rules' }]`.
- [ ] **Step 5: `npm test && npm run typecheck && npm run lint && npm run build`** (confirm `/admin/pay-rules` in the route table). **Step 6: Commit** `git commit -m "Pay rules(web): /admin/pay-rules matrix editor + Admin nav"`.

---

### Task 8: Docs, seeder, e2e, and the full gate

**Files:**
- Modify: `docs/02-data-model.md`, `docs/03-api.md`, `docs/04-backend-conventions.md`, `docs/05-rbac.md`, `docs/06-roadmap.md` (M4c **complete**, and M4 complete), `docs/features.md`
- Create: `scripts/e2e-pay-rules.sh`
- Modify: `backend/database/seeders/CompanySeeder.php`

- [ ] **Step 1: Seeder** — seed one default `pay_rules` version = the statutory floor matrix (all 5 DayTypes at their `config('hris.pay_floors')` values, the 3 scalars at floor), effective an early date (e.g. `2026-01-01`), created_by the seeded sysadmin, so M5 has a version to read and the screen is non-empty. Match the seeder's `Model::create` style.
- [ ] **Step 2: `scripts/e2e-pay-rules.sh`** — mirror `scripts/e2e-holidays.sh` structure (shebang, header comment, `set -euo pipefail`, `API` default, login helper, jq). Walk: log in as `sysadmin@hris.test`; create a floor-valid version (a fresh effective_from, e.g. `2027-01-01`, regular-holiday worked at 250%); assert 201 + 5 day_rates; list and confirm it's present; a **below-floor** write (regular-holiday worked 150%) → 422 `pay_rate_below_floor` with the cell in `details.violations`; a **duplicate** effective_from → 409 `pay_rule_exists`; log in as `hr.manila@hris.test` and confirm GET/POST → **403 forbidden**; read the `activity_log` (via `psql`, mirroring e2e-holidays) for the version's causer + uuid subject. `bash -n` clean; run live only if `curl -sf …/health` succeeds.
- [ ] **Step 3: Docs** (verify against code): `02-data-model.md` (the two tables + the config floor + immutability + effective-dated resolution). `03-api.md` (the 4 endpoints, `403`/`400`/`409 pay_rule_exists`/`422 pay_rate_below_floor` codes, and the note that this uses a 403 sysadmin gate rather than the office 404 discipline, with the reason). `04-backend-conventions.md` (confirm the `pay_rules`/`config('hris.pay_floors')` rows in the config-vs-database table are now real, not forward-declared). `05-rbac.md` (the `pay-rules.manage` gate = System Admin only). `06-roadmap.md` (M4c **Status: complete** with real counts; **M4 complete**; RecomputeRange still M5; the `PayMultiplier`↔config-floor reconciliation recorded as M5's). `features.md` (a sysadmin sets effective-dated pay rates, floored by law).
- [ ] **Step 4: Full gate** — `cd backend && ./vendor/bin/pest && ./vendor/bin/pest --testsuite=Arch`; `cd ../frontend/web && npm run lint && npm test && npm run typecheck && npm run build`; `cd /home/haru/projects/hris && make test`. Report real counts. If containerized `test-web` flakes on unrelated files, rerun once (M4b precedent).
- [ ] **Step 5: Commit** `git commit -m "Pay rules: docs, seeder, e2e, M4c status; M4 complete"`.

## Done When

A System Admin creates a 2026 version with regular-holiday-worked at 250% (above the 200% floor) → accepted and logged; the same at 150% → refused `422 pay_rate_below_floor` naming that cell; a duplicate `effective_from` → `409`; a non-sysadmin → `403`; the version is immutable (no PATCH). No pay is computed — the version is the input M5 reads on a worked date to stamp `rule_version_id`. Full suite green (backend + arch + frontend), `e2e-pay-rules.sh` passes live. **M4 — the configuration spine — is complete.**
