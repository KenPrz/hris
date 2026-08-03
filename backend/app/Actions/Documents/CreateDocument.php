<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Creates one document (kind) row. Same shape as CreateDocumentCategory: no pre-check +
 * unique-violation catch here, because CreateDocumentRequest already enforces
 * `unique:documents,code` before this action ever runs, so a duplicate code is a clean 400
 * validation_failed at the FormRequest layer, never a raw UniqueConstraintViolationException
 * reaching this class.
 */
final class CreateDocument
{
    public function execute(CreateDocumentInput $in): Document
    {
        return DB::transaction(function () use ($in): Document {
            return Document::query()->create([
                'code' => $in->code,
                'name' => $in->name,
                'description' => $in->description,
                'category_id' => $in->categoryId,
                'applies_to' => $in->appliesTo,
                'is_required' => $in->isRequired,
                'validity_months' => $in->validityMonths,
            ]);
        });
    }
}
