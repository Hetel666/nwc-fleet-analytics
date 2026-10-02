<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDashboardEventNotesRequest;
use App\Services\DashboardEventNoteService;
use Illuminate\Http\JsonResponse;

class DashboardEventNoteController extends Controller
{
    public function store(StoreDashboardEventNotesRequest $request, DashboardEventNoteService $notes): JsonResponse
    {
        $notes->saveMany($request->items(), (int) $request->user()->id);

        return response()->json([
            'saved' => count($request->items()),
        ]);
    }
}
