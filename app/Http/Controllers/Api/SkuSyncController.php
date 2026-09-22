<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MasterSkuSyncService;
use Illuminate\Support\Facades\Auth;

class SkuSyncController extends Controller
{
    public function detect(MasterSkuSyncService $skuSync)
    {
        $user = Auth::user();

        return response()->json($skuSync->detectUnlinkedSkus($user->id));
    }
}
