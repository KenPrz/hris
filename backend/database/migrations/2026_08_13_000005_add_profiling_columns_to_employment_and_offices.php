<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three "Assignment" fields that are not profile data.
 *
 * designation/labor_type go on employment_records because they are effective-dated by
 * nature: a promotion changes the designation on a date, and putting it on the profile
 * would make last March's report show today's job title. region goes on offices because
 * Cebu is in Region VII regardless of who works there.
 *
 * Nothing is cached onto `employees`. The current_* columns exist so office scoping stays
 * a plain WHERE; no scope query filters by designation, so a current_designation would be
 * cache invalidation bought for nothing. See the M10a spec, decision 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_records', function (Blueprint $table): void {
            $table->text('designation')->nullable();
            $table->text('labor_type')->nullable();   // Domain\Profile\LaborType
        });

        Schema::table('offices', function (Blueprint $table): void {
            $table->text('region')->nullable();       // 'VII'
        });
    }

    public function down(): void
    {
        Schema::table('employment_records', function (Blueprint $table): void {
            $table->dropColumn(['designation', 'labor_type']);
        });

        Schema::table('offices', function (Blueprint $table): void {
            $table->dropColumn('region');
        });
    }
};
