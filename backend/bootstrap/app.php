<?php

declare(strict_types=1);

use App\Exceptions\ApiErrorEnvelope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // No `health: '/up'`. It is a second health endpoint with no action, no entry in
        // routes/api.php, and no error envelope — it returns HTML. GET /api/v1/health is
        // the real one, built as a real action. See routes/api.php.
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // One definition of the error envelope, for our exceptions and the framework's
        // alike. See docs/03-api.md.
        ApiErrorEnvelope::register($exceptions);
    })->create();
