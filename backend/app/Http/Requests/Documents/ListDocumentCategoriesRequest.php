<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

final class ListDocumentCategoriesRequest extends FormRequest
{
    /**
     * Same manageCatalog gate as the other three catalog FormRequests, kept identical here
     * for uniformity across the CRUD set — not because the data is otherwise sensitive.
     * The same rows this route returns are ALSO served, unauthenticated-permission-wise, by
     * the deliberately ungated `GET /documents/catalog` (`ShowCatalogController`) — same
     * resources, same order, no gate at all. Gating this route doesn't make the data any
     * less public; it just keeps `/admin/document-categories`'s four routes (list, create,
     * update, delete) sharing one authorization shape instead of the list being the odd one
     * out.
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
