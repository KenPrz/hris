<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Office;

return [

    /*
    |--------------------------------------------------------------------------
    | Documentable types
    |--------------------------------------------------------------------------
    |
    | The whitelist of models that may own a document, keyed by the wire alias
    | ('employee') a client sees. This is NOT a Relation::morphMap() — there
    | isn't one. `document_files.documentable_type` stores the full class name
    | (App\Models\Employee), exactly as spatie's `media.model_type` and
    | Activitylog's `activity_log.subject_type` already do, because Employee and
    | Office are already global morph subjects via LogsActivity and a real morph
    | map would have silently rewritten activity_log's subject_type for both.
    | This array is used for validation, routing, and translating the stored
    | FQCN to its alias when serializing a DocumentFile for the API (see the
    | later DocumentFileResource). Config, not database: adding an owner type
    | needs routes, a policy branch, and UI regardless, so it is an engineering
    | act. Which document KINDS apply to which owner is a different question and
    | lives in `documents.applies_to`, which admins edit at runtime.
    |
    | The keys here must match App\Domain\Documents\Documentable's backed values.
    |
    */

    'documentable' => [
        'employee' => Employee::class,
        'office' => Office::class,
    ],

];
