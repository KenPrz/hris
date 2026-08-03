<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

final class CreateDocumentCategoryRequest extends FormRequest
{
    /**
     * Gated on DocumentPolicy::manageCatalog, not is_system_admin like most of /admin: the
     * catalog is company-wide reference data with no office to scope by, so any HR Admin
     * holding `document.manage` may edit it. Deliberately NOT overridden with a
     * failedAuthorization() — the default 403 is correct here, the same plain-403 shape as
     * /admin/pay-rules and /admin/organizations, because there is no owner id in the URL
     * for a 404-not-403 enumeration guard to protect.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageCatalog', Document::class) === true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'unique:document_categories,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:512'],
        ];
    }
}
