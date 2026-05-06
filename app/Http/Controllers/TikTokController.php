<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TikTokController extends Controller
{
    public function callback(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $request->all()
        ]);
    }

    public function redirectToTikTok()
    {
        $appKey = env('TIKTOK_APP_KEY');
        $redirectUri = env('TIKTOK_REDIRECT_URI');

        $url = "https://services.tiktokshop.com/open/authorize?app_key={$appKey}&redirect_uri={$redirectUri}";

        return redirect($url);
    }
}
