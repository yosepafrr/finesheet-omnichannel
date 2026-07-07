<?php

namespace App\Services;

class DashboardService
{
    public function getStats()
    {
        return [
            'sales' => 1000000,
            'orders' => 200,
        ];
    }
}