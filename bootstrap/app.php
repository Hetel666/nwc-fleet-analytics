<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: null,
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB
        );

        $middleware->web(
            append: [
                App\Http\Middleware\SecurityHeaders::class,
                App\Http\Middleware\SetLocale::class,
            ],
            replace: [
                Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class => App\Http\Middleware\ValidateCsrfToken::class,
            ],
        );

        $middleware->alias([
            'active' => App\Http\Middleware\EnsureActiveUser::class,
            'admin' => App\Http\Middleware\EnsureAdmin::class,
            'dashboard.section' => App\Http\Middleware\EnsureDashboardSectionAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('app.session_expired')], 419);
            }

            return redirect()
                ->route('login')
                ->withErrors(['email' => __('app.session_expired')]);
        });
    })->create();
