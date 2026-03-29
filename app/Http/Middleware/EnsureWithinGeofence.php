<?php

namespace App\Http\Middleware;

use App\Support\Geofence;
use App\Support\UserPremises;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureWithinGeofence
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if ($user->isAdmin()) {
            return $next($request);
        }

        if (!Geofence::isEnabled()) {
            return $next($request);
        }

        $latitude = $request->session()->get('geofence.latitude');
        $longitude = $request->session()->get('geofence.longitude');

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return $this->forceLogout(
                $request,
                'Location verification is required for this account. Please sign in again with location enabled.'
            );
        }

        if (!Geofence::contains((float) $latitude, (float) $longitude)) {
            return $this->redirectRestricted($request, (float) $latitude, (float) $longitude);
        }

        if (!UserPremises::containsForUser($user, (float) $latitude, (float) $longitude)) {
            return $this->redirectRestricted($request, (float) $latitude, (float) $longitude);
        }

        return $next($request);
    }

    private function forceLogout(Request $request, string $message): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['username' => $message]);
    }

    private function redirectRestricted(Request $request, float $latitude, float $longitude): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('geofence.restricted')
            ->with('geofence_attempted_latitude', $latitude)
            ->with('geofence_attempted_longitude', $longitude);
    }
}
