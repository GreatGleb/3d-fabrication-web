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

    /*
    | Медиа прототипа по умолчанию (D-43, W-33): тип => ключ записи => коллекция => файл в public/. Сайт выводит файл,
    | пока у записи нет своего медиа этой коллекции; загрузка в админке перекрывает его, удаление возвращает.
    | Видео hero по умолчанию — один набор с подписью источника hero_video_source (только без своего video).
    */
    'media_defaults' => [
        'page_text' => [
            'home' => [
                'logo' => 'defaults/logo.jpg',
                'favicon' => 'defaults/favicon.svg',
                'video' => 'defaults/hero-engineering.mp4',
                'video_mobile' => 'defaults/hero-engineering-mobile.mp4',
                'video_poster' => 'defaults/hero-video-poster.jpg',
            ],
            'about' => ['images' => 'defaults/engineering.jpg'],
        ],
        'service' => [
            'scanning' => ['images' => 'defaults/services/scanning.png'],
            'modelling' => ['images' => 'defaults/services/modelling.png'],
            'printing' => ['images' => 'defaults/services/printing.png'],
            'milling' => ['images' => 'defaults/services/milling.png'],
            'engraving' => ['images' => 'defaults/services/engraving.png'],
            'training' => ['images' => 'defaults/services/training.png'],
        ],
        'project' => [
            'part-reconstruction' => ['images' => 'defaults/project-part-reconstruction.jpg'],
            'prototype' => ['images' => 'defaults/project-prototype.jpg'],
            'production-fixture' => ['images' => 'defaults/project-production-fixture.jpg'],
        ],
    ],

    'hero_video_source' => ['text' => 'Bosch / The Apic', 'url' => 'https://www.youtube.com/watch?v=1Nd_xg8QfXo'],

];
