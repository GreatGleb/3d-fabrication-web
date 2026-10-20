<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Вход и выход (W-07, §6.3). Один вход для клиента и администратора; нейтральная ошибка; ограничение попыток:
 * e-mail + IP и отдельно IP. После входа — session()->regenerate(), при выходе — invalidate().
 */
class LoginController extends Controller
{
    use AuthForm;

    /** Неудачных попыток в минуту: e-mail + IP и все попытки с IP. */
    public const MAX_PER_EMAIL = 5;

    public const MAX_PER_IP = 20;

    public function create(): View
    {
        return $this->form('login');
    }

    public function store(Request $request): RedirectResponse
    {
        $email = $this->normalizeEmail($request);
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ], $this->messages());

        $keys = ['login:'.$email.'|'.$request->ip() => self::MAX_PER_EMAIL, 'login-ip:'.$request->ip() => self::MAX_PER_IP];
        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'email' => __('account.messages.throttle', ['seconds' => RateLimiter::availableIn($key)]),
                ]);
            }
        }

        if (! Auth::attempt(['email' => $email, 'password' => (string) $request->input('password')])) {
            foreach (array_keys($keys) as $key) {
                RateLimiter::hit($key);
            }

            throw ValidationException::withMessages(['email' => __('account.messages.failed')]);
        }

        RateLimiter::clear(array_key_first($keys));
        $request->session()->regenerate();

        $home = $request->user()->is_admin ? route($this->locale().'.admin') : route($this->locale().'.account');

        return redirect()->intended($home);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($this->locale().'.account.login');
    }
}
