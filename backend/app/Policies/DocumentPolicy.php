<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for the document CATALOG — the company-wide list of kinds and categories.
 *
 * Deliberately unscoped by office: `documents` and `document_categories` have no office_id,
 * so there is nothing to scope by and holding `document.manage` is the whole check. FILE
 * authorization is a different question — it IS office-scoped, through the hr_admin_offices
 * pivot — and arrives with the file endpoints in M10b-b.
 *
 * System admins never reach here; Gate::before short-circuits first.
 */
final class DocumentPolicy
{
    public function manageCatalog(User $user): bool
    {
        return $user->can('document.manage');
    }
}
