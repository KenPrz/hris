# M1 — Time and Pay Primitives Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the pure integer value objects the whole pay engine computes on, and pin the entire DOLE premium-pay matrix as a table-driven unit test — with zero database.

**Architecture:** Every class here is a `final readonly` value object or a stateless resolver in `app/Domain/`. No I/O, no container, no `config()`, no Eloquent. `tests/Unit/` runs on plain PHPUnit with no booted application, and must stay that way. The primitives take their parameters as constructor arguments precisely so they remain testable without a framework.

**Tech Stack:** PHP 8.5 · Pest 4 (Unit suite, no Laravel bootstrap) · TypeScript (browser mirrors)

## Global Constraints

- **PHP 8.5**, **Laravel 13**, **PostgreSQL 18**. Pinned.
- **`declare(strict_types=1);` at the top of every PHP file** in `app/` and `tests/`. CI greps for it.
- **Never call `env()` outside `config/`.** Also: **nothing in `app/Domain/` may call `config()`** — a value object takes its parameters as constructor arguments. Otherwise every unit test needs a booted container to test integer arithmetic.
- **Worked time is integer minutes.** Never decimal hours, in any layer, ever. `7h 20m` is `7.333…`.
- **Money is integer centavos.** No float constructor exists — not discouraged, *absent*.
- **Pay multipliers are integer basis points.** 200% is `20000`. `BasisPoints::ONE === 10000`.
- **All rounding goes through one primitive.** `Money::fraction()` rounds half away from zero. Do not add a second rounding rule anywhere.
- **Art. 82 exemption gates every premium.** Managerial employees and field personnel get no overtime, no night differential, no holiday premium, no SIL.
- **`tests/Unit/` must not boot the application.** `backend/tests/Pest.php` deliberately extends `Tests\TestCase` into `Feature` and `Arch` only. Do not add `->in('Unit')`, do not use `RefreshDatabase`, do not call `config()` or any facade from a unit test.
- **Commit messages carry no attribution trailers** — no `Co-Authored-By`, no `Generated with`, no session URL.

## The DOLE matrix this milestone exists to encode

Every multiplier below is expressed in basis points (`10000` = 100%). These are the numbers the plan's tests pin by name.

**Base rate for time worked:**

| Day type | Not rest day | On rest day |
| --- | --- | --- |
| `Ordinary` | 10000 | 13000 |
| `SpecialWorking` | 10000 | 13000 |
| `SpecialNonWorking` | 13000 | 15000 |
| `RegularHoliday` | 20000 | 26000 |
| `DoubleRegularHoliday` | 30000 | 39000 |

**Note `SpecialNonWorking` + rest day is 15000, not 13000 × 13000 / 10000 = 16900.** DOLE specifies a flat 150%. The rest-day adjustment is therefore a **lookup table, not a formula** — this is the single most important reason `PayMultiplier` is table-driven.

**Overtime factor**, applied to the base above: `12500` when the day is `Ordinary` or `SpecialWorking` **and** it is not a rest day; `13000` otherwise (overtime is +30% of that day's own hourly rate).

**Night differential factor:** `11000`, always, applied last. It compounds on the already-premium rate — 200% × 130% × 110% = 286% for holiday overtime at 2am, **not** 210%.

**Art. 82-exempt employees:** `10000`, always. No overtime, no night differential, no holiday premium.

**Unworked days** are a separate resolver, because they are a flat day's wage rather than an hourly multiplier: `RegularHoliday` → `10000`, `DoubleRegularHoliday` → `20000`, everything else → `0` (a special non-working day is "no work, no pay" absent a more generous company policy).

## File structure

```
backend/app/Domain/Money/Money.php               integer centavos; the one rounding primitive
backend/app/Domain/Time/Minutes.php              non-negative integer duration
backend/app/Domain/Time/WorkInterval.php         a paired span, minutes from business-day midnight
backend/app/Domain/Time/PunchPairer.php          ordered punch list -> intervals, odd counts reported
backend/app/Domain/Time/MealBreakPolicy.php      Assumed(n, over) | Explicit
backend/app/Domain/Time/NightDiffSplitter.php    split an interval against 22:00-06:00
backend/app/Domain/Time/WorkedSplit.php          regular vs overtime minutes
backend/app/Domain/Time/OvertimeThreshold.php    worked vs scheduled -> WorkedSplit
backend/app/Domain/Pay/BasisPoints.php           integer multipliers, composed exactly
backend/app/Domain/Pay/DayType.php               the five day types
backend/app/Domain/Pay/PayMultiplier.php         the DOLE matrix
frontend/web/src/lib/duration.ts                 integer minutes -> "7h 20m"
frontend/web/src/lib/money.ts                    integer centavos -> "₱1,234.50"
```

**Time representation.** Instants are **integer minutes from the start of the business day, in the office's local wall-clock time**. A night shift running 22:00 → 06:00 is `1320 → 1800`; values may exceed `1440`. Converting UTC `timestamptz` punches into office-local business-day minutes is the engine's job in M5 — **not** these primitives'. This is what keeps M1 pure and integer-only, and it is why night-differential splitting can work on plain integers instead of dragging a timezone database into a value object.

---

### Task 1: `Minutes`

**Files:**
- Create: `backend/app/Domain/Time/Minutes.php`
- Test: `backend/tests/Unit/Domain/Time/MinutesTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Domain\Time\Minutes` — `final readonly`, public `int $value`. Statics `of(int): self`, `zero(): self`, `sum(list<self>): self`. Methods `plus(self): self`, `minus(self): self`, `isZero(): bool`, `equals(self): bool`, `compareTo(self): int`, `greaterThan(self): bool`, `lessThan(self): bool`, `min(self): self`, `max(self): self`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Domain/Time/MinutesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Time\Minutes;

it('holds a whole number of minutes', function (): void {
    expect(Minutes::of(440)->value)->toBe(440)
        ->and(Minutes::zero()->value)->toBe(0);
});

it('refuses a negative duration', function (): void {
    // A negative duration is a bug, not a value. Undertime is its own non-negative
    // magnitude, recorded separately — see OvertimeThreshold.
    expect(fn () => Minutes::of(-1))
        ->toThrow(InvalidArgumentException::class, 'cannot be negative');
});

it('adds and subtracts', function (): void {
    expect(Minutes::of(480)->plus(Minutes::of(90))->value)->toBe(570)
        ->and(Minutes::of(480)->minus(Minutes::of(90))->value)->toBe(390);
});

it('refuses a subtraction that would go negative rather than clamping', function (): void {
    // Clamping to zero would silently swallow the bug that produced the inversion.
    expect(fn () => Minutes::of(90)->minus(Minutes::of(480)))
        ->toThrow(InvalidArgumentException::class, 'cannot be negative');
});

it('sums a list', function (): void {
    expect(Minutes::sum([Minutes::of(60), Minutes::of(30), Minutes::of(15)])->value)->toBe(105)
        ->and(Minutes::sum([])->value)->toBe(0);
});

it('compares', function (): void {
    $short = Minutes::of(60);
    $long = Minutes::of(120);

    expect($short->lessThan($long))->toBeTrue()
        ->and($long->greaterThan($short))->toBeTrue()
        ->and($short->equals(Minutes::of(60)))->toBeTrue()
        ->and($short->compareTo($long))->toBe(-1)
        ->and($short->min($long)->value)->toBe(60)
        ->and($short->max($long)->value)->toBe(120)
        ->and(Minutes::zero()->isZero())->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Time/MinutesTest.php`
Expected: FAIL — `Class "App\Domain\Time\Minutes" not found`.

- [ ] **Step 3: Write the implementation**

Create `backend/app/Domain/Time/Minutes.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Time;

use InvalidArgumentException;

/**
 * A duration, as a whole non-negative number of minutes.
 *
 * There is deliberately no float constructor and no "hours" accessor returning a
 * decimal. `7h 20m` is `7.333…`, and a shift is not a number you may round twice —
 * every rounding in this system happens once, in Money::fraction(), on money.
 *
 * Negative is unrepresentable. Undertime is a separate non-negative magnitude
 * (see OvertimeThreshold); a negative duration reaching the pay engine would be a
 * bug wearing a value's clothes.
 *
 * See docs/01-architecture.md.
 */
final readonly class Minutes
{
    private function __construct(
        public int $value,
    ) {}

    public static function of(int $minutes): self
    {
        if ($minutes < 0) {
            throw new InvalidArgumentException("A duration cannot be negative: {$minutes} minutes.");
        }

        return new self($minutes);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** @param  list<self>  $durations */
    public static function sum(array $durations): self
    {
        $total = 0;

        foreach ($durations as $duration) {
            $total += $duration->value;
        }

        return new self($total);
    }

    public function plus(self $other): self
    {
        return new self($this->value + $other->value);
    }

    public function minus(self $other): self
    {
        return self::of($this->value - $other->value);
    }

    public function isZero(): bool
    {
        return $this->value === 0;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function compareTo(self $other): int
    {
        return $this->value <=> $other->value;
    }

    public function greaterThan(self $other): bool
    {
        return $this->value > $other->value;
    }

    public function lessThan(self $other): bool
    {
        return $this->value < $other->value;
    }

    public function min(self $other): self
    {
        return $this->lessThan($other) ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->greaterThan($other) ? $this : $other;
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Time/MinutesTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Domain/Time/Minutes.php backend/tests/Unit/Domain/Time/MinutesTest.php
git commit -m "Time: Minutes, a non-negative integer duration

No float constructor and no decimal-hours accessor. Negative is
unrepresentable — undertime is its own magnitude, not a negative one."
```

---

### Task 2: `Money`

Ported from `/home/haru/projects/pos/backend/app/Domain/Money/Money.php`. **Read that file first and port it, do not rewrite it from the code below alone** — it is battle-tested and its comments carry reasoning worth keeping.

**Files:**
- Create: `backend/app/Domain/Money/Money.php`
- Test: `backend/tests/Unit/Domain/Money/MoneyTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Domain\Money\Money` — `final readonly`, public `int $cents`. Statics `fromCents(int): self`, `zero(): self`, `parse(string): self`, `sum(list<self>): self`. Methods `plus`, `minus`, `multipliedBy(int)`, `negated`, `absolute`, **`fraction(int $numerator, int $denominator): self`** (the single rounding primitive, half away from zero), `allocate(int): list<self>`, `allocateByRatios(list<int>): list<self>`, `isZero`, `isPositive`, `isNegative`, `equals`, `compareTo`, `greaterThan`, `greaterThanOrEqual`, `lessThan`, `min`, `max`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Domain/Money/MoneyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Money\Money;

it('holds integer centavos', function (): void {
    expect(Money::fromCents(123456)->cents)->toBe(123456)
        ->and(Money::zero()->cents)->toBe(0);
});

it('parses a decimal string with string arithmetic, never a float cast', function (): void {
    // (int) (float) '1.15' * 100 famously yields 114. This is why parse() exists.
    expect(Money::parse('1.15')->cents)->toBe(115)
        ->and(Money::parse('12.34')->cents)->toBe(1234)
        ->and(Money::parse('7')->cents)->toBe(700)
        ->and(Money::parse('-3.05')->cents)->toBe(-305);
});

it('rejects a third decimal place rather than rounding it away', function (): void {
    // Silently discarding a digit is losing money quietly.
    expect(fn () => Money::parse('1.234'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::parse('abc'))->toThrow(InvalidArgumentException::class);
});

it('adds, subtracts, multiplies and negates', function (): void {
    expect(Money::fromCents(1000)->plus(Money::fromCents(250))->cents)->toBe(1250)
        ->and(Money::fromCents(1000)->minus(Money::fromCents(250))->cents)->toBe(750)
        ->and(Money::fromCents(1000)->multipliedBy(3)->cents)->toBe(3000)
        ->and(Money::fromCents(-500)->negated()->cents)->toBe(500)
        ->and(Money::fromCents(-500)->absolute()->cents)->toBe(500);
});

it('rounds half away from zero in fraction(), the one rounding primitive', function (): void {
    // 0.5c rounds up to 1c rather than vanishing — and symmetrically for negatives.
    expect(Money::fromCents(1)->fraction(1, 2)->cents)->toBe(1)
        ->and(Money::fromCents(-1)->fraction(1, 2)->cents)->toBe(-1)
        ->and(Money::fromCents(3)->fraction(1, 2)->cents)->toBe(2)
        ->and(Money::fromCents(10000)->fraction(13, 10)->cents)->toBe(13000);
});

it('refuses division by zero', function (): void {
    expect(fn () => Money::fromCents(100)->fraction(1, 0))
        ->toThrow(InvalidArgumentException::class, 'Division by zero');
});

it('allocates so the parts always sum exactly back to the whole', function (): void {
    $parts = Money::fromCents(1000)->allocate(3);

    expect(array_map(fn (Money $m): int => $m->cents, $parts))->toBe([334, 333, 333])
        ->and(Money::sum($parts)->cents)->toBe(1000);
});

it('allocates by ratios without inventing or destroying centavos', function (): void {
    $parts = Money::fromCents(1000)->allocateByRatios([1, 1, 3]);

    expect(Money::sum($parts)->cents)->toBe(1000)
        ->and(count($parts))->toBe(3);
});

it('rejects nonsensical allocations', function (): void {
    expect(fn () => Money::fromCents(100)->allocate(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromCents(100)->allocateByRatios([]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromCents(100)->allocateByRatios([0, 0]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::fromCents(100)->allocateByRatios([1, -1]))->toThrow(InvalidArgumentException::class);
});

it('fails with a reason on overflow rather than silently promoting to float', function (): void {
    // PHP promotes integer overflow to float — the exact thing this class prevents.
    expect(fn () => Money::fromCents(PHP_INT_MAX)->fraction(1000, 1))
        ->toThrow(InvalidArgumentException::class, 'integer overflow');
});

it('compares', function (): void {
    $small = Money::fromCents(100);
    $large = Money::fromCents(500);

    expect($small->lessThan($large))->toBeTrue()
        ->and($large->greaterThan($small))->toBeTrue()
        ->and($large->greaterThanOrEqual(Money::fromCents(500)))->toBeTrue()
        ->and($small->equals(Money::fromCents(100)))->toBeTrue()
        ->and($small->compareTo($large))->toBe(-1)
        ->and($small->min($large)->cents)->toBe(100)
        ->and($small->max($large)->cents)->toBe(500)
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and($small->isPositive())->toBeTrue()
        ->and($small->negated()->isNegative())->toBeTrue();
});

it('has no float constructor at all', function (): void {
    // Not discouraged — absent. Under strict_types this is a TypeError, and that is
    // the point: the mistake is unrepresentable rather than merely frowned upon.
    expect(fn () => Money::fromCents(1.5)) // @phpstan-ignore-line
        ->toThrow(TypeError::class);
});

it('sums a list', function (): void {
    expect(Money::sum([Money::fromCents(100), Money::fromCents(250)])->cents)->toBe(350)
        ->and(Money::sum([])->cents)->toBe(0);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Money/MoneyTest.php`
Expected: FAIL — `Class "App\Domain\Money\Money" not found`.

- [ ] **Step 3: Port the implementation**

Copy `/home/haru/projects/pos/backend/app/Domain/Money/Money.php` to `backend/app/Domain/Money/Money.php` and make exactly these changes:

1. **Remove `multipliedByQuantity()`** and its `Quantity` dependency. HRIS has no fractional quantities; `Quantity` is not being ported.
2. In the class docblock, change the currency sentence to reference `config('hris.currency')` rather than `config('pos.currency')`, and the closing reference to `docs/01-architecture.md` stays as-is.
3. Change the docblock word "cashier" to "payroll run" in the no-float-constructor paragraph — the failure mode here is a wrong payslip, not a wrong receipt.

**Change nothing else.** In particular keep `divideRoundHalfUp()`, `assertNoOverflow()`, `allocate()`, and `allocateByRatios()` byte-identical, including their comments. They are the parts that took the longest to get right.

- [ ] **Step 4: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Money/MoneyTest.php`
Expected: PASS, 13 tests.

- [ ] **Step 5: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Domain/Money/Money.php backend/tests/Unit/Domain/Money/MoneyTest.php
git commit -m "Money: integer centavos, ported from the POS sibling

fraction() stays the single rounding primitive — one place a centavo can
be created or destroyed, one place to test. Quantity is not ported;
HRIS has no fractional quantities."
```

---

### Task 3: `BasisPoints`

**Files:**
- Create: `backend/app/Domain/Pay/BasisPoints.php`
- Test: `backend/tests/Unit/Domain/Pay/BasisPointsTest.php`

**Interfaces:**
- Consumes: `App\Domain\Money\Money` (Task 2) — for `applyTo()`.
- Produces: `App\Domain\Pay\BasisPoints` — `final readonly`, public `int $value`, public const `ONE = 10000`. Statics `of(int): self`, `one(): self`. Methods `times(self): self`, `applyTo(Money): Money`, `equals(self): bool`, `toPercentString(): string`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Domain/Pay/BasisPointsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Money\Money;
use App\Domain\Pay\BasisPoints;

it('expresses a multiplier as an integer', function (): void {
    expect(BasisPoints::ONE)->toBe(10000)
        ->and(BasisPoints::of(20000)->value)->toBe(20000)
        ->and(BasisPoints::one()->value)->toBe(10000);
});

it('refuses a negative multiplier', function (): void {
    expect(fn () => BasisPoints::of(-1))
        ->toThrow(InvalidArgumentException::class, 'cannot be negative');
});

it('composes exactly for the DOLE compounding chain', function (): void {
    // 200% regular holiday x 130% rest day x 130% overtime x 110% night differential.
    // Every step lands on a whole basis point, so nothing is lost to rounding.
    $composed = BasisPoints::of(20000)      // regular holiday
        ->times(BasisPoints::of(13000))     // on a rest day  -> 260%
        ->times(BasisPoints::of(13000))     // overtime       -> 338%
        ->times(BasisPoints::of(11000));    // night diff     -> 371.8%

    expect($composed->value)->toBe(37180);
});

it('composes step by step to the published DOLE figures', function (): void {
    expect(BasisPoints::of(20000)->times(BasisPoints::of(13000))->value)->toBe(26000)
        ->and(BasisPoints::of(26000)->times(BasisPoints::of(13000))->value)->toBe(33800)
        ->and(BasisPoints::of(13000)->times(BasisPoints::of(13000))->value)->toBe(16900);
});

it('is multiplicatively neutral at ONE', function (): void {
    expect(BasisPoints::of(13000)->times(BasisPoints::one())->value)->toBe(13000)
        ->and(BasisPoints::one()->times(BasisPoints::of(13000))->value)->toBe(13000);
});

it('rounds a composition half away from zero when it cannot be exact', function (): void {
    // 10001 x 10001 / 10000 = 10002.0001 -> 10002
    expect(BasisPoints::of(10001)->times(BasisPoints::of(10001))->value)->toBe(10002);
    // 10005 x 10001 / 10000 = 10006.0005 -> 10006
    expect(BasisPoints::of(10005)->times(BasisPoints::of(10001))->value)->toBe(10006);
});

it('applies to money through the one rounding primitive', function (): void {
    // A daily rate of PHP 1,000.00 at 260% is PHP 2,600.00.
    expect(BasisPoints::of(26000)->applyTo(Money::fromCents(100000))->cents)->toBe(260000)
        ->and(BasisPoints::one()->applyTo(Money::fromCents(12345))->cents)->toBe(12345)
        // 37180 bp of 1 centavo is 3.718c, which rounds to 4c.
        ->and(BasisPoints::of(37180)->applyTo(Money::fromCents(1))->cents)->toBe(4);
});

it('renders a human-readable percent for reports and payslips', function (): void {
    expect(BasisPoints::of(10000)->toPercentString())->toBe('100%')
        ->and(BasisPoints::of(26000)->toPercentString())->toBe('260%')
        ->and(BasisPoints::of(37180)->toPercentString())->toBe('371.8%')
        ->and(BasisPoints::of(12525)->toPercentString())->toBe('125.25%');
});

it('compares by value', function (): void {
    expect(BasisPoints::of(13000)->equals(BasisPoints::of(13000)))->toBeTrue()
        ->and(BasisPoints::of(13000)->equals(BasisPoints::of(15000)))->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Pay/BasisPointsTest.php`
Expected: FAIL — `Class "App\Domain\Pay\BasisPoints" not found`.

- [ ] **Step 3: Write the implementation**

Create `backend/app/Domain/Pay/BasisPoints.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Pay;

use App\Domain\Money\Money;
use InvalidArgumentException;

/**
 * A pay multiplier, as an integer number of basis points. 100% is 10000; 260% is 26000.
 *
 * A multiplier is money's co-conspirator, so it does not get to be a float either. The
 * DOLE premium rates compound — 200% x 130% x 130% x 110% for holiday overtime at 2am —
 * and the order they compound in has to be fixed and testable rather than incidental.
 *
 * Composition rounds half away from zero, through the same rule as Money::fraction().
 * In practice the published rates all land on whole basis points, so nothing rounds; the
 * rule exists so that a future rate ending in an odd fraction cannot behave surprisingly.
 *
 * See docs/01-architecture.md.
 */
final readonly class BasisPoints
{
    /** 100%. One whole, unmultiplied. */
    public const int ONE = 10_000;

    private function __construct(
        public int $value,
    ) {}

    public static function of(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException("A pay multiplier cannot be negative: {$basisPoints} bp.");
        }

        return new self($basisPoints);
    }

    public static function one(): self
    {
        return new self(self::ONE);
    }

    /**
     * Compose two multipliers. 200% times 130% is 260%, not 330%.
     */
    public function times(self $other): self
    {
        if ($this->value !== 0 && abs($other->value) > intdiv(PHP_INT_MAX, $this->value)) {
            throw new InvalidArgumentException(
                "Composing {$this->value} bp with {$other->value} bp would overflow."
            );
        }

        return new self(self::divideRoundHalfUp($this->value * $other->value, self::ONE));
    }

    /**
     * Apply to an amount. Delegates to Money::fraction() rather than doing its own
     * arithmetic, because there is exactly one place in this system where a centavo
     * may be created or destroyed.
     */
    public function applyTo(Money $amount): Money
    {
        return $amount->fraction($this->value, self::ONE);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * For payslips and reports: '260%', '371.8%', '125.25%'. Trailing zeros are trimmed
     * so the common whole-percent case reads as a whole percent.
     */
    public function toPercentString(): string
    {
        $whole = intdiv($this->value, 100);
        $fraction = $this->value % 100;

        if ($fraction === 0) {
            return "{$whole}%";
        }

        $decimals = rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');

        return "{$whole}.{$decimals}%";
    }

    /**
     * The same rounding rule as Money::divideRoundHalfUp(). Duplicated deliberately
     * rather than exposed from Money: making it public there would invite call sites
     * to round outside fraction(), which is the one thing that rule exists to prevent.
     */
    private static function divideRoundHalfUp(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);

        if (($numerator % $denominator) * 2 >= $denominator) {
            $quotient++;
        }

        return $quotient;
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Pay/BasisPointsTest.php`
Expected: PASS, 9 tests.

- [ ] **Step 5: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Domain/Pay/BasisPoints.php backend/tests/Unit/Domain/Pay/BasisPointsTest.php
git commit -m "Pay: BasisPoints, integer multipliers that compose exactly

The DOLE chain 200% x 130% x 130% x 110% lands on 371.8% with nothing
lost. applyTo() delegates to Money::fraction() so there stays exactly
one place a centavo can be created or destroyed."
```

---

### Task 4: `DayType` and `PayMultiplier` — the DOLE matrix

The centerpiece of M1. If this is wrong, it is not a bug report — it is back-pay and a DOLE complaint.

**Files:**
- Create: `backend/app/Domain/Pay/DayType.php`
- Create: `backend/app/Domain/Pay/PayMultiplier.php`
- Test: `backend/tests/Unit/Domain/Pay/PayMultiplierTest.php`

**Interfaces:**
- Consumes: `App\Domain\Pay\BasisPoints` (Task 3).
- Produces:
  - `App\Domain\Pay\DayType` — a backed string enum: `Ordinary = 'ordinary'`, `SpecialWorking = 'special_working'`, `SpecialNonWorking = 'special_non_working'`, `RegularHoliday = 'regular_holiday'`, `DoubleRegularHoliday = 'double_regular_holiday'`.
  - `App\Domain\Pay\PayMultiplier` — `final`, all statics:
    - `forWorkedTime(DayType $dayType, bool $isRestDay, bool $isOvertime, bool $isNightDiff, bool $isArt82Exempt): BasisPoints`
    - `forUnworkedDay(DayType $dayType, bool $isArt82Exempt): BasisPoints`

**Every parameter is mandatory. None has a default.** `$isArt82Exempt` in particular must never acquire one: making it required by signature is how "Art. 82 gates every premium" is enforced — it becomes impossible to compute a multiplier without stating the employee's status. That is stronger than any arch test, and Task 8 corrects the docs to say so.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/Domain/Pay/PayMultiplierTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Pay\BasisPoints;
use App\Domain\Pay\DayType;
use App\Domain\Pay\PayMultiplier;

/*
| The DOLE premium-pay matrix, pinned cell by cell. Every number here traces to the
| Labor Code as amended (Arts. 86-94) and the DOLE handbook's worked examples.
|
| Read this file as the specification. If a rate changes by advisory, the change lands
| in the pay_rules table (M4), not here — these are the statutory floors the engine
| validates configured rates against.
*/

// base rate for time worked ---------------------------------------------------

dataset('worked base rates', [
    'ordinary day' => [DayType::Ordinary, false, 10000],
    'ordinary day on a rest day' => [DayType::Ordinary, true, 13000],
    'special working day' => [DayType::SpecialWorking, false, 10000],
    'special working day on a rest day' => [DayType::SpecialWorking, true, 13000],
    'special non-working day' => [DayType::SpecialNonWorking, false, 13000],
    'special non-working day on a rest day' => [DayType::SpecialNonWorking, true, 15000],
    'regular holiday' => [DayType::RegularHoliday, false, 20000],
    'regular holiday on a rest day' => [DayType::RegularHoliday, true, 26000],
    'double regular holiday' => [DayType::DoubleRegularHoliday, false, 30000],
    'double regular holiday on a rest day' => [DayType::DoubleRegularHoliday, true, 39000],
]);

it('pays the statutory base rate for time worked', function (DayType $type, bool $restDay, int $expected): void {
    expect(PayMultiplier::forWorkedTime($type, $restDay, false, false, false)->value)->toBe($expected);
})->with('worked base rates');

it('does not derive the special non-working rest-day rate by formula', function (): void {
    // 130% x 130% would be 169%. DOLE specifies a flat 150%. This single cell is why
    // the rest-day adjustment is a lookup table and not a multiplication.
    expect(PayMultiplier::forWorkedTime(DayType::SpecialNonWorking, true, false, false, false)->value)
        ->toBe(15000)
        ->and(BasisPoints::of(13000)->times(BasisPoints::of(13000))->value)->toBe(16900);
});

// overtime --------------------------------------------------------------------

dataset('overtime rates', [
    'ordinary day' => [DayType::Ordinary, false, 12500],
    'ordinary day on a rest day' => [DayType::Ordinary, true, 16900],
    'special working day' => [DayType::SpecialWorking, false, 12500],
    'special non-working day' => [DayType::SpecialNonWorking, false, 16900],
    'special non-working day on a rest day' => [DayType::SpecialNonWorking, true, 19500],
    'regular holiday' => [DayType::RegularHoliday, false, 26000],
    'regular holiday on a rest day' => [DayType::RegularHoliday, true, 33800],
    'double regular holiday' => [DayType::DoubleRegularHoliday, false, 39000],
]);

it('pays overtime at +25% on an ordinary day and +30% everywhere else', function (DayType $type, bool $restDay, int $expected): void {
    expect(PayMultiplier::forWorkedTime($type, $restDay, true, false, false)->value)->toBe($expected);
})->with('overtime rates');

// night differential ----------------------------------------------------------

it('compounds night differential on the already-premium rate, not on base pay', function (): void {
    // The mistake this pins: 200% + 10% = 210% is wrong. It is 200% x 110% = 220%.
    expect(PayMultiplier::forWorkedTime(DayType::RegularHoliday, false, false, true, false)->value)
        ->toBe(22000);

    // Holiday overtime at 2am: 200% x 130% x 110%.
    expect(PayMultiplier::forWorkedTime(DayType::RegularHoliday, false, true, true, false)->value)
        ->toBe(28600);

    // An ordinary night shift hour.
    expect(PayMultiplier::forWorkedTime(DayType::Ordinary, false, false, true, false)->value)
        ->toBe(11000);
});

it('reaches 371.8% for the worst-case hour in the matrix', function (): void {
    // Regular holiday, falling on a rest day, in overtime, inside the night window:
    // 200% x 130% x 130% x 110%.
    expect(PayMultiplier::forWorkedTime(DayType::RegularHoliday, true, true, true, false)->value)
        ->toBe(37180);
});

// Art. 82 --------------------------------------------------------------------

it('pays an Art. 82-exempt employee flat, whatever the day', function (): void {
    // Managerial employees and field personnel are outside Art. 82's coverage: no
    // overtime, no night differential, no holiday premium. Every combination collapses.
    foreach (DayType::cases() as $type) {
        foreach ([true, false] as $restDay) {
            foreach ([true, false] as $overtime) {
                foreach ([true, false] as $night) {
                    expect(PayMultiplier::forWorkedTime($type, $restDay, $overtime, $night, true)->value)
                        ->toBe(10000);
                }
            }
        }
    }
});

it('pays an Art. 82-exempt employee nothing extra for an unworked holiday', function (): void {
    expect(PayMultiplier::forUnworkedDay(DayType::RegularHoliday, true)->value)->toBe(0)
        ->and(PayMultiplier::forUnworkedDay(DayType::DoubleRegularHoliday, true)->value)->toBe(0);
});

// unworked days ---------------------------------------------------------------

dataset('unworked day rates', [
    'ordinary day' => [DayType::Ordinary, 0],
    'special working day' => [DayType::SpecialWorking, 0],
    'special non-working day' => [DayType::SpecialNonWorking, 0],
    'regular holiday' => [DayType::RegularHoliday, 10000],
    'double regular holiday' => [DayType::DoubleRegularHoliday, 20000],
]);

it('pays an unworked regular holiday and nothing for an unworked special day', function (DayType $type, int $expected): void {
    // "No work, no pay" applies to special non-working days absent a more generous
    // company policy; a regular holiday is paid whether worked or not.
    expect(PayMultiplier::forUnworkedDay($type, false)->value)->toBe($expected);
})->with('unworked day rates');

// completeness ----------------------------------------------------------------

it('covers every day type, so a new one cannot be added without a rate', function (): void {
    foreach (DayType::cases() as $type) {
        expect(PayMultiplier::forWorkedTime($type, false, false, false, false)->value)
            ->toBeGreaterThanOrEqual(10000)
            ->and(PayMultiplier::forUnworkedDay($type, false)->value)->toBeGreaterThanOrEqual(0);
    }
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Pay/PayMultiplierTest.php`
Expected: FAIL — `Class "App\Domain\Pay\DayType" not found`.

- [ ] **Step 3: Write `DayType`**

Create `backend/app/Domain/Pay/DayType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Pay;

/**
 * What kind of day this is, for pay purposes.
 *
 * Philippine holidays are set by annual presidential proclamation and the dates move —
 * Eid'l Fitr and Eid'l Adha move a great deal. Which calendar dates carry which type is
 * therefore data (the holidays table, M4); this enum is only the closed set of types the
 * Labor Code recognises.
 *
 * DoubleRegularHoliday is the case where two regular holidays coincide, which happens
 * rarely but is not hypothetical — Araw ng Kagitingan has fallen on Good Friday.
 */
enum DayType: string
{
    case Ordinary = 'ordinary';
    case SpecialWorking = 'special_working';
    case SpecialNonWorking = 'special_non_working';
    case RegularHoliday = 'regular_holiday';
    case DoubleRegularHoliday = 'double_regular_holiday';
}
```

- [ ] **Step 4: Write `PayMultiplier`**

Create `backend/app/Domain/Pay/PayMultiplier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Pay;

/**
 * The DOLE premium-pay matrix, as pure integer arithmetic.
 *
 * These are the **statutory floors** from the Labor Code as amended (Arts. 86-94). They
 * are code, not data, precisely because they are law: an admin configures rates in the
 * pay_rules table (M4) and those rates are validated against these, so a misconfigured
 * 100% on a regular holiday is refused at the boundary rather than discovered at payday.
 *
 * Every parameter is mandatory. $isArt82Exempt especially must never acquire a default:
 * requiring it by signature is how "Art. 82 gates every premium" is enforced — it is not
 * possible to compute a multiplier here without stating the employee's status.
 *
 * See docs/06-roadmap.md for the matrix in table form.
 */
final class PayMultiplier
{
    /**
     * Base rate for time worked, keyed by [day type][is rest day].
     *
     * Note special non-working on a rest day is 150%, not 130% x 130% = 169%. DOLE
     * specifies a flat rate. This is why the table is a lookup and not a formula, and
     * the single most likely thing to get wrong by deriving it.
     *
     * @var array<string, array{false: int, true: int}>
     */
    private const array WORKED_BASE = [
        DayType::Ordinary->value => [false => 10_000, true => 13_000],
        DayType::SpecialWorking->value => [false => 10_000, true => 13_000],
        DayType::SpecialNonWorking->value => [false => 13_000, true => 15_000],
        DayType::RegularHoliday->value => [false => 20_000, true => 26_000],
        DayType::DoubleRegularHoliday->value => [false => 30_000, true => 39_000],
    ];

    /**
     * What a day pays when it is not worked. A regular holiday is paid regardless; a
     * special non-working day is "no work, no pay" absent a more generous company policy.
     *
     * @var array<string, int>
     */
    private const array UNWORKED = [
        DayType::Ordinary->value => 0,
        DayType::SpecialWorking->value => 0,
        DayType::SpecialNonWorking->value => 0,
        DayType::RegularHoliday->value => 10_000,
        DayType::DoubleRegularHoliday->value => 20_000,
    ];

    /** Overtime on an ordinary working day is +25%; everywhere else it is +30%. */
    private const int OVERTIME_ORDINARY = 12_500;

    private const int OVERTIME_PREMIUM = 13_000;

    /** Night differential, 22:00-06:00 (Art. 86). Compounds on whatever rate applies. */
    private const int NIGHT_DIFFERENTIAL = 11_000;

    public static function forWorkedTime(
        DayType $dayType,
        bool $isRestDay,
        bool $isOvertime,
        bool $isNightDiff,
        bool $isArt82Exempt,
    ): BasisPoints {
        // Managerial employees and field personnel are outside Art. 82's coverage:
        // no overtime, no night differential, no holiday premium, no SIL.
        if ($isArt82Exempt) {
            return BasisPoints::one();
        }

        $rate = BasisPoints::of(self::WORKED_BASE[$dayType->value][$isRestDay]);

        if ($isOvertime) {
            $rate = $rate->times(BasisPoints::of(self::overtimeFactor($dayType, $isRestDay)));
        }

        // Applied last, and multiplicatively: 10% of the hourly rate *for that hour*,
        // not 10% of base pay. Holiday overtime at 2am is 200% x 130% x 110% = 286%.
        if ($isNightDiff) {
            $rate = $rate->times(BasisPoints::of(self::NIGHT_DIFFERENTIAL));
        }

        return $rate;
    }

    public static function forUnworkedDay(DayType $dayType, bool $isArt82Exempt): BasisPoints
    {
        if ($isArt82Exempt) {
            return BasisPoints::of(0);
        }

        return BasisPoints::of(self::UNWORKED[$dayType->value]);
    }

    private static function overtimeFactor(DayType $dayType, bool $isRestDay): int
    {
        $isPlainWorkingDay = $dayType === DayType::Ordinary || $dayType === DayType::SpecialWorking;

        return ($isPlainWorkingDay && ! $isRestDay)
            ? self::OVERTIME_ORDINARY
            : self::OVERTIME_PREMIUM;
    }
}
```

- [ ] **Step 5: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Pay/PayMultiplierTest.php`
Expected: PASS — **29 tests**. The nine `it()` blocks expand via datasets: 10 base-rate cells, 8 overtime cells, 5 unworked-day cells, plus 6 standalone cases.

- [ ] **Step 6: Prove the matrix bites**

A table of constants that no test would catch changing is not a specification. Verify one cell is genuinely load-bearing:

```bash
cd backend
# Temporarily break the cell most likely to be "corrected" by a future reader:
sed -i "s/DayType::SpecialNonWorking->value => \[false => 13_000, true => 15_000\]/DayType::SpecialNonWorking->value => [false => 13_000, true => 16_900]/" app/Domain/Pay/PayMultiplier.php
./vendor/bin/pest tests/Unit/Domain/Pay/PayMultiplierTest.php
```

Expected: FAIL, on both `special non-working day on a rest day` and `does not derive the special non-working rest-day rate by formula`.

```bash
git checkout app/Domain/Pay/PayMultiplier.php
./vendor/bin/pest tests/Unit/Domain/Pay/PayMultiplierTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Domain/Pay/DayType.php backend/app/Domain/Pay/PayMultiplier.php backend/tests/Unit/Domain/Pay/PayMultiplierTest.php
git commit -m "Pay: the DOLE premium matrix, pinned cell by cell

Statutory floors as code, because they are law; configured rates in
pay_rules get validated against them. isArt82Exempt is mandatory by
signature, so a premium cannot be computed without stating status."
```

---

### Task 5: `WorkInterval`, `PunchPairer`, `MealBreakPolicy`

Meal breaks are configurable per office (see `docs/superpowers/specs/2026-07-23-hris-foundation-design.md`), so a day may be two punches or four. Both paths are built here.

**Files:**
- Create: `backend/app/Domain/Time/WorkInterval.php`
- Create: `backend/app/Domain/Time/PunchPairer.php`
- Create: `backend/app/Domain/Time/MealBreakPolicy.php`
- Test: `backend/tests/Unit/Domain/Time/PunchPairerTest.php`
- Test: `backend/tests/Unit/Domain/Time/MealBreakPolicyTest.php`

**Interfaces:**
- Consumes: `App\Domain\Time\Minutes` (Task 1).
- Produces:
  - `App\Domain\Time\WorkInterval` — `final readonly`, public `int $startMinute`, public `int $endMinute`. Static `of(int $start, int $end): self`. Method `duration(): Minutes`.
  - `App\Domain\Time\PunchPairer` — `final`. Static `pair(list<int> $punchMinutes): PairedPunches`.
  - `App\Domain\Time\PairedPunches` — `final readonly`, public `list<WorkInterval> $intervals`, public `?int $unpairedMinute`. Methods `hasUnpaired(): bool`, `totalWorked(): Minutes`.
  - `App\Domain\Time\MealBreakPolicy` — `final readonly`. Statics `assumed(int $breakMinutes, int $appliesOverMinutes): self`, `explicit(): self`. Method `netWorked(Minutes $gross): Minutes`.

**Time representation reminder:** all minutes are **offsets from the start of the business day in office-local wall-clock time**, and may exceed 1440 for a shift crossing midnight. A 22:00→06:00 night shift is `1320 → 1800`.

- [ ] **Step 1: Write the failing `PunchPairer` test**

Create `backend/tests/Unit/Domain/Time/PunchPairerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Time\PunchPairer;

it('pairs a plain two-punch day', function (): void {
    // 08:00 -> 17:00
    $paired = PunchPairer::pair([480, 1020]);

    expect($paired->intervals)->toHaveCount(1)
        ->and($paired->intervals[0]->startMinute)->toBe(480)
        ->and($paired->intervals[0]->endMinute)->toBe(1020)
        ->and($paired->hasUnpaired())->toBeFalse()
        ->and($paired->totalWorked()->value)->toBe(540);
});

it('pairs a four-punch day with an explicit meal break', function (): void {
    // 08:00 -> 12:00, back 13:00 -> 17:00. Offices on the explicit policy punch out
    // for lunch, so the break simply is not inside any interval.
    $paired = PunchPairer::pair([480, 720, 780, 1020]);

    expect($paired->intervals)->toHaveCount(2)
        ->and($paired->totalWorked()->value)->toBe(480)
        ->and($paired->hasUnpaired())->toBeFalse();
});

it('pairs a night shift that crosses midnight', function (): void {
    // 22:00 -> 06:00 the next morning, as minutes from the business day's start.
    $paired = PunchPairer::pair([1320, 1800]);

    expect($paired->totalWorked()->value)->toBe(480);
});

it('reports an odd punch count as unpaired rather than guessing', function (): void {
    // A punch-in with no punch-out. The day computes as zero paid hours and is flagged
    // incomplete; the employee files an adjustment (M5). Never auto-close it here.
    $paired = PunchPairer::pair([480, 720, 780]);

    expect($paired->hasUnpaired())->toBeTrue()
        ->and($paired->unpairedMinute)->toBe(780)
        ->and($paired->intervals)->toHaveCount(1)
        ->and($paired->totalWorked()->value)->toBe(240);
});

it('treats a lone punch as entirely unpaired', function (): void {
    $paired = PunchPairer::pair([480]);

    expect($paired->hasUnpaired())->toBeTrue()
        ->and($paired->unpairedMinute)->toBe(480)
        ->and($paired->intervals)->toBeEmpty()
        ->and($paired->totalWorked()->value)->toBe(0);
});

it('handles a day with no punches at all', function (): void {
    $paired = PunchPairer::pair([]);

    expect($paired->hasUnpaired())->toBeFalse()
        ->and($paired->intervals)->toBeEmpty()
        ->and($paired->totalWorked()->value)->toBe(0);
});

it('refuses punches that are not in ascending order', function (): void {
    // Out-of-order punches mean the caller sorted wrong or the data is corrupt.
    // Sorting them here would paper over that silently.
    expect(fn () => PunchPairer::pair([720, 480]))
        ->toThrow(InvalidArgumentException::class, 'ascending order');
});

it('refuses two punches at the same minute', function (): void {
    // A zero-length interval is a double-punch, not a shift.
    expect(fn () => PunchPairer::pair([480, 480]))
        ->toThrow(InvalidArgumentException::class, 'ascending order');
});

it('refuses a negative punch minute', function (): void {
    expect(fn () => PunchPairer::pair([-5, 480]))
        ->toThrow(InvalidArgumentException::class, 'cannot be negative');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Time/PunchPairerTest.php`
Expected: FAIL — `Class "App\Domain\Time\PunchPairer" not found`.

- [ ] **Step 3: Write `WorkInterval`**

Create `backend/app/Domain/Time/WorkInterval.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Time;

use InvalidArgumentException;

/**
 * A span of worked time, as minutes from the start of the business day in the office's
 * local wall-clock time.
 *
 * Values may exceed 1440: a 22:00 -> 06:00 night shift is 1320 -> 1800. Converting UTC
 * timestamptz punches into office-local business-day minutes is the engine's job (M5),
 * not this class's — which is what lets night-differential splitting be plain integer
 * arithmetic instead of dragging a timezone database into a value object.
 */
final readonly class WorkInterval
{
    private function __construct(
        public int $startMinute,
        public int $endMinute,
    ) {}

    public static function of(int $startMinute, int $endMinute): self
    {
        if ($startMinute < 0) {
            throw new InvalidArgumentException("A punch minute cannot be negative: {$startMinute}.");
        }

        if ($endMinute <= $startMinute) {
            throw new InvalidArgumentException(
                "An interval must end after it starts: {$startMinute} -> {$endMinute}."
            );
        }

        return new self($startMinute, $endMinute);
    }

    public function duration(): Minutes
    {
        return Minutes::of($this->endMinute - $this->startMinute);
    }
}
```

- [ ] **Step 4: Write `PairedPunches` and `PunchPairer`**

Create `backend/app/Domain/Time/PunchPairer.php` (both classes live in this file — they have one responsibility between them and are never used apart):

```php
<?php

declare(strict_types=1);

namespace App\Domain\Time;

use InvalidArgumentException;

/**
 * The result of pairing a day's punches.
 */
final readonly class PairedPunches
{
    /** @param  list<WorkInterval>  $intervals */
    public function __construct(
        public array $intervals,
        public ?int $unpairedMinute,
    ) {}

    public function hasUnpaired(): bool
    {
        return $this->unpairedMinute !== null;
    }

    public function totalWorked(): Minutes
    {
        return Minutes::sum(array_map(
            static fn (WorkInterval $interval): Minutes => $interval->duration(),
            $this->intervals,
        ));
    }
}

/**
 * Turns an ordered list of punch minutes into intervals.
 *
 * Pairs **arbitrary even counts**, not just one in/out pair: meal breaks are
 * configurable per office, and an office on the explicit policy produces a four-punch
 * day. See docs/superpowers/specs/2026-07-23-hris-foundation-design.md.
 *
 * An odd count is reported, never guessed at. A punch-in with no punch-out computes as
 * zero paid hours and is flagged incomplete; the employee files an adjustment (M5).
 * Auto-closing at the scheduled end time would pay for time nobody verified, and would
 * silently conceal people who left early.
 */
final class PunchPairer
{
    /** @param  list<int>  $punchMinutes  Ascending, from the start of the business day. */
    public static function pair(array $punchMinutes): PairedPunches
    {
        self::assertOrdered($punchMinutes);

        $intervals = [];
        $count = count($punchMinutes);
        $pairable = $count - ($count % 2);

        for ($i = 0; $i < $pairable; $i += 2) {
            $intervals[] = WorkInterval::of($punchMinutes[$i], $punchMinutes[$i + 1]);
        }

        return new PairedPunches(
            intervals: $intervals,
            unpairedMinute: $count % 2 === 1 ? $punchMinutes[$count - 1] : null,
        );
    }

    /** @param  list<int>  $punchMinutes */
    private static function assertOrdered(array $punchMinutes): void
    {
        $previous = null;

        foreach ($punchMinutes as $minute) {
            if ($minute < 0) {
                throw new InvalidArgumentException("A punch minute cannot be negative: {$minute}.");
            }

            if ($previous !== null && $minute <= $previous) {
                throw new InvalidArgumentException(
                    "Punches must be in ascending order: {$previous} is followed by {$minute}."
                );
            }

            $previous = $minute;
        }
    }
}
```

- [ ] **Step 5: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Time/PunchPairerTest.php`
Expected: PASS, 9 tests.

- [ ] **Step 6: Write the failing `MealBreakPolicy` test**

Create `backend/tests/Unit/Domain/Time/MealBreakPolicyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Time\MealBreakPolicy;
use App\Domain\Time\Minutes;

it('deducts a fixed break once the threshold is passed, under the assumed policy', function (): void {
    // Art. 83's 60-minute unpaid meal break, applied to any span over 5 hours.
    $policy = MealBreakPolicy::assumed(breakMinutes: 60, appliesOverMinutes: 300);

    expect($policy->netWorked(Minutes::of(540))->value)->toBe(480)
        ->and($policy->netWorked(Minutes::of(301))->value)->toBe(241);
});

it('deducts nothing from a short span under the assumed policy', function (): void {
    $policy = MealBreakPolicy::assumed(breakMinutes: 60, appliesOverMinutes: 300);

    expect($policy->netWorked(Minutes::of(300))->value)->toBe(300)
        ->and($policy->netWorked(Minutes::of(240))->value)->toBe(240)
        ->and($policy->netWorked(Minutes::zero())->value)->toBe(0);
});

it('never drives a span negative under the assumed policy', function (): void {
    // A threshold shorter than the break would otherwise produce a negative duration.
    $policy = MealBreakPolicy::assumed(breakMinutes: 60, appliesOverMinutes: 30);

    expect($policy->netWorked(Minutes::of(45))->value)->toBe(0);
});

it('deducts nothing under the explicit policy, because the break was punched out', function (): void {
    // Offices on the explicit policy have employees clock out for lunch, so the break
    // is already absent from the paired intervals. Deducting again would double-count.
    $policy = MealBreakPolicy::explicit();

    expect($policy->netWorked(Minutes::of(480))->value)->toBe(480)
        ->and($policy->netWorked(Minutes::of(540))->value)->toBe(540);
});

it('refuses nonsensical assumed parameters', function (): void {
    expect(fn () => MealBreakPolicy::assumed(-1, 300))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => MealBreakPolicy::assumed(60, -1))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 7: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Time/MealBreakPolicyTest.php`
Expected: FAIL — `Class "App\Domain\Time\MealBreakPolicy" not found`.

- [ ] **Step 8: Write `MealBreakPolicy`**

Create `backend/app/Domain/Time/MealBreakPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Time;

use InvalidArgumentException;

/**
 * How an office handles the Art. 83 meal break. Configurable per office — see
 * docs/superpowers/specs/2026-07-23-hris-foundation-design.md for the decision and
 * what it costs.
 *
 * Assumed: a fixed unpaid break is deducted from any span over a threshold. A day is
 * two punches. Simple, and what most PH employers run — at the cost that an employee
 * who works through lunch is unpaid for it with no record either way.
 *
 * Explicit: employees punch out and back in, so the break is already absent from the
 * paired intervals and nothing is deducted. Working through lunch becomes visible and
 * payable, at the cost of doubled punch volume and more incomplete days.
 *
 * Takes its parameters as constructor arguments and never reads config, so it stays
 * testable without a booted container. The office column that selects it lands in M2.
 */
final readonly class MealBreakPolicy
{
    private function __construct(
        private int $breakMinutes,
        private int $appliesOverMinutes,
    ) {}

    public static function assumed(int $breakMinutes, int $appliesOverMinutes): self
    {
        if ($breakMinutes < 0) {
            throw new InvalidArgumentException("A meal break cannot be negative: {$breakMinutes} minutes.");
        }

        if ($appliesOverMinutes < 0) {
            throw new InvalidArgumentException("A meal-break threshold cannot be negative: {$appliesOverMinutes} minutes.");
        }

        return new self($breakMinutes, $appliesOverMinutes);
    }

    public static function explicit(): self
    {
        return new self(0, PHP_INT_MAX);
    }

    /**
     * Net worked minutes after the policy is applied.
     *
     * Clamps at zero rather than throwing: a threshold configured shorter than the break
     * is a misconfiguration to surface in reporting, not a reason to fail a payroll run
     * mid-computation.
     */
    public function netWorked(Minutes $gross): Minutes
    {
        if ($gross->value <= $this->appliesOverMinutes) {
            return $gross;
        }

        return Minutes::of(max(0, $gross->value - $this->breakMinutes));
    }
}
```

- [ ] **Step 9: Run both files to verify they pass**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Time/`
Expected: PASS — 6 (Minutes) + 9 (PunchPairer) + 5 (MealBreakPolicy) = 20 tests.

- [ ] **Step 10: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Domain/Time/ backend/tests/Unit/Domain/Time/
git commit -m "Time: pair arbitrary even punch counts, and the meal-break policy

Meal breaks are configurable per office, so a day is two punches or four.
An odd count is reported unpaired, never guessed at — auto-closing would
pay for time nobody verified."
```

---

### Task 6: `NightDiffSplitter` and `OvertimeThreshold`

**Files:**
- Create: `backend/app/Domain/Time/NightDiffSplitter.php`
- Create: `backend/app/Domain/Time/WorkedSplit.php`
- Create: `backend/app/Domain/Time/OvertimeThreshold.php`
- Test: `backend/tests/Unit/Domain/Time/NightDiffSplitterTest.php`
- Test: `backend/tests/Unit/Domain/Time/OvertimeThresholdTest.php`

**Interfaces:**
- Consumes: `App\Domain\Time\Minutes` (Task 1), `App\Domain\Time\WorkInterval` (Task 5).
- Produces:
  - `App\Domain\Time\NightDiffSplitter` — `final`. Consts `WINDOW_START_MINUTE = 1320` (22:00), `WINDOW_END_MINUTE = 360` (06:00). Static `split(WorkInterval $interval): WorkedSplit` returning `inside` = night minutes, `outside` = day minutes.
  - `App\Domain\Time\WorkedSplit` — `final readonly`, public `Minutes $inside`, public `Minutes $outside`. Static `of(Minutes $inside, Minutes $outside): self`. Method `total(): Minutes`.
  - `App\Domain\Time\OvertimeThreshold` — `final`. Static `split(Minutes $worked, Minutes $scheduled): WorkedSplit` where `inside` = regular minutes and `outside` = overtime minutes. Static `undertime(Minutes $worked, Minutes $scheduled): Minutes`.

`WorkedSplit` is deliberately reused for both. Its two fields mean "the part that matched the criterion" and "the part that did not" — night vs day, regular vs overtime. One small type, two uses, no premature abstraction.

- [ ] **Step 1: Write the failing `NightDiffSplitter` test**

Create `backend/tests/Unit/Domain/Time/NightDiffSplitterTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Time\NightDiffSplitter;
use App\Domain\Time\WorkInterval;

it('finds no night minutes in an ordinary day shift', function (): void {
    // 08:00 -> 17:00
    $split = NightDiffSplitter::split(WorkInterval::of(480, 1020));

    expect($split->inside->value)->toBe(0)
        ->and($split->outside->value)->toBe(540);
});

it('splits an evening shift that runs into the night window', function (): void {
    // 18:00 -> 23:30. The window opens at 22:00, so 90 minutes are night.
    $split = NightDiffSplitter::split(WorkInterval::of(1080, 1410));

    expect($split->inside->value)->toBe(90)
        ->and($split->outside->value)->toBe(240)
        ->and($split->total()->value)->toBe(330);
});

it('splits a night shift that crosses midnight', function (): void {
    // 22:00 -> 06:00, entirely inside the window.
    $split = NightDiffSplitter::split(WorkInterval::of(1320, 1800));

    expect($split->inside->value)->toBe(480)
        ->and($split->outside->value)->toBe(0);
});

it('splits a shift ending after the window closes', function (): void {
    // 02:00 -> 10:00. Night runs 02:00-06:00 (240 min), day 06:00-10:00 (240 min).
    $split = NightDiffSplitter::split(WorkInterval::of(120, 600));

    expect($split->inside->value)->toBe(240)
        ->and($split->outside->value)->toBe(240);
});

it('splits a shift spanning both night bands of one business day', function (): void {
    // 03:00 -> 23:00 — an unrealistic 20-hour span, but it proves both bands are found:
    // 03:00-06:00 (180) plus 22:00-23:00 (60) = 240 night minutes.
    $split = NightDiffSplitter::split(WorkInterval::of(180, 1380));

    expect($split->inside->value)->toBe(240)
        ->and($split->outside->value)->toBe(960)
        ->and($split->total()->value)->toBe(1200);
});

it('handles a shift that starts and ends inside the same night band', function (): void {
    // 23:00 -> 01:00
    $split = NightDiffSplitter::split(WorkInterval::of(1380, 1500));

    expect($split->inside->value)->toBe(120)
        ->and($split->outside->value)->toBe(0);
});

it('always accounts for every worked minute exactly once', function (): void {
    // The property that matters: inside + outside == the interval's own duration.
    foreach ([[480, 1020], [1080, 1410], [1320, 1800], [120, 600], [180, 1380], [1380, 1500], [0, 1440]] as [$start, $end]) {
        $interval = WorkInterval::of($start, $end);
        $split = NightDiffSplitter::split($interval);

        expect($split->total()->value)->toBe($interval->duration()->value);
    }
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Time/NightDiffSplitterTest.php`
Expected: FAIL — `Class "App\Domain\Time\NightDiffSplitter" not found`.

- [ ] **Step 3: Write `WorkedSplit`**

Create `backend/app/Domain/Time/WorkedSplit.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Worked time divided in two by some criterion: the part that matched and the part
 * that did not.
 *
 * Used for night differential (inside/outside the 22:00-06:00 window) and for overtime
 * (regular hours vs. hours beyond the schedule). One small type, two uses — the shape
 * is identical and inventing a second name for it would be abstraction without benefit.
 */
final readonly class WorkedSplit
{
    private function __construct(
        public Minutes $inside,
        public Minutes $outside,
    ) {}

    public static function of(Minutes $inside, Minutes $outside): self
    {
        return new self($inside, $outside);
    }

    public function total(): Minutes
    {
        return $this->inside->plus($this->outside);
    }
}
```

- [ ] **Step 4: Write `NightDiffSplitter`**

Create `backend/app/Domain/Time/NightDiffSplitter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Splits a worked interval against the night-differential window, 22:00-06:00 (Art. 86).
 *
 * Works on integer minutes from the start of the business day, so a shift crossing
 * midnight needs no special case and no timezone database: the window simply recurs
 * every 1440 minutes, and the splitter walks as many recurrences as the interval spans.
 *
 * Night differential is 10% of the hourly rate *for that hour*, so these minutes get
 * multiplied by whatever premium already applies — see PayMultiplier.
 */
final class NightDiffSplitter
{
    /** 22:00, as minutes from midnight. */
    public const int WINDOW_START_MINUTE = 1_320;

    /** 06:00, as minutes from midnight. The window wraps, so this is less than the start. */
    public const int WINDOW_END_MINUTE = 360;

    private const int MINUTES_PER_DAY = 1_440;

    public static function split(WorkInterval $interval): WorkedSplit
    {
        $night = 0;

        // The window recurs daily. Walk one day before the interval starts through one
        // day after it ends, so a band straddling either edge is still counted.
        $firstDay = intdiv($interval->startMinute, self::MINUTES_PER_DAY) - 1;
        $lastDay = intdiv($interval->endMinute, self::MINUTES_PER_DAY) + 1;

        for ($day = $firstDay; $day <= $lastDay; $day++) {
            $offset = $day * self::MINUTES_PER_DAY;

            $night += self::overlap(
                $interval->startMinute,
                $interval->endMinute,
                $offset + self::WINDOW_START_MINUTE,
                $offset + self::MINUTES_PER_DAY + self::WINDOW_END_MINUTE,
            );
        }

        return WorkedSplit::of(
            inside: Minutes::of($night),
            outside: Minutes::of($interval->duration()->value - $night),
        );
    }

    private static function overlap(int $aStart, int $aEnd, int $bStart, int $bEnd): int
    {
        return max(0, min($aEnd, $bEnd) - max($aStart, $bStart));
    }
}
```

- [ ] **Step 5: Run it to verify it passes**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Time/NightDiffSplitterTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 6: Write the failing `OvertimeThreshold` test**

Create `backend/tests/Unit/Domain/Time/OvertimeThresholdTest.php`:

```php
<?php

declare(strict_types=1);

use App\Domain\Time\Minutes;
use App\Domain\Time\OvertimeThreshold;

it('reports a full day with no overtime', function (): void {
    $split = OvertimeThreshold::split(Minutes::of(480), Minutes::of(480));

    expect($split->inside->value)->toBe(480)
        ->and($split->outside->value)->toBe(0);
});

it('splits hours beyond the scheduled day into overtime', function (): void {
    // 10h30m worked against an 8h schedule.
    $split = OvertimeThreshold::split(Minutes::of(630), Minutes::of(480));

    expect($split->inside->value)->toBe(480)
        ->and($split->outside->value)->toBe(150);
});

it('reports no overtime and no negative regular time when a day is short', function (): void {
    $split = OvertimeThreshold::split(Minutes::of(300), Minutes::of(480));

    expect($split->inside->value)->toBe(300)
        ->and($split->outside->value)->toBe(0);
});

it('measures undertime as its own non-negative magnitude', function (): void {
    // Undertime is not negative overtime. It is a separate number on the payslip.
    expect(OvertimeThreshold::undertime(Minutes::of(300), Minutes::of(480))->value)->toBe(180)
        ->and(OvertimeThreshold::undertime(Minutes::of(480), Minutes::of(480))->value)->toBe(0)
        ->and(OvertimeThreshold::undertime(Minutes::of(630), Minutes::of(480))->value)->toBe(0);
});

it('handles a compressed workweek schedule', function (): void {
    // 4x10 compressed: a 10-hour scheduled day, so hour nine is regular, not overtime.
    $split = OvertimeThreshold::split(Minutes::of(660), Minutes::of(600));

    expect($split->inside->value)->toBe(600)
        ->and($split->outside->value)->toBe(60);
});

it('always accounts for every worked minute exactly once', function (): void {
    foreach ([[480, 480], [630, 480], [300, 480], [660, 600], [0, 480]] as [$worked, $scheduled]) {
        $split = OvertimeThreshold::split(Minutes::of($worked), Minutes::of($scheduled));

        expect($split->total()->value)->toBe($worked);
    }
});
```

- [ ] **Step 7: Run it to verify it fails**

Run: `cd backend && ./vendor/bin/pest tests/Unit/Domain/Time/OvertimeThresholdTest.php`
Expected: FAIL — `Class "App\Domain\Time\OvertimeThreshold" not found`.

- [ ] **Step 8: Write `OvertimeThreshold`**

Create `backend/app/Domain/Time/OvertimeThreshold.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Time;

/**
 * Divides worked minutes into regular and overtime against a scheduled day.
 *
 * The threshold is the schedule's own length, not a fixed eight hours: a compressed
 * 4x10 workweek has a ten-hour scheduled day, so hour nine is regular time (DOLE
 * Department Advisory 02-04).
 *
 * Undertime is deliberately *not* negative overtime. It is a separate non-negative
 * magnitude, because it appears as its own line on a payslip and because a negative
 * duration is not representable in Minutes.
 *
 * Note this splits *worked* time only. Whether the overtime is payable is a different
 * question, answered in M5: the engine pays min(actual, approved) against a pre-filed
 * overtime authorization, and shows the remainder as unpaid excess time.
 */
final class OvertimeThreshold
{
    /** `inside` is regular time; `outside` is overtime. */
    public static function split(Minutes $worked, Minutes $scheduled): WorkedSplit
    {
        $regular = $worked->min($scheduled);

        return WorkedSplit::of(
            inside: $regular,
            outside: $worked->minus($regular),
        );
    }

    public static function undertime(Minutes $worked, Minutes $scheduled): Minutes
    {
        return $scheduled->minus($scheduled->min($worked));
    }
}
```

- [ ] **Step 9: Run the whole unit suite**

Run: `cd backend && ./vendor/bin/pest --testsuite=Unit`
Expected: PASS — 84 tests. (Minutes 6, Money 13, BasisPoints 9, PayMultiplier 29 after dataset expansion, PunchPairer 9, MealBreakPolicy 5, NightDiffSplitter 7, OvertimeThreshold 6.)

- [ ] **Step 10: Commit**

```bash
cd /home/haru/projects/hris
git add backend/app/Domain/Time/ backend/tests/Unit/Domain/Time/
git commit -m "Time: night-differential splitting and the overtime threshold

The night window recurs every 1440 minutes, so a shift crossing midnight
needs no special case. The overtime threshold is the schedule's own
length, not a fixed eight hours — a 4x10 compressed week depends on it."
```

---

### Task 7: Browser mirrors

The one place integer minutes and integer centavos become human text.

**Files:**
- Create: `frontend/web/src/lib/duration.ts`
- Create: `frontend/web/src/lib/duration.test.ts`
- Create: `frontend/web/src/lib/money.ts`
- Create: `frontend/web/src/lib/money.test.ts`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `formatDuration(minutes: number): string` — `450` → `"7h 30m"`, `60` → `"1h"`, `45` → `"45m"`, `0` → `"0m"`.
  - `formatDurationDecimal(minutes: number): string` — `450` → `"7.50"`, for payroll export columns only.
  - `formatCentavos(cents: number): string` — `123456` → `"₱1,234.56"`.
  - `formatCentavosPlain(cents: number): string` — `123456` → `"1,234.56"`, no symbol, for table cells with a currency column header.

- [ ] **Step 1: Write the failing tests**

Create `frontend/web/src/lib/duration.test.ts`:

```ts
import { describe, expect, it } from 'vitest'

import { formatDuration, formatDurationDecimal } from './duration'

describe('formatDuration', () => {
  it('renders hours and minutes', () => {
    expect(formatDuration(450)).toBe('7h 30m')
    expect(formatDuration(140)).toBe('2h 20m')
  })

  it('drops the minutes part on a whole hour', () => {
    expect(formatDuration(60)).toBe('1h')
    expect(formatDuration(480)).toBe('8h')
  })

  it('drops the hours part under an hour', () => {
    expect(formatDuration(45)).toBe('45m')
    expect(formatDuration(0)).toBe('0m')
  })

  it('handles spans longer than a day', () => {
    // Night shifts and cumulative period totals both exceed 24h.
    expect(formatDuration(1500)).toBe('25h')
    expect(formatDuration(1501)).toBe('25h 1m')
  })

  it('rejects a non-integer, because worked time is integer minutes', () => {
    // 7.333… hours is exactly the value this whole system exists to never produce.
    expect(() => formatDuration(7.5)).toThrow(/integer/)
  })

  it('rejects a negative duration', () => {
    expect(() => formatDuration(-1)).toThrow(/negative/)
  })
})

describe('formatDurationDecimal', () => {
  it('renders decimal hours for payroll export columns only', () => {
    expect(formatDurationDecimal(450)).toBe('7.50')
    expect(formatDurationDecimal(480)).toBe('8.00')
    expect(formatDurationDecimal(440)).toBe('7.33')
  })
})
```

Create `frontend/web/src/lib/money.test.ts`:

```ts
import { describe, expect, it } from 'vitest'

import { formatCentavos, formatCentavosPlain } from './money'

describe('formatCentavos', () => {
  it('renders pesos with a symbol, a group separator and two decimals', () => {
    expect(formatCentavos(123456)).toBe('₱1,234.56')
    expect(formatCentavos(0)).toBe('₱0.00')
    expect(formatCentavos(5)).toBe('₱0.05')
  })

  it('renders large amounts', () => {
    expect(formatCentavos(123456789)).toBe('₱1,234,567.89')
  })

  it('renders negatives with the sign before the symbol', () => {
    expect(formatCentavos(-50000)).toBe('-₱500.00')
  })

  it('rejects a non-integer, because money is integer centavos', () => {
    expect(() => formatCentavos(1.5)).toThrow(/integer/)
  })
})

describe('formatCentavosPlain', () => {
  it('omits the symbol for tables that carry a currency column header', () => {
    expect(formatCentavosPlain(123456)).toBe('1,234.56')
    expect(formatCentavosPlain(-50000)).toBe('-500.00')
  })
})
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd frontend/web && npm test`
Expected: FAIL — `Failed to resolve import "./duration"` and `"./money"`.

- [ ] **Step 3: Write `duration.ts`**

Create `frontend/web/src/lib/duration.ts`:

```ts
/**
 * The one place integer minutes become human text — the direct mirror of the backend's
 * `App\Domain\Time\Minutes`.
 *
 * Worked time is integer minutes everywhere, in every layer. `7h 20m` is 7.333… hours,
 * and a shift is not a number you may round twice; JavaScript's `number` is IEEE-754,
 * so a decimal-hours value passing through the browser would be exactly the drift this
 * system exists to prevent. See docs/01-architecture.md.
 */

function assertWholeMinutes(minutes: number): void {
  if (!Number.isInteger(minutes)) {
    throw new Error(`Worked time is integer minutes; got ${minutes}.`)
  }
  if (minutes < 0) {
    throw new Error(`A duration cannot be negative; got ${minutes}.`)
  }
}

/** `450` → `"7h 30m"`, `60` → `"1h"`, `45` → `"45m"`, `0` → `"0m"`. */
export function formatDuration(minutes: number): string {
  assertWholeMinutes(minutes)

  const hours = Math.floor(minutes / 60)
  const remainder = minutes % 60

  if (hours === 0) return `${remainder}m`
  if (remainder === 0) return `${hours}h`

  return `${hours}h ${remainder}m`
}

/**
 * Decimal hours, two places — for payroll export columns and nothing else.
 *
 * Deliberately separate from `formatDuration` and deliberately awkward to reach for:
 * this is the only representation in the system where a duration is not integer
 * minutes, and it exists solely because external payroll formats demand it.
 */
export function formatDurationDecimal(minutes: number): string {
  assertWholeMinutes(minutes)

  return (minutes / 60).toFixed(2)
}
```

- [ ] **Step 4: Write `money.ts`**

Create `frontend/web/src/lib/money.ts`:

```ts
/**
 * The one place integer centavos become human text — the mirror of the backend's
 * `App\Domain\Money\Money`.
 *
 * Money is integer centavos in every layer. There is no parsing here and no arithmetic:
 * the browser formats amounts the server computed, and never computes one itself. Every
 * centavo in this system is created or destroyed in exactly one place, `Money::fraction()`,
 * on the server. See docs/01-architecture.md.
 */

const CURRENCY_SYMBOL = '₱'

function assertWholeCentavos(cents: number): void {
  if (!Number.isInteger(cents)) {
    throw new Error(`Money is integer centavos; got ${cents}.`)
  }
}

function group(cents: number): string {
  const absolute = Math.abs(cents)
  const pesos = Math.floor(absolute / 100)
  const remainder = (absolute % 100).toString().padStart(2, '0')

  return `${pesos.toLocaleString('en-PH')}.${remainder}`
}

/** `123456` → `"₱1,234.56"`. Negative amounts sign before the symbol: `"-₱500.00"`. */
export function formatCentavos(cents: number): string {
  assertWholeCentavos(cents)

  const sign = cents < 0 ? '-' : ''

  return `${sign}${CURRENCY_SYMBOL}${group(cents)}`
}

/** `123456` → `"1,234.56"`. For table cells whose column header already names the currency. */
export function formatCentavosPlain(cents: number): string {
  assertWholeCentavos(cents)

  const sign = cents < 0 ? '-' : ''

  return `${sign}${group(cents)}`
}
```

- [ ] **Step 5: Run the frontend gate**

Run: `cd frontend/web && npm run lint && npm test && npm run typecheck && npm run build`
Expected: all PASS. Vitest: 4 existing + 10 new = **14 tests** across 3 files.

- [ ] **Step 6: Commit**

```bash
cd /home/haru/projects/hris
git add frontend/web/src/lib/duration.ts frontend/web/src/lib/duration.test.ts frontend/web/src/lib/money.ts frontend/web/src/lib/money.test.ts
git commit -m "Web: the browser mirrors for minutes and centavos

One place each where an integer becomes human text. formatDurationDecimal
is deliberately separate and deliberately awkward — decimal hours exist
only because external payroll formats demand them."
```

---

### Task 8: Tighten the arch rules and reconcile the docs

M0's final review named two arch rules M1 must extend, and one documented promise that needs correcting rather than keeping.

**Files:**
- Modify: `backend/tests/Arch/ConventionsTest.php`
- Modify: `docs/04-backend-conventions.md`
- Modify: `docs/01-architecture.md`
- Modify: `docs/06-roadmap.md`

**Interfaces:**
- Consumes: everything from Tasks 1–7.
- Produces: nothing consumed by later work; a gate and an honest doc set.

- [ ] **Step 1: Tighten the domain-purity arch rule**

In `backend/tests/Arch/ConventionsTest.php`, replace the existing `'the domain layer is framework-agnostic'` rule with:

```php
arch('the domain layer is framework-agnostic')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate\Http',
        'Illuminate\Foundation',
        'Illuminate\Support\Facades',
        'Illuminate\Database',
    ]);
```

Then add, immediately after it:

```php
arch('the domain layer never reads configuration')
    ->expect('App\Domain')
    ->not->toUse(['config', 'env', 'app', 'resolve']);

arch('domain value objects are final')
    ->expect('App\Domain')
    ->toBeClasses()
    ->toBeFinal()
    ->ignoring('App\Domain\Pay\DayType');
```

`DayType` is ignored because it is an enum, not a class, and Pest's `toBeClasses()` would reject it.

The configuration rule is the one that matters most: a value object that calls `config()` means every unit test needs a booted container to test integer arithmetic, and `tests/Unit/` stops being pure. `docs/04-backend-conventions.md` already states the rule; this makes CI enforce it.

- [ ] **Step 2: Run the arch suite**

Run: `cd backend && ./vendor/bin/pest --testsuite=Arch`
Expected: PASS, 10 tests.

- [ ] **Step 3: Prove the new rules bite**

```bash
cd backend
cat > app/Domain/Pay/Scratch.php <<'PHP'
<?php

declare(strict_types=1);

namespace App\Domain\Pay;

final class Scratch
{
    public function rate(): int
    {
        return (int) config('hris.currency');
    }
}
PHP
./vendor/bin/pest --testsuite=Arch
```

Expected: FAIL on `the domain layer never reads configuration`.

```bash
rm app/Domain/Pay/Scratch.php
./vendor/bin/pest --testsuite=Arch
```

Expected: PASS, 10 tests.

- [ ] **Step 4: Correct the Art. 82 enforcement claim**

`docs/04-backend-conventions.md`'s rule 7 currently promises "`tests/Arch/` enforces this from M1". That is not what was built, and a promise a reader can check and find false costs more than it buys.

Find rule 7 and replace its final sentence with an accurate description of the real mechanism:

> Enforcement is by type signature, not by arch test: `PayMultiplier::forWorkedTime()` and `forUnworkedDay()` take `bool $isArt82Exempt` as a **mandatory** parameter with no default, so it is not possible to compute a premium without stating the employee's status. That is stronger than a static rule — an arch test can only see that a symbol was referenced, while a required parameter makes the omission fail to compile.

- [ ] **Step 5: Record M1 in the architecture doc**

In `docs/01-architecture.md`'s numeric-rules section, add the two facts M1 established that a reader of the code would otherwise have to infer:

> **Time is represented as integer minutes from the start of the business day, in the office's local wall-clock time.** Values may exceed 1440: a 22:00 → 06:00 night shift is `1320 → 1800`. Converting UTC `timestamptz` punches into office-local business-day minutes is the engine's job (M5), not the primitives'. This is what lets night-differential splitting be plain integer arithmetic rather than dragging a timezone database into a value object.
>
> **`BasisPoints::times()` carries its own copy of the half-away-from-zero rounding rule** rather than calling into `Money`. Exposing `Money`'s rule publicly would invite call sites to round outside `fraction()`, which is the single thing that rule exists to prevent. The duplication is four lines and is deliberate.

- [ ] **Step 6: Mark M1 complete in the roadmap**

In `docs/06-roadmap.md`, add a `**Status: complete.**` block under M1, in the same style as M0's, recording:

- The rest-day adjustment is a **lookup table, not a formula** — special non-working on a rest day is a flat 150%, not 130% × 130% = 169%. Deriving it is the most likely way to get the matrix wrong.
- Night differential **compounds on the already-premium rate**: holiday overtime at 2am is 200% × 130% × 110% = 286%, not 210%.
- `PunchPairer` pairs arbitrary even counts because meal breaks are per-office configurable; an odd count is reported, never guessed at.
- The night window recurs every 1440 minutes, so a shift crossing midnight needs no special case.
- Art. 82 enforcement is by mandatory parameter, not by arch test — see `04-backend-conventions.md` rule 7.

- [ ] **Step 7: Run everything**

```bash
cd /home/haru/projects/hris/backend && ./vendor/bin/pest
cd /home/haru/projects/hris/frontend/web && npm run lint && npm test && npm run typecheck && npm run build
cd /home/haru/projects/hris && make test
```

Expected: backend **111 tests** (27 from M0 + 84 new), frontend **14 tests**, all green, output pristine.

- [ ] **Step 8: Commit**

```bash
cd /home/haru/projects/hris
git add backend/tests/Arch/ConventionsTest.php docs/
git commit -m "Arch: bar config reads from the domain layer; reconcile the docs

A value object that calls config() means every unit test needs a booted
container to test integer arithmetic. Rule 7 promised an arch test for
Art. 82; the real mechanism is a mandatory parameter, which is stronger."
```

---

## Done When

`./vendor/bin/pest --testsuite=Unit` is green with **84 tests and zero database**, the full DOLE premium matrix is pinned cell by cell, and the arch suite refuses a domain class that reads configuration.
