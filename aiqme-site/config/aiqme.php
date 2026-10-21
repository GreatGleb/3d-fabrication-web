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

    /*
    | Формы обращений и регистрации на обучение (W-09, §6.4): получатель письма о новой заявке (клиенту писем нет, §5.1),
    | ограничение частоты отправок с одного IP (обе формы вместе, в час) и вложения контактной формы — до 5 файлов
    | по 15 МиБ, расширения прототипа. Это не утверждённые производственные пределы; в админке не редактируются.
    */
    'forms' => [
        'mail_to' => 'info@aiqme.com',
        'per_hour' => (int) env('AIQME_FORMS_PER_HOUR', 10),
        'attachments_max_files' => 5,
        'attachments_max_kb' => 15360,
        'attachments_extensions' => ['stl', 'step', 'stp', 'obj', 'pdf', 'jpg', 'jpeg', 'png'],
    ],

    /*
    | Калькулятор (W-27): примерные тарифы D-09 (не подтверждены: материалы и плотности г/см³, одна цена за грамм
    | десятичной строкой, плата за заказ и за файл в центах, заполнение по умолчанию, %), габарит оборудования D-24 (мм)
    | и лимиты приёма §6.4 (байт на файл, файлов в заявке). До W-11 страница передаёт их в JS через data-атрибуты;
    | W-11 заменяет тарифы и габарит настройками админки.
    */
    'calculator' => [
        'materials' => ['PLA' => 1.24, 'PETG' => 1.27, 'ABS' => 1.04],
        'rate_per_gram' => '0.10',
        'order_fee_cents' => 500,
        'file_fee_cents' => 0,
        'default_infill' => 20,
        'max_dimension_mm' => 250,
        'max_file_bytes' => 25 * 1024 * 1024,
        'max_files' => 10,
    ],

    'hero_video_source' => ['text' => 'Bosch / The Apic', 'url' => 'https://www.youtube.com/watch?v=1Nd_xg8QfXo'],

];
