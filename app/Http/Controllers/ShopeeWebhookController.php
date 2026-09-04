<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Jobs\SyncShopeeOrderJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopeeWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);
        $signatureHeader = $request->header('Authorization');

        Log::info('Shopee Webhook Received', [
            'payload' => $payload,
            'signature' => $signatureHeader,
        ]);

        $code = $payload['code'] ?? null;
        $shopId = $payload['shop_id'] ?? null;
        $data = $payload['data'] ?? [];

        if (!$this->verifySignature($request->url(), $rawBody, $signatureHeader)) {
            Log::warning('Shopee Webhook Signature Verification Failed!', [
                'url' => $request->url(),
            ]);
            
            // Di production wajib menolak request invalid
            if (app()->environment('production')) {
                return response()->json(['error' => 'Invalid signature'], 401); 
            }
            
            // BYPASS UNTUK TEST PUSH DARI CONSOLE
            if ($code === 3 && isset($data['ordersn'])) {
                Log::info('Bypassing signature check for Local Test Push - Firing Dummy Event');
                
                $dummyOrder = new \App\Models\Order();
                $dummyOrder->id = rand(1000, 9999);
                $dummyOrder->order_sn = '(TEST) ' . $data['ordersn'];
                $dummyOrder->platform = 'Shopee';
                $dummyOrder->store_id = $shopId;
                
                broadcast(new \App\Events\OrderCreated($dummyOrder));
                return response()->json(['code' => 0, 'message' => 'success']);
            }

            // Di lokal/sandbox, kembalikan 200 agar Shopee berhenti me-retry, namun STOP proses sinkronisasi
            return response()->json(['message' => 'Invalid signature ignored in local'], 200);
        } else {
            Log::info('Shopee Webhook Signature Verified!');
        }

        // Handle Test Push Verification from Shopee Console
        if ($code === 0) {
            return response()->json(['code' => 0, 'message' => 'success']);
        }

        if (!$shopId || !$code) {
            return response()->json(['message' => 'Invalid payload format'], 400);
        }

        $orderPushCodes = [3, 4, 15, 23, 24, 25, 30]; // Order related pushes
        $productPushCodes = [6, 8, 16, 17, 22, 27]; // Product related pushes

        if (in_array($code, $orderPushCodes)) {
            $orderSn = $data['ordersn'] ?? null;
            if ($orderSn) {
                Log::info("Dispatching HandleShopeeOrderWebhookJob for Order: {$orderSn} (Code: {$code})");
                \App\Jobs\HandleShopeeOrderWebhookJob::dispatch($shopId, $orderSn)->onQueue('orders');
            }
        } elseif (in_array($code, $productPushCodes)) {
            $itemId = $data['item_id'] ?? null;
            if ($itemId) {
                Log::info("Dispatching HandleShopeeProductWebhookJob for Item: {$itemId} (Code: {$code})");
                \App\Jobs\HandleShopeeProductWebhookJob::dispatch($shopId, $itemId)->onQueue('products');
            }
        }

        return response()->json(['code' => 0, 'message' => 'success']);
    }

    private function verifySignature($url, $rawBody, $signatureHeader)
    {
        if (!$signatureHeader) return false;

        // Jika menggunakan ngrok, $url mungkin terdeteksi sebagai http:// padahal di Shopee diset https://
        // Ini akan membuat baseString berbeda dan validasi gagal. Kita force ke https jika itu ngrok.
        if (strpos($url, 'http://') === 0 && strpos($url, 'ngrok') !== false) {
            $url = str_replace('http://', 'https://', $url);
        }

        $partnerKey = config('services.shopee.partner_key');
        
        // Shopee Docs: HMAC-SHA256(webhook_url + "|" + request_body, partner_key)
        $baseString = $url . '|' . $rawBody;
        $calculatedSign = hash_hmac('sha256', $baseString, $partnerKey);

        if (!hash_equals($calculatedSign, $signatureHeader)) {
            Log::debug('Signature Debug Info', [
                'url_used' => $url,
                'calculated_signature' => $calculatedSign,
                'received_signature' => $signatureHeader,
                'partner_key_length' => strlen($partnerKey)
            ]);
            return false;
        }
        
        return true;
    }
}
