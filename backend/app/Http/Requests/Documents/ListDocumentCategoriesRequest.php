<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

final class ListDocumentCategoriesRequest extends FormRequest
{
    /**
     * Same manageCatalog gate as the other three catalog FormRequests, kept identical here
     * on purpose — the read is behind the same gate as the writes, so all four routes share
     * one authorization shape rather than the list quietly being open to more actors than
     * the mutations.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manageCatalog', Document::class) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
