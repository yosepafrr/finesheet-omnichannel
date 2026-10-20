<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function stats(Request $request, DashboardService $service)
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|in:7,14,30',
        ]);

        return response()->json(
            $service->getStats((int) ($validated['days'] ?? 7))
        );
    }
}
