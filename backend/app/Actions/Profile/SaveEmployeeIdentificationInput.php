<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use Illuminate\Http\UploadedFile;

/**
 * `$scan` is `Illuminate\Http\UploadedFile`, matching SubmitAttendanceAdjustmentInput and
 * SubmitLeaveRequestInput. The "actions never touch HTTP" arch rule names Request, Response,
 * JsonResponse, JsonResource, and FormRequest — not UploadedFile — so this is the house
 * pattern, not an exception to it.
 */
final readonly class SaveEmployeeIdentificationInput
{
    public function __construct(
        public string $employeeId,
        public string $categoryId,
        public string $number,
        public ?string $issuedOn = null,
        public ?string $expiresOn = null,
        public ?string $notes = null,
        public ?UploadedFile $scan = null,
    ) {}
}
