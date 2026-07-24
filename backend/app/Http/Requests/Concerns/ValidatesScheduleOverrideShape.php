<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * Mirrors the schedule_overrides DB CHECKs at the request layer, so a bad payload is a
 * clean 400 here rather than a raw constraint-violation 500 from Postgres: is_rest XOR
 * hours for a single day, and — when working — a real minute range. Cross-midnight is
 * deliberately accepted (end may exceed 1439, up to start + 1440); only the DB-mirrored
 * upper bound is enforced, never a 1439 cap. Shared by CreateScheduleOverrideRequest and
 * UpdateScheduleOverrideRequest — both accept the same is_rest/hours shape.
 */
trait ValidatesScheduleOverrideShape
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $isRest = $this->boolean('is_rest');
            $carriesHours = $this->filled('start_minute') || $this->filled('end_minute') || $this->filled('break_minutes');

            if ($isRest) {
                if ($carriesHours) {
                    $validator->errors()->add('is_rest', 'a rest override must not carry hours');
                }

                return;
            }

            if (! $this->filled('start_minute') || ! $this->filled('end_minute') || ! $this->filled('break_minutes')) {
                $validator->errors()->add('is_rest', 'a working override must carry start_minute, end_minute, and break_minutes');

                return;
            }

            $start = (int) $this->input('start_minute');
            $end = (int) $this->input('end_minute');
            $break = (int) $this->input('break_minutes');

            if (! ($start >= 0 && $start < 1440 && $end > $start && $end <= $start + 1440 && $break >= 0 && $break < ($end - $start))) {
                $validator->errors()->add('start_minute', 'invalid working-hours minutes');
            }
        });
    }
}
