<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentFile> */
final class DocumentFileFactory extends Factory
{
    protected $model = DocumentFile::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'documentable_type' => Employee::class,
            'documentable_id' => Employee::factory(),
            'uploaded_by' => null,
            'sha256' => hash('sha256', $this->faker->uuid()),
            'issued_on' => null,
            'expires_on' => null,
        ];
    }
}
