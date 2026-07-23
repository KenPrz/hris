<?php

declare(strict_types=1);

namespace App\Actions\Employees;

use App\Exceptions\Domain\EmployeeAlreadyHasLogin;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Provisions a login for an employee who does not yet have one. An employee has at most
 * one user_id; re-provisioning an already-linked employee is a domain failure, not a
 * silent overwrite.
 */
final class ProvisionUser
{
    public function execute(ProvisionUserInput $in): User
    {
        return DB::transaction(function () use ($in): User {
            $employee = Employee::query()->lockForUpdate()->findOrFail($in->employeeId);

            if ($employee->user_id !== null) {
                throw new EmployeeAlreadyHasLogin($employee->id);
            }

            $user = User::query()->create([
                'name' => $in->name,
                'email' => $in->email,
                'password' => Hash::make($in->password),
            ]);

            $employee->update(['user_id' => $user->id]);

            return $user;
        });
    }
}
