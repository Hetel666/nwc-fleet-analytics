<?php

namespace App\Http\Requests;

use App\Support\DurationFormatter;
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
        if (! $this->filled('work_category') && $this->filled('status')) {
            $this->merge(['work_category' => $this->input('status')]);
        }

        if (in_array($this->input('project_id'), ['', 'all'], true)) {
            $this->merge(['project_id' => null]);
        }

        foreach (['project_ids', 'vehicle_types'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $this->merge([
                    $key => collect(explode(',', $value))
                        ->map(fn (string $item): string => trim($item))
                        ->filter()
                        ->values()
                        ->all(),
                ]);
            }
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
            'ownership_scope' => ['nullable', Rule::in(['operational', 'project_groups'])],
            'view' => ['nullable', Rule::in(['units', 'equipment_types', 'projects'])],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
            'equipment_type_id' => ['nullable', 'integer', 'exists:equipment_types,id'],
            'vehicle_type' => ['nullable', 'string', 'max:100'],
            'vehicle_types' => ['nullable', 'array'],
            'vehicle_types.*' => ['string', 'max:100'],
            'metric' => ['nullable', Rule::in(['engine_hours', 'mileage'])],
            'group_by' => ['nullable', Rule::in(['details', 'day', 'unit'])],
            'search' => ['nullable', 'string', 'max:120'],
            'unit_name' => ['nullable', 'string', 'max:120'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'wialon_id' => ['nullable', 'string', 'max:60'],
            'sort' => ['nullable', Rule::in([
                'date',
                'name',
                'registration_number',
                'vehicle_type',
                'project',
                'ownership',
                'daytime_hours',
                'overtime_hours',
                'total_hours',
                'engine_hours',
                'mileage',
                'data_status',
                'wialon_id',
            ])],
            'work_category' => ['nullable', Rule::in([
                '0_1',
                '1_7',
                '7_10',
                'over_10',
                'less_than_1',
                'from_1_to_7',
                'from_7_to_10',
                'less_than_1_hour',
                'less_than_7_hours',
                'between_7_and_10_hours',
                'night_shift_only',
                'over_10_hours',
                'over_10_day_hours',
                'overtime',
                'no_data',
            ])],
            'status' => ['nullable', Rule::in([
                '0_1',
                '1_7',
                '7_10',
                'over_10',
                'less_than_1',
                'from_1_to_7',
                'from_7_to_10',
                'less_than_1_hour',
                'less_than_7_hours',
                'between_7_and_10_hours',
                'night_shift_only',
                'over_10_hours',
                'over_10_day_hours',
                'overtime',
                'no_data',
            ])],
            'day_status' => ['nullable', Rule::in([
                'all',
                '0_1',
                '1_7',
                '7_10',
                'over_10',
                'less_than_1',
                'from_1_to_7',
                'from_7_to_10',
                'less_than_1_hour',
                'less_than_7_hours',
                'between_7_and_10_hours',
                'night_shift_only',
                'over_10_hours',
                'over_10_day_hours',
            ])],
            'data_status' => ['nullable', Rule::in(['all', 'available', 'missing'])],
            'has_overtime' => ['nullable', Rule::in(['all', 'yes', 'no'])],
            'day_hours_min' => ['nullable', 'numeric', 'min:0'],
            'day_hours_max' => ['nullable', 'numeric', 'min:0'],
            'overtime_hours_min' => ['nullable', 'numeric', 'min:0'],
            'overtime_hours_max' => ['nullable', 'numeric', 'min:0'],
            'total_hours_min' => ['nullable', 'numeric', 'min:0'],
            'total_hours_max' => ['nullable', 'numeric', 'min:0'],
            'duration_format' => ['nullable', Rule::in(DurationFormatter::allowed())],
            'geofence_violation' => ['nullable', 'boolean'],
            'live_only' => ['nullable', 'boolean'],
            'current_geozone_project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'current_geozone_id' => ['nullable', 'integer', 'exists:geofences,id'],
            'current_geozone_key' => ['nullable', 'string', 'max:255'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ];
    }
}
