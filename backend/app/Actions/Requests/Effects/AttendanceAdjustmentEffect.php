<?php

declare(strict_types=1);

namespace App\Actions\Requests\Effects;

use App\Actions\Attendance\ApplyAttendanceAdjustment;
use App\Domain\Requests\RequestEffect;
use App\Models\Request;

/** The attendance-adjustment effect: delegates to the existing add/void/amend action. */
final class AttendanceAdjustmentEffect implements RequestEffect
{
    public function __construct(private readonly ApplyAttendanceAdjustment $apply) {}

    public function applyOnApproval(Request $request, string $approverUserId): void
    {
        $this->apply->apply($request, $approverUserId);
    }
}
