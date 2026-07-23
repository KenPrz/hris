<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global oversight is a flag, not a role: spatie cannot express an assignment that spans
 * every office, and a null-team role assignment is impossible because model_has_roles's
 * key columns are NOT NULL. Granted via Gate::before. See docs/05-rbac.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_system_admin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_system_admin');
        });
    }
};
