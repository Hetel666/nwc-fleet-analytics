<?php

namespace App\Http\Requests;

use App\Models\HistoricalRecalculation;
use App\Services\HistoricalRecalculationModuleRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHistoricalRecalculationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'dashboard_section' => $this->input('dashboard_section', HistoricalRecalculation::SECTION_DAILY_AVERAGES),
        ]);
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage-historical-recalculations');
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'timezone' => ['required', 'timezone'],
            'dashboard_section' => ['required', Rule::in(array_keys(
                app(HistoricalRecalculationModuleRegistry::class)->definitions()
            ))],
            'operation' => ['required', Rule::in([
                HistoricalRecalculation::OPERATION_FETCH,
                HistoricalRecalculation::OPERATION_RECALCULATE,
                HistoricalRecalculation::OPERATION_FETCH_AND_RECALCULATE,
            ])],
            'scope' => ['required', Rule::in([
                HistoricalRecalculation::SCOPE_ALL_PROJECTS,
                HistoricalRecalculation::SCOPE_SELECTED_PROJECTS,
            ])],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['integer', 'exists:projects,id'],
            'force' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $timezone = $this->input('timezone', config('historical_recalculation.timezone'));

            if ($this->input('scope') === HistoricalRecalculation::SCOPE_SELECTED_PROJECTS
                && count((array) $this->input('project_ids', [])) === 0) {
                $validator->errors()->add('project_ids', 'Seçilmiş layihələr üçün ən azı bir layihə seçilməlidir.');
            }

            if ($this->input('dashboard_section') === HistoricalRecalculation::SECTION_MONTHLY_EFFICIENCY
                && $this->input('scope') === HistoricalRecalculation::SCOPE_SELECTED_PROJECTS) {
                $validator->errors()->add('scope', 'Aylıq effektivlik yalnız Bütün layihələr rejimində yenilənə bilər.');
            }

            if (! $this->filled(['date_from', 'date_to'])) {
                return;
            }

            $from = Carbon::parse($this->input('date_from'), $timezone)->startOfDay();
            $to = Carbon::parse($this->input('date_to'), $timezone)->startOfDay();
            $today = now($timezone)->startOfDay();
            $maxDays = (int) config('historical_recalculation.max_range_days', 365);
            $days = $from->diffInDays($to) + 1;

            if ($this->input('dashboard_section') === HistoricalRecalculation::SECTION_GEOFENCE_VIOLATIONS) {
                $maxDays = min(
                    $maxDays,
                    max(1, (int) config('geofence_violations.max_report_period_days', 31))
                );
            }

            if ($to->gt($today)) {
                $validator->errors()->add('date_to', 'Bitmə tarixi gələcək tarix ola bilməz.');
            }

            if ($days > $maxDays) {
                $validator->errors()->add('date_to', "Seçilmiş interval {$maxDays} gündən çox ola bilməz.");
            }
        });
    }
}
