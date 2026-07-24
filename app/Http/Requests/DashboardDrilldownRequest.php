<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardDrilldownRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->input('project_id'), ['', 'all'], true)) {
            $this->merge(['project_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'ownership' => ['nullable', Rule::in(['all', 'nwc', 'icare'])],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'equipment_type_id' => ['nullable', 'integer', 'exists:equipment_types,id'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'metric' => ['nullable', Rule::in(['engine_hours', 'mileage'])],
            'top_working_equipment_id' => ['nullable', 'integer', 'exists:equipments,id'],
            'top_working_stat_date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(['name', 'vehicle_type', 'project', 'ownership', 'wialon_id'])],
            'work_category' => ['nullable', Rule::in(['less_than_1', 'from_1_to_7', 'from_7_to_10', 'overtime', 'no_data'])],
            'data_status' => ['nullable', Rule::in(['all', 'available', 'missing'])],
            'geofence_violation' => ['nullable', 'boolean'],
            'current_geozone_project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'current_geozone_id' => ['nullable', 'integer', 'exists:geofences,id'],
            'current_geozone_key' => ['nullable', 'string', 'max:255'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }
}
