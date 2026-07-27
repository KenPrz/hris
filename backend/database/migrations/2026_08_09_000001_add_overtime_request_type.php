<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens the request spine to admit overtime pre-authorization alongside attendance
 * adjustment and leave. Overtime is single-hop (RequestType::requiresHrStep() === false),
 * routed exactly like an attendance adjustment.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE requests DROP CONSTRAINT requests_type_check');
        DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_type_check CHECK (type IN ('attendance_adjustment','leave','overtime'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE requests DROP CONSTRAINT requests_type_check');
        DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_type_check CHECK (type IN ('attendance_adjustment','leave'))");
    }
};
