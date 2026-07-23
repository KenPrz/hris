<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HR-Admin scope grant: (user, office) pairs. Being in this table for an office is
 * what confers HR-Admin scope over that office's employees. The verb set comes from the
 * spatie 'HR Admin' role; this pivot is the "over whom". See docs/05-rbac.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_admin_offices', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('office_id')->constrained()->cascadeOnDelete();

            $table->primary(['user_id', 'office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_admin_offices');
    }
};
