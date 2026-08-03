<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Domain\Documents\Documentable;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDocumentRequest extends FormRequest
{
    /** Same manageCatalog gate as CreateDocumentRequest; see its docblock. */
    public function authorize(): bool
    {
        return $this->user()?->can('manageCatalog', Document::class) === true;
    }

    public function rules(): array
    {
        // Full-object on PATCH, same as UpdateDocumentCategoryRequest: every field is
        // required/nullable rather than `sometimes`, since UpdateDocument always writes
        // every column. `is_required` is the one exception — it stays `sometimes` because
        // it's a boolean, not a string/nullable field: an omitted key is legitimate input
        // (UpdateController's `has('is_required') ? boolean(...) : false` treats absence as
        // false), so `sometimes` lets it be omitted without a `required` failure, while
        // still validating it as a boolean whenever it IS present.
        $documentId = $this->route('document')?->id;

        return [
            // ->ignore($documentId) so a document keeps its own code on update — without
            // it, saving a document unchanged would 400 against its own row.
            'code' => ['required', 'string', 'max:64', Rule::unique('documents', 'code')->ignore($documentId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:512'],
            'category_id' => ['required', 'uuid', 'exists:document_categories,id'],
            'applies_to' => ['nullable', Rule::enum(Documentable::class)],
            'is_required' => ['sometimes', 'boolean'],
            'validity_months' => ['nullable', 'integer', 'min:1', 'max:600'],
        ];
    }
}
