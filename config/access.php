<?php

$permissions = [
    'dashboard.view' => ['group' => 'Umum', 'label' => 'Lihat dashboard'],
    'stores.view' => ['group' => 'Toko', 'label' => 'Lihat daftar toko'],
    'stores.manage' => ['group' => 'Toko', 'label' => 'Kelola dan otorisasi toko'],
    'products.view' => ['group' => 'Produk', 'label' => 'Lihat produk'],
    'products.edit' => ['group' => 'Produk', 'label' => 'Tambah dan edit produk master'],
    'products.stock.manage' => ['group' => 'Produk', 'label' => 'Edit dan sinkronkan stok'],
    'products.hpp.view' => ['group' => 'Produk', 'label' => 'Lihat HPP'],
    'products.hpp.edit' => ['group' => 'Produk', 'label' => 'Edit HPP'],
    'products.supplier.manage' => ['group' => 'Produk', 'label' => 'Atur supplier produk'],
    'products.sync' => ['group' => 'Produk', 'label' => 'Sinkronkan produk marketplace'],
    'orders.view' => ['group' => 'Pesanan', 'label' => 'Lihat pesanan'],
    'orders.process' => ['group' => 'Pesanan', 'label' => 'Proses pesanan'],
    'orders.ship' => ['group' => 'Pesanan', 'label' => 'Atur pengiriman'],
    'orders.print' => ['group' => 'Pesanan', 'label' => 'Cetak resi'],
    'orders.sync' => ['group' => 'Pesanan', 'label' => 'Sinkronkan pesanan'],
    'orders.finance.view' => ['group' => 'Pesanan', 'label' => 'Lihat HPP, escrow, dan profit pesanan'],
    'fulfillment.settings.manage' => ['group' => 'Pesanan', 'label' => 'Kelola pengaturan fulfillment toko'],
    'finance.profit.view' => ['group' => 'Keuangan', 'label' => 'Lihat Profit Tracker'],
    'finance.payable.manage' => ['group' => 'Keuangan', 'label' => 'Kelola transaksi supplier'],
    'finance.cashflow.manage' => ['group' => 'Keuangan', 'label' => 'Kelola cashflow'],
];

return [
    'permissions' => $permissions,
    'presets' => [
        'order_operations' => [
            'label' => 'Admin Fulfillment',
            'layout' => 'order_operations',
            'permissions' => [
                'stores.view', 'orders.view', 'orders.process',
                'orders.ship', 'orders.print', 'orders.sync', 'fulfillment.settings.manage',
            ],
        ],
        'product_operations' => [
            'label' => 'Admin Produk',
            'layout' => 'product_operations',
            'permissions' => [
                'dashboard.view', 'stores.view', 'products.view', 'products.edit',
                'products.stock.manage', 'products.sync',
            ],
        ],
        'read_only' => [
            'label' => 'Read Only',
            'layout' => 'read_only',
            'permissions' => ['dashboard.view', 'stores.view', 'products.view', 'orders.view'],
        ],
        'finance_operations' => [
            'label' => 'Admin Finance',
            'layout' => 'finance_operations',
            'permissions' => [
                'orders.view', 'orders.finance.view', 'finance.profit.view',
                'finance.payable.manage', 'finance.cashflow.manage',
            ],
        ],
        'custom' => [
            'label' => 'Custom',
            'layout' => 'custom',
            'permissions' => [],
        ],
    ],
];
