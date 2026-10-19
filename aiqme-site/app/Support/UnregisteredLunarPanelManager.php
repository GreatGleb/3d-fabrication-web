<?php

namespace App\Support;

use Filament\Panel;
use Lunar\Admin\LunarPanelManager;

/**
 * Менеджер панели Lunar, когда панель выключена (§2.3): Filament-панель не регистрируется,
 * поэтому маршрутов /lunar нет. Lunar после миграций создаёт базовые роли и права для guard
 * своей панели (EnsureBaseRolesAndPermissions) и без зарегистрированной панели падает;
 * здесь он получает тот же guard staff из незарегистрированного объекта Panel.
 */
class UnregisteredLunarPanelManager extends LunarPanelManager
{
    public function register(): self
    {
        return $this;
    }

    public function getPanel(): Panel
    {
        return Panel::make()->id($this->panelId)->authGuard('staff');
    }
}
