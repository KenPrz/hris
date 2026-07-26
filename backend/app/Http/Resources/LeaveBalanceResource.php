<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a balance read: the leave type plus its DERIVED balance (see
 * App\Domain\Leave\LeaveBalances — never a stored field) in both raw minutes and the
 * readable day/hour/minute decomposition (App\Domain\Leave\LeaveUnit::readable()).
 */
final class LeaveBalanceResource extends JsonResource
{
    /** @param array{days: int, hours: int, minutes: int} $balanceReadable */
    public function __construct(
        private readonly LeaveType $leaveType,
        private readonly int $balanceMinutes,
        private readonly array $balanceReadable,
    ) {
        parent::__construct(null);
    }

    public function toArray(Request $request): array
    {
        return [
            'leave_type' => LeaveTypeResource::make($this->leaveType)->toArray($request),
            'balance_minutes' => $this->balanceMinutes,
            'balance_readable' => $this->balanceReadable,
        ];
    }
}
