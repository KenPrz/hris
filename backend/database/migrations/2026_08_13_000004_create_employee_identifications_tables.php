<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Government and financial IDs as ROWS against a catalog, not eight columns on the
 * profile. An identification is not a bare number — it has an issue date, an expiry, and a
 * scanned copy HR is expected to be able to produce, none of which a column can carry, and
 * a ninth ID type must be a row rather than a migration.
 *
 * unique(employee_id, category_id) is what makes the write path a clean upsert: one
 * employee has one TIN. See the M10a spec, decision 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_identification_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('code')->unique();      // 'TIN', 'SSS', 'HDMF', 'PHIC', ...
            $table->text('name');                // 'TIN', 'SSS ID', 'Pag-IBIG MID', ...
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_identifications', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('employee_identification_categories');

            $table->text('number');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_identifications');
        Schema::dropIfExists('employee_identification_categories');
    }
};
