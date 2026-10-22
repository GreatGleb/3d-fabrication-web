<?php

namespace App\Livewire\Admin;

use App\Domain\Integrations\Models\IntegrationEvent;
use App\Domain\Integrations\Sheets\SheetsProjector;
use App\Domain\Integrations\Telegram\Models\TelegramBinding;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Экран Integrations (§1.4): состояние Stripe, почты и Google Таблиц (W-18: настроено ли в .env, последняя ошибка канала
 * outbox), ссылка на Google Таблицу (settings sheets_url, выводится и в Customers) и раздел Telegram (W-19): состояние
 * привязки, последняя ошибка Bot API и код привязки. Код — 32 случайных байта в base64url (43 символа, ≥128 бит), действует 10 минут,
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

    /** Ключ settings со ссылкой на Google Таблицу (§2.5, §5.3). */
    public const SHEETS_URL = 'sheets_url';

    public string $sheetsUrl = '';

    #[Locked]
    public ?string $status = null;

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
        $this->sheetsUrl = (string) self::sheetsUrl();
    }

    /** Ссылка на Google Таблицу из settings или null. */
    public static function sheetsUrl(): ?string
    {
        return Setting::find(self::SHEETS_URL)?->value['url'] ?? null;
    }

    /** Сохраняет ссылку на таблицу (https, до 500 символов); пустое поле — ссылки нет. Доступ — по правам самой таблицы. */
    public function saveSheetsUrl(): void
    {
        $this->status = null;
        $this->sheetsUrl = trim($this->sheetsUrl);
        $this->validate(['sheetsUrl' => ['nullable', 'url:https', 'max:500']], ['*' => __('admin.integrations.sheets.invalid')]);
        if ($this->sheetsUrl === '') {
            Setting::destroy(self::SHEETS_URL);
        } else {
            Setting::updateOrCreate(['key' => self::SHEETS_URL], ['value' => ['url' => $this->sheetsUrl]]);
        }
        $this->status = __('admin.integrations.sheets.saved');
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
        $lastError = fn (string $channel): ?IntegrationEvent => IntegrationEvent::where($channel.'_status', 'failed')->latest('updated_at')->first();

        return view('admin.integrations-panel', [
            'binding' => TelegramBinding::current(),
            'configured' => filled(config('services.telegram.token')) && filled(config('services.telegram.webhook_secret')),
            'stripe' => filled(config('services.stripe.key')) && filled(config('services.stripe.secret')) && filled(config('services.stripe.webhook_secret')),
            'mail' => ! in_array(config('mail.default'), ['log', 'array'], true),
            'mailError' => $lastError('mail'),
            'sheets' => SheetsProjector::configured(),
            'sheetsError' => $lastError('sheets'),
            'savedUrl' => self::sheetsUrl(),
        ]);
    }
}
