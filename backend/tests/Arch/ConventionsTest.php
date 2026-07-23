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
    ->ignoring('App\Domain\Pay\DayType')
    ->toBeFinal()
    ->ignoring('App\Domain\Pay\DayType');

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
    $columns = ['current_office_id', 'current_department_id', 'current_reports_to_id'];

    $writers = [];

    $files = (new Finder)
        ->files()
        ->in(base_path('app'))
        ->name('*.php');

    foreach ($files as $file) {
        $contents = $file->getContents();

        foreach ($columns as $column) {
            $quoted = preg_quote($column, '/');

            $pattern = '/'
                .'[\'"]'.$quoted.'[\'"]\s*=>'          // mass assignment: 'col' => ...
                .'|->'.$quoted.'\b\s*=(?!=|>)'         // property assignment: ->col = (not ==, ===, =>)
                .'|setAttribute\(\s*[\'"]'.$quoted.'[\'"]' // setAttribute('col', ...)
                .'/';

            if (preg_match($pattern, $contents) === 1) {
                $writers[$file->getRelativePathname()] = true;
            }
        }
    }

    expect(array_keys($writers))->toBe(['Actions/Employees/RecordEmploymentChange.php']);
});
