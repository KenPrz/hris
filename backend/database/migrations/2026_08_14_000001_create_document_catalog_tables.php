<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The document catalog: a shelf (`document_categories`) and the kinds on it (`documents`).
 *
 * Unlike M10a's `employee_identification_categories` — fixed by Philippine law, so seeded
 * config — this catalog is ADMIN-EDITABLE at runtime. "Company Policy 2027" is the company's
 * business, not an engineer's, which is the config-vs-database line in 04-backend-conventions.md.
 *
 * `applies_to` / `is_required` / `validity_months` are behaviour, not taxonomy. They live here
 * rather than in a separate "document type" table because a second table with identical
 * columns to `document_categories` would be one concept named twice — see the M10b spec,
 * decision 3.
 *
 * The FK from documents.category_id is deliberately RESTRICT (Laravel's default), not cascade:
 * deleting a shelf must not silently delete every kind on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('code')->unique();
            $table->text('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('code')->unique();
            $table->text('name');
            $table->text('description')->nullable();
            $table->foreignUuid('category_id')->constrained('document_categories');

            // 'employee' | 'office' | null (both). Morph aliases, matching config/documents.php
            // — never a FQCN. Plain text with a PHP backed enum on the model, per the M10a
            // precedent: no CHECK constraint, so adding an owner type is not a migration.
            $table->text('applies_to')->nullable();

            $table->boolean('is_required')->default(false);

            // null = never expires. A signed contract does not lapse; an NBI clearance does.
            $table->integer('validity_months')->nullable();

            $table->timestamps();

            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_categories');
    }
};
