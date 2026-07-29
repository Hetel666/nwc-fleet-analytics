<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GeofenceViolationsDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'equipment_type' => [
                'nullable',
                'string',
                Rule::in(config('geofence_violations.allowed_equipment_types', [])),
            ],
            'ownership_type' => ['nullable', 'string', Rule::in(['NWC', 'ICARE'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'completed'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        $timezone = config('app.timezone', 'Asia/Baku');
        $defaultTo = now($timezone)->toDateString();
        $defaultFrom = now($timezone)
            ->subDays(max(1, (int) config('geofence_violations.default_period_days', 7)) - 1)
            ->toDateString();
        $validated = $this->validated();

        return [
            'date_from' => $validated['date_from'] ?? $defaultFrom,
            'date_to' => $validated['date_to'] ?? $defaultTo,
            'project_id' => isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            'equipment_type' => $validated['equipment_type'] ?? null,
            'ownership_type' => $validated['ownership_type'] ?? null,
            'status' => $validated['status'] ?? null,
            'search' => trim((string) ($validated['search'] ?? '')),
        ];
    }
}
