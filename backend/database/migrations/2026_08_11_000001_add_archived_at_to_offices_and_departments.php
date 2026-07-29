<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive-never-delete for the org tree: a nullable archived_at marks an office/department
 * closed without removing it (a legal-retention record, not a row to delete). Active lists
 * filter whereNull('archived_at'). Non-cascading — an archived office keeps its children.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->timestampTz('archived_at')->nullable();
        });
        Schema::table('departments', function (Blueprint $table): void {
            $table->timestampTz('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offices', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn('archived_at'));
    }
};
