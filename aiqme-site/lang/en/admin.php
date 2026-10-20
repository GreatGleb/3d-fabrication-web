<?php

// Админка (§1.4, W-08). Из прототипа: меню и названия вкладок, заголовки Overview/Services/Content & SEO/Audit history,
// колонки списка, «Add a draft +», «Edit», «Both languages», «Draft», «Content editor», подписи полей формы
// (Title, Text, SEO title, URL, SEO description, Description), «Save», «Files & photos», «Time», «Action»,
// «No actions yet.». Остальное — короткие нейтральные надписи, перечисленные в docs/progress.md (W-08).
// Подпись поля перевода выводится на языке поля: «Pavadinimas LT», «Title EN», «Title RU» — как в прототипе.
return [
    'menu' => 'Administration menu',
    'tabs' => [
        'overview' => 'Overview',
        'services' => 'Services',
        'content' => 'Content & SEO',
        'audit' => 'Audit log',
    ],
    'overview' => [
        'heading' => 'Your working day, at a glance.',
    ],
    'list' => [
        'title' => 'Title',
        'languages' => 'LT / EN',
        'add' => 'Add a draft +',
        'edit' => 'Edit',
        'both' => 'Both languages',
        'draft' => 'Draft',
    ],
    'editor' => [
        'heading' => 'Content editor',
        'save' => 'Save',
        'saved' => 'Draft saved.',
        'publish' => 'Publish :locale',
        'unpublish' => 'Unpublish :locale',
        'published_locale' => 'Published: :locale.',
        'unpublished_locale' => 'Unpublished: :locale.',
        'one_per_line' => '(one per line)',
        'state' => [
            'published' => 'Published',
            'changes' => 'Unpublished changes',
            'draft' => 'Draft',
            'none' => 'Not created',
        ],
    ],
    'fields' => [
        'title' => 'Title',
        'slug' => 'URL',
        'seo_title' => 'SEO title',
        'seo_description' => 'SEO description',
        'short' => 'Short description',
        'label' => 'Block caption',
        'benefits' => 'Block points',
        'description' => 'Description',
        'problems' => 'Problems',
        'provide' => 'What should you provide?',
        'receive' => 'What will you receive?',
    ],
    'media' => [
        'heading' => 'Files & photos',
        'collection' => 'Purpose',
        'collections' => [
            'images' => 'Image',
            'video' => 'Video',
            'video_mobile' => 'Mobile video',
            'video_poster' => 'Video poster',
        ],
        'file' => 'File (JPEG, PNG, WebP or MP4, up to :max MB)',
        'alt' => 'Alt text',
        'source' => 'Source',
        'source_url' => 'Source link',
        'upload' => 'Upload',
        'remove' => 'Remove',
        'uploaded' => 'File uploaded.',
        'removed' => 'File removed.',
        'none' => 'No files yet.',
    ],
    'audit' => [
        'heading' => 'Audit history',
        'time' => 'Time',
        'channel' => 'Channel',
        'action' => 'Action',
        'object' => 'Object',
        'details' => 'Details',
        'empty' => 'No actions yet.',
        'channels' => [
            'web' => 'Website',
            'telegram' => 'Telegram',
            'stripe' => 'Stripe',
            'system' => 'System',
        ],
        // Событие журнала (AuditLog::record) «content.published» → events.content.published.
        'events' => [
            'content' => ['published' => 'Published', 'unpublished' => 'Unpublished'],
        ],
    ],
];
