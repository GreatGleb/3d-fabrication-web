<?php

namespace App\Providers;

use App\Domain\Content\UiTextLoader;
use App\Domain\Integrations\Sheets\GoogleTokenProvider;
use App\Domain\Integrations\Sheets\HttpSheetsClient;
use App\Domain\Integrations\Sheets\SheetsClient;
use App\Domain\Integrations\Sheets\TokenProvider;
use App\Domain\Orders\OrderNumber;
use App\Domain\Orders\RequestCartValidator;
use App\Models\User;
use App\Support\UnregisteredLunarPanelManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Lunar\Admin\Support\Facades\LunarPanel;
use Lunar\Facades\Telemetry;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Stripe\StripeClientInterface;
use Stripe\Util\ApiVersion;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (self::lunarPanelEnabled($this->app->environment(), (bool) config('aiqme.lunar_panel'))) {
            LunarPanel::register();
        } else {
            $this->app->scoped('lunar-panel', fn (): UnregisteredLunarPanelManager => new UnregisteredLunarPanelManager);
        }

        // Клиент Stripe (W-16, §2.6): ключ из .env, версия API endpoint §5.5, таймаут вызова; тесты подменяют
        // StripeClientInterface моком — сетевых запросов к Stripe в тестах нет.
        $this->app->bind(StripeClientInterface::class, function (): StripeClient {
            $http = new CurlClient;
            $http->setTimeout(config('services.stripe.timeout'));
            $http->setConnectTimeout(min(10, config('services.stripe.timeout')));
            ApiRequestor::setHttpClient($http);

            return new StripeClient(['api_key' => config('services.stripe.secret') ?: null, 'stripe_version' => ApiVersion::CURRENT]);
        });

        // Google Таблицы (W-18, §5.3): токен сервисного аккаунта из .env (один на процесс worker), клиент Sheets API на
        // HTTP-клиенте Laravel; тесты подменяют поставщика токена заглушкой, клиент — фейком сетки.
        $this->app->singleton(TokenProvider::class, fn (): GoogleTokenProvider => new GoogleTokenProvider(config('aiqme.sheets.key')));
        $this->app->bind(SheetsClient::class, fn ($app): HttpSheetsClient => new HttpSheetsClient($app->make(TokenProvider::class), (string) config('aiqme.sheets.spreadsheet_id')));

        // ui_text: значения из БД поверх lang/ на уровне переводчика (§4.1), __() в любом шаблоне их учитывает.
        $this->app->extend('translation.loader', fn (Loader $files): UiTextLoader => new UiTextLoader($files));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Lunar раз в день отправляет статистику на stats.lunarphp.io; внешние вызовы запрещены (D-20).
        Telemetry::optOut();

        // canonical, hreflang и sitemap — абсолютные адреса от APP_URL, а не от заголовка Host (§4.4).
        URL::forceRootUrl(config('app.url'));

        // Единственный администратор (§2.4, §6.1): права на запись контента и админку.
        Gate::define('admin', fn (User $user): bool => $user->is_admin === true);

        // Временные загрузки Livewire (медиа админки) — тот же предел, что у MediaFile (§6.4); по умолчанию Livewire — 12 МБ.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.config('aiqme.media_max_kb')]]);

        // Формы обращений и регистрации на обучение (W-09): общий лимит отправок с одного IP в час; превышение — 429.
        RateLimiter::for('forms', fn (Request $request): Limit => Limit::perHour(config('aiqme.forms.per_hour'))->by($request->ip()));

        // Заявки на изготовление (W-12, §3.1): своя частота по IP; корзина Lunar → заказ без адресов Lunar, через
        // валидатор заявки; номер заказа — порядковый из счётчика counters.
        RateLimiter::for('orders', fn (Request $request): Limit => Limit::perHour(config('aiqme.orders.per_hour'))->by($request->ip()));
        // Гостевая страница заказа (W-15, §6.2): подбор токена ограничен частотой по IP.
        RateLimiter::for('guest-orders', fn (Request $request): Limit => Limit::perMinute(config('aiqme.orders.guest_per_minute'))->by($request->ip()));
        config([
            'lunar.cart.validators.order_create' => [RequestCartValidator::class],
            'lunar.orders.reference_generator' => OrderNumber::class,
        ]);

        // Пароль — не короче 8 символов (§6.3); тот же minlength в формах входа, регистрации и сброса.
        Password::defaults(fn (): Password => Password::min(8));
    }

    public static function lunarPanelEnabled(string $environment, bool $flag): bool
    {
        return $flag && $environment !== 'production';
    }
}
