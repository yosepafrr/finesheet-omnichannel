<?php

namespace App\Http\Middleware;

use App\Services\AccessControlService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnforceAccessProfile
{
    public function __construct(private readonly AccessControlService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->access->profile($request);

        if ($this->access->isRevoked($request)) {
            Auth::guard('web')->logout();
            $this->access->forgetDeviceProfile();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Profil akses pada perangkat ini sudah dinonaktifkan. Silakan masuk kembali.',
            ], 401);
        }

        if ($this->access->isOwnerMode($request)) {
            return $next($request);
        }

        $permission = $this->requiredPermission($request);

        if ($permission === false) {
            return $next($request);
        }

        if (! is_string($permission) || ! $this->access->allows($request, $permission)) {
            return response()->json([
                'message' => 'Profil aktif tidak memiliki izin untuk melakukan tindakan ini.',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }

    private function requiredPermission(Request $request): string|false|null
    {
        $path = $request->path();
        $method = $request->method();

        if (in_array($path, ['api/user', 'api/access/context', 'api/access/switch-owner'], true)) {
            return false;
        }

        if ($path === 'dashboard/stats') {
            return 'dashboard.view';
        }
        if (Str::is('api/stores*', $path)) {
            return $method === 'GET' ? 'stores.view' : 'stores.manage';
        }
        if (Str::is(['connect/shopee*', 'connect/tiktok*', 'shopee/*', 'tiktok/*'], $path)) {
            return 'stores.manage';
        }

        if (Str::is('api/products*', $path) || Str::is('api/variants*', $path) || Str::is('api/master-products*', $path) || Str::is('api/sku-sync*', $path)) {
            if ($method === 'GET') {
                return 'products.view';
            }
            if ($path === 'api/master-products/variants/bulk' && $method === 'PUT') {
                return $request->input('field') === 'hpp'
                    ? 'products.hpp.edit'
                    : 'products.stock.manage';
            }
            if (Str::is(['*hpp*'], $path)) {
                return 'products.hpp.edit';
            }
            if (Str::is(['*supplier*'], $path)) {
                return 'products.supplier.manage';
            }
            if (Str::is(['*push*'], $path)) {
                return 'products.stock.manage';
            }

            return 'products.edit';
        }

        if ($path === 'api/sync/products') {
            return 'products.sync';
        }
        if (Str::is('api/orders*', $path)) {
            return 'orders.view';
        }
        if ($path === 'api/sync/orders/status') {
            return 'orders.view';
        }
        if ($path === 'api/sync/orders') {
            return 'orders.sync';
        }
        if (Str::is('api/profit-tracker*', $path) || Str::is('api/tiktok-ads*', $path)) {
            return 'finance.profit.view';
        }
        if (Str::is('api/payable*', $path)) {
            return 'finance.payable.manage';
        }
        if (Str::is('api/cashflow*', $path)) {
            return 'finance.cashflow.manage';
        }

        // Unlisted authenticated endpoints remain owner-only until explicitly classified.
        return null;
    }
}
