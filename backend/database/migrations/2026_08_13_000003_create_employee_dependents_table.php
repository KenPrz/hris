<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_dependents', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            // ponytail: NULLABLE by explicit decision (spec, decision 8) — an orphan
            // dependent row is unreachable by every query in the system, this was raised,
            // and the answer was to keep it. Intent, not an oversight to tighten.
            $table->foreignUuid('employee_id')->nullable()->constrained()->cascadeOnDelete();

            $table->text('name');
            $table->foreignUuid('relationship_id')->constrained('relationships');
            $table->date('birth_date')->nullable();
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_dependents');
    }
};
