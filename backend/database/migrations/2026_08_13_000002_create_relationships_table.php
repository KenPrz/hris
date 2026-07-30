<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dependent-relationship catalog (spouse/child/parent/…). A table rather than a PHP
 * enum because it is referenced by a foreign key, not merely validated — the one place
 * M10a's "closed sets are enums" rule bends, deliberately. See the spec, decision 4.
 *
 * Seeded by ProfileCatalogSeeder, which production runs through hris:bootstrap-admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationships', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->text('code')->unique();
            $table->text('description');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationships');
    }
};
