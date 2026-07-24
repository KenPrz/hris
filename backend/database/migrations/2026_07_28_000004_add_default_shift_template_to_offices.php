<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->foreignUuid('default_shift_template_id')
                ->nullable()
                ->after('timezone')
                ->constrained('shift_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_shift_template_id');
        });
    }
};
