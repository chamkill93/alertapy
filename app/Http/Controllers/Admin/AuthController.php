<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\AdministratorPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user('web')?->is_admin) {
            return redirect()->route('admin.dashboard');
        }

        if ($this->localSetupAvailable($request)) {
            return redirect()->route('admin.setup');
        }

        return view('admin.auth.login');
    }

    public function setup(Request $request): View
    {
        abort_unless($this->localSetupAvailable($request), 404);

        return view('admin.auth.setup');
    }

    public function setupStore(Request $request): RedirectResponse
    {
        abort_unless($this->localSetupAvailable($request), 404);
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(12), new AdministratorPassword, 'confirmed'],
        ]);

        $user = Cache::lock('alertapy:admin-setup', 30)->get(function () use ($request, $data): User {
            return DB::transaction(function () use ($request, $data): User {
                abort_unless($this->localSetupAvailable($request), 404);
                $user = new User;
                $user->name = trim($data['name']);
                $user->email = $data['email'];
                $user->password = $data['password'];
                $user->is_admin = true;
                $user->save();

                return $user;
            });
        });
        abort_unless($user instanceof User, 409, 'La creación del administrador ya está en curso. Volvé a intentar.');

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget('url.intended');

        return redirect()->route('admin.dashboard');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', new AdministratorPassword],
        ]);
        $credentials['email'] = Str::lower(trim($credentials['email']));
        $identityKey = 'admin-login:'.hash('sha256', $credentials['email'].'|'.$request->ip());
        $ipKey = 'admin-login-ip:'.hash('sha256', (string) $request->ip());

        if (RateLimiter::tooManyAttempts($identityKey, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $retryAfter = max(RateLimiter::availableIn($identityKey), RateLimiter::availableIn($ipKey));
            abort(429, 'Demasiados intentos. Volvé a intentar en un minuto.', ['Retry-After' => (string) max(1, $retryAfter)]);
        }

        if (! Auth::guard('web')->attempt([...$credentials, 'is_admin' => true])) {
            RateLimiter::hit($identityKey, 60);
            RateLimiter::hit($ipKey, 60);

            throw ValidationException::withMessages([
                'email' => 'El correo o la contraseña no son correctos.',
            ]);
        }

        RateLimiter::clear($identityKey);
        $request->session()->regenerate();

        // Always land in the backoffice, even if a previous guest request supplied another destination.
        $request->session()->forget('url.intended');

        return redirect()->route('admin.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function localSetupAvailable(Request $request): bool
    {
        return app()->environment('local')
            && in_array($request->ip(), ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)
            && ! User::query()->where('is_admin', true)->exists();
    }
}
