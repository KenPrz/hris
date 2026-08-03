<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDocumentCategoryRequest extends FormRequest
{
    /** Same manageCatalog gate as CreateDocumentCategoryRequest; see its docblock. */
    public function authorize(): bool
    {
        return $this->user()?->can('manageCatalog', Document::class) === true;
    }

    public function rules(): array
    {
        // Full-object on PATCH, same as UpdateOfficeRequest: every field required/nullable
        // rather than `sometimes`, since UpdateDocumentCategory always writes every column.
        $categoryId = $this->route('category')?->id;

        return [
            // ->ignore($categoryId) so a category keeps its own code on update — without
            // it, saving a category unchanged would 400 against its own row.
            'code' => ['required', 'string', 'max:64', Rule::unique('document_categories', 'code')->ignore($categoryId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:512'],
        ];
    }
}
