<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Domain\IdempotencyKeyReused;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replay protection for mutations. The subtlety: the key and the work it guards must commit
 * together or not at all, so THIS middleware opens the transaction and the action's own
 * DB::transaction() nests inside it as a savepoint. Ported from POS; the hash is scoped to
 * the acting user (POS scoped to a register, which HRIS has no equivalent of).
 * See docs/04-backend-conventions.md ("Two subtleties").
 */
final class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            return $next($request);
        }

        // Fold the actor into the hash so a key is confined to whoever minted it: anyone
        // else replaying the same key + body gets a 409, never a cached response built for
        // a different person.
        $hash = hash('sha256', implode('|', [
            $request->user()?->getAuthIdentifier() ?? '',
            $request->method(),
            $request->path(),
            $request->getContent(),
        ]));

        return DB::transaction(function () use ($key, $hash, $request, $next): Response {
            // Claim the key BEFORE running the guarded work.
            //
            // This used to be `SELECT … FOR UPDATE` followed by an insert afterwards. A lock
            // on a row that does not exist yet locks NOTHING, so two first uses of one key
            // both passed that check, both ran $next() — the guarded work executed twice —
            // and then the loser's insert raised a 23505 with no savepoint, rolling back its
            // whole transaction and surfacing as a 500 where a replayed 2xx was owed.
            //
            // insertOrIgnore is the atomic claim: exactly one caller inserts, and everyone
            // else falls through to a lock that now has a real row to wait on.
            $claimed = self::claim($key, $hash);

            if (! $claimed) {
                // Someone else holds the claim. This blocks until their transaction ends.
                $seen = IdempotencyKey::whereKey($key)->lockForUpdate()->first();

                if ($seen !== null) {
                    if (! hash_equals($seen->request_hash, $hash)) {
                        throw new IdempotencyKeyReused($key);   // 409
                    }

                    // A committed claim always carries its outcome: the owner either records
                    // the response (2xx) or releases the key (non-2xx) before committing.
                    if ($seen->response_code !== null) {
                        return response()->json($seen->response_body, $seen->response_code);
                    }
                }

                // The owner released the key — their work threw, or returned non-2xx, so
                // nothing durable happened and this request owns the retry. Claim it now.
                // Another caller can still win that race; a 409 tells the client to retry,
                // which is the honest answer and is bounded, unlike looping here.
                if (! self::claim($key, $hash)) {
                    throw new IdempotencyKeyReused($key);
                }
            }

            $response = $next($request);   // the action's DB::transaction() nests here

            // Only success keeps the key, so a flagged-but-stored punch (a 2xx) is recorded,
            // while a genuine failure releases it and stays retryable — the same rule as
            // before, expressed as "resolve or release" now that the row exists up front.
            if ($response->isSuccessful()) {
                IdempotencyKey::whereKey($key)->update([
                    'response_code' => $response->getStatusCode(),
                    'response_body' => json_decode($response->getContent(), true),
                ]);
            } else {
                IdempotencyKey::whereKey($key)->delete();
            }

            return $response;
        });
    }

    /**
     * Atomically reserve the key with no outcome yet. True when this caller won it.
     *
     * insertOrIgnore rather than a check-then-insert: the check is exactly what could not be
     * made safe, since there is no row to lock until someone creates one.
     */
    private static function claim(string $key, string $hash): bool
    {
        return DB::table('idempotency_keys')->insertOrIgnore([
            'key' => $key,
            'request_hash' => $hash,
            'response_code' => null,
            'response_body' => null,
            'created_at' => now(),
        ]) === 1;
    }
}
