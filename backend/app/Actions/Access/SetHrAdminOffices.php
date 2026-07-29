<?php

declare(strict_types=1);

namespace App\Actions\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Couples the two halves of HR-Admin access into one write: the `hr_admin_offices`
 * pivot (the "over whom") and the spatie 'HR Admin' role (the "what verbs"). A user
 * with offices but no role, or a role but no offices, is a bug — CompanySeeder's
 * `$user->assignRole('HR Admin'); $user->hrAdminOffices()->attach($officeId);` pairing
 * is generalized here rather than duplicated at every call site.
 *
 * Setting office_ids to [] revokes HR-Admin entirely: the pivot is cleared and the role
 * removed, not left dangling with an empty scope.
 */
final class SetHrAdminOffices
{
    public function execute(SetHrAdminOfficesInput $in): User
    {
        return DB::transaction(function () use ($in): User {
            $user = User::query()->findOrFail($in->userId);

            $user->hrAdminOffices()->sync($in->officeIds);

            if ($in->officeIds !== []) {
                $user->assignRole('HR Admin');
            } else {
                $user->removeRole('HR Admin');
            }

            // causedBy() takes a Model, not a bare id (see ReopenCutoff::execute for the
            // same convention) — passing the raw uuid string instead makes Spatie's
            // CauserResolver fall back to resolveUsingId(), which resolves the *current
            // default auth guard's* provider. Inside a real request that guard is
            // 'sanctum' (Authenticate::authenticate() calls Auth::shouldUse() on success),
            // and Sanctum's guard has no getProvider() — a crash that only ever surfaces
            // when this action is driven over HTTP, never from a direct/console call.
            // Resolving the model ourselves sidesteps guard resolution entirely.
            activity()
                ->performedOn($user)
                ->causedBy(User::find($in->actorId))
                ->withProperties(['office_ids' => $in->officeIds])
                ->log('hr_admin_offices_set');

            return $user->fresh();
        });
    }
}
