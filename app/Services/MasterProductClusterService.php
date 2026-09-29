<?php

namespace App\Services;

class MasterProductClusterService
{
    /**
     * @return array{product_key: ?string, product_label: ?string, variant_keys: array<int, string>, variant_labels: array<int, string>}
     */
    public function fromSku(mixed $sku): array
    {
        $segments = collect(preg_split('/-+/', trim((string) $sku)) ?: [])
            ->map(fn (string $segment) => trim($segment))
            ->filter(fn (string $segment) => $segment !== '')
            ->values();

        if ($segments->isEmpty()) {
            return [
                'product_key' => null,
                'product_label' => null,
                'variant_keys' => [],
                'variant_labels' => [],
            ];
        }

        $productSegments = $segments->take(2);
        $variantSegments = $segments->slice(2)
            ->map(fn (string $segment) => mb_strtolower($segment))
            ->unique()
            ->values();

        return [
            'product_key' => $productSegments
                ->map(fn (string $segment) => mb_strtolower($segment))
                ->implode('-'),
            'product_label' => $productSegments
                ->map(fn (string $segment) => mb_strtoupper($segment))
                ->implode('-'),
            'variant_keys' => $variantSegments->all(),
            'variant_labels' => $variantSegments
                ->map(fn (string $segment) => mb_strtoupper($segment))
                ->all(),
        ];
    }
}
