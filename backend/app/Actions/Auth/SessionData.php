<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Employee;
use App\Models\User;

final readonly class SessionData
{
    /**
     * @param  list<string>  $hrOffices
     * @param  list<string>  $permissions
     */
    public function __construct(
        public User $user,
        public ?Employee $employee,
        public bool $isSystemAdmin,
        public bool $hasReports,
        public array $hrOffices,
        public array $permissions,
    ) {}
}
