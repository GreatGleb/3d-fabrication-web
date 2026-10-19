<?php

return [

    /*
    | Штатная Filament-панель Lunar (/lunar) — инструмент разработчика (§2.3 плана).
    | Регистрируется только при AIQME_LUNAR_PANEL=true и не в production:
    | в рабочей среде маршрута /lunar нет независимо от флага.
    */
    'lunar_panel' => (bool) env('AIQME_LUNAR_PANEL', false),

];
