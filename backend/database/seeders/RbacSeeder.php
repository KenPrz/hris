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

        // Flush BETWEEN create and sync, not just at the end. findOrCreate's first lookup
        // loads the registrar's permission collection into cache while it is still empty
        // and caches that empty result; syncPermissions() then resolves permission *names*
        // against that stale collection and throws PermissionDoesNotExist for a permission
        // that was just inserted. This bites on a fresh boot (migrate:fresh --seed) where
        // nothing warmed the cache with the real rows first. Flushing here forces the sync
        // to reload from the DB. See docs/05-rbac.md (Caching).
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::findOrCreate('HR Admin');
        $role->syncPermissions(self::HR_PERMISSIONS);

        // And once more after writing, so any caller that reads permissions in the same
        // process (the CompanySeeder assigning the role next) sees the fresh set.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
