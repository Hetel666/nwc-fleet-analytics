<?php

namespace App\Http\Controllers;

use App\Models\Geofence;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GeofenceController extends Controller
{
    public function index(): View
    {
        return view('geofences.index', [
            'geofences' => Geofence::query()->with('project')->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('geofences.form', $this->formData(new Geofence()));
    }

    public function store(Request $request): RedirectResponse
    {
        Geofence::create($this->validated($request));

        return redirect()->route('geofences.index')->with('status', __('app.saved'));
    }

    public function edit(Geofence $geofence): View
    {
        return view('geofences.form', $this->formData($geofence));
    }

    public function update(Request $request, Geofence $geofence): RedirectResponse
    {
        $geofence->update($this->validated($request));

        return redirect()->route('geofences.index')->with('status', __('app.saved'));
    }

    public function destroy(Geofence $geofence): RedirectResponse
    {
        $geofence->delete();

        return redirect()->route('geofences.index')->with('status', __('app.deleted'));
    }

    private function formData(Geofence $geofence): array
    {
        return [
            'geofence' => $geofence,
            'projects' => Project::query()->orderBy('name')->get(),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'project_id' => ['required', 'exists:projects,id'],
            'wialon_geofence_id' => ['nullable', 'string', 'max:100'],
            'geometry_json' => ['nullable', 'json'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['geometry_json'] = $data['geometry_json'] ? json_decode($data['geometry_json'], true, 512, JSON_THROW_ON_ERROR) : null;
        $data['active'] = $request->boolean('active');

        return $data;
    }
}
