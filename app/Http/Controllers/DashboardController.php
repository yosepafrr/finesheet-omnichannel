<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\DashboardService;

class DashboardController extends Controller
{
    public function stats(DashboardService $service)
    {
        return response()->json(
            $service->getStats()
        );
    }
}
