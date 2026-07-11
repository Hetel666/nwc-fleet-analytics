<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "base-uri 'self'",
                "object-src 'none'",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "img-src 'self' data: https://*.tile.openstreetmap.org https://unpkg.com",
                "font-src 'self' data: https://cdn.jsdelivr.net",
                "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com",
                "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com",
                "connect-src 'self'",
            ]),
        ];

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
