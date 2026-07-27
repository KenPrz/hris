<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens the request spine to admit leave alongside attendance adjustment.
 * `RequestType::requiresHrStep()` is what tells them apart at the domain layer: leave is
 * two-hop (manager -> HR), attendance adjustment stays single-hop.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE requests DROP CONSTRAINT requests_type_check');
        DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_type_check CHECK (type IN ('attendance_adjustment','leave'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE requests DROP CONSTRAINT requests_type_check');
        DB::statement("ALTER TABLE requests ADD CONSTRAINT requests_type_check CHECK (type IN ('attendance_adjustment'))");
    }
};
