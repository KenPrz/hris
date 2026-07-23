<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens attendance_logs.source to admit 'adjustment' — the source recorded on a punch
 * that an approved attendance-adjustment request writes into the append-only ledger.
 * Additive only: the ledger itself is never mutated, and no existing row's source changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE attendance_logs DROP CONSTRAINT attendance_logs_source_check');
        DB::statement("ALTER TABLE attendance_logs ADD CONSTRAINT attendance_logs_source_check CHECK (source IN ('web','manual','device','adjustment'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attendance_logs DROP CONSTRAINT attendance_logs_source_check');
        DB::statement("ALTER TABLE attendance_logs ADD CONSTRAINT attendance_logs_source_check CHECK (source IN ('web','manual','device'))");
    }
};
