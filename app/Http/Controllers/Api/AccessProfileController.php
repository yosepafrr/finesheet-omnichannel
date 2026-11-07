<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessActivityLog;
use App\Models\AccessProfile;
use App\Services\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccessProfileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'profiles' => $request->user()->accessProfiles()->latest()->get(),
            'permissions' => config('access.permissions'),
            'presets' => config('access.presets'),
            'activity' => AccessActivityLog::query()
                ->where('user_id', $request->user()->id)
                ->with('profile:id,name')
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function store(Request $request, AccessControlService $access): JsonResponse
    {
        $validated = $this->validateProfile($request);
        $profile = $request->user()->accessProfiles()->create([
            ...$validated,
            'permissions' => $access->sanitizePermissions($validated['permissions']),
        ]);

        $this->log($request, 'profile_created', $profile, ['name' => $profile->name]);

        return response()->json(['message' => 'Profil operator berhasil dibuat.', 'profile' => $profile], 201);
    }

    public function update(Request $request, AccessProfile $profile, AccessControlService $access): JsonResponse
    {
        $this->ensureOwned($request, $profile);
        $validated = $this->validateProfile($request, $profile);
        $profile->update([
            ...$validated,
            'permissions' => $access->sanitizePermissions($validated['permissions']),
        ]);

        $this->log($request, 'profile_updated', $profile, ['name' => $profile->name]);

        return response()->json(['message' => 'Profil operator berhasil diperbarui.', 'profile' => $profile]);
    }

    public function destroy(Request $request, AccessProfile $profile): JsonResponse
    {
        $this->ensureOwned($request, $profile);
        $this->log($request, 'profile_deleted', $profile, ['name' => $profile->name]);
        $profile->delete();

        return response()->json(['message' => 'Profil operator berhasil dihapus.']);
    }

    private function validateProfile(Request $request, ?AccessProfile $profile = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('access_profiles', 'name')
                    ->where('user_id', $request->user()->id)
                    ->ignore($profile?->id),
            ],
            'layout' => ['required', Rule::in(['order_operations', 'product_operations', 'finance_operations', 'read_only', 'custom'])],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function ensureOwned(Request $request, AccessProfile $profile): void
    {
        abort_unless((int) $profile->user_id === (int) $request->user()->id, 404);
    }

    private function log(Request $request, string $action, ?AccessProfile $profile, array $metadata = []): void
    {
        AccessActivityLog::create([
            'user_id' => $request->user()->id,
            'access_profile_id' => $profile?->id,
            'action' => $action,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
