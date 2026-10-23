<?php

namespace App\Services;

class OrderCancellationMapper
{
    private const PAYMENT_CANCELLATION_FRAGMENTS = [
        'unpaid',
        'payment',
        'payment timeout',
        'payment time out',
        'payment expired',
        'failed to pay',
        'did not pay',
        'not paid',
        'terlambat membayar',
        'belum membayar',
        'tidak membayar',
        'gagal membayar',
        'batas waktu pembayaran',
        'pembayaran',
        'pembayaran kedaluwarsa',
    ];

    /**
     * Normalize raw cancellation data into a standardized category:
     * - SELLER_LATE_SHIPMENT
     * - BUYER_SIDE
     * - UNKNOWN
     */
    public static function normalize(string $platform, ?string $cancelSource, ?string $cancelReason, ?string $buyerCancelReason = null): string
    {
        $source = strtoupper(trim($cancelSource ?? ''));
        $reason = trim($cancelReason ?? '');
        $reasonLower = strtolower($reason);
        $buyerReasonLower = strtolower(trim($buyerCancelReason ?? ''));

        if (strcasecmp($platform, 'Shopee') === 0) {
            // Buyer initiated or buyer reason is present
            if ($source === 'BUYER' || ! empty($buyerCancelReason)) {
                return 'BUYER_SIDE';
            }

            // Seller initiated
            if ($source === 'SELLER') {
                if (str_contains($reasonLower, 'customer') || str_contains($reasonLower, 'buyer')) {
                    return 'BUYER_SIDE';
                }
                if (str_contains($reasonLower, 'late') || str_contains($reasonLower, 'ship')) {
                    return 'SELLER_LATE_SHIPMENT';
                }

                return 'UNKNOWN';
            }

            // System / Shopee auto cancellation
            if ($source === 'SYSTEM' || $source === 'SHOPEE') {
                if (
                    self::isPaymentCancellationReason($reason, $buyerCancelReason) ||
                    str_contains($reasonLower, 'fraud') ||
                    str_contains($reasonLower, 'voucher')
                ) {
                    return 'BUYER_SIDE';
                }

                if (
                    str_contains($reasonLower, 'undelivered by seller') ||
                    str_contains($reasonLower, 'ship in time') ||
                    str_contains($reasonLower, 'dts') ||
                    str_contains($reasonLower, 'fulfill') ||
                    str_contains($reasonLower, 'collection') ||
                    str_contains($reasonLower, 'not ship') ||
                    str_contains($reasonLower, 'terlambat mengirim') ||
                    str_contains($reasonLower, 'batas waktu pengiriman') ||
                    str_contains($reasonLower, 'gagal mengirim')
                ) {
                    return 'SELLER_LATE_SHIPMENT';
                }

                return 'UNKNOWN';
            }

            // Fallback when source is empty
            if (! empty($buyerCancelReason)) {
                return 'BUYER_SIDE';
            }

            return 'UNKNOWN';
        }

        if (strcasecmp($platform, 'Tiktokshop') === 0 || strcasecmp($platform, 'Tiktok') === 0) {
            if ($source === 'BUYER') {
                return 'BUYER_SIDE';
            }

            if ($source === 'SYSTEM') {
                if (
                    str_contains($reasonLower, 'collection time out') ||
                    str_contains($reasonLower, 'dispatch time out') ||
                    str_contains($reasonLower, 'sla time out') ||
                    str_contains($reasonLower, 'late dispatch') ||
                    str_contains($reasonLower, 'batas waktu pick up') ||
                    str_contains($reasonLower, 'batas waktu pickup') ||
                    str_contains($reasonLower, 'batas waktu pengiriman') ||
                    str_contains($reasonLower, 'terlambat mengirim')
                ) {
                    return 'SELLER_LATE_SHIPMENT';
                }

                if (
                    self::isPaymentCancellationReason($reason, $buyerCancelReason) ||
                    str_contains($reasonLower, 'fraud') ||
                    str_contains($reasonLower, 'risk')
                ) {
                    return 'BUYER_SIDE';
                }

                return 'UNKNOWN';
            }

            if ($source === 'SELLER') {
                if (
                    str_contains($reasonLower, 'late') ||
                    str_contains($reasonLower, 'ship') ||
                    str_contains($reasonLower, 'dispatch')
                ) {
                    return 'SELLER_LATE_SHIPMENT';
                }

                return 'UNKNOWN';
            }

            // Fallback heuristics when source is empty/null
            if (
                str_contains($reasonLower, 'collection time out') ||
                str_contains($reasonLower, 'dispatch time out') ||
                str_contains($reasonLower, 'sla time out') ||
                str_contains($reasonLower, 'late dispatch') ||
                str_contains($reasonLower, 'batas waktu pick up') ||
                str_contains($reasonLower, 'batas waktu pickup') ||
                str_contains($reasonLower, 'batas waktu pengiriman') ||
                str_contains($reasonLower, 'terlambat mengirim')
            ) {
                return 'SELLER_LATE_SHIPMENT';
            }

            if (
                self::isPaymentCancellationReason($reason, $buyerCancelReason) ||
                str_contains($reasonLower, 'fraud')
            ) {
                return 'BUYER_SIDE';
            }

            return 'UNKNOWN';
        }

        return 'UNKNOWN';
    }

    public static function isPaymentCancellationReason(?string ...$reasons): bool
    {
        $text = strtolower(trim(implode(' ', array_filter(
            $reasons,
            fn (?string $reason) => $reason !== null && trim($reason) !== '',
        ))));

        if ($text === '') {
            return false;
        }

        foreach (self::PAYMENT_CANCELLATION_FRAGMENTS as $fragment) {
            if (str_contains($text, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
