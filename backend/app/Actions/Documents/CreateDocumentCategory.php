<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\DocumentCategory;
use Illuminate\Support\Facades\DB;

/**
 * Creates one document-category row. Unlike CreateOffice/CreatePayRule, there is no
 * pre-check + unique-violation catch here: CreateDocumentCategoryRequest already enforces
 * `unique:document_categories,code` before this action ever runs, so the duplicate-code
 * case is a clean 400 validation_failed at the FormRequest layer, never a raw
 * UniqueConstraintViolationException reaching this class.
 */
final class CreateDocumentCategory
{
    public function execute(CreateDocumentCategoryInput $in): DocumentCategory
    {
        return DB::transaction(function () use ($in): DocumentCategory {
            return DocumentCategory::query()->create([
                'code' => $in->code,
                'name' => $in->name,
                'description' => $in->description,
            ]);
        });
    }
}
