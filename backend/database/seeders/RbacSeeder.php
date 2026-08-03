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
    // RESERVED WORDS — never add a permission literally named `viewFullProfile`,
    // `viewRedactedProfile`, or `updateProfile` (or any other bare policy-ability name) to
    // this list. spatie/laravel-permission registers its own Gate::before that grants any
    // ability whose NAME matches a permission the user holds, regardless of which policy
    // method that ability maps to or what arguments it was called with. A permission named
    // after one of EmployeePolicy's abilities would therefore grant that ability GLOBALLY —
    // bypassing the hr_admin_offices pivot check inside administersOfficeOf() entirely — the
    // moment any role held it. Today's catalog is deliberately dotted (`employee.pii.edit`)
    // so no such collision exists; keep it that way. See docs/05-rbac.md and the M10a
    // follow-ups.
    private const array HR_PERMISSIONS = [
        'employee.manage',
        'employee.pii.edit',
        'leave.approve',
        'schedule.manage',
        'holiday.manage',
        'cutoff.manage',
        // Enforcement is via OfficeScope, same as holiday.manage/schedule.manage — this
        // widens the catalog, not a new code gate.
        'leave.manage',
        // Documents (M10b). Two tiers: `document.manage` is office-scoped at the FILE level
        // (M10b-b) and unscoped for the company-wide catalog; `document.manage.self` lets an
        // employee file and read their OWN documents but never delete one — removing a filed
        // document is HR's act. Both dotted, per the reserved-words note above.
        'document.manage',
        'document.manage.self',
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
