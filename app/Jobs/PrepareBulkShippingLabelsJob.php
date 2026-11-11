<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderFulfillmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PrepareBulkShippingLabelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly int $userId,
        public readonly array $orderIds,
        public readonly string $token,
    ) {
        $this->onQueue('fulfillment');
    }

    public function handle(OrderFulfillmentService $fulfillment): void
    {
        $this->updateStatus('processing', 2, 'Memeriksa pesanan yang dipilih.');

        try {
            $orders = Order::with(['store', 'packages', 'orderProducts'])
                ->whereHas('store', fn ($query) => $query->where('user_id', $this->userId))
                ->whereIn('id', $this->orderIds)
                ->get()
                ->sortBy(fn (Order $order) => array_search($order->id, $this->orderIds, true))
                ->values();

            if ($orders->count() !== count(array_unique($this->orderIds))) {
                throw new RuntimeException('Sebagian pesanan tidak lagi tersedia untuk dicetak.');
            }

            $result = $fulfillment->labels(
                $orders,
                fn (int $progress, string $message) => $this->updateStatus(
                    'processing',
                    $progress,
                    $message,
                ),
            );

            if (($result['type'] ?? null) !== 'binary') {
                throw new RuntimeException('Dokumen resi massal tidak tersedia untuk pilihan pesanan ini.');
            }

            $path = "shipping-label-jobs/{$this->userId}/{$this->token}.pdf";
            if (! Storage::disk('local')->put($path, $result['body'])) {
                throw new RuntimeException('Dokumen resi selesai dibuat tetapi gagal disimpan.');
            }

            $this->updateStatus('ready', 100, 'Dokumen resi siap dibuka.', [
                'path' => $path,
                'filename' => $result['filename'] ?? "resi-{$orders->count()}-pesanan.pdf",
            ]);
        } catch (Throwable $exception) {
            if (! $exception instanceof RuntimeException) {
                report($exception);
            }

            Log::warning('Bulk shipping label job failed', [
                'user_id' => $this->userId,
                'order_ids' => $this->orderIds,
                'token' => $this->token,
                'message' => $exception->getMessage(),
            ]);

            $this->updateStatus(
                'failed',
                100,
                $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Dokumen resi massal belum dapat disiapkan. Coba kembali beberapa saat lagi.',
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->updateStatus(
            'failed',
            100,
            $exception?->getMessage() ?: 'Proses menyiapkan resi berhenti sebelum selesai.',
        );
    }

    public static function statusKey(string $token): string
    {
        return "shipping-label-job:{$token}";
    }

    private function updateStatus(
        string $status,
        int $progress,
        string $message,
        array $extra = [],
    ): void {
        Cache::put(self::statusKey($this->token), array_merge([
            'user_id' => $this->userId,
            'status' => $status,
            'progress' => max(0, min(100, $progress)),
            'message' => $message,
            'updated_at' => now()->toIso8601String(),
        ], $extra), now()->addHour());
    }
}
