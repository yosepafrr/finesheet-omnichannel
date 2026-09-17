<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class E2ETestDataSeeder extends Seeder
{
    public function run(): void
    {
        $envFile = base_path('.env.test');
        if (!file_exists($envFile)) {
            $this->command->error('.env.test file not found');
            return;
        }

        $envContent = file_get_contents($envFile);
        $lines = explode("\n", $envContent);
        $env = [];
        foreach ($lines as $line) {
            if (trim($line) !== '' && strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $env[trim($key)] = trim($value, "\"'");
            }
        }

        $userAEmail = $env['TEST_USER_A_EMAIL'] ?? 'a@test.com';
        $userAPassword = $env['TEST_USER_A_PASSWORD'] ?? 'password';
        $userBEmail = $env['TEST_USER_B_EMAIL'] ?? 'b@test.com';
        $userBPassword = $env['TEST_USER_B_PASSWORD'] ?? 'password';

        // CREATE USER A
        $userA = \App\Models\User::firstOrCreate(
            ['email' => $userAEmail],
            [
                'name' => 'Test User A',
                'password' => \Illuminate\Support\Facades\Hash::make($userAPassword),
                'email_verified_at' => now(),
            ]
        );
        $userA->password = \Illuminate\Support\Facades\Hash::make($userAPassword);
        $userA->save();

        // CREATE USER B
        $userB = \App\Models\User::firstOrCreate(
            ['email' => $userBEmail],
            [
                'name' => 'Test User B',
                'password' => \Illuminate\Support\Facades\Hash::make($userBPassword),
                'email_verified_at' => now(),
            ]
        );
        $userB->password = \Illuminate\Support\Facades\Hash::make($userBPassword);
        $userB->save();

        // CREATE DATA FOR USER A
        $storeA = \App\Models\Store::updateOrCreate(
            ['user_id' => $userA->id, 'store_name' => 'Store A Shopee'],
            [
                'platform' => 'Shopee',
                'shopee_shop_id' => '99001',
                'access_token' => 'e2e-token-a',
                'refresh_token' => 'e2e-refresh-a',
                'shop_expired_at' => now()->addYear(),
                'token_expired_at' => now()->addYear(),
            ]
        );
        $supplierA = \App\Models\Supplier::firstOrCreate(['user_id' => $userA->id, 'name' => 'Supplier A']);
        $productA = \App\Models\Product::updateOrCreate(
            ['product_id' => '990001'],
            ['store_id' => $storeA->id, 'platform' => 'Shopee', 'product_name' => 'Product A', 'hpp' => 50000, 'supplier_id' => $supplierA->id]
        );
        $orderA = \App\Models\Order::firstOrCreate(
            ['store_id' => $storeA->id, 'order_sn' => 'ORD-A-1'],
            ['platform' => 'Shopee', 'order_status' => 'COMPLETED', 'order_selling_price' => 100000, 'order_time' => now()]
        );

        // CREATE DATA FOR USER B
        $storeB = \App\Models\Store::updateOrCreate(
            ['user_id' => $userB->id, 'store_name' => 'Store B Shopee'],
            [
                'platform' => 'Shopee',
                'shopee_shop_id' => '99002',
                'access_token' => 'e2e-token-b',
                'refresh_token' => 'e2e-refresh-b',
                'shop_expired_at' => now()->addYear(),
                'token_expired_at' => now()->addYear(),
            ]
        );
        $supplierB = \App\Models\Supplier::firstOrCreate(['user_id' => $userB->id, 'name' => 'Supplier B']);
        $productB = \App\Models\Product::updateOrCreate(
            ['product_id' => '990002'],
            ['store_id' => $storeB->id, 'platform' => 'Shopee', 'product_name' => 'Product B', 'hpp' => 60000, 'supplier_id' => $supplierB->id]
        );
        $orderB = \App\Models\Order::firstOrCreate(
            ['store_id' => $storeB->id, 'order_sn' => 'ORD-B-1'],
            ['platform' => 'Shopee', 'order_status' => 'COMPLETED', 'order_selling_price' => 120000, 'order_time' => now()]
        );

        $this->command->info('E2E Test Data Seeded Successfully.');
    }
}
