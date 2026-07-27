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
        public string $note,
        public ?UploadedFile $attachment,
    ) {}
}
