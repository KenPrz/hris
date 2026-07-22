<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Domain\DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * The single definition of the error envelope in docs/03-api.md.
 *
 * Our own DomainExceptions are the easy half. The other half is the framework's own
 * exceptions — a 404 or a validation failure must come back in the same shape as
 * everything else, or "one shape, everywhere, so the client has one code path" is a
 * claim the API does not actually honour.
 */
final class ApiErrorEnvelope
{
    public static function register(Exceptions $exceptions): void
    {
        // Business-rule failures thrown by actions. Every code in the docs/03-api.md
        // error table is one subclass of DomainException.
        $exceptions->render(fn (DomainException $e, Request $r) => self::applies($r)
            ? self::json($e->errorCode(), $e->getMessage(), $e->details(), $e->httpStatus())
            : null);

        // Well-formed but invalid input. 400, not Laravel's default 422 — docs/03-api.md
        // reserves 422 for requests that are structurally fine but semantically rejected.
        $exceptions->render(fn (ValidationException $e, Request $r) => self::applies($r)
            ? self::json('validation_failed', 'The request is invalid.', ['fields' => $e->errors()], 400)
            : null);

        $exceptions->render(fn (AuthenticationException $e, Request $r) => self::applies($r)
            ? self::json('unauthenticated', 'Authentication is required.', [], 401)
            : null);

        $exceptions->render(fn (AuthorizationException $e, Request $r) => self::applies($r)
            ? self::json('forbidden', 'This action is not allowed.', [], 403)
            : null);

        $exceptions->render(fn (AccessDeniedHttpException $e, Request $r) => self::applies($r)
            ? self::json('forbidden', 'This action is not allowed.', [], 403)
            : null);

        $exceptions->render(fn (NotFoundHttpException $e, Request $r) => self::applies($r)
            ? self::json('not_found', 'The requested resource does not exist.', [], 404)
            : null);

        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $r) => self::applies($r)
            ? self::json('method_not_allowed', 'That method is not supported here.', [], 405)
            : null);

        $exceptions->render(fn (TooManyRequestsHttpException $e, Request $r) => self::applies($r)
            ? self::json('too_many_requests', 'Slow down.', [], 429)
            : null);
    }

    private static function applies(Request $request): bool
    {
        return $request->is('api/*');
    }

    /** @param array<string, mixed> $details */
    private static function json(string $code, string $message, array $details, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                // Cast so empty details serialize as {} rather than []. `details` is
                // always an object in the contract, and a client typing it as
                // Record<string, unknown> should never be handed a JSON array.
                'details' => (object) $details,
            ],
        ], $status);
    }
}
