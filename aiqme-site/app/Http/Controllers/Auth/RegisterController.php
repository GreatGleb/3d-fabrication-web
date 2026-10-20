<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Регистрация (W-07, §1.3): имя, e-mail, пароль; создаёт только клиента — is_admin не входит в $fillable (§6.1).
 * Подтверждения почты нет, как в прототипе.
 */
class RegisterController extends Controller
{
    use AuthForm;

    public function create(): View
    {
        return $this->form('register');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
        ], $this->messages());

        $user = User::create($data);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route($this->locale().'.account');
    }
}
