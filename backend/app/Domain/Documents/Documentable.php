<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * The owner types a document may apply to. The backed values ARE the morph aliases stored in
 * `document_files.documentable_type` and declared in `config/documents.php` — one spelling,
 * three places, so a rename cannot half-land.
 */
enum Documentable: string
{
    case Employee = 'employee';
    case Office = 'office';
}
