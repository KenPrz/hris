<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The personnel file's 1:1 side table. Keyed on employee_id as the PRIMARY KEY — the
 * relationship is the identity, so there is no surrogate id to get out of sync.
 *
 * Deliberately NOT columns on `employees`: half of this changes over a career (address,
 * phone, marital status) and `employees` is the row every office-scope query touches.
 * See docs/superpowers/specs/2026-07-30-m10a-employee-profiling-design.md, decision 1.
 *
 * gender/marital_status/blood_type are plain text with PHP backed enums cast on the model
 * (decision 4) — no CHECK constraint, deliberately. Adding a marital status must not be a
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->foreignUuid('employee_id')->primary()->constrained()->cascadeOnDelete();

            $table->text('salutation')->nullable();
            $table->text('nickname')->nullable();

            $table->text('home_address')->nullable();
            $table->text('personal_email')->nullable();
            $table->text('phone')->nullable();
            $table->text('fax')->nullable();
            $table->text('mobile')->nullable();
            // ponytail: one free-text line ("Juan Perez (father) 0917…"), not name/relation/
            // phone columns. Split it when something needs to dial it programmatically.
            $table->text('emergency_contact')->nullable();

            $table->text('gender')->nullable();            // Domain\Profile\Gender
            $table->date('birth_date')->nullable();
            $table->text('birthplace')->nullable();
            $table->text('marital_status')->nullable();    // Domain\Profile\MaritalStatus
            $table->text('citizenship')->nullable();
            $table->text('religion')->nullable();
            $table->text('blood_type')->nullable();        // Domain\Profile\BloodType

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
