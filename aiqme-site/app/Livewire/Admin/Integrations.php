<?php

namespace App\Livewire\Admin;

use App\Domain\Integrations\Telegram\Models\TelegramBinding;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Экран Integrations (§1.4), раздел Telegram (W-19; остальные подключения добавляет W-18): состояние привязки, последняя
 * ошибка Bot API и код привязки. Код — 32 случайных байта в base64url (43 символа, ≥128 бит), действует 10 минут,
 * одноразовый; хранится только SHA-256, показывается один раз. Новый код заменяет прежний, привязка остаётся до его
 * использования. Секреты не показываются. Каждый запрос — только администратору, иначе 404.
 */
class Integrations extends Component
{
    public const CODE_MINUTES = 10;

    #[Locked]
    public string $uiLocale;

    #[Locked]
    public ?string $code = null;

    public function boot(): void
    {
        abort_unless(auth()->user() && Gate::allows('admin'), 404);
    }

    public function hydrate(): void
    {
        app()->setLocale($this->uiLocale);
    }

    public function mount(): void
    {
        $this->uiLocale = app()->getLocale();
    }

    public function telegramCode(): void
    {
        $this->code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        TelegramBinding::current()->fill([
            'user_id' => auth()->id(),
            'code_hash' => hash('sha256', $this->code),
            'code_expires_at' => now()->addMinutes(self::CODE_MINUTES),
        ])->save();
    }

    public function render(): View
    {
        return view('admin.integrations-telegram', [
            'binding' => TelegramBinding::current(),
            'configured' => filled(config('services.telegram.token')) && filled(config('services.telegram.webhook_secret')),
        ]);
    }
}
