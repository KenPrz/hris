<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Exceptions\Domain\DocumentCatalogInUse;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Deletes one document (kind) row — refuse, never cascade. `documents` is referenced by
 * `document_files.document_id` behind an `ON DELETE RESTRICT` FK; the count check below is
 * the clean-409-with-a-count path for the overwhelmingly common case, and the FK itself is
 * the race-safe backstop for a concurrent DocumentFile upload that slips past this check
 * between the count and the delete (see Task 7 Step 7: deleting this check surfaces the FK
 * violation as a raw QueryException 500 instead).
 */
final class DeleteDocument
{
    public function execute(DeleteDocumentInput $in): void
    {
        DB::transaction(function () use ($in): void {
            $document = Document::query()->findOrFail($in->documentId);

            // Refuse, never cascade. The DB's RESTRICT FK is the backstop; this check exists
            // so the caller gets a 409 with a count rather than a raw QueryException 500.
            $dependents = $document->files()->count();

            if ($dependents > 0) {
                throw new DocumentCatalogInUse('document', $document->id, $dependents);
            }

            $document->delete();
        });
    }
}
