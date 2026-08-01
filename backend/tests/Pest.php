<?php

declare(strict_types=1);

use App\Models\Office;
use App\Models\User;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature and Arch tests bind to Tests\TestCase, which boots the application.
| tests/Unit/ is deliberately left on plain PHPUnit with no booted app: a unit
| test that needs the container is a feature test wearing the wrong hat, and
| the pure value objects M1 builds should be provable without one.
|
*/

pest()->extend(TestCase::class)->in('Feature');

pest()->extend(Tests\TestCase::class)->in('Arch');

/*
|--------------------------------------------------------------------------
| Shared test helpers
|--------------------------------------------------------------------------
|
| Pest file-scope functions are GLOBAL — a second declaration of the same name
| anywhere under tests/ is a PHP fatal error, not a test failure. Helpers used
| by more than one test file belong here, not in a file-scope `function` in an
| individual test.
|
*/

/** An HR Admin scoped to one office: the role (verbs) plus the pivot (scope). */
function hrAdminFor(Office $office): User
{
    $user = User::factory()->create();
    $user->assignRole('HR Admin');
    $user->hrAdminOffices()->attach($office->id);

    return $user->fresh();
}
