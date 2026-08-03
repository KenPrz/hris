<?php

declare(strict_types=1);

namespace App\Actions\Documents;

final readonly class CreateDocumentCategoryInput
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description,
    ) {}
}
