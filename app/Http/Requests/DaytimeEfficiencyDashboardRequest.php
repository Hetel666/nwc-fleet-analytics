<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DaytimeEfficiencyDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $yesterday = now(config('daytime_efficiency.timezone', 'Asia/Baku'))->subDay()->toDateString();

        $this->merge([
            'date_from' => $this->input('date_from', $yesterday),
            'date_to' => $this->input('date_to', $this->input('date_from', $yesterday)),
            'per_page' => $this->input('per_page', config('daytime_efficiency.page_size', 50)),
        ]);
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'equipment_type_id' => ['nullable', 'integer', 'exists:equipment_types,id'],
            'ownership_type' => ['nullable', Rule::in(['nwc', 'icare'])],
            'category' => ['nullable', Rule::in(array_keys(config('daytime_efficiency.categories', [])))],
            'detail_status' => ['nullable', Rule::in(['normal', 'not_working', 'no_data', 'empty_value', 'parse_error', 'anomaly'])],
            'search' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', Rule::in(['fact_date', 'unit_name_snapshot', 'engine_hours_decimal', 'mileage_adjusted', 'beginning_at', 'end_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:20', 'max:'.config('daytime_efficiency.max_page_size', 200)],
        ];
    }
}
