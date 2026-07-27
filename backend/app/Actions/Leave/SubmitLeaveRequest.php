<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use App\Domain\Requests\RequestState;
use App\Domain\Requests\RequestType;
use App\Models\Employee;
use App\Models\LeaveDetail;
use App\Models\Request;
use Illuminate\Support\Facades\DB;

/**
 * Creates a leave request and its 1:1 detail row, plus the optional supporting
 * attachment — the submit step only, mirroring SubmitAttendanceAdjustment. The initial
 * state branches on the employee's OWN manager: an employee with no manager on file has
 * no hop-1 approver, so their request starts already past hop 1 (manager_approved) —
 * still actionable at HR's hop 2, never silently stuck pending forever. Everyone else
 * starts pending, same as an attendance adjustment. The amount-from-scheduled-days
 * computation and every domain guard (is_active, requires_attachment, zero working days)
 * already ran in the controller before this is called — this action only persists.
 */
final class SubmitLeaveRequest
{
    public function execute(SubmitLeaveRequestInput $in): Request
    {
        return DB::transaction(function () use ($in): Request {
            $employee = Employee::query()->findOrFail($in->employeeId);

            $state = $employee->current_reports_to_id === null
                ? RequestState::ManagerApproved
                : RequestState::Pending;

            $request = Request::query()->create([
                'type' => RequestType::Leave,
                'employee_id' => $in->employeeId,
                'state' => $state,
                'note' => $in->note,
            ]);

            LeaveDetail::query()->create([
                'request_id' => $request->id,
                'leave_type_id' => $in->leaveTypeId,
                'start_date' => $in->startDate,
                'end_date' => $in->endDate,
                'day_part' => $in->dayPart,
                'amount_minutes' => $in->amountMinutes,
            ]);

            if ($in->attachment !== null) {
                $request->addMedia($in->attachment->getRealPath())
                    ->usingFileName($in->attachment->getClientOriginalName())
                    ->toMediaCollection('attachment');
            }

            return $request->fresh(['leaveDetail']);
        });
    }
}
