<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * The owner types a document may apply to. The backed values are the wire aliases used in
 * `documents.applies_to`, `config/documents.php`'s whitelist keys, and the API's
 * `documentable_type`/`applies_to` fields — one spelling, several places.
 *
 * They are NOT what `document_files.documentable_type` stores in the database. There is no
 * `Relation::morphMap()` for this relation (see `02-data-model.md`'s "Document management"
 * section and `config/documents.php`'s own comment): that column holds the full class name
 * (`App\Models\Employee`), exactly like spatie's `media.model_type` and
 * `activity_log.subject_type` already do. The alias here is a wire-layer concern only —
 * translated from the stored FQCN when a `DocumentFile` is serialized (M10b-b).
 */
enum Documentable: string
{
    case Employee = 'employee';
    case Office = 'office';
}
