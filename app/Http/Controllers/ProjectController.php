<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        return view('projects.index', [
            'projects' => Project::query()->withCount(['equipment', 'geofences'])->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('projects.form', ['project' => new Project()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Project::create($this->validated($request));

        return redirect()->route('projects.index')->with('status', __('app.saved'));
    }

    public function edit(Project $project): View
    {
        return view('projects.form', compact('project'));
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $project->update($this->validated($request));

        return redirect()->route('projects.index')->with('status', __('app.saved'));
    }

    public function destroy(Project $project): RedirectResponse
    {
        $project->delete();

        return redirect()->route('projects.index')->with('status', __('app.deleted'));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['active'] = $request->boolean('active');

        return $data;
    }
}
