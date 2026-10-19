<?php

use App\Http\Middleware\EnsureEmailTransportReady;
use App\Http\Middleware\EnsureUserActive;
use App\Http\Middleware\RequireAssistSession;
use App\Http\Middleware\RequireSchoolPermission;
use App\Http\Middleware\RequireTerminalHeader;
use App\Http\Middleware\ResetDatabaseContext;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Http\ProblemRenderer;
use App\Support\Http\RequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // API dashboard berbasis cookie sesi (Sanctum SPA): grup `web` memberi sesi + CSRF
            // pada SETIAP request, tanpa bergantung pada deteksi origin "stateful".
            Route::middleware('web')
                ->prefix('api/v1')
                ->group(base_path('routes/api.php'));

            // Terminal: tanpa sesi/CSRF cookie; autentikasi lewat token perangkat (AuthenticateDevice).
            Route::prefix('api/v1/terminal')->group(base_path('routes/terminal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(ResetDatabaseContext::class);
        $middleware->appendToGroup('web', EnsureUserActive::class);
        $middleware->alias([
            RequireSchoolPermission::ALIAS => RequireSchoolPermission::class,
            EnsureEmailTransportReady::ALIAS => EnsureEmailTransportReady::class,
            RequireTerminalHeader::ALIAS => RequireTerminalHeader::class,
            'assist.session' => RequireAssistSession::class,
        ]);
        // Tanpa halaman login di API: tamu selalu mendapat 401 problem+json.
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->redirectUsersTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*', 'sanctum/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            return $isApi($request) ? app(ProblemRenderer::class)->render($e, $request) : null;
        });
    })->create();
