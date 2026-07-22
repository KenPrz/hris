<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Exceptions\Domain\DomainException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * The single definition of the error envelope in docs/03-api.md.
 *
 * Our own DomainExceptions are the easy half. The other half is the framework's own
 * exceptions — a 404 or a validation failure must come back in the same shape as
 * everything else, or "one shape, everywhere, so the client has one code path" is a
 * claim the API does not actually honour.
 *
 * The envelope is **closed, not enumerated**: the specific handlers below are followed
 * by a catch-all for every HttpException and, outside debug, for every uncaught
 * Throwable. An enumerated list has to be remembered every time a new failure mode
 * appears, and the ones that get forgotten fail *silently*, in the framework's shape.
 */
final class ApiErrorEnvelope
{
    /**
     * Status → stable, machine-readable `code`. Clients branch on the code, so it must
     * not drift; statuses not listed here derive `http_<status>`, which is stable too.
     *
     * @var array<int, string>
     */
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        419 => 'token_mismatch',
        422 => 'unprocessable',
        423 => 'locked',
        429 => 'too_many_requests',
        500 => 'internal_error',
        503 => 'service_unavailable',
    ];

    /**
     * Deliberately canned. The fallback cannot know whether an exception's own message
     * is safe to show — `Response::denyAsNotFound('not the owner of salary #5')` would
     * otherwise leak exactly the fact the 404-not-403 rule exists to hide. `message` is
     * human-readable and must never be parsed; `code` carries the contract.
     *
     * @var array<int, string>
     */
    private const MESSAGES = [
        400 => 'The request could not be understood.',
        401 => 'Authentication is required.',
        403 => 'This action is not allowed.',
        404 => 'The requested resource does not exist.',
        405 => 'That method is not supported here.',
        409 => 'That conflicts with the current state.',
        419 => 'The session expired. Try again.',
        422 => 'The request could not be processed.',
        423 => 'That resource is locked.',
        429 => 'Slow down.',
        500 => 'Something went wrong on our end.',
        503 => 'The service is temporarily unavailable.',
    ];

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

        // No AuthorizationException handler here on purpose. Handler::render() calls
        // prepareException() *before* renderViaCallbacks(), and prepareException rewrites
        // every AuthorizationException — into AccessDeniedHttpException without a status,
        // into a plain HttpException with one. A callback typed on AuthorizationException
        // can never fire. The two handlers that do the work are below.
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

        // --- The close. Registered last, because renderViaCallbacks() walks the
        // callbacks in registration order and takes the first non-null response, so
        // everything specific above still wins. ---

        // Every remaining HTTP exception, whatever produced it: abort(409), a statused
        // policy denial (the 404-not-403 rule), TokenMismatchException's 419, a
        // BadRequestHttpException from a malformed JSON body.
        $exceptions->render(function (HttpExceptionInterface $e, Request $r): ?JsonResponse {
            if (! self::applies($r)) {
                return null;
            }

            $status = $e->getStatusCode();

            return self::json(
                self::CODES[$status] ?? 'http_'.$status,
                self::MESSAGES[$status] ?? 'The request could not be completed.',
                [],
                $status,
            );
        });

        // Anything left is a bug, not a refusal. In debug we return null so Laravel's own
        // detailed page — message, exception class, file, line, trace — still surfaces;
        // swallowing that would make local debugging miserable. Outside debug the client
        // gets the envelope and nothing else.
        $exceptions->render(function (Throwable $e, Request $r): ?JsonResponse {
            if (! self::applies($r) || config('app.debug') === true) {
                return null;
            }

            return self::json('internal_error', self::MESSAGES[500], [], 500);
        });
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
