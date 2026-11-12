<?php

// Тексты оболочки — из видимого текста шапки, подвала и cookie-плашки прототипа (verified-route-screens/lt/dom.html)
// и вызовов t('LT','EN') app.js. Надписи оболочки D-21 (демо-строка шапки, «Demonstracinis prototipas», noscript,
// первое предложение cookie-плашки, группа «Prototipo aplinkos») не переносятся. 'placeholder' — заглушка §4.1 (D-21).
return [
    'skip' => 'Pereiti prie turinio',
    'nav_label' => 'Pagrindinis meniu',
    'nav' => [
        'home' => 'Pagrindinis',
        'services' => 'Paslaugos',
        'products' => 'Produktai',
        'projects' => 'Projektai',
        'information' => 'Informacija',
        'about' => 'Apie mus',
        'contact' => 'Kontaktai',
    ],
    'search' => 'Paieška',
    'account' => 'Paskyra',
    'cart' => 'Krepšelis',
    'menu' => 'Atverti meniu',
    'language' => 'Kalba', // правка 11.11, J-02: имя кнопки выбора языка (второй ряд с tagline удалён)
    'quote_cta' => 'Įkelkite failus ir gaukite pasiūlymą',
    'footer' => [
        'slogan' => 'Kai tikslumas įgauna formą', // pataisa 11.11, J-01
        'solutions' => 'Sprendimai',
        'services' => 'Paslaugos',
        'products' => 'Produktai',
        'calculator' => 'Užsakymo suvestinė',
        'training' => 'Mokymai',
        'know_us' => 'Susipažinkime',
        'about' => 'Apie AIQME',
        'projects' => 'Projektai',
        'library' => 'Nemokama biblioteka',
        'contact' => 'Kontaktai',
        'delivery' => 'Pristatymas',
        'returns' => 'Grąžinimas',
        'privacy' => 'Privatumas',
        'cookies' => 'Slapukai',
    ],
    'cookie' => [
        'text' => 'Analitika ir reklamos sekimas nenaudojami. Puslapyje „Kontaktai“ rodomas „Google Maps“ žemėlapis – jį įkeliant „Google“ gali naudoti savo slapukus.',
        'accept' => 'Tik būtini',
        'settings' => 'Nustatymai',
    ],
    'close' => 'Uždaryti',
    'placeholder' => 'Informacija ruošiama',
];
