<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;

class EnsureViewerAccess
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (! $user || $user->isAdmin()) {
            return $next($request);
        }
        abort_unless($request->isMethodSafe() || $request->routeIs('logout'), 403);
        if ($user->hasRestrictedProjects()) {
            abort_if($request->is('admin/*', 'api/wialon-catalog/*'), 403);
            $project = $request->route('project');
            $id = $project instanceof Project ? $project->id : $request->query('project_id');
            if ($id !== null && $id !== '' && $id !== 'all') {
                abort_unless(is_scalar($id) && $user->canAccessProject((int) $id), 403);
            } else {
                $id = ($user->project_ids ?? [])[0] ?? null;
            }
            if ($request->is('dashboard*', 'api/dashboard/*', 'geofence-violations*', 'projects/*/dashboard')) {
                abort_if($id === null, 403);
                $request->query->set('project_id', (int) $id);
                if ($request->query->has('project_ids')) {
                    $request->query->set('project_ids', [(int) $id]);
                }
            }
        }

        return $next($request);
    }
}
