<?php

namespace App\Http\Controllers\DocEase;

use App\Domain\DocEase\DocEaseGateway;
use App\Models\DocEaseUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('doc-ease.auth.login');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
        ]);

        $login = trim((string) $credentials['login']);
        $throttleKey = $this->throttleKey($request, $login);

        if (RateLimiter::tooManyAttempts($throttleKey, 8)) {
            $seconds = (int) RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'login' => 'Too many login attempts. Try again in ' . max(1, $seconds) . ' second(s).',
            ]);
        }

        $user = $this->findUserForLogin($login);
        if (!$user || !Hash::check((string) $credentials['password'], $user->getAuthPassword())) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'login' => 'Invalid email or password.',
            ]);
        }

        if (!$user->canSignIn()) {
            throw ValidationException::withMessages([
                'login' => 'Your account is pending admin approval.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        Auth::guard('doc_ease')->login($user, false);
        $request->session()->regenerate();

        return redirect()->intended(route('doc-ease.portal'));
    }

    public function portal(Request $request): View
    {
        /** @var DocEaseUser|null $user */
        $user = Auth::guard('doc_ease')->user();

        return view('doc-ease.portal', [
            'user' => $user,
        ]);
    }

    public function launchLegacy(Request $request, DocEaseGateway $docEaseGateway): RedirectResponse
    {
        if (!$docEaseGateway->entrypointExists()) {
            abort(404);
        }

        if ($docEaseGateway->bridgeEnabled() && !$docEaseGateway->bridgeExists()) {
            abort(404);
        }

        if ($docEaseGateway->bridgeEnabled() && !$docEaseGateway->bridgeSecretConfigured()) {
            abort(503, 'Doc-Ease bridge is enabled but not configured.');
        }

        try {
            $launchUrl = $docEaseGateway->launchUrlForUser(Auth::guard('doc_ease')->user());
        } catch (RuntimeException $e) {
            abort(503, $e->getMessage());
        }

        return redirect()->to($launchUrl);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('doc_ease')->logout();

        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('doc-ease.login');
    }

    private function findUserForLogin(string $login): ?DocEaseUser
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        return DocEaseUser::query()
            ->where('useremail', $login)
            ->orWhere(static function ($query) use ($login): void {
                $query->where('username', $login)
                    ->whereIn('role', ['student', 'user']);
            })
            ->first();
    }

    private function throttleKey(Request $request, string $login): string
    {
        $normalized = strtolower(trim($login));
        if ($normalized === '') {
            $normalized = 'unknown';
        }

        return $normalized . '|' . (string) $request->ip();
    }
}
