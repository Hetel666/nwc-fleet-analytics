<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        try {
            DB::select('select 1');

            return response()->json([
                'status' => 'ok',
                'app' => 'Fleet Analytics',
                'database' => 'ok',
            ]);
        } catch (Throwable) {
            return response()->json([
                'status' => 'error',
                'app' => 'Fleet Analytics',
                'database' => 'unavailable',
            ], 503);
        }
    }
}
