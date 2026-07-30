<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeIdentification;
use App\Models\EmployeeIdentificationCategory;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Six actors against all eight M10a routes. Every expectation below is a deliberate policy
 * decision from the spec, not an observation of what the code happens to do — if one of
 * these flips, the authorization contract changed and the spec needs updating too.
 *
 * 404 is the correct denial everywhere: the employee id is in the URL, and a
 * 403-for-real / 404-for-nonexistent split lets any authenticated user enumerate employee
 * ids. See docs/05-rbac.md.
 *
 * `GET /profile/catalog` is deliberately excluded — it is ungated static reference data,
 * so every actor is allowed and a row of eight `true`s would be noise. ShowCatalogTest
 * covers it.
 */
beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->cebu = Office::factory()->create(['code' => 'CEB']);
    $this->manila = Office::factory()->create(['code' => 'MNL']);

    // The subject: a Cebu employee with a login, one identification, and a manager.
    $this->selfUser = User::factory()->create();
    $this->subject = Employee::factory()->create([
        'user_id' => $this->selfUser->id,
        'current_office_id' => $this->cebu->id,
    ]);

    $this->category = EmployeeIdentificationCategory::query()->create([
        'code' => 'TIN', 'name' => 'TIN', 'description' => 'BIR Taxpayer ID',
    ]);
    $this->identification = EmployeeIdentification::factory()->create([
        'employee_id' => $this->subject->id,
        'category_id' => $this->category->id,
        'number' => '653536955000',
    ]);

    $managerUser = User::factory()->create();
    $manager = Employee::factory()->create([
        'user_id' => $managerUser->id,
        'current_office_id' => $this->cebu->id,
    ]);
    $this->subject->update(['current_reports_to_id' => $manager->id]);

    $hrIn = User::factory()->create();
    $hrIn->assignRole('HR Admin');
    $hrIn->hrAdminOffices()->attach($this->cebu->id);

    $hrOut = User::factory()->create();
    $hrOut->assignRole('HR Admin');
    $hrOut->hrAdminOffices()->attach($this->manila->id);

    $stranger = User::factory()->create();
    Employee::factory()->create(['user_id' => $stranger->id, 'current_office_id' => $this->manila->id]);

    $this->actors = [
        'self' => $this->selfUser->fresh(),
        'manager' => $managerUser->fresh(),
        'hr-in-scope' => $hrIn->fresh(),
        'hr-out-of-scope' => $hrOut->fresh(),
        'stranger' => $stranger->fresh(),
        'system-admin' => User::factory()->create(['is_system_admin' => true]),
    ];
});

/**
 * @return array<string, array{method: string, uri: string, payload: array<string, mixed>}>
 */
function matrixRoutes(string $employeeId, string $identificationId, string $categoryId): array
{
    return [
        'GET /me/profile' => ['method' => 'getJson', 'uri' => '/api/v1/me/profile', 'payload' => []],
        'GET /admin/…/profile' => ['method' => 'getJson', 'uri' => "/api/v1/admin/employees/{$employeeId}/profile", 'payload' => []],
        'GET /employees/…/profile' => ['method' => 'getJson', 'uri' => "/api/v1/employees/{$employeeId}/profile", 'payload' => []],
        'PUT /admin/…/profile' => ['method' => 'putJson', 'uri' => "/api/v1/admin/employees/{$employeeId}/profile", 'payload' => ['nickname' => 'X']],
        'PUT /admin/…/dependents' => ['method' => 'putJson', 'uri' => "/api/v1/admin/employees/{$employeeId}/dependents", 'payload' => ['dependents' => []]],
        'POST /admin/…/identifications' => ['method' => 'postJson', 'uri' => "/api/v1/admin/employees/{$employeeId}/identifications", 'payload' => ['category_id' => $categoryId, 'number' => '1']],
        'DELETE /admin/…/identifications/{id}' => ['method' => 'deleteJson', 'uri' => "/api/v1/admin/employees/{$employeeId}/identifications/{$identificationId}", 'payload' => []],
        'GET /employees/…/scan' => ['method' => 'getJson', 'uri' => "/api/v1/employees/{$employeeId}/identifications/{$identificationId}/scan", 'payload' => []],
    ];
}

it('enforces the documented matrix across every M10a route', function (): void {
    // true = allowed (2xx), false = denied (404). The scan route is 404 for everyone here
    // because the fixture identification carries no media — the AUTHORIZED actors still
    // 404, which is why it is listed false for them too; IdentificationEndpointsTest proves
    // the authorized-with-media case separately.
    $expected = [
        'self' => [
            'GET /me/profile' => true,
            'GET /admin/…/profile' => true,          // self branch of viewFullProfile
            'GET /employees/…/profile' => true,      // self is inside EmployeeScope
            'PUT /admin/…/profile' => false,         // employees do not edit their own PII
            'PUT /admin/…/dependents' => false,
            'POST /admin/…/identifications' => false,
            'DELETE /admin/…/identifications/{id}' => false,
            'GET /employees/…/scan' => false,        // no media on the fixture
        ],
        'manager' => [
            'GET /me/profile' => true,               // their OWN profile, not the subject's
            'GET /admin/…/profile' => false,         // redacted only
            'GET /employees/…/profile' => true,
            'PUT /admin/…/profile' => false,
            'PUT /admin/…/dependents' => false,
            'POST /admin/…/identifications' => false,
            'DELETE /admin/…/identifications/{id}' => false,
            'GET /employees/…/scan' => false,
        ],
        'hr-in-scope' => [
            'GET /me/profile' => false,              // this HR user has no employee row
            'GET /admin/…/profile' => true,
            'GET /employees/…/profile' => true,
            'PUT /admin/…/profile' => true,
            'PUT /admin/…/dependents' => true,
            'POST /admin/…/identifications' => true,
            'DELETE /admin/…/identifications/{id}' => true,
            'GET /employees/…/scan' => false,
        ],
        'hr-out-of-scope' => [
            'GET /me/profile' => false,
            'GET /admin/…/profile' => false,
            'GET /employees/…/profile' => false,
            'PUT /admin/…/profile' => false,
            'PUT /admin/…/dependents' => false,
            'POST /admin/…/identifications' => false,
            'DELETE /admin/…/identifications/{id}' => false,
            'GET /employees/…/scan' => false,
        ],
        'stranger' => [
            'GET /me/profile' => true,               // their own, empty profile
            'GET /admin/…/profile' => false,
            'GET /employees/…/profile' => false,
            'PUT /admin/…/profile' => false,
            'PUT /admin/…/dependents' => false,
            'POST /admin/…/identifications' => false,
            'DELETE /admin/…/identifications/{id}' => false,
            'GET /employees/…/scan' => false,
        ],
        'system-admin' => [
            'GET /me/profile' => false,              // no employee row; /me/profile 404s
            'GET /admin/…/profile' => true,
            'GET /employees/…/profile' => true,
            'PUT /admin/…/profile' => true,
            'PUT /admin/…/dependents' => true,
            'POST /admin/…/identifications' => true,
            'DELETE /admin/…/identifications/{id}' => true,
            'GET /employees/…/scan' => false,
        ],
    ];

    $failures = [];

    foreach ($expected as $actorName => $routeExpectations) {
        foreach ($routeExpectations as $routeName => $allowed) {
            // The brief's original loop called $this->refreshApplication() here to reset
            // fixtures between destructive calls. That does not work under RefreshDatabase:
            // refreshApplication() swaps $this->app for a freshly booted Application, whose
            // db connection is a brand-new Postgres session — one that cannot see any of the
            // fixtures created above, because they live inside the still-open outer
            // transaction RefreshDatabase began for the ORIGINAL app/connection. Every query
            // through the new app would find nothing, turning every cell into a false
            // negative regardless of the actor.
            //
            // Instead, recreate only the fixture a cell can mutate: the identification row.
            // Employees, offices, and role assignments are untouched by every cell, so they
            // never need to be rebuilt. Deleting by (employee_id, category_id) — rather than
            // by the previous identification's id — also covers the POST cell, which
            // upserts onto the SAME category and would otherwise collide with the
            // unique(employee_id, category_id) constraint on the next iteration's insert.
            EmployeeIdentification::query()
                ->where('employee_id', $this->subject->id)
                ->where('category_id', $this->category->id)
                ->delete();

            $this->identification = EmployeeIdentification::factory()->create([
                'employee_id' => $this->subject->id,
                'category_id' => $this->category->id,
                'number' => '653536955000',
            ]);

            $routes = matrixRoutes($this->subject->id, $this->identification->id, $this->category->id);
            $route = $routes[$routeName];
            $method = $route['method'];

            $response = $this->actingAs($this->actors[$actorName])
                ->{$method}($route['uri'], $route['payload']);

            $succeeded = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;

            if ($succeeded !== $allowed) {
                $failures[] = sprintf(
                    '%s -> %s: expected %s, got HTTP %d',
                    $actorName,
                    $routeName,
                    $allowed ? 'allowed' : 'denied',
                    $response->getStatusCode(),
                );
            }
        }
    }

    expect($failures)->toBe([]);
});
