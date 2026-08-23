<?php

namespace App\Http\Requests;

use App\Models\DashboardDataImport;
use App\Models\Equipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDashboardDataImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'module' => ['required', Rule::in(array_keys(DashboardDataImport::modules()))],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('active', true)],
            'ownership_type' => ['nullable', Rule::in([Equipment::OWNERSHIP_NWC, Equipment::OWNERSHIP_ICARE])],
            'files' => ['required', 'array', 'min:1', 'max:'.config('dashboard_manual_import.max_files', 100)],
            'files.*' => ['required', 'file', 'extensions:xlsx', 'max:'.config('dashboard_manual_import.max_file_kilobytes', 51200)],
        ];
    }

    public function messages(): array
    {
        return [
            'files.required' => 'Ən azı bir Wialon XLSX faylı seçilməlidir.',
            'files.*.extensions' => 'Yalnız Wialon-dan çıxarılmış XLSX faylı qəbul edilir.',
            'date_to.after_or_equal' => 'Bitmə tarixi başlanğıc tarixindən əvvəl ola bilməz.',
        ];
    }
}
