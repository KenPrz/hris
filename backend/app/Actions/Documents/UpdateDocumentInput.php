<?php

declare(strict_types=1);

namespace App\Actions\Documents;

final readonly class UpdateDocumentInput
{
    public function __construct(
        public string $documentId,
        public string $code,
        public string $name,
        public ?string $description,
        public string $categoryId,
        public ?string $appliesTo,
        public bool $isRequired,
        public ?int $validityMonths,
    ) {}
}
