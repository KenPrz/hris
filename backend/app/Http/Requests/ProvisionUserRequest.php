<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProvisionUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_system_admin;
    }

    /**
     * 404, not the default 403. The employee id is in the URL, so authorize()
     * runs after route-model binding: a real id binds then 403s while a fabricated id
     * 404s at binding — a status split that lets any authenticated user enumerate which
     * employee ids exist company-wide. A non-admin now gets the same 404 as a nonexistent
     * id, so existence never leaks. (Contrast CreateEmployee, which has no subject in the
     * URL and correctly 403s a non-admin.)
     */
    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
