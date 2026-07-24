<?php

declare(strict_types=1);

namespace App\Domain\Scope;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one definition of "which offices may this user administer" — the M4 config boundary,
 * mirroring EmployeeScope for employees. Returns a query constraint, so it composes into any
 * office query and the boundary lives in one place. See docs/05-rbac.md.
 */
final class OfficeScope
{
    /** @return Builder<Office> */
    public static function administeredBy(User $user): Builder
    {
        $query = Office::query();

        if ($user->is_system_admin) {
            return $query;
        }

        $officeIds = $user->hrAdminOffices()->pluck('offices.id')->all();

        // No HR offices → administers nothing. Force empty, never unconstrained.
        return $officeIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('id', $officeIds);
    }

    /**
     * Resolves an office id to the Office the caller administers, or 404s. Shared by every
     * endpoint that takes an office id on the request (list/create/clone) — an out-of-scope
     * office and a nonexistent one must be indistinguishable to the caller (404-not-403), so
     * this is the one place that throws for both.
     */
    public static function administeredOrFail(User $user, ?string $officeId): Office
    {
        $office = self::administeredBy($user)->find($officeId);

        if ($office === null) {
            throw new NotFoundHttpException;
        }

        return $office;
    }

    /**
     * Same 404-not-403 discipline as administeredOrFail(), for the route-model-bound
     * endpoints (update/delete) that already have the record and only need to confirm the
     * caller administers its office.
     */
    public static function assertAdministers(User $user, string $officeId): void
    {
        $administers = self::administeredBy($user)->whereKey($officeId)->exists();

        if (! $administers) {
            throw new NotFoundHttpException;
        }
    }
}
