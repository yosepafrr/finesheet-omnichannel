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

        if (!$this->verifySignature($request->url(), $rawBody, $signatureHeader)) {
            Log::warning('TikTok Webhook Signature Verification Failed!');
            // return response()->json(['error' => 'Invalid signature'], 401);
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

        // Example routing based on Tiktok event type
        // '1' could mean Order Status Update, '2' could mean Product Update, etc.
        // Needs adjustment according to real TikTok OpenAPI specs.
        if ($type == 1) { // Order Event (Placeholder)
            $orderId = $data['order_id'] ?? null;
            if ($orderId) {
                Log::info("Dispatching HandleTiktokOrderWebhookJob for Order: {$orderId}");
                HandleTiktokOrderWebhookJob::dispatch($shopId, $orderId)->onQueue('orders');
            }
        } elseif ($type == 2) { // Product Event (Placeholder)
            $productId = $data['product_id'] ?? null;
            if ($productId) {
                Log::info("Dispatching HandleTiktokProductWebhookJob for Item: {$productId}");
                HandleTiktokProductWebhookJob::dispatch($shopId, $productId)->onQueue('products');
            }
        }

        return response()->json(['code' => 0, 'message' => 'success']);
    }

    private function verifySignature($url, $rawBody, $signatureHeader)
    {
        if (!$signatureHeader) return false;

        $appSecret = config('services.tiktok.app_secret');
        if (!$appSecret) return true; // Bypass if not configured yet

        // Tiktok Signature verification logic (example)
        // signature = HMAC_SHA256(app_secret, url + body)
        $calculatedSign = hash_hmac('sha256', $url . $rawBody, $appSecret);

        return hash_equals($calculatedSign, $signatureHeader);
    }
}
