<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EquipmentController extends Controller
{
    public function index(Request $request): View
    {
        return view('equipment.index', [
            'equipment' => Equipment::query()
                ->with(['type', 'project'])
                ->when($request->filled('project_id'), fn ($query) => $query->where('project_id', $request->integer('project_id')))
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'projects' => Project::query()->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('equipment.form', $this->formData(new Equipment()));
    }

    public function store(Request $request): RedirectResponse
    {
        Equipment::create($this->validated($request));

        return redirect()->route('equipment.index')->with('status', __('app.saved'));
    }

    public function edit(Equipment $equipment): View
    {
        return view('equipment.form', $this->formData($equipment));
    }

    public function update(Request $request, Equipment $equipment): RedirectResponse
    {
        $equipment->update($this->validated($request, $equipment));

        return redirect()->route('equipment.index')->with('status', __('app.saved'));
    }

    public function destroy(Equipment $equipment): RedirectResponse
    {
        $equipment->delete();

        return redirect()->route('equipment.index')->with('status', __('app.deleted'));
    }

    private function formData(Equipment $equipment): array
    {
        return [
            'equipment' => $equipment,
            'types' => EquipmentType::query()->orderBy('name')->get(),
            'projects' => Project::query()->orderBy('name')->get(),
            'ownershipTypes' => [Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE],
            'calculationModes' => [
                Equipment::MODE_ENGINE_HOURS,
                Equipment::MODE_IGNITION,
                Equipment::MODE_MILEAGE,
            ],
        ];
    }

    private function validated(Request $request, ?Equipment $equipment = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'wialon_unit_id' => ['required', 'string', 'max:100', Rule::unique('equipments', 'wialon_unit_id')->ignore($equipment)],
            'equipment_type_id' => ['required', 'exists:equipment_types,id'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'ownership_type' => ['required', Rule::in([Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])],
            'calculation_mode' => ['required', Rule::in([Equipment::MODE_ENGINE_HOURS, Equipment::MODE_IGNITION, Equipment::MODE_MILEAGE])],
            'planned_daily_hours' => ['required', 'numeric', 'min:1', 'max:24'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['active'] = $request->boolean('active');

        return $data;
    }
}
