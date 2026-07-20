<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as BaseValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

class ValidateCsrfToken extends BaseValidateCsrfToken
{
    /**
     * Keep CSRF validation enabled, but redirect expired web forms to login.
     */
    public function handle($request, Closure $next): Response
    {
        try {
            return parent::handle($request, $next);
        } catch (TokenMismatchException $exception) {
            if ($request instanceof Request && $request->expectsJson()) {
                return response()->json(['message' => __('app.session_expired')], 419);
            }

            return redirect()
                ->route('login')
                ->withErrors(['email' => __('app.session_expired')]);
        }
    }
}
