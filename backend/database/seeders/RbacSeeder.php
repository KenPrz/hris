<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The HR-Admin verb catalog, as data. Manager authority is derived from the org chart
 * (no role), and system-admin is a flag (Gate::before) — so this is the one role spatie
 * carries in M2. Future specialized roles (Payroll Officer, Recruiter) are added here.
 * See docs/05-rbac.md.
 */
final class RbacSeeder extends Seeder
{
    private const array HR_PERMISSIONS = [
        'employee.manage',
        'employee.pii.edit',
        'leave.approve',
        'schedule.manage',
        'holiday.manage',
        'cutoff.manage',
    ];

    public function run(): void
    {
        foreach (self::HR_PERMISSIONS as $name) {
            Permission::findOrCreate($name);
        }

        $role = Role::findOrCreate('HR Admin');
        $role->syncPermissions(self::HR_PERMISSIONS);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
