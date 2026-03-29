<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Geofence;
use App\Support\LoginClickBypass;
use App\Support\UserPremises;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        LoginClickBypass::ensureSeeded();

        return view('auth.login-cover', [
            'geofenceEnabled' => Geofence::isEnabled(),
            'loginBypassEnabled' => LoginClickBypass::featureAllowed() && LoginClickBypass::isEnabled(),
            'loginBypassRules' => LoginClickBypass::fetchPublicRules(),
            'loginBypassEndpoint' => route('login.bypass'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string'],
        ]);

        $username = trim((string) $credentials['username']);
        $throttleKey = $this->throttleKey($request, $username);

        if (RateLimiter::tooManyAttempts($throttleKey, 8)) {
            $seconds = (int) RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'username' => __('auth.throttle', [
                    'seconds' => max(1, $seconds),
                    'minutes' => (int) ceil(max(1, $seconds) / 60),
                ]),
            ]);
        }

        if (! Auth::attempt([
            'username' => $username,
            'password' => $credentials['password'],
            'is_active' => true,
            'registration_status' => UserRegistrationStatus::APPROVED->value,
        ], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'username' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        /** @var User|null $authenticatedUser */
        $authenticatedUser = $request->user();
        $requiresGeofence = Geofence::isEnabled() && $authenticatedUser && !$authenticatedUser->isAdmin();

        if ($requiresGeofence) {
            $latitude = $request->input('geofence_latitude');
            $longitude = $request->input('geofence_longitude');

            if (!is_numeric($latitude) || !is_numeric($longitude)) {
                Auth::logout();
                throw ValidationException::withMessages([
                    'username' => 'Location access is required to sign in.',
                ]);
            }

            if (!Geofence::contains((float) $latitude, (float) $longitude)) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()
                    ->route('geofence.restricted')
                    ->with('geofence_attempted_latitude', (float) $latitude)
                    ->with('geofence_attempted_longitude', (float) $longitude);
            }

            if (!UserPremises::containsForUser($authenticatedUser, (float) $latitude, (float) $longitude)) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()
                    ->route('geofence.restricted')
                    ->with('geofence_attempted_latitude', (float) $latitude)
                    ->with('geofence_attempted_longitude', (float) $longitude);
            }

            $request->session()->put('geofence.latitude', (float) $latitude);
            $request->session()->put('geofence.longitude', (float) $longitude);
        } else {
            $request->session()->forget(['geofence.latitude', 'geofence.longitude']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function throttleKey(Request $request, string $username): string
    {
        $normalized = strtolower(trim($username));
        if ($normalized === '') {
            $normalized = 'unknown';
        }

        return $normalized . '|' . (string) $request->ip();
    }
}
