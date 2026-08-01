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
    // EmployeeScope (and OfficeScope, its M4 office analogue) are Scope/query-constraint
    // builders, not plain domain value objects or policies — their entire job is to return
    // an Eloquent Builder. The M1 purity rule this arch test enforces bars config() and
    // facades from the domain layer; it was never meant to bar the ORM from the one class
    // whose contract is "hand back a constrained query." See
    // docs/superpowers/specs/2026-07-23-m2-schema-auth-rbac-design.md.
    //
    // ApprovalQueues (M6a) is the same shape one level up: two named queries that hand
    // back a constrained Request Builder rather than a boolean, exactly like EmployeeScope
    // does for Employee. Same carve-out, same reasoning.
    ->ignoring(['App\Domain\Scope\EmployeeScope', 'App\Domain\Scope\OfficeScope', 'App\Domain\Requests\ApprovalQueues']);

arch('the domain layer never reads configuration')
    ->expect('App\Domain')
    ->not->toUse(['config', 'env', 'app', 'resolve']);

arch('domain value objects are final')
    ->expect('App\Domain')
    ->toBeClasses()
    ->ignoring([
        'App\Domain\Pay\DayType',
        'App\Domain\Pay\SummaryLineKind',
        'App\Domain\Attendance\PunchDirection',
        'App\Domain\Attendance\PunchSource',
        'App\Domain\Attendance\PunchVerification',
        'App\Domain\Attendance\AdjustmentOperation',
        'App\Domain\Requests\RequestType',
        'App\Domain\Requests\RequestState',
        'App\Domain\Requests\RequestEffect',
        'App\Domain\Requests\RequestEffectResolver',
        'App\Domain\Schedule\Weekday',
        'App\Domain\Schedule\ScheduleSource',
        'App\Domain\Compute\RecomputeTrigger',
        'App\Domain\Cutoff\CutoffState',
        'App\Domain\Profile\Gender',
        'App\Domain\Profile\MaritalStatus',
        'App\Domain\Profile\BloodType',
        'App\Domain\Profile\LaborType',
        'App\Domain\Documents\Documentable',
    ])
    ->toBeFinal()
    ->ignoring([
        'App\Domain\Pay\DayType',
        'App\Domain\Pay\SummaryLineKind',
        'App\Domain\Attendance\PunchDirection',
        'App\Domain\Attendance\PunchSource',
        'App\Domain\Attendance\PunchVerification',
        'App\Domain\Attendance\AdjustmentOperation',
        'App\Domain\Requests\RequestType',
        'App\Domain\Requests\RequestState',
        'App\Domain\Requests\RequestEffect',
        'App\Domain\Requests\RequestEffectResolver',
        'App\Domain\Schedule\Weekday',
        'App\Domain\Schedule\ScheduleSource',
        'App\Domain\Compute\RecomputeTrigger',
        'App\Domain\Cutoff\CutoffState',
        'App\Domain\Profile\Gender',
        'App\Domain\Profile\MaritalStatus',
        'App\Domain\Profile\BloodType',
        'App\Domain\Profile\LaborType',
        'App\Domain\Documents\Documentable',
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

// `->expect('App')->toUseStrictTypes()` scans only the App\ PSR-4 root. Pest-arch's
// namespace resolution filters out anything under tests/, and database/migrations isn't
// PSR-4-mapped at all — so migrations get no arch coverage and tests get none of the
// strict-types rule, contrary to what CLAUDE.md advertises ("every PHP file in app/ and
// tests/"). Tests\ IS autoloaded (autoload-dev), so it can go through the same rule; the
// migrations need a direct grep, since a rule can't see files it can't resolve.
arch('strict types in tests too')
    ->expect('Tests')
    ->toUseStrictTypes();

test('every migration declares strict_types', function (): void {
    $offenders = [];

    foreach ((new Finder)->files()->in(base_path('database'))->name('*.php') as $file) {
        // Match the opening tag then the declare on the first non-blank lines — the same
        // shape `declare(strict_types=1);` takes at the top of every file in the repo.
        if (! preg_match('/\A<\?php\s+declare\(strict_types=1\);/', $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'File(s) under database/ missing a top-of-file declare(strict_types=1): '.implode(', ', $offenders));
});

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
    //
    // The Task 7 transition controllers (Approve|Reject|CancelController) used to live
    // here as a third, deliberately different shape: they serve a Request, not an
    // AttendanceLog, and their authorization boundary is RequestAuthority (approve/reject)
    // or requester-identity (cancel) — enforced inside the row-locked action, not by an
    // EmployeeScope query or a `user()->employee` read in the controller itself. M6a Task 3
    // relocated all six read/decision controllers to Http/Controllers/Requests, which this
    // guard never scans (it's scoped to Http/Controllers/Attendance), so no exemption is
    // needed any more — removing a name from this list without deleting the file it names
    // would be caught immediately by "controllers are final single-action classes" failing
    // to find the class, or by this loop simply never seeing it walk past a file that no
    // longer exists under Attendance/. The guarantee those three controllers care about is
    // still proven by the 404-vs-409 matrix in
    // tests/Feature/Requests/RequestDecisionsTest.php.
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

test('every Profile controller references an authorization boundary, except the catalog read', function (): void {
    // A third sibling to the Employees/ and Attendance/ guards above, for M10a's
    // app/Http/Controllers/Profile/ directory — which had NO such rule despite the two other
    // controller-serving-sensitive-data directories both having one. Today's two controllers
    // are ungated *by design*: ShowMyProfileController is self-only (guarded the same way
    // Attendance/'s self-only controllers are, via `$request->user()->employee`), and
    // ShowCatalogController genuinely has no boundary — it serves static, company-wide
    // reference data (relationships, identification categories) with nothing
    // employee-specific or sensitive in it, by its own docblock. That "ungated by design"
    // state is exactly the one in which a future GATED controller dropped into this
    // directory gets no CI backstop at all, so this widens the guard now rather than after
    // M10b adds one. ShowCatalogController is allowed explicitly BY NAME, with the reasoning
    // above, rather than weakening the rule for the whole directory.
    $exemptByDesign = ['ShowCatalogController.php'];

    $offenders = [];

    $files = (new Finder)
        ->files()
        ->in(base_path('app/Http/Controllers/Profile'))
        ->name('*.php');

    $patterns = [
        '/EmployeeScope/',
        '/user\(\)->employee\b/',
        '/->cannot\(/',
        '/->can\(/',
        '/->authorize\(/',
        '/Gate::/',
    ];

    foreach ($files as $file) {
        if (in_array($file->getFilename(), $exemptByDesign, true)) {
            continue;
        }

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

    expect($offenders)->toBe([], 'Controller(s) under app/Http/Controllers/Profile/ serve profile data without referencing an authorization boundary (EmployeeScope, $request->user()->employee, or a ->cannot()/->can()/->authorize()/Gate:: call): '.implode(', ', $offenders));
});

test('every Admin\Documents controller is guarded by a FormRequest whose authorize() actually gates', function (): void {
    // Closes the gap the M10a-era Profile/ guard above deliberately left open: that guard's
    // docblock says a future GATED controller dropped into app/Http/Controllers/Profile/
    // would get no CI backstop, and names the exact same risk for
    // app/Http/Controllers/Admin/Documents/ once Task 6 (M10b-a) added gated controllers
    // there — until now, that directory held only the sibling Documents/ShowCatalogController
    // (a different directory, deliberately ungated by design, not scanned by this guard).
    // Task 7 (M10b-a) is expected to add more controllers under this same directory; they
    // are covered by this guard automatically, with no further changes needed here unless
    // they gate through some other mechanism entirely.
    //
    // This directory's controllers gate DIFFERENTLY than every controller the guards above
    // scan: EmployeePolicy/EmployeeScope/->cannot(/->can(/->authorize(/Gate:: never appear in
    // the controller body itself, because DocumentCategory/Document catalog controllers
    // authorize entirely inside their FormRequest's authorize() method — the controller only
    // type-hints the FormRequest in its __invoke signature and never calls a gate inline.
    // Reusing the Employees/Attendance/Profile guards' inline-call patterns verbatim would
    // therefore never fire — a controller with NO gate at all would still "pass" because none
    // of those patterns are expected to appear here regardless.
    //
    // A first version of this guard checked ONLY for a `use App\Http\Requests\Documents\
    // ...Request;` import line — an import-presence check, not an authorization-boundary
    // check. Code review caught that it passes three regressions it should catch: a dead
    // unused import (the class is imported but never actually type-hinted anywhere), a
    // FormRequest type-hinted in __invoke() whose authorize() unconditionally `return
    // true;`s, and (implicitly) any FormRequest whose authorize() body has no gate
    // expression at all. This version instead performs two hops, BOTH of which must pass:
    //
    //   1. One of the imported App\Http\Requests\Documents\*Request classes must actually
    //      appear as a parameter type-hint in the controller's __invoke() signature — not
    //      merely imported. A dead import fails this hop.
    //   2. That specific FormRequest's authorize() method body — read from its own file
    //      under app/Http/Requests/Documents/ — must contain a real gate expression
    //      (`->can(`, `Gate::`, or `manageCatalog`), not a bare `return true;` or an empty
    //      body.
    //
    // What this STILL cannot prove — same limit the Employees/Attendance/Profile guards
    // above have — is that the gate names the *right* ability for the action being
    // performed (e.g. that a delete route's authorize() doesn't accidentally check a read
    // permission). That is the feature tests' job (DocumentCategoryCrudTest's "denies every
    // route to an actor without document.manage" case), not this guard's; this guard only
    // proves SOME real gate expression sits on the path every request through this
    // directory must take.
    $extractBalanced = function (string $contents, int $fromPos, string $open, string $close): string {
        $depth = 0;
        $capturedStart = null;
        $length = strlen($contents);

        for ($i = $fromPos; $i < $length; $i++) {
            $char = $contents[$i];

            if ($char === $open) {
                if ($depth === 0) {
                    $capturedStart = $i + 1;
                }

                $depth++;
            } elseif ($char === $close) {
                $depth--;

                if ($depth === 0 && $capturedStart !== null) {
                    return substr($contents, $capturedStart, $i - $capturedStart);
                }
            }
        }

        return '';
    };

    $offenders = [];

    $controllerFiles = (new Finder)
        ->files()
        ->in(base_path('app/Http/Controllers/Admin/Documents'))
        ->name('*.php');

    foreach ($controllerFiles as $file) {
        $contents = $file->getContents();
        $relativePath = $file->getRelativePathname();

        preg_match_all('/use App\\\\Http\\\\Requests\\\\Documents\\\\(\w+Request);/', $contents, $importMatches);
        $importedClasses = $importMatches[1];

        if ($importedClasses === []) {
            $offenders[$relativePath] = 'imports no FormRequest under App\Http\Requests\Documents';

            continue;
        }

        // Hop 1: one of the imports must be a parameter type-hint in __invoke(), not merely
        // an unused `use` line.
        $invokePos = strpos($contents, 'function __invoke');
        $parenPos = $invokePos === false ? false : strpos($contents, '(', $invokePos);
        $invokeParams = $parenPos === false ? '' : $extractBalanced($contents, $parenPos, '(', ')');

        $guardingClass = null;

        foreach ($importedClasses as $className) {
            if (preg_match('/\b'.preg_quote($className, '/').'\s+\$\w+/', $invokeParams) === 1) {
                $guardingClass = $className;

                break;
            }
        }

        if ($guardingClass === null) {
            $offenders[$relativePath] = 'imports a Documents FormRequest but never type-hints it in __invoke()';

            continue;
        }

        // Hop 2: that FormRequest's own authorize() body must contain a real gate
        // expression, not a bare `return true;`.
        $requestFile = base_path('app/Http/Requests/Documents/'.$guardingClass.'.php');

        if (! is_file($requestFile)) {
            $offenders[$relativePath] = "guarding class {$guardingClass} has no matching file under app/Http/Requests/Documents/";

            continue;
        }

        $requestContents = file_get_contents($requestFile);
        $authorizePos = strpos($requestContents, 'function authorize');
        $bracePos = $authorizePos === false ? false : strpos($requestContents, '{', $authorizePos);
        $authorizeBody = $bracePos === false ? '' : $extractBalanced($requestContents, $bracePos, '{', '}');

        $hasRealGate = $authorizeBody !== ''
            && (str_contains($authorizeBody, '->can(') || str_contains($authorizeBody, 'Gate::') || str_contains($authorizeBody, 'manageCatalog'));

        if (! $hasRealGate) {
            $offenders[$relativePath] = "{$guardingClass}::authorize() contains no ->can(/Gate::/manageCatalog gate expression";
        }
    }

    expect($offenders)->toBe([], 'Controller(s) under app/Http/Controllers/Admin/Documents/ fail the two-hop FormRequest-gate check: '.json_encode($offenders));
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
                '/\bSET\b[^;\'"]*\b'.$quoted.'\b\s*=/i',           // raw SQL: UPDATE ... SET col = ... (DB::statement/update)
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
        // A bare builder insert (AttendanceLog::query()->insert([...])), the quiet-save and
        // force-delete forms, the increment helpers, and raw SQL (DB::statement/insert/
        // update/delete/…) all write without the verbs above — closed here so the guarantee
        // is as broad as the docblock claims, not just the Eloquent-create shapes.
        '/->insert(GetId|OrIgnore)?\(/',
        '/->forceDelete\(/',
        '/->(save|update)Quietly\(/',
        '/->(increment|decrement|touch)\(/',
        '/DB::(statement|insert|update|delete|unprepared|affectingStatement)\(/',
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

test('only RecordAnnulment writes attendance_annulments', function (): void {
    // Same mechanism as the attendance_logs single-writer guard above, adapted to the
    // annulment table: gate on "file mentions AttendanceAnnulment or attendance_annulments
    // at all" first, then flag any of the write forms below. ApplyAttendanceAdjustment (a
    // later task) will READ attendance_annulments (e.g.
    // AttendanceAnnulment::query()->where('attendance_log_id', ...)->exists()) — a read,
    // not a write — so the raw DB::table pattern stays scoped to write verbs only
    // (insert/insertOrIgnore/insertGetId/update/upsert/delete), and no ->where(/->exists(/
    // ->find( patterns are added, exactly mirroring the attendance_logs guard's scoping.
    $writers = [];

    $files = (new Finder)
        ->files()
        ->in(base_path('app'))
        ->name('*.php');

    $patterns = [
        '/AttendanceAnnulment::query\(\)\s*->\s*create\(/',
        '/AttendanceAnnulment::create\(/',
        '/new\s+AttendanceAnnulment\b/',
        '/->update\(/',
        '/->delete\(/',
        '/->save\(/',
        '/updateOrCreate\(/',
        '/firstOrCreate\(/',
        '/->upsert\(/',
        // Same broadened write-form coverage as the attendance_logs guard: a bare builder
        // insert, quiet-save/force-delete, the increment helpers, and raw SQL.
        '/->insert(GetId|OrIgnore)?\(/',
        '/->forceDelete\(/',
        '/->(save|update)Quietly\(/',
        '/->(increment|decrement|touch)\(/',
        '/DB::(statement|insert|update|delete|unprepared|affectingStatement)\(/',
        '/DB::table\(\s*[\'"]attendance_annulments[\'"]\s*\)\s*->\s*(insert|insertOrIgnore|insertGetId|update|upsert|delete)\(/',
    ];

    foreach ($files as $file) {
        $relativePath = str_replace('\\', '/', $file->getRelativePathname());

        // The model definition itself always mentions "AttendanceAnnulment" and defines
        // relations, casts, etc. — none of which are writes. Exempt it explicitly rather
        // than relying on the write patterns never matching it.
        if ($relativePath === 'Models/AttendanceAnnulment.php') {
            continue;
        }

        // A JsonResource is a read-only presentation layer that structurally cannot call
        // create()/update()/fill()/save()/upsert() on the model it presents. Exempt the
        // whole directory, as above.
        if (str_starts_with($relativePath, 'Http/Resources/')) {
            continue;
        }

        $contents = $file->getContents();

        if (! str_contains($contents, 'AttendanceAnnulment') && ! str_contains($contents, 'attendance_annulments')) {
            continue;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $writers[$relativePath] = true;

                break;
            }
        }
    }

    expect(array_keys($writers))->toBe(['Actions/Attendance/RecordAnnulment.php']);
});
