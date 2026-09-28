<?php

namespace App\Services;

use Carbon\Carbon;

class LogisticsStatusNormalizer
{
    private const FINAL_FAILED_DELIVERY_ACTION_CODES = [
        70201,
        70202,
        70203,
        70204,
    ];

    private const POTENTIALLY_FAILED_DELIVERY_ACTION_CODES = [
        40601,
    ];

    private const FAILED_DELIVERY_PHRASES = [
        'delivery failed',
        'delivery fail',
        'delivery failure',
        'delivery exception',
        'delivery unsuccessful',
        'failed delivery',
        'failed to deliver',
        'could not be delivered',
        'unable to deliver',
        'delivery attempt failed',
        'returned to seller',
        'returned to sender',
        'return to seller',
        'return to sender',
        'returning to seller',
        'returning to sender',
        'returned to the seller',
        'returned to the sender',
        'returning to the seller',
        'returning to the sender',
        'being returned to seller',
        'being returned to sender',
        'being returned to the seller',
        'being returned to the sender',
        'package return',
        'return package',
        'return in progress',
        'return started',
        'recipient rejected',
        'dikembalikan',
        'paket gagal',
        'pengiriman gagal',
        'pengantaran gagal',
        'gagal dikirim',
        'gagal antar',
        'gagal diantar',
        'tidak berhasil dikirim',
        'tidak dapat dikirim',
    ];

    private const RETRYABLE_DELIVERY_PHRASES = [
        'will try to deliver it again',
        'will try to deliver again',
        'will attempt delivery again',
        'delivery will be attempted again',
        'another delivery attempt will be made',
        'akan mencoba mengantar kembali',
        'akan mencoba mengirim kembali',
        'akan dicoba antar kembali',
        'akan dicoba dikirim kembali',
        'pengantaran akan dicoba kembali',
        'pengiriman akan dicoba kembali',
    ];

    private const DELIVERED_PHRASES = [
        'delivered',
        'delivery completed',
        'delivery done',
        'paket telah diterima',
        'paket sudah diterima',
    ];

    public function normalize(array $payload, ?string $currentStatus = null): ?string
    {
        $events = $this->timelineEvents($payload);

        if ($events !== []) {
            $latest = $events[0];
            $eventStatus = $this->eventStatus($latest);

            if ($eventStatus !== null) {
                return $eventStatus;
            }
        }

        $text = $this->flattenText($payload);

        if ($this->containsPhrase($text, self::RETRYABLE_DELIVERY_PHRASES)) {
            return 'IN_TRANSIT';
        }

        if ($this->containsFinalFailedActionCode($payload)
            || $this->containsPhrase($text, self::FAILED_DELIVERY_PHRASES)) {
            return 'DELIVERY_FAILED';
        }

        if ($this->containsPhrase($text, self::DELIVERED_PHRASES)) {
            return 'DELIVERED';
        }

        return $text !== '' ? 'IN_TRANSIT' : $currentStatus;
    }

    public function isFailedDelivery(array|string|null $value): bool
    {
        if (is_array($value)) {
            return $this->normalize($value) === 'DELIVERY_FAILED';
        }

        $text = $this->normalizeText((string) $value);

        if ($this->containsPhrase($text, self::RETRYABLE_DELIVERY_PHRASES)) {
            return false;
        }

        return $this->containsPhrase($text, self::FAILED_DELIVERY_PHRASES);
    }

    private function containsFinalFailedActionCode(array $value): bool
    {
        if (isset($value['action_code'])
            && in_array((int) $value['action_code'], self::FINAL_FAILED_DELIVERY_ACTION_CODES, true)) {
            return true;
        }

        foreach ($value as $child) {
            if (is_array($child) && $this->containsFinalFailedActionCode($child)) {
                return true;
            }
        }

        return false;
    }

    public function latestDescription(array $payload): string
    {
        $events = [];
        $this->collectEvents($payload, $events);

        if ($events === []) {
            return '';
        }

        usort($events, fn (array $left, array $right) => $right['time'] <=> $left['time']);

        return $events[0]['description'];
    }

    public function trackingNumber(array $payload): ?string
    {
        foreach ($payload['logistics_details'] ?? [] as $detail) {
            if (! empty($detail['newest_tracking_no'])) {
                return (string) $detail['newest_tracking_no'];
            }
        }

        return $this->findFirstValue($payload, [
            'newest_tracking_no',
            'tracking_number',
            'tracking_no',
            'shipping_tracking_number',
        ]);
    }

    public function failedDeliveryOccurredAt(array $payload): ?Carbon
    {
        if ($this->normalize($payload) !== 'DELIVERY_FAILED') {
            return null;
        }

        $events = [];
        $this->collectEvents($payload, $events);

        $timestamp = collect($events)
            ->filter(function (array $event) {
                return $event['time'] > 0
                    && $this->eventStatus($event) === 'DELIVERY_FAILED';
            })
            ->min('time');

        if (! $timestamp) {
            return null;
        }

        return $this->dateFromTimestamp((int) $timestamp);
    }

    /**
     * @return array<int, array{
     *     description: string,
     *     action_code: int|string|null,
     *     occurred_at: string|null,
     *     timestamp: int,
     *     is_failed_delivery: bool
     * }>
     */
    public function history(array $payload): array
    {
        $events = [];
        $this->collectEvents($payload, $events);

        usort($events, fn (array $left, array $right) => $right['time'] <=> $left['time']);

        $seen = [];
        $history = [];

        foreach ($events as $event) {
            $description = trim((string) $event['description']);
            $key = strtolower($description).'|'.$event['time'].'|'.($event['action_code'] ?? '');

            if ($description === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $occurredAt = $event['time'] > 0
                ? $this->dateFromTimestamp((int) $event['time'])
                : null;

            $history[] = [
                'description' => $description,
                'action_code' => $event['action_code'],
                'occurred_at' => $occurredAt?->toIso8601String(),
                'timestamp' => (int) $event['time'],
                'is_failed_delivery' => $this->eventStatus($event) === 'DELIVERY_FAILED',
            ];
        }

        return $history;
    }

    private function timelineEvents(array $payload): array
    {
        $events = [];
        $this->collectEvents($payload, $events);

        usort($events, fn (array $left, array $right) => $right['time'] <=> $left['time']);

        return $events;
    }

    private function eventStatus(array $event): ?string
    {
        $description = $this->normalizeText((string) ($event['description'] ?? ''));
        $actionCode = $event['action_code'] !== null ? (int) $event['action_code'] : null;

        if ($this->containsPhrase($description, self::RETRYABLE_DELIVERY_PHRASES)) {
            return 'IN_TRANSIT';
        }

        if (($actionCode !== null && in_array($actionCode, self::FINAL_FAILED_DELIVERY_ACTION_CODES, true))
            || $this->containsPhrase($description, self::FAILED_DELIVERY_PHRASES)
            || ($actionCode !== null
                && in_array($actionCode, self::POTENTIALLY_FAILED_DELIVERY_ACTION_CODES, true))) {
            return 'DELIVERY_FAILED';
        }

        if ($this->containsPhrase($description, self::DELIVERED_PHRASES)) {
            return 'DELIVERED';
        }

        return $description !== '' ? 'IN_TRANSIT' : null;
    }

    private function dateFromTimestamp(int $timestamp): Carbon
    {
        $date = $timestamp > 100_000_000_000
            ? Carbon::createFromTimestampMs($timestamp, 'UTC')
            : Carbon::createFromTimestamp($timestamp, 'UTC');

        try {
            $timezone = config('app.timezone', 'UTC');
        } catch (\Throwable) {
            $timezone = 'UTC';
        }

        return $date->setTimezone($timezone);
    }

    private function collectEvents(array $value, array &$events): void
    {
        $description = $value['description']
            ?? $value['event']
            ?? $value['logistics_status']
            ?? $value['sub_status']
            ?? $value['status']
            ?? $value['message']
            ?? null;

        if (is_scalar($description) && trim((string) $description) !== '') {
            $events[] = [
                'description' => trim((string) $description),
                'time' => $this->eventTimestamp($value['update_time_millis']
                    ?? $value['update_time']
                    ?? $value['event_time']
                    ?? $value['create_time']
                    ?? null),
                'action_code' => $value['action_code'] ?? null,
            ];
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectEvents($child, $events);
            }
        }
    }

    private function eventTimestamp(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return 0;
        }

        try {
            return Carbon::parse($value, 'UTC')->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function flattenText(array $value): string
    {
        $parts = [];
        array_walk_recursive($value, function ($item) use (&$parts) {
            if (is_scalar($item)) {
                $parts[] = (string) $item;
            }
        });

        return $this->normalizeText(implode(' ', $parts));
    }

    private function findFirstValue(array $value, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($value[$key]) && is_scalar($value[$key]) && (string) $value[$key] !== '') {
                return (string) $value[$key];
            }
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $result = $this->findFirstValue($child, $keys);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    private function normalizeText(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[_\-]+/', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function containsPhrase(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
