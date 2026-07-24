<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDashboardLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'widgets' => ['required', 'array', 'min:1', 'max:50'],
            'widgets.*.key' => ['required', 'string', 'max:120'],
            'widgets.*.order' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'widgets.*.width' => ['nullable', 'integer', 'in:4,5,6,7,12'],
            'widgets.*.title' => ['nullable', 'string', 'max:120'],
            'widgets.*.visible' => ['nullable', 'boolean'],
        ];
    }
}
