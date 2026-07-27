<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens leave_ledger's `source` CHECK to admit `leave_taken` alongside `manual_grant` —
 * the debit LeaveEffect writes on a leave request's final approval, distinct from an HR
 * admin's manual credit (GrantLeave).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE leave_ledger DROP CONSTRAINT leave_ledger_source_check');
        DB::statement("ALTER TABLE leave_ledger ADD CONSTRAINT leave_ledger_source_check CHECK (source IN ('manual_grant','leave_taken'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE leave_ledger DROP CONSTRAINT leave_ledger_source_check');
        DB::statement("ALTER TABLE leave_ledger ADD CONSTRAINT leave_ledger_source_check CHECK (source IN ('manual_grant'))");
    }
};
