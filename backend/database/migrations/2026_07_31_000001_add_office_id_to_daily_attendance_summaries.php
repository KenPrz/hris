<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_attendance_summaries', function (Blueprint $table): void {
            $table->foreignUuid('office_id')->nullable()->after('date')->constrained('offices')->nullOnDelete();
            $table->index(['office_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_attendance_summaries', function (Blueprint $table): void {
            $table->dropIndex(['office_id', 'date']);
            $table->dropConstrainedForeignId('office_id');
        });
    }
};
