<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CreateShiftTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the office-scope check is the controller's job, not this request's
    }

    /**
     * Shape only — deliberately NO `exists:offices,id`. That would let a fake office id
     * 400 while an out-of-scope real one 404s in the controller, reintroducing the
     * enumeration oracle the 404-not-403 rule exists to close.
     */
    public function rules(): array
    {
        return [
            'office_id' => ['required', 'uuid'],
            'name' => ['required', 'string'],
            'days' => ['required', 'array', 'size:7'],
            'days.*.weekday' => ['required', 'integer', 'between:0,6'],
            'days.*.is_rest' => ['required', 'boolean'],
            'days.*.start_minute' => ['nullable', 'integer', 'between:0,1439', 'required_if:days.*.is_rest,false'],
            'days.*.end_minute' => ['nullable', 'integer', 'between:1,2879', 'required_if:days.*.is_rest,false'],
            'days.*.break_minutes' => ['nullable', 'integer', 'min:0', 'required_if:days.*.is_rest,false'],
        ];
    }

    /**
     * Mirrors the shift_template_days DB CHECKs at the request layer, so a bad payload is
     * a clean 400 here rather than a raw constraint-violation 500 from Postgres: exactly
     * the seven distinct weekdays 0..6, and a working day's minutes forming a real range.
     */
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
