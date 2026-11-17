<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\MessagingChannelContract;
use App\Models\Farmer;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class PlannerAuthController
{
    public function requestOtp(Request $request, MessagingChannelContract $channel): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'tenant_slug' => ['nullable', 'string', 'max:64'],
        ]);

        $phoneHash = Farmer::hashPhone($validated['phone']);
        $dailyKey = 'planner_otp_daily:' . $phoneHash . ':' . now()->toDateString();
        $dailyCount = (int) Cache::get($dailyKey, 0);
        if ($dailyCount >= 10) {
            return response()->json(['message' => 'Daily OTP limit reached. Try again tomorrow.'], 429);
        }
        Cache::put($dailyKey, $dailyCount + 1, now()->endOfDay());

        $otp = (string) random_int(100000, 999999);

        Cache::put("planner_otp:{$phoneHash}", [
            'hash' => Hash::make($otp),
            'attempts' => 0,
            'tenant_slug' => $validated['tenant_slug'] ?? null,
        ], now()->addMinutes(10));

        if (config('betterlink.whatsapp.enabled')) {
            try {
                $channel->sendTextMessage(
                    $validated['phone'],
                    "Your BetterlinkAgr AI Farm Planner verification code is: {$otp}. Valid for 10 minutes."
                );
            } catch (\Throwable) {
                // Non-fatal in sandbox/offline tests
            }
        }

        $response = [
            'message' => 'Verification code sent.',
            'expires_in_seconds' => 600,
        ];

        if (app()->environment(['local', 'testing'])) {
            $response['debug_otp'] = $otp;
        }

        return response()->json($response);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'otp' => ['required', 'string', 'size:6'],
            'farmer_name' => ['nullable', 'string', 'max:120'],
            'tenant_slug' => ['nullable', 'string', 'max:64'],
        ]);

        $phoneHash = Farmer::hashPhone($validated['phone']);
        $cacheKey = "planner_otp:{$phoneHash}";
        $entry = Cache::get($cacheKey);

        if (!is_array($entry)) {
            return response()->json(['message' => 'OTP expired or not requested.'], 422);
        }

        if (($entry['attempts'] ?? 0) >= 5) {
            Cache::forget($cacheKey);
            return response()->json(['message' => 'Too many invalid OTP attempts. Request a new code.'], 429);
        }

        if (!Hash::check($validated['otp'], (string) ($entry['hash'] ?? ''))) {
            $entry['attempts'] = ((int) ($entry['attempts'] ?? 0)) + 1;
            Cache::put($cacheKey, $entry, now()->addMinutes(10));

            return response()->json(['message' => 'Invalid verification code.'], 422);
        }

        Cache::forget($cacheKey);

        $farmer = Farmer::findOrCreateByPhone(
            $validated['phone'],
            $validated['farmer_name'] ?? 'Web Planner Farmer'
        );

        $tenantSlug = $validated['tenant_slug'] ?? $entry['tenant_slug'] ?? null;
        $tenant = $tenantSlug
            ? Tenant::query()->where('slug', '=', $tenantSlug)->where('is_active', '=', true)->first()
            : Tenant::query()->where('is_active', '=', true)->first();

        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);

        Cache::put("planner_token:{$tokenHash}", [
            'farmer_id' => $farmer->id,
            'tenant_id' => $tenant?->id,
        ], now()->addDays(7));

        return response()->json([
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_in_seconds' => 7 * 86400,
            'farmer' => [
                'id' => $farmer->id,
                'tenant_id' => $tenant?->id,
            ],
        ])->cookie(
            'planner_token',
            $plainToken,
            7 * 24 * 60,
            '/',
            null,
            app()->isProduction(),
            true, // HttpOnly
            false,
            'lax' // SameSite=Lax
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?: (string) $request->cookie('planner_token', '');
        if ($token !== '') {
            Cache::forget('planner_token:' . hash('sha256', $token));
        }

        return response()->json(['message' => 'Logged out.'])->withoutCookie('planner_token');
    }
}
