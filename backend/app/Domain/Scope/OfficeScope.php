<?php

declare(strict_types=1);

namespace App\Domain\Scope;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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
     * The office the caller administers with this id, or null. Pure — no HTTP. Shared by
     * every endpoint that takes an office id on the request (list/create/clone); the
     * controller turns a null into the 404 (an out-of-scope office and a nonexistent one
     * are indistinguishable to the caller — the 404-not-403 discipline). Domain stays
     * HTTP-agnostic so this is equally callable from a command, a seeder, or a queued job.
     */
    public static function administered(User $user, ?string $officeId): ?Office
    {
        return self::administeredBy($user)->find($officeId);
    }

    /**
     * Whether the caller administers this office — for the route-model-bound endpoints
     * (update/delete) that already have the record. Pure; the controller throws the 404.
     */
    public static function administers(User $user, string $officeId): bool
    {
        return self::administeredBy($user)->whereKey($officeId)->exists();
    }
}
