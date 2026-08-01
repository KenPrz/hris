<?php

declare(strict_types=1);

namespace App\Actions\Documents;

final readonly class DeleteDocumentCategoryInput
{
    public function __construct(
        public string $categoryId,
    ) {}
}
