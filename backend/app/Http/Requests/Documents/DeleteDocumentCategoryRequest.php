<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

final class DeleteDocumentCategoryRequest extends FormRequest
{
    /** Same manageCatalog gate as CreateDocumentCategoryRequest; see its docblock. */
    public function authorize(): bool
    {
        return $this->user()?->can('manageCatalog', Document::class) === true;
    }

    public function rules(): array
    {
        // {category} binds via route-model-binding and the request body is empty — nothing
        // beyond the gate itself to validate, same shape as OfficeAdminRequest.
        return [];
    }
}
