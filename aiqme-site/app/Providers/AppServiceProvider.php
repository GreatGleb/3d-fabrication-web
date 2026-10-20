<?php

namespace App\Providers;

use App\Domain\Content\UiTextLoader;
use App\Models\User;
use App\Support\UnregisteredLunarPanelManager;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Lunar\Admin\Support\Facades\LunarPanel;
use Lunar\Facades\Telemetry;

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

        // Пароль — не короче 8 символов (§6.3); тот же minlength в формах входа, регистрации и сброса.
        Password::defaults(fn (): Password => Password::min(8));
    }

    public static function lunarPanelEnabled(string $environment, bool $flag): bool
    {
        return $flag && $environment !== 'production';
    }
}
