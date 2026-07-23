<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replay protection for mutations — a client-generated key stores the original outcome so
 * a retried punch returns it instead of writing a second row. The key and the work it
 * guards commit in ONE transaction, which EnsureIdempotency opens. Ported from POS.
 * See docs/01-architecture.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->text('key')->primary();
            $table->text('request_hash');            // sha256(user + method + path + body)
            $table->integer('response_code');
            $table->jsonb('response_body');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');             // pruning window
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
