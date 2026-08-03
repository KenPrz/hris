<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

final class ListDocumentsRequest extends FormRequest
{
    /**
     * Same manageCatalog gate as the other three Document FormRequests, kept identical here
     * for uniformity across the CRUD set — not because the data is otherwise sensitive. The
     * same rows this route returns are ALSO served, unauthenticated-permission-wise, by the
     * deliberately ungated `GET /documents/catalog` (`ShowCatalogController`) — same
     * resources, same order, no gate at all. Gating this route doesn't make the data any
     * less public; it just keeps `/admin/documents`'s four routes (list, create, update,
     * delete) sharing one authorization shape instead of the list being the odd one out. See
     * CreateDocumentRequest's docblock for the rest of the reasoning.
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
