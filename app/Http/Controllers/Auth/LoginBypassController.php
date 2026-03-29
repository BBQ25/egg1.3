<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Geofence;
use App\Support\LoginClickBypass;
use App\Support\UserPremises;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginBypassController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (!LoginClickBypass::featureAllowed()) {
            return response()->json([
                'ok' => false,
                'message' => 'Login bypass is disabled by policy.',
            ], 403);
        }

        LoginClickBypass::ensureSeeded();

        if (!LoginClickBypass::isEnabled()) {
            return response()->json([
                'ok' => false,
                'message' => 'Login bypass is disabled.',
            ], 403);
        }

        $validated = $request->validate([
            'click_count' => ['required', 'integer', 'min:2', 'max:20'],
            'duration_ms' => ['required', 'integer', 'min:0', 'max:30000'],
            'geofence_latitude' => ['nullable', 'numeric'],
            'geofence_longitude' => ['nullable', 'numeric'],
        ]);

        $match = LoginClickBypass::matchRule(
            (int) $validated['click_count'],
            (int) $validated['duration_ms']
        );

        if (!$match) {
            return response()->json([
                'ok' => false,
                'message' => 'No matching bypass rule.',
            ], 400);
        }

        $user = $match['user'];

        if (!$user->isActive() || !$user->isApproved()) {
            return response()->json([
                'ok' => false,
                'message' => 'Target account is not active.',
            ], 403);
        }

        $requiresGeofence = Geofence::isEnabled() && !$user->isAdmin();

        if ($requiresGeofence) {
            $latitude = $validated['geofence_latitude'] ?? null;
            $longitude = $validated['geofence_longitude'] ?? null;

            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Location access is required to sign in.',
                ], 422);
            }

            if (!Geofence::contains((float) $latitude, (float) $longitude)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'You are outside the allowed geofence.',
                ], 403);
            }

            if (!UserPremises::containsForUser($user, (float) $latitude, (float) $longitude)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'You are outside the allowed premises.',
                ], 403);
            }

            $request->session()->put('geofence.latitude', (float) $latitude);
            $request->session()->put('geofence.longitude', (float) $longitude);
        } else {
            $request->session()->forget(['geofence.latitude', 'geofence.longitude']);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'ok' => true,
            'redirect' => route('dashboard'),
        ]);
    }
}
