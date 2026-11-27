<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class OrderListSortService
{
    public const OPTIONS = [
        'ready_oldest' => ['ready_to_ship_at', 'asc'],
        'ready_newest' => ['ready_to_ship_at', 'desc'],
        'deadline_oldest' => ['ship_by_date', 'asc'],
        'deadline_newest' => ['ship_by_date', 'desc'],
        'created_newest' => ['order_time', 'desc'],
    ];

    public function apply(Builder $query, string $sort): void
    {
        [$column, $direction] = self::OPTIONS[$sort];
        $query->orderByRaw("CASE WHEN {$column} IS NULL THEN 1 ELSE 0 END")
            ->orderBy($column, $direction);
        if ($column !== 'order_time') {
            $query->orderBy('order_time', $direction);
        }
        $query->orderBy('id', $direction);
    }

    public function dates(array $raw, string $platform): array
    {
        $isShopee = strtolower($platform) === 'shopee';
        $ready = null;
        foreach ($isShopee ? ['pay_time'] : ['rts_time', 'paid_time'] as $key) {
            $ready = $this->timestamp($raw[$key] ?? null);
            if ($ready !== null) break;
        }
        $deadline = $isShopee ? ($raw['ship_by_date'] ?? null) : ($raw['rts_sla_time'] ?? null);

        return array_filter([
            'ready_to_ship_at' => $ready,
            'ship_by_date' => $this->timestamp($deadline),
        ], fn ($value) => $value !== null);
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_numeric($value) || (float) $value <= 0 || (float) $value > 253402300799) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $value)->setTimezone(config('app.timezone'))->toDateTimeString();
    }
}
