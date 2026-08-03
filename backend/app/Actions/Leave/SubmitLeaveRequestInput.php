<?php

declare(strict_types=1);

namespace App\Actions\Leave;

use Illuminate\Http\UploadedFile;

final readonly class SubmitLeaveRequestInput
{
    public function __construct(
        public string $employeeId,
        public string $leaveTypeId,
        public string $startDate,
        public string $endDate,
        public string $dayPart,
        public int $amountMinutes,
        /** Per scheduled working day — what the compute engine prices a leave day at and
         *  what amount_minutes is a multiple of. Snapshotted so a later change to the
         *  office's minutes_per_leave_day cannot restate leave already filed. */
        public int $minutesPerDay,
        public string $note,
        public ?UploadedFile $attachment,
    ) {}
}
