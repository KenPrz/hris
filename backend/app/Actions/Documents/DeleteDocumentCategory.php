<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Exceptions\Domain\DocumentCatalogInUse;
use App\Models\DocumentCategory;
use Illuminate\Support\Facades\DB;

/**
 * Deletes one document-category row — refuse, never cascade. `document_categories` is
 * referenced by `documents.category_id` behind an `ON DELETE RESTRICT` FK; the count
 * check below is the clean-409-with-a-count path for the overwhelmingly common case, and
 * the FK itself is the race-safe backstop for a concurrent Document creation that slips
 * past this check between the count and the delete (see Task 6 Step 9: deleting this
 * check surfaces the FK violation as a raw QueryException 500 instead).
 */
final class DeleteDocumentCategory
{
    public function execute(DeleteDocumentCategoryInput $in): void
    {
        DB::transaction(function () use ($in): void {
            $category = DocumentCategory::query()->findOrFail($in->categoryId);

            // Refuse, never cascade. The DB's RESTRICT FK is the backstop; this check exists
            // so the caller gets a 409 with a count rather than a raw QueryException 500.
            $dependents = $category->documents()->count();

            if ($dependents > 0) {
                throw new DocumentCatalogInUse('document_category', $category->id, $dependents);
            }

            $category->delete();
        });
    }
}
