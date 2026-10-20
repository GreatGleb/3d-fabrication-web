<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Общее для экранов входа, регистрации и восстановления (W-07): шаблон формы и локализованные сообщения проверки. */
trait AuthForm
{
    /** @param  array<string, mixed>  $data */
    protected function form(string $mode, array $data = []): View
    {
        return view('account.auth', ['mode' => $mode, ...$data]);
    }

    /**
     * Сообщения проверки LT/EN по имени правила (Q-19-04); :min и :max подставляет валидатор.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'required' => __('account.messages.required'),
            'email' => __('account.messages.email'),
            'max' => __('account.messages.max'),
            'min' => __('account.messages.password_min'),
            'unique' => __('account.messages.email_taken'),
        ];
    }

    /** E-mail сравнивается без учёта регистра: в форму и в БД — строчными. */
    protected function normalizeEmail(Request $request): string
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $request->merge(['email' => $email]);

        return $email;
    }

    protected function locale(): string
    {
        return app()->getLocale();
    }
}
