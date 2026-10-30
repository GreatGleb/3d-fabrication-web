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
                'logo_white' => 'defaults/logo-white.png', // только пока нет своего logo
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
        // Капча ALTCHA (правка 28.10 П3): ключ подписи задач (64 hex) только в .env, пусто — задачи не выдаются и формы
        // не принимаются. Задача PBKDF2/SHA-256: cost итераций, секретный counter из диапазона, срок в секундах.
        // Выдача задач (GET /altcha) — в минуту с одного IP; через env, как AIQME_ORDERS_PER_HOUR.
        'altcha' => [
            'hmac_key' => env('ALTCHA_HMAC_KEY'),
            'cost' => 5000,
            'counter' => [5000, 10000],
            'expires' => 1800,
            'per_minute' => (int) env('AIQME_ALTCHA_PER_MINUTE', 30),
        ],
    ],

    /*
    | Калькулятор (W-27, §6.4): лимиты приёма — байт на файл и файлов в заявке; страница передаёт их в JS data-атрибутами.
    | Это не утверждённые производственные пределы; в админке не редактируются. Тарифы, заполнение по умолчанию и габарит
    | оборудования — настройки админки (таблица settings, W-11).
    */
    'calculator' => [
        'max_file_bytes' => 50 * 1024 * 1024,
        'max_files' => 10,
        // Сколько заявка ждёт очереди на разбор STL на сервере (разбор — по одному, файл в 50 МиБ ≈ 3 с).
        'analysis_wait_seconds' => 90,
    ],

    /*
    | Заявки на изготовление (W-12, §3.1): ограничение частоты отправок с одного IP (в час; превышение — 429 без записи)
    | и первый порядковый номер заказа (счётчик counters; номер выдаёт UPDATE … RETURNING). Гостевая страница заказа
    | (W-15, §6.2): запросов в минуту с одного IP (превышение — 429). В админке не редактируются.
    */
    'orders' => [
        'per_hour' => (int) env('AIQME_ORDERS_PER_HOUR', 10),
        'guest_per_minute' => 30,
        'first_number' => (int) env('AIQME_ORDERS_FIRST_NUMBER', 1),
    ],

    /*
    | Google Таблицы (W-18, §5.3): ключ сервисного аккаунта (JSON в base64) и ID таблицы — только в .env. Не заданы —
    | канал sheets не обрабатывается, события копятся pending. Ссылка на таблицу для админки — в settings (sheets_url).
    */
    'sheets' => [
        'key' => env('GOOGLE_SHEETS_KEY_BASE64'),
        'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),
    ],

    /*
    | Доверенные прокси (W-20, §6.6): адреса или подсети через запятую, не «*» — подсеть внешней сети общего прокси или,
    | когда web опубликован на 127.0.0.1:<порт>, шлюз сети Compose-проекта (docker network inspect, поле Gateway).
    | Пусто — X-Forwarded-* не принимаются. Читает App\Http\Middleware\TrustProxies в момент запроса.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'hero_video_source' => ['text' => 'Bosch / The Apic', 'url' => 'https://www.youtube.com/watch?v=1Nd_xg8QfXo'],

];
