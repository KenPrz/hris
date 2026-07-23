<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // any authenticated user; RequestAuthority decides in the action (404 if not)
    }

    public function rules(): array
    {
        return [];
    }
}
