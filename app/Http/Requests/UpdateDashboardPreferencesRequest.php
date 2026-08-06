<?php

namespace App\Http\Requests;

use App\Models\UserDashboardPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDashboardPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'layout' => ['sometimes', 'required', Rule::in(UserDashboardPreference::LAYOUTS)],
            'theme' => ['sometimes', 'required', Rule::in(UserDashboardPreference::THEMES)],
            'density' => ['sometimes', 'required', Rule::in(UserDashboardPreference::DENSITIES)],
            'sidebar_state' => ['sometimes', 'required', Rule::in(UserDashboardPreference::SIDEBAR_STATES)],
            'donut_legend_position' => ['sometimes', 'required', Rule::in(UserDashboardPreference::LEGEND_POSITIONS)],
            'table_density' => ['sometimes', 'required', Rule::in(UserDashboardPreference::DENSITIES)],
            'kpi_size' => ['sometimes', 'required', Rule::in(UserDashboardPreference::KPI_SIZES)],
            'hidden_widgets' => ['sometimes', 'array', 'max:80'],
            'hidden_widgets.*' => ['string', Rule::in(UserDashboardPreference::DASHBOARD_WIDGET_KEYS)],
        ];
    }
}
