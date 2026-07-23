<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
| The rules in docs/04-backend-conventions.md, enforced by CI rather than by review.
| A convention nobody checks is a suggestion, and this pattern's entire value is that
| every system action looks like every other one.
*/

arch('actions never touch HTTP')
    ->expect('App\Actions')
    ->not->toUse([
        'Illuminate\Http\Request',
        'Illuminate\Http\Response',
        'Illuminate\Http\JsonResponse',
        'Illuminate\Http\Resources\Json\JsonResource',
        'Illuminate\Foundation\Http\FormRequest',
    ]);

arch('actions are final')
    ->expect('App\Actions')
    ->toBeClasses()
    ->toBeFinal();

arch('the domain layer is framework-agnostic')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate\Http',
        'Illuminate\Foundation',
        'Illuminate\Support\Facades',
        'Illuminate\Database',
    ])
    // EmployeeScope is a Scope/query-constraint builder, not a plain domain value object or
    // policy — its entire job is to return an Eloquent Builder. The M1 purity rule this arch
    // test enforces bars config() and facades from the domain layer; it was never meant to bar
    // the ORM from the one class whose contract is "hand back a constrained query." See
    // docs/superpowers/specs/2026-07-23-m2-schema-auth-rbac-design.md.
    ->ignoring('App\Domain\Scope\EmployeeScope');

arch('the domain layer never reads configuration')
    ->expect('App\Domain')
    ->not->toUse(['config', 'env', 'app', 'resolve']);

arch('domain value objects are final')
    ->expect('App\Domain')
    ->toBeClasses()
    ->ignoring([
        'App\Domain\Pay\DayType',
        'App\Domain\Attendance\PunchDirection',
        'App\Domain\Attendance\PunchSource',
        'App\Domain\Attendance\PunchVerification',
    ])
    ->toBeFinal()
    ->ignoring([
        'App\Domain\Pay\DayType',
        'App\Domain\Attendance\PunchDirection',
        'App\Domain\Attendance\PunchSource',
        'App\Domain\Attendance\PunchVerification',
    ]);

arch('controllers are final single-action classes')
    ->expect('App\Http\Controllers')
    ->toBeFinal()
    ->toBeInvokable();

arch('env() is only ever called from config files')
    ->expect('env')
    ->not->toBeUsed();

arch('nothing debug-related survives to CI')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('domain exceptions extend the base so the render hook catches them')
    ->expect('App\Exceptions\Domain')
    ->toExtend('App\Exceptions\Domain\DomainException')
    ->ignoring('App\Exceptions\Domain\DomainException');

arch('strict types everywhere')
    ->expect('App')
    ->toUseStrictTypes();

// The show controller authorizes through EmployeePolicy::view() rather than calling
// EmployeeScope directly (it defers to the policy, which is the single definition of
// "in scope" shared with the index — see EmployeePolicy). Asserting the bare
// `->expect('App\Http\Controllers\Employees')->toUse(EmployeeScope::class)` form fails for
// ShowEmployeeController because it references the policy, not the scope, by name. Scoping
// this rule to ListEmployeesController keeps the guarantee precise: the index — which loads
// employees directly, with nothing else standing between it and the database — must never
// bypass EmployeeScope.
arch('the employee index goes through EmployeeScope, never a bare Employee query')
    ->expect('App\Http\Controllers\Employees\ListEmployeesController')
    ->toUse('App\Domain\Scope\EmployeeScope');

// ShowEmployeeController never references EmployeePolicy by name — it goes through the
// Gate (`$request->user()->cannot('view', $employee)`), resolved at runtime via the
// Gate::policy() binding in AppServiceProvider, so there is no `use` statement for a
// ->toUse() rule to find. Asserting the policy itself is built on EmployeeScope closes the
// gap for the one controller checked here: the show path is show -> Gate -> EmployeePolicy
// -> EmployeeScope. Together, these three rules enforce: the index filters at the query
// level through EmployeeScope; the policy resolves "can see" as EmployeeScope membership;
// and (below) every controller under Employees/ must reference an authorization boundary —
// EmployeeScope or a gate call — so a regression that deletes the show controller's
// `cannot()` check, or a new sibling controller that loads and serializes an employee with
// no gate at all, fails CI. The third rule is a source-grep for the *reference*, not a
// proof the check is semantically correct (right policy ability, right query) — the
// feature-test matrix in ScopeMatrixTest proves the semantics.
arch('EmployeePolicy defines "can see" as EmployeeScope membership')
    ->expect('App\Policies\EmployeePolicy')
    ->toUse('App\Domain\Scope\EmployeeScope');

test('every Employees controller references an authorization boundary', function (): void {
    // Neither of the two rules above forces ShowEmployeeController — or any future
    // controller dropped into this directory — to gate at all. toUse() only proves that a
    // file which *does* reference EmployeeScope or EmployeePolicy uses it correctly; it says
    // nothing about a file that references neither. So this greps every controller under
    // Employees/ and requires each one to contain either the query-filter pattern
    // (EmployeeScope, for index-style controllers) or a per-record gate call (->cannot(,
    // ->can(, ->authorize(, or Gate::, for show-style controllers). A file with neither is
    // an unguarded read path: it can load and serialize an Employee with nothing standing
    // between it and the database.
    $offenders = [];

    $files = (new Finder)
        ->files()
        ->in(base_path('app/Http/Controllers/Employees'))
        ->name('*.php');

    $patterns = [
        '/EmployeeScope/',
        '/->cannot\(/',
        '/->can\(/',
        '/->authorize\(/',
        '/Gate::/',
    ];

    foreach ($files as $file) {
        $contents = $file->getContents();

        $guarded = false;

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $guarded = true;

                break;
            }
        }

        if (! $guarded) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'Controller(s) under app/Http/Controllers/Employees/ serve employees without referencing an authorization boundary (EmployeeScope or a ->cannot()/->can()/->authorize()/Gate:: call): '.implode(', ', $offenders));
});

test('every Attendance controller references a scope or self check', function (): void {
    // Sibling to the Employees rule above, adapted to the M3 read paths. A read
    // controller under Attendance/ is guarded one of two ways: it is self-only, loading
    // the caller's OWN employee via `$request->user()->employee` (ListMyAttendanceController,
    // PunchController), or it is scoped by EmployeeScope for a subject taken from the
    // route (ListEmployeeAttendanceController). Either reference proves a boundary is
    // present; this greps every controller under Attendance/ for one of the two and fails
    // if neither is found, so a future controller that loads and serializes an
    // AttendanceLog with no scope/self check at all is caught here rather than in review.
    $offenders = [];

    $files = (new Finder)
        ->files()
        ->in(base_path('app/Http/Controllers/Attendance'))
        ->name('*.php');

    $patterns = [
        '/EmployeeScope/',
        '/user\(\)->employee\b/',
    ];

    foreach ($files as $file) {
        $contents = $file->getContents();

        $guarded = false;

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $guarded = true;

                break;
            }
        }

        if (! $guarded) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'Controller(s) under app/Http/Controllers/Attendance/ serve attendance data without referencing a scope or self check (EmployeeScope or $request->user()->employee): '.implode(', ', $offenders));
});

test('only RecordEmploymentChange writes the employment cache columns', function (): void {
    // The installed pest-plugin-arch's toOnlyBeUsedIn() walks a class/function "uses"
    // dependency graph built from `use` statements and function-call nodes — it does not
    // see plain string literals sitting inside an array literal like
    // ['current_office_id' => $value]. A rule built on it would silently never fire for
    // this case (verified empirically: it kept passing even with a second writer of
    // current_office_id present), so this asserts the guarantee directly: grep app/ for
    // any file that writes one of the three cache columns, and require
    // RecordEmploymentChange.php to be the only one.
    //
    // Three write forms are matched, per column:
    //   - mass assignment:      'current_office_id' => ...   (create()/update()/fill())
    //   - property assignment:  $employee->current_office_id = ...
    //   - setAttribute:         $employee->setAttribute('current_office_id', ...)
    // A bare `=` is required (not `==`, `===`, `=>`, `<=`, `>=`, `!=`) so that reads like
    // `where('current_office_id', '=', $x)` and relation definitions like
    // `belongsTo(Employee::class, 'current_reports_to_id')` — a quoted string followed by
    // `)`, never `=>` or `=` — are left alone.
    //
    // The mass-assignment form ('col' => ...) is textually identical whether it is a write
    // (Employee::create(['current_office_id' => $x])) or a read used to shape output
    // (['current_office_id' => $employee->current_office_id] in a JsonResource). A
    // JsonResource is a read-only presentation layer that structurally cannot call
    // create()/update()/fill()/save(), so a 'col' => in app/Http/Resources is always a
    // read-mapping, never a write — that sub-pattern is skipped there. The property and
    // setAttribute forms genuinely indicate a write anywhere, including an accidental one
    // in a Resource, so those stay global.
    $columns = ['current_office_id', 'current_department_id', 'current_reports_to_id'];

    $writers = [];

    $files = (new Finder)
        ->files()
        ->in(base_path('app'))
        ->name('*.php');

    foreach ($files as $file) {
        $contents = $file->getContents();
        $isResource = str_contains(str_replace('\\', '/', $file->getRelativePathname()), 'Http/Resources/');

        foreach ($columns as $column) {
            $quoted = preg_quote($column, '/');

            $patterns = [
                '/->'.$quoted.'\b\s*=(?!=|>)/',                    // property assignment: ->col = (not ==, ===, =>)
                '/setAttribute\(\s*[\'"]'.$quoted.'[\'"]/',        // setAttribute('col', ...)
            ];

            if (! $isResource) {
                $patterns[] = '/[\'"]'.$quoted.'[\'"]\s*=>/';      // mass assignment: 'col' => ...
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $writers[$file->getRelativePathname()] = true;

                    break 2;
                }
            }
        }
    }

    expect(array_keys($writers))->toBe(['Actions/Employees/RecordEmploymentChange.php']);
});

test('only RecordPunch writes attendance_logs', function (): void {
    // Same mechanism as the cache-column guard above, adapted to a single append-only
    // table rather than a set of columns: toOnlyBeUsedIn() walks a use-statement/call-node
    // graph and would not reliably catch a write buried in an array literal or a
    // model-static call chain, so this asserts the guarantee directly by grepping app/ for
    // any file that writes an AttendanceLog and requiring RecordPunch.php to be the only
    // one.
    //
    // A write is only reachable from a file that references AttendanceLog (the class) or
    // attendance_logs (the raw table name) at all, so gating on "file mentions either"
    // first (rather than grepping ->update(/->save(/etc. across all of app/, which would
    // flag every unrelated model's calls) keeps the rule precise while still catching the
    // forms called out in the spec:
    //   - AttendanceLog::query()->create(   / AttendanceLog::create(   (mass-assignment create)
    //   - new AttendanceLog                  (construct-then-save)
    //   - ->update(                          / ->delete(               (any mutation once the
    //     file is already known to touch the model — attendance_logs is append-only, so
    //     even a single update/delete call in an AttendanceLog-referencing file is a
    //     violation, not just a suspicious pattern)
    //   - ->save(                            (the idiomatic fetch-mutate-save form — this
    //     was previously unmatched, so a second writer using it would pass silently)
    //   - updateOrCreate(   / firstOrCreate(  (Eloquent's other create-or-write helpers)
    //   - ->upsert(                           (bulk write helper)
    //   - DB::table('attendance_logs')->insert(/update(/upsert(/delete( — raw query-builder
    //     writes that bypass the model entirely. Scoped to the write methods specifically
    //     (not a bare DB::table('attendance_logs') reference) so a future read-only report
    //     query built on the raw builder — legitimate once Task 7 lands — doesn't trip
    //     this guard; only insert/update/upsert/delete chained onto the table call count.
    // The factory in database/ is exempt — the guard scans app/ only.
    $writers = [];

    $files = (new Finder)
        ->files()
        ->in(base_path('app'))
        ->name('*.php');

    $patterns = [
        '/AttendanceLog::query\(\)\s*->\s*create\(/',
        '/AttendanceLog::create\(/',
        '/new\s+AttendanceLog\b/',
        '/->update\(/',
        '/->delete\(/',
        '/->save\(/',
        '/updateOrCreate\(/',
        '/firstOrCreate\(/',
        '/->upsert\(/',
        '/DB::table\(\s*[\'"]attendance_logs[\'"]\s*\)\s*->\s*(insert|insertOrIgnore|insertGetId|update|upsert|delete)\(/',
    ];

    foreach ($files as $file) {
        $relativePath = str_replace('\\', '/', $file->getRelativePathname());

        // The model definition itself always mentions "AttendanceLog" and defines
        // relations, casts, etc. — none of which are writes. Exempt it explicitly rather
        // than relying on the write patterns never matching it, since a future cast or
        // scope method could easily contain the substring "->update(" incidentally.
        if ($relativePath === 'Models/AttendanceLog.php') {
            continue;
        }

        // A JsonResource is a read-only presentation layer that structurally cannot call
        // create()/update()/fill()/save()/upsert() on the model it presents — the same
        // reasoning the cache-writer guard above applies to its mass-assignment
        // sub-pattern. Exempt the whole directory rather than pattern-by-pattern, since
        // none of the write patterns here have a legitimate read-only reading in a
        // Resource the way 'col' => ... does for the cache-column guard.
        if (str_starts_with($relativePath, 'Http/Resources/')) {
            continue;
        }

        $contents = $file->getContents();

        if (! str_contains($contents, 'AttendanceLog') && ! str_contains($contents, 'attendance_logs')) {
            continue;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $writers[$relativePath] = true;

                break;
            }
        }
    }

    expect(array_keys($writers))->toBe(['Actions/Attendance/RecordPunch.php']);
});
