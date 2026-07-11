<?php

namespace App\Http\Controllers;

use App\Models\EquipmentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EquipmentTypeController extends Controller
{
    public function index(): View
    {
        return view('equipment_types.index', [
            'types' => EquipmentType::query()->withCount('equipment')->orderBy('name')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('equipment_types.form', ['type' => new EquipmentType()]);
    }

    public function store(Request $request): RedirectResponse
    {
        EquipmentType::create($this->validated($request));

        return redirect()->route('equipment-types.index')->with('status', __('app.saved'));
    }

    public function edit(EquipmentType $equipmentType): View
    {
        return view('equipment_types.form', ['type' => $equipmentType]);
    }

    public function update(Request $request, EquipmentType $equipmentType): RedirectResponse
    {
        $equipmentType->update($this->validated($request, $equipmentType));

        return redirect()->route('equipment-types.index')->with('status', __('app.saved'));
    }

    public function destroy(EquipmentType $equipmentType): RedirectResponse
    {
        $equipmentType->delete();

        return redirect()->route('equipment-types.index')->with('status', __('app.deleted'));
    }

    private function validated(Request $request, ?EquipmentType $type = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('equipment_types', 'name')->ignore($type)],
        ]);
    }
}
