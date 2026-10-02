<?php

namespace App\Http\Requests;

use App\Models\DashboardEventNote;
use App\Services\DashboardEventNoteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDashboardEventNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'max:200'],
            'items.*.event_key' => ['required', 'string', 'size:40'],
            'items.*.dashboard_key' => ['required', Rule::in([
                DashboardEventNoteService::DASHBOARD_GENERAL_EFFICIENCY,
                DashboardEventNoteService::DASHBOARD_GEOFENCE_TRANSFERS,
                DashboardEventNoteService::DASHBOARD_GEOFENCE_VIOLATIONS,
            ])],
            'items.*.event_type' => ['required', 'string', 'max:80'],
            'items.*.event_date' => ['nullable', 'date'],
            'items.*.project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'items.*.equipment_id' => ['nullable', 'integer', 'exists:equipments,id'],
            'items.*.wialon_unit_id' => ['nullable', 'string', 'max:80'],
            'items.*.unit_name' => ['nullable', 'string', 'max:255'],
            'items.*.event_status' => ['nullable', 'string', 'max:80'],
            'items.*.note' => ['nullable', 'string', 'max:5000'],
            'items.*.investigation_status' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                $dashboardAttribute = str_replace('.investigation_status', '.dashboard_key', $attribute);

                if (! array_key_exists($value, DashboardEventNote::statusLabelsForDashboard((string) $this->input($dashboardAttribute)))) {
                    $fail('Seçilmiş araşdırma statusu yanlışdır.');
                }
            }],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        return $this->validated('items');
    }
}
