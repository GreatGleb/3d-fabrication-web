<?php

namespace App\Providers;

use App\Support\UnregisteredLunarPanelManager;
use Illuminate\Support\ServiceProvider;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Lunar раз в день отправляет статистику на stats.lunarphp.io; внешние вызовы запрещены (D-20).
        Telemetry::optOut();
    }

    public static function lunarPanelEnabled(string $environment, bool $flag): bool
    {
        return $flag && $environment !== 'production';
    }
}
