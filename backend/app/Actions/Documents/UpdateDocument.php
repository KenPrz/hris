<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Updates one document (kind) row, full-object on PATCH — every field is required or
 * nullable as UpdateDocumentRequest validates, never `sometimes`. Like Create, no
 * unique-violation catch here: UpdateDocumentRequest's `Rule::unique(...)->ignore()` already
 * keeps a rename to another document's code a clean 400, and lets a document keep its own
 * code.
 */
final class UpdateDocument
{
    public function execute(UpdateDocumentInput $in): Document
    {
        return DB::transaction(function () use ($in): Document {
            $document = Document::query()->findOrFail($in->documentId);

            $document->fill([
                'code' => $in->code,
                'name' => $in->name,
                'description' => $in->description,
                'category_id' => $in->categoryId,
                'applies_to' => $in->appliesTo,
                'is_required' => $in->isRequired,
                'validity_months' => $in->validityMonths,
            ])->save();

            return $document;
        });
    }
}
