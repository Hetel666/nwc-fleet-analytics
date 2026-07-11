<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) $request->session()->get('locale', config('app.locale', 'az'));

        if (! in_array($locale, ['az', 'ru', 'en'], true)) {
            $locale = 'az';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
