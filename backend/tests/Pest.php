<?php

declare(strict_types=1);

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
