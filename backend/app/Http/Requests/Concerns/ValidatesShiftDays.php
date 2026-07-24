<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * Mirrors the shift_template_days DB CHECKs at the request layer, so a bad payload is a
 * clean 400 here rather than a raw constraint-violation 500 from Postgres: exactly the
 * seven distinct weekdays 0..6, and a working day's minutes forming a real range. Shared
 * by CreateShiftTemplateRequest and UpdateShiftTemplateRequest — both accept the same
 * `days` shape, so the completeness/is_rest-XOR-hours/minute-range checks live here once.
 */
trait ValidatesShiftDays
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $weekdays = collect($this->input('days', []))
                ->pluck('weekday')
                ->map(fn (mixed $weekday): int => (int) $weekday);

            if ($weekdays->unique()->sort()->values()->all() !== range(0, 6)) {
                $validator->errors()->add('days', 'days must cover each weekday 0..6 exactly once');
            }

            foreach ($this->input('days', []) as $i => $day) {
                $isRest = ($day['is_rest'] ?? null) === true;

                if ($isRest) {
                    // Mirrors the DB's is_rest XOR hours CHECK: a rest day carries no
                    // hours at all, so any of the three present is a clean 400 here
                    // rather than a raw constraint-violation 500 from Postgres.
                    $carriesHours = isset($day['start_minute']) || isset($day['end_minute']) || isset($day['break_minutes']);

                    if ($carriesHours) {
                        $validator->errors()->add("days.$i", 'a rest day must not carry hours');
                    }
                } elseif (($day['is_rest'] ?? null) === false) {
                    $start = (int) ($day['start_minute'] ?? 0);
                    $end = (int) ($day['end_minute'] ?? 0);
                    $break = (int) ($day['break_minutes'] ?? 0);

                    if (! ($end > $start && $end <= $start + 1440 && $break < ($end - $start))) {
                        $validator->errors()->add("days.$i", 'invalid working-day minutes');
                    }
                }
            }
        });
    }
}
