<?php

declare(strict_types=1);

namespace App\Http\Controllers\Leave;

use App\Domain\Leave\LeaveBalances;
use App\Domain\Leave\LeaveUnit;
use App\Exceptions\Domain\NotAnEmployee;
use App\Http\Resources\LeaveBalanceResource;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The caller's own leave balances — one row per active, balance-deducting leave type in
 * their current office. LeaveBalances::forEmployee() only returns keys for types that HAVE
 * ledger rows, so every active type is fetched first and merged against it: an absent key
 * means the balance is 0, not that the type doesn't exist. Balances are DERIVED fresh on
 * every call — there is no stored field to go stale.
 */
final class ListMyLeaveController
{
    public function __invoke(Request $request): JsonResponse
    {
        $employee = $request->user()->employee ?? throw new NotAnEmployee;

        $office = $employee->currentOffice;

        $leaveTypes = LeaveType::query()
            ->where('office_id', $employee->current_office_id)
            ->where('deducts_balance', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $balances = LeaveBalances::forEmployee($employee);

        $rows = $leaveTypes
            ->map(function (LeaveType $leaveType) use ($balances, $office, $request): array {
                $minutes = $balances[$leaveType->id] ?? 0;

                return (new LeaveBalanceResource($leaveType, $minutes, LeaveUnit::readable($minutes, $office->minutes_per_leave_day)))
                    ->resolve($request);
            })
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }
}
