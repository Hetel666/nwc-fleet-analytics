<?php

namespace App\Http\Middleware;

use App\Support\DashboardSectionAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDashboardSectionAccess
{
    public function handle(Request $request, Closure $next, ?string $section = null): Response
    {
        $resolvedSection = match ($section) {
            'tab' => DashboardSectionAccess::normalizeTab((string) ($request->route('tab') ?? $request->query('tab'))),
            'export' => DashboardSectionAccess::sectionForExportBlock((string) $request->query('block', 'overview')),
            'drilldown' => DashboardSectionAccess::sectionForDrilldown($request),
            default => DashboardSectionAccess::normalizeTab($section),
        };

        abort_unless($request->user()?->canAccessDashboardSection($resolvedSection), 403);

        return $next($request);
    }
}
