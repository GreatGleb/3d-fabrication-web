<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Восстановление пароля (W-07, F-11): письмо со ссылкой через очередь (User::sendPasswordResetNotification);
 * одинаковый ответ для существующих и несуществующих адресов; токен брокера Laravel — одноразовый, со сроком
 * auth.passwords.users.expire. Смена пароля меняет его хеш — AuthenticateSession (auth.session) завершает прежние сессии.
 */
class PasswordResetController extends Controller
{
    use AuthForm;

    /** Запросов ссылки с одного IP в минуту; повтор на тот же e-mail брокер сдерживает сам (throttle в config/auth.php). */
    public const MAX_PER_IP = 5;

    public function create(): View
    {
        return $this->form('reset');
    }

    public function store(Request $request): RedirectResponse
    {
        $email = $this->normalizeEmail($request);
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']], $this->messages());

        $key = 'password-reset:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_IP)) {
            throw ValidationException::withMessages([
                'email' => __('account.messages.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }
        RateLimiter::hit($key);

        // Итог брокера (отправлено, нет такого адреса, повтор слишком рано) не раскрывается.
        Password::sendResetLink(['email' => $email]);

        return back()->with('status', __('account.messages.reset_sent'));
    }

    public function edit(Request $request, string $token): View
    {
        return $this->form('new_password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function update(Request $request, string $token): RedirectResponse
    {
        $email = $this->normalizeEmail($request);
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', PasswordRule::defaults()],
        ], $this->messages());

        $status = Password::reset(
            ['email' => $email, 'password' => (string) $request->input('password'), 'token' => $token],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __('account.messages.reset_invalid')]);
        }

        return redirect()->route($this->locale().'.account.login')->with('status', __('account.messages.password_reset'));
    }
}
