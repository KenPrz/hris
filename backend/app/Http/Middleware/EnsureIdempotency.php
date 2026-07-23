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
            $seen = IdempotencyKey::whereKey($key)->lockForUpdate()->first();

            if ($seen !== null) {
                if (! hash_equals($seen->request_hash, $hash)) {
                    throw new IdempotencyKeyReused($key);   // 409
                }

                return response()->json($seen->response_body, $seen->response_code);
            }

            $response = $next($request);   // the action's DB::transaction() nests here

            // Only success earns a key, so a flagged-but-stored punch (a 2xx) is recorded,
            // while a genuine failure rolls back to the savepoint and stays retryable.
            if ($response->isSuccessful()) {
                IdempotencyKey::create([
                    'key' => $key,
                    'request_hash' => $hash,
                    'response_code' => $response->getStatusCode(),
                    'response_body' => json_decode($response->getContent(), true),
                    'created_at' => now(),
                ]);
            }

            return $response;
        });
    }
}
