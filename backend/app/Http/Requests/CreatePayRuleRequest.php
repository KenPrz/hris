<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Pay\DayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CreatePayRuleRequest extends FormRequest
{
    /**
     * Sysadmin-only, the codebase's usual idiom (RecordEmploymentRequest et al.). Pay
     * rules are a company singleton, not a per-office/per-subject resource, so the
     * 404-not-403 enumeration discipline does not apply here — a non-admin gets the
     * default failedAuthorization() (AuthorizationException -> 403 forbidden).
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    public function rules(): array
    {
        return [
            'effective_from' => ['required', 'date'],
            'overtime_ordinary_bp' => ['required', 'integer', 'min:0'],
            'overtime_premium_bp' => ['required', 'integer', 'min:0'],
            'night_diff_bp' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string'],

            // Shape only. Completeness (exactly the five DayType values, no dup, none
            // missing) is asserted below in withValidator so StatutoryFloor always
            // receives a complete matrix — a partial one would silently compare fewer
            // than five cells against the floor.
            'day_rates' => ['required', 'array', 'size:5'],
            'day_rates.*.day_type' => ['required', Rule::in(array_column(DayType::cases(), 'value'))],
            'day_rates.*.worked_bp' => ['required', 'integer', 'min:0'],
            'day_rates.*.worked_rest_bp' => ['required', 'integer', 'min:0'],
            'day_rates.*.unworked_bp' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $dayTypes = collect($this->input('day_rates', []))
                ->pluck('day_type')
                ->filter(fn (mixed $value): bool => is_string($value))
                ->unique()
                ->sort()
                ->values()
                ->all();

            $expected = collect(DayType::cases())
                ->map(fn (DayType $dayType): string => $dayType->value)
                ->sort()
                ->values()
                ->all();

            if ($dayTypes !== $expected) {
                $validator->errors()->add('day_rates', 'day_rates must contain exactly the five day types, each exactly once');
            }
        });
    }
}
