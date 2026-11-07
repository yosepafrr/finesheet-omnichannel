<?php

namespace App\Services;

use App\Models\AccessProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class AccessControlService
{
    public const SESSION_KEY = 'active_access_profile_id';

    public const REVOKED_SESSION_KEY = 'access_profile_revoked';

    public const DEVICE_COOKIE_KEY = 'finesheet_access_profile';

    private const DEVICE_COOKIE_MINUTES = 60 * 24 * 365 * 5;

    public function profile(Request $request): ?AccessProfile
    {
        $sessionProfileId = $request->session()->get(self::SESSION_KEY);
        $profileId = $sessionProfileId;

        if (! $profileId && $request->user()) {
            [$cookieUserId, $cookieProfileId] = array_pad(
                explode(':', (string) $request->cookie(self::DEVICE_COOKIE_KEY), 2),
                2,
                null
            );

            if ($cookieProfileId && (int) $cookieUserId === (int) $request->user()->id) {
                $profileId = $cookieProfileId;
            } elseif ($request->cookie(self::DEVICE_COOKIE_KEY)) {
                $this->forgetDeviceProfile();
            }
        }

        if (! $profileId || ! $request->user()) {
            return null;
        }

        $profile = AccessProfile::query()
            ->whereKey($profileId)
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->first();

        if (! $profile) {
            $request->session()->forget(self::SESSION_KEY);
            if ($profileId) {
                $request->session()->put(self::REVOKED_SESSION_KEY, true);
            }
            $this->forgetDeviceProfile();
        } elseif (! $request->session()->has(self::SESSION_KEY)) {
            $request->session()->put(self::SESSION_KEY, $profile->id);
        }

        return $profile;
    }

    public function isOwnerMode(Request $request): bool
    {
        return ! $this->isRevoked($request) && $this->profile($request) === null;
    }

    public function isRevoked(Request $request): bool
    {
        return (bool) $request->session()->get(self::REVOKED_SESSION_KEY, false);
    }

    public function allows(Request $request, string $permission): bool
    {
        $profile = $this->profile($request);

        return ! $this->isRevoked($request)
            && ($profile === null || in_array($permission, $profile->permissions ?? [], true));
    }

    public function allowedPermissions(): array
    {
        return array_keys(config('access.permissions', []));
    }

    public function rememberDeviceProfile(int $userId, int $profileId): void
    {
        Cookie::queue(
            self::DEVICE_COOKIE_KEY,
            $userId.':'.$profileId,
            self::DEVICE_COOKIE_MINUTES,
            '/',
            null,
            null,
            true,
            false,
            'lax'
        );
    }

    public function forgetDeviceProfile(): void
    {
        Cookie::queue(Cookie::forget(self::DEVICE_COOKIE_KEY));
    }

    public function sanitizePermissions(array $permissions): array
    {
        $permissions = array_values(array_unique(array_intersect($permissions, $this->allowedPermissions())));
        $dependencies = [
            'stores.manage' => 'stores.view',
            'products.edit' => 'products.view',
            'products.stock.manage' => 'products.view',
            'products.hpp.view' => 'products.view',
            'products.hpp.edit' => 'products.hpp.view',
            'products.supplier.manage' => 'products.view',
            'products.sync' => 'products.view',
            'orders.process' => 'orders.view',
            'orders.ship' => 'orders.view',
            'orders.print' => 'orders.view',
            'orders.sync' => 'orders.view',
            'orders.finance.view' => 'orders.view',
            'fulfillment.settings.manage' => 'orders.view',
        ];

        do {
            $before = count($permissions);
            foreach ($dependencies as $permission => $dependency) {
                if (in_array($permission, $permissions, true) && ! in_array($dependency, $permissions, true)) {
                    $permissions[] = $dependency;
                }
            }
        } while (count($permissions) !== $before);

        return array_values(array_unique($permissions));
    }
}
