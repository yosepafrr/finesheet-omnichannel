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

        $returnPushCodes = [2, 10, 11, 12, 64, 65, 67]; // Return / Reverse / Aftersales / RMA pushes
        $orderPushCodes = [1, 3, 4]; // Forward order lifecycle pushes
        $productPushCodes = [5, 15, 16, 18, 19, 25, 27, 37, 38, 50, 51, 52, 62, 68]; // Product related pushes

        $isReturn = in_array($type, $returnPushCodes)
            || !empty($data['return_id'])
            || !empty($data['reverse_order_id'])
            || !empty($data['reverse_event_type'])
            || !empty($data['return_status']);

        if ($isReturn) {
            $orderId = $data['order_id'] ?? null;
            $returnId = $data['return_id'] ?? $data['reverse_order_id'] ?? null;

            Log::info("TikTok Return Webhook Detected for Order: {$orderId}, Return: {$returnId} (Type: {$type})");

            // Fast-track create/update OrderReturn in DB so it immediately shows up on order list and order detail
            if ($orderId && $returnId) {
                try {
                    $order = \App\Models\Order::where('platform', 'Tiktokshop')
                        ->where('order_sn', $orderId)
                        ->first();

                    if ($order) {
                        $reverseStatus = $data['reverse_order_status'] ?? null;
                        $rawStatus = $data['return_status'] ?? null;

                        if (!$rawStatus) {
                            if ($reverseStatus == 100) {
                                $platformStatus = 'RETURN_OR_REFUND_REQUEST_COMPLETE';
                            } elseif (in_array($reverseStatus, [50, 51])) {
                                $platformStatus = 'REFUND_PROCESSING';
                            } elseif (($data['reverse_event_type'] ?? '') === 'UNSUPPORTED') {
                                $platformStatus = 'RETURN_OR_REFUND_REQUEST_COMPLETE';
                            } else {
                                $platformStatus = $data['reverse_event_type'] ?? 'RETURN_OR_REFUND_REQUEST_PENDING';
                            }
                        } else {
                            $platformStatus = $rawStatus;
                        }

                        $normalizedStatus = \App\Jobs\SyncTiktokReturnJob::normalizeStatus($platformStatus);
                        $returnType = $data['return_type'] ?? ($data['reverse_type'] ?? 'RETURN_AND_REFUND');

                        \App\Models\OrderReturn::updateOrCreate(
                            [
                                'platform' => 'Tiktokshop',
                                'external_return_id' => (string)$returnId,
                            ],
                            [
                                'order_id' => $order->id,
                                'return_status' => $platformStatus,
                                'platform_status' => $platformStatus,
                                'normalized_status' => $normalizedStatus,
                                'return_type' => is_string($returnType) ? $returnType : 'RETURN_AND_REFUND',
                                'created_at_platform' => isset($data['create_time']) 
                                    ? \Carbon\Carbon::createFromTimestamp($data['create_time'])->setTimezone(config('app.timezone')) 
                                    : now(),
                                'updated_at_platform' => isset($data['update_time']) 
                                    ? \Carbon\Carbon::createFromTimestamp($data['update_time'])->setTimezone(config('app.timezone')) 
                                    : now(),
                                'raw_data' => $data,
                            ]
                        );
                        Log::info("Fast-track OrderReturn created/updated for Order: {$orderId}, Return: {$returnId}");
                    }
                } catch (\Throwable $e) {
                    Log::error("Error fast-tracking OrderReturn in webhook: " . $e->getMessage());
                }
            }

            if ($orderId) {
                HandleTiktokOrderWebhookJob::dispatch($shopId, $orderId)->onQueue('orders');
            }

            $store = \App\Models\Store::where('platform', 'Tiktokshop')
                ->where(function($query) use ($shopId) {
                    $query->where('shopee_shop_id', $shopId)
                          ->orWhere('shopee_shop_id', 'LIKE', 'ROW_%');
                })
                ->first();

            if ($store) {
                Log::info("Dispatching SyncTiktokReturnJob for Store {$store->id} (Type: {$type})");
                \App\Jobs\SyncTiktokReturnJob::dispatch($store, \Carbon\Carbon::now()->subDays(7)->timestamp, time())->onQueue('orders');
            }
        } elseif (in_array($type, $orderPushCodes) || !empty($data['order_id'])) {
            $orderId = $data['order_id'] ?? null;
            if ($orderId) {
                Log::info("Dispatching HandleTiktokOrderWebhookJob for Order: {$orderId} (Type: {$type})");
                HandleTiktokOrderWebhookJob::dispatch($shopId, $orderId)->onQueue('orders');
            }
        } elseif (in_array($type, $productPushCodes) || !empty($data['product_id'])) {
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
