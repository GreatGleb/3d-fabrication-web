<?php

// Реплики Telegram-бота контента (W-19, §4.5). Из прототипа (сценарии чата, t('LT','EN') app.js): «Pirmiausia sukurkite
// juodraštį.», «Peržiūrėti juodraštį», «Peržiūra: …», «Būsena: juodraštis. Publikavimui reikia atskiro administratoriaus
// patvirtinimo.»; кнопки публикации, состояния и «Juodraštis išsaugotas.» — из lang/admin.php. Остальное — нейтральные
// надписи, перечисленные в docs/progress.md (W-19). Демо-реплики прототипа (заказы, оплата, экспорт клиентов) не переносятся.
return [
    'menu' => 'Pasirinkite turinio tipą.',
    'types' => [
        'page_text' => 'Puslapių tekstai',
        'service' => 'Paslaugos',
        'project' => 'Projektai',
        'article' => 'Straipsniai',
        'training' => 'Mokymai',
        'product' => 'Produktai',
        'brand' => 'Prekių ženklai',
        'category' => 'Kategorijos',
        'faq_item' => 'DUK',
    ],
    'back' => '← Atgal',
    'bind_invalid' => 'Kodas neteisingas arba nebegalioja.',
    'bound' => 'Pokalbis susietas.',
    'ask_title' => 'Atsiųskite pavadinimą (:locale).',
    'ask_slug' => 'Atsiųskite URL (:locale): mažosios lotyniškos raidės, skaičiai ir brūkšneliai.',
    'ask_field' => ':field (:locale). Atsiųskite naują tekstą. Dabartinis tekstas:',
    'ask_meta' => ':field: atsiųskite reikšmę.',
    'ask_media' => 'Atsiųskite nuotrauką arba vaizdo įrašą: JPEG, PNG, WebP arba MP4, iki :max MB.',
    'add_media' => '+ :collection',
    'media_context' => 'Pirmiausia pasirinkite įrašą ir failo paskirtį.',
    'media_too_large' => 'Botas priima failus iki :max MB. Didesnius failus įkelkite administravimo svetainėje.',
    'no_draft' => 'Pirmiausia sukurkite juodraštį.',
    'preview_button' => 'Peržiūrėti juodraštį',
    'preview' => 'Peržiūra: :title',
    'preview_draft' => 'Būsena: juodraštis. Publikavimui reikia atskiro administratoriaus patvirtinimo.',
    'outdated' => 'Mygtukas pasenęs: įrašas pakeistas.',
    'stale' => 'Pakeista kitame lange. Jūsų tekstas neišsaugotas.',
    'stale_current' => 'Dabartinė versija',
    'stale_yours' => 'Jūsų tekstas',
];
