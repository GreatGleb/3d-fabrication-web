<?php

// Реплики Telegram-бота контента (W-19, §4.5). Из прототипа (сценарии чата, t('LT','EN') app.js): «Create a draft first.»,
// «Preview draft», «Preview: …», «Status: draft. Publication requires a separate administrator confirmation.»; кнопки
// публикации, состояния и «Draft saved.» — из lang/admin.php. Остальное — нейтральные надписи, перечисленные
// в docs/progress.md (W-19). Демо-реплики прототипа (заказы, оплата, экспорт клиентов) не переносятся (§4.5, D-21).
return [
    'menu' => 'Choose a content type.',
    'types' => [
        'page_text' => 'Page texts',
        'service' => 'Services',
        'project' => 'Projects',
        'article' => 'Articles',
        'training' => 'Training',
        'product' => 'Products',
        'brand' => 'Brands',
        'category' => 'Categories',
        'faq_item' => 'FAQ',
    ],
    'back' => '← Back',
    'bind_invalid' => 'The code is invalid or has expired.',
    'bound' => 'Chat linked.',
    'ask_title' => 'Send the title (:locale).',
    'ask_slug' => 'Send the URL (:locale): lowercase Latin letters, digits and hyphens.',
    'ask_field' => ':field (:locale). Send the new text. Current text:',
    'ask_meta' => ':field: send the value.',
    'ask_media' => 'Send a photo or video: JPEG, PNG, WebP or MP4, up to :max MB.',
    'add_media' => '+ :collection',
    'media_context' => 'Choose a record and the file purpose first.',
    'media_too_large' => 'The bot accepts files up to :max MB. Upload larger files in the web administration.',
    'no_draft' => 'Create a draft first.',
    'preview_button' => 'Preview draft',
    'preview' => 'Preview: :title',
    'preview_draft' => 'Status: draft. Publication requires a separate administrator confirmation.',
    'outdated' => 'This button is outdated: the record has changed.',
    'stale' => 'Changed in another window. Your text was not saved.',
    'stale_current' => 'Current version',
    'stale_yours' => 'Your text',
];
