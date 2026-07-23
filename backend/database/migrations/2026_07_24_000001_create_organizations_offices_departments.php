<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The 3-tier hierarchy, as flat FKs. See docs/02-data-model.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('name');
            $table->text('legal_name')->nullable();
            $table->text('tin')->nullable();            // BIR taxpayer id
            $table->text('timezone')->default('Asia/Manila');
            $table->timestamps();
        });

        Schema::create('offices', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('code');
            $table->text('timezone')->default('Asia/Manila');
            // Geofence + IP allowlist are stored now so the M3 punch endpoint has a home
            // to validate against; they are unused until then.
            $table->decimal('geofence_lat', 10, 7)->nullable();
            $table->decimal('geofence_lng', 10, 7)->nullable();
            $table->integer('geofence_radius_m')->nullable();
            $table->jsonb('ip_allowlist')->nullable();
            $table->timestamps();

            $table->unique('code');
        });

        Schema::create('departments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->foreignUuid('office_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('code');
            $table->timestamps();

            $table->unique(['office_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
        Schema::dropIfExists('offices');
        Schema::dropIfExists('organizations');
    }
};
