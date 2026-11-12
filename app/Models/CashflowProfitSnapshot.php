<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CashflowProfitSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'snapshot_date',
        'transactions',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'transactions' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public static function invalidate(int $userId, CarbonInterface|string|null $date): void
    {
        if (! $date) {
            return;
        }

        $dateString = $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date, config('app.timezone'))->toDateString();

        static::query()
            ->where('user_id', $userId)
            ->whereDate('snapshot_date', $dateString)
            ->delete();

        Cache::forget("cashflow-profit-status:{$userId}:{$dateString}");
        Cache::forget("cashflow-profit-result:{$userId}:{$dateString}");
    }

    public static function invalidateForOrder(?Order $order): void
    {
        if (! $order?->order_time) {
            return;
        }

        $order->loadMissing('store:id,user_id');
        $userId = (int) ($order->store?->user_id ?: 0);
        if ($userId > 0) {
            static::invalidate($userId, $order->order_time);
        }
    }
}
