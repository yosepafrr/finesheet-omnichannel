<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessActivityLog;
use App\Models\AccessProfile;
use App\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AccessSessionController extends Controller
{
    public function context(Request $request, AccessControlService $access): JsonResponse
    {
        $profile = $access->profile($request);

        return response()->json([
            'is_owner_mode' => $profile === null,
            'active_profile' => $profile,
            'permissions' => $profile?->permissions ?? $access->allowedPermissions(),
            'available_profiles' => $profile === null
                ? $request->user()->accessProfiles()->where('is_active', true)->orderBy('name')->get()
                : [],
        ]);
    }

    public function switchProfile(Request $request, AccessControlService $access): JsonResponse
    {
        abort_unless($access->isOwnerMode($request), 403, 'Kembali ke mode Owner sebelum memilih operator lain.');

        $validated = $request->validate(['profile_id' => ['required', 'integer']]);
        $profile = $request->user()->accessProfiles()
            ->whereKey($validated['profile_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $request->session()->put(AccessControlService::SESSION_KEY, $profile->id);
        $request->session()->forget(AccessControlService::REVOKED_SESSION_KEY);
        $request->session()->regenerate();
        $access->rememberDeviceProfile($request->user()->id, $profile->id);
        $this->log($request, 'switched_to_profile', $profile);

        return response()->json(['message' => "Mode {$profile->name} aktif."]);
    }

    public function switchOwner(Request $request, AccessControlService $access): JsonResponse
    {
        $key = 'access-owner:'.$request->user()->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'password' => 'Terlalu banyak percobaan. Coba kembali dalam '.RateLimiter::availableIn($key).' detik.',
            ]);
        }

        $validated = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($validated['password'], $request->user()->password)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['password' => 'Password Owner tidak sesuai.']);
        }

        RateLimiter::clear($key);
        $profileId = $request->session()->get(AccessControlService::SESSION_KEY);
        $request->session()->forget(AccessControlService::SESSION_KEY);
        $request->session()->forget(AccessControlService::REVOKED_SESSION_KEY);
        $request->session()->regenerate();
        $access->forgetDeviceProfile();

        $profile = $profileId ? AccessProfile::find($profileId) : null;
        $this->log($request, 'switched_to_owner', $profile);

        return response()->json(['message' => 'Mode Owner aktif.']);
    }

    private function log(Request $request, string $action, ?AccessProfile $profile): void
    {
        AccessActivityLog::create([
            'user_id' => $request->user()->id,
            'access_profile_id' => $profile?->id,
            'action' => $action,
            'metadata' => ['profile_name' => $profile?->name],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
