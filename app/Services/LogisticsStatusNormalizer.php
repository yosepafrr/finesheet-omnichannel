<?php

namespace App\Services;

class LogisticsStatusNormalizer
{
    private const FAILED_DELIVERY_PHRASES = [
        'delivery failed',
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
        'return in progress',
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

    public function normalize(array $payload, ?string $currentStatus = null): ?string
    {
        if ($currentStatus === 'DELIVERY_FAILED' || $this->isFailedDelivery($payload)) {
            return 'DELIVERY_FAILED';
        }

        $text = $this->flattenText($payload);
        if ($this->containsPhrase($text, ['delivered'])) {
            return 'DELIVERED';
        }

        return $text !== '' ? 'IN_TRANSIT' : $currentStatus;
    }

    public function isFailedDelivery(array|string|null $value): bool
    {
        $text = is_array($value) ? $this->flattenText($value) : $this->normalizeText((string) $value);

        return $this->containsPhrase($text, self::FAILED_DELIVERY_PHRASES);
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
                'time' => (int) ($value['update_time_millis']
                    ?? $value['update_time']
                    ?? $value['event_time']
                    ?? $value['create_time']
                    ?? 0),
            ];
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectEvents($child, $events);
            }
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
