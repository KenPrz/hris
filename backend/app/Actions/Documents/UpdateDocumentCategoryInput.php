<?php

declare(strict_types=1);

namespace App\Actions\Documents;

final readonly class UpdateDocumentCategoryInput
{
    public function __construct(
        public string $categoryId,
        public string $code,
        public string $name,
        public ?string $description,
    ) {}
}
