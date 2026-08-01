<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Domain\Documents\Documentable;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateDocumentRequest extends FormRequest
{
    /**
     * Gated on DocumentPolicy::manageCatalog, not is_system_admin like most of /admin: the
     * catalog is company-wide reference data with no office to scope by, so any HR Admin
     * holding `document.manage` may edit it. Deliberately NOT overridden with a
     * failedAuthorization() — the default 403 is correct here, the same plain-403 shape as
     * /admin/document-categories, because there is no owner id in the URL for a
     * 404-not-403 enumeration guard to protect.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageCatalog', Document::class) === true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'unique:documents,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:512'],
            // exists: IS correct here, unlike office_id in CreateLeaveTypeRequest. That rule
            // omits exists because an out-of-scope office must 404 in the controller and an
            // exists failure would 400 instead — an enumeration oracle. document_categories
            // is company-wide reference data readable by any authenticated user via
            // GET /documents/catalog, so there is nothing to enumerate here.
            'category_id' => ['required', 'uuid', 'exists:document_categories,id'],
            // Rule::enum matches the backed value exactly — 'Employee' is a 400, not a
            // silent coerce.
            'applies_to' => ['nullable', Rule::enum(Documentable::class)],
            'is_required' => ['sometimes', 'boolean'],
            // min:1, not 0: a zero-month validity means "expired on issue", which is never
            // what anyone means, and null already expresses "never expires".
            'validity_months' => ['nullable', 'integer', 'min:1', 'max:600'],
        ];
    }
}
