<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every employee gets a name. first_name/last_name are NOT NULL with a DEFAULT '' so any
 * existing row stays valid; the DB does not enforce non-empty — the FormRequest does that
 * on create/update (M8b Task 2/3). middle_name/name_suffix are optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->text('first_name')->default('');
            $table->text('middle_name')->nullable();
            $table->text('last_name')->default('');
            $table->text('name_suffix')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'middle_name', 'last_name', 'name_suffix']);
        });
    }
};
