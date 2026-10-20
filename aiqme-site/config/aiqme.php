<?php

return [

    /*
    | Штатная Filament-панель Lunar (/lunar) — инструмент разработчика (§2.3 плана).
    | Регистрируется только при AIQME_LUNAR_PANEL=true и не в production:
    | в рабочей среде маршрута /lunar нет независимо от флага.
    */
    'lunar_panel' => (bool) env('AIQME_LUNAR_PANEL', false),

    /*
    | Медиа контента из админки и Telegram-бота (§6.4): предел файла в КБ, по умолчанию 20 МБ (как у Telegram Bot API).
    | Тот же предел задаётся временным загрузкам Livewire (livewire.temporary_file_upload.rules, AppServiceProvider).
    | В админке не редактируется.
    */
    'media_max_kb' => (int) env('AIQME_MEDIA_MAX_KB', 20480),

];
