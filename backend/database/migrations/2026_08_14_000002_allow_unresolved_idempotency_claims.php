<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets an idempotency key exist before its outcome does.
 *
 * EnsureIdempotency used to read `SELECT … FOR UPDATE` on the key and, finding nothing,
 * run the guarded work and insert afterwards. A lock on a row that does not exist yet
 * locks nothing, so two first uses of one key both passed that check, both executed the
 * work, and the loser's insert raised a 23505 with no savepoint — rolling back its whole
 * transaction and surfacing as a 500 where a replayed 2xx was owed.
 *
 * The fix claims the key with an atomic insert BEFORE running the work, which means the
 * row briefly exists with no response yet. Hence nullable — with a CHECK that the two
 * response columns move together, so a half-written key is a constraint violation rather
 * than a replay of `null`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE idempotency_keys ALTER COLUMN response_code DROP NOT NULL');
        DB::statement('ALTER TABLE idempotency_keys ALTER COLUMN response_body DROP NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_response_pair_check
            CHECK ((response_code IS NULL) = (response_body IS NULL))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE idempotency_keys DROP CONSTRAINT idempotency_keys_response_pair_check');
        DB::statement('DELETE FROM idempotency_keys WHERE response_code IS NULL');
        DB::statement('ALTER TABLE idempotency_keys ALTER COLUMN response_code SET NOT NULL');
        DB::statement('ALTER TABLE idempotency_keys ALTER COLUMN response_body SET NOT NULL');
    }
};
