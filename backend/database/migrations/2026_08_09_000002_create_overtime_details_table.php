<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The overtime pre-authorization request's 1:1 detail — mirrors leave_details: the primary
 * key IS the request's id (no separate id column), one request, one detail, enforced by the
 * database. A single business date and the requested-and-approved overtime minutes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_details', function (Blueprint $table): void {
            $table->uuid('request_id')->primary();
            $table->foreign('request_id')->references('id')->on('requests')->cascadeOnDelete();

            $table->date('date');
            $table->integer('minutes');
        });

        DB::statement('ALTER TABLE overtime_details ADD CONSTRAINT overtime_details_minutes_pos_check CHECK (minutes > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_details');
    }
};
