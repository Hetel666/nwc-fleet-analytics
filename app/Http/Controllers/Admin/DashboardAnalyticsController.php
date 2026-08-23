<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class DashboardAnalyticsController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard-analytics.index', [
            'groups' => config('dashboard_analytics.groups', []),
        ]);
    }
}
