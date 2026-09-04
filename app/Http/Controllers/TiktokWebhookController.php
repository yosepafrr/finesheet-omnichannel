<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Jobs\HandleTiktokOrderWebhookJob;
use App\Jobs\HandleTiktokProductWebhookJob;

class TiktokWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        // TikTok usually sends signatures in Headers (e.g. x-tts-webhook-signature)
        // Adjust header name based on official TikTok API docs.
        $signatureHeader = $request->header('x-tts-webhook-signature') ?? $request->header('Authorization');

        Log::info('TikTok Webhook Received', [
            'payload' => $payload,
            'signature' => $signatureHeader,
        ]);

        if (!$this->verifySignature($rawBody, $signatureHeader)) {
            Log::warning('TikTok Webhook Signature Verification Failed!', [
                'url' => $request->url(),
            ]);
            
            if (app()->environment('production')) {
                return response()->json(['error' => 'Invalid signature'], 401);
            }
            return response()->json(['message' => 'Invalid signature ignored in local'], 200);
        } else {
            Log::info('TikTok Webhook Signature Verified!');
        }

        // Parse payload (Tiktok webhook payload structure is different from Shopee)
        $type = $payload['type'] ?? null; 
        $shopId = $payload['shop_id'] ?? null;
        $data = $payload['data'] ?? [];

        if (!$shopId || !$type) {
            return response()->json(['message' => 'Invalid payload format'], 400);
        }

        $orderPushCodes = [1, 2, 3, 4, 11, 12, 64, 67]; // Order related pushes
        $productPushCodes = [5, 15, 16, 18, 19, 25, 27, 37, 38, 50, 51, 52, 62, 68]; // Product related pushes

        if (in_array($type, $orderPushCodes)) {
            $orderId = $data['order_id'] ?? null;
            if ($orderId) {
                Log::info("Dispatching HandleTiktokOrderWebhookJob for Order: {$orderId} (Type: {$type})");
                HandleTiktokOrderWebhookJob::dispatch($shopId, $orderId)->onQueue('orders');
            }
        } elseif (in_array($type, $productPushCodes)) {
            $productId = $data['product_id'] ?? null;
            if ($productId) {
                Log::info("Dispatching HandleTiktokProductWebhookJob for Item: {$productId} (Type: {$type})");
                HandleTiktokProductWebhookJob::dispatch($shopId, $productId)->onQueue('products');
            }
        }

        return response()->json(['code' => 0, 'message' => 'success']);
    }

    private function verifySignature($rawBody, $signatureHeader)
    {
        if (!$signatureHeader) return false;

        $appKey = config('services.tiktok.app_key');
        $appSecret = config('services.tiktok.app_secret');
        if (!$appSecret || !$appKey) return false; // Fail if not configured

        // TikTok Shop OpenAPI v2.0 Signature Rule:
        // sign = HMAC_SHA256(app_key + raw_body, app_secret)
        $calculatedSign = hash_hmac('sha256', $appKey . $rawBody, $appSecret);

        if (!hash_equals($calculatedSign, $signatureHeader)) {
            Log::debug('TikTok Signature Debug', [
                'calculated' => $calculatedSign,
                'received' => $signatureHeader,
            ]);
            return false;
        }
        
        return true;
    }
}
