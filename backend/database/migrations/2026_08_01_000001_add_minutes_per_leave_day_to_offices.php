<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->smallInteger('minutes_per_leave_day')->default(480)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->dropColumn('minutes_per_leave_day');
        });
    }
};
