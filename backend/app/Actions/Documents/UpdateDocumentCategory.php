<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\DocumentCategory;
use Illuminate\Support\Facades\DB;

/**
 * Updates one document-category row, full-object on PATCH — every field is required or
 * nullable as UpdateDocumentCategoryRequest validates, never `sometimes`. Like Create, no
 * unique-violation catch here: UpdateDocumentCategoryRequest's `Rule::unique(...)->ignore()`
 * already keeps a rename to another category's code a clean 400, and lets a category keep
 * its own code.
 */
final class UpdateDocumentCategory
{
    public function execute(UpdateDocumentCategoryInput $in): DocumentCategory
    {
        return DB::transaction(function () use ($in): DocumentCategory {
            $category = DocumentCategory::query()->findOrFail($in->categoryId);

            $category->fill([
                'code' => $in->code,
                'name' => $in->name,
                'description' => $in->description,
            ])->save();

            return $category;
        });
    }
}
