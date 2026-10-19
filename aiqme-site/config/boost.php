<?php

/*
| PHP AIQME — только через bin/php (расширения и лимиты, tasks.json rules). По умолчанию пути
| абсолютные: так их получает MCP-сервер Boost, запущенный из любого каталога. bin/boost-install
| задаёт относительные пути для текста правил (AGENTS.md, CLAUDE.md в Git) и затем пишет
| абсолютные пути в локальные .mcp.json и .codex/config.toml. Остальные настройки — из vendor.
*/

return [
    'executable_paths' => [
        'php' => env('BOOST_PHP_EXECUTABLE_PATH', base_path('bin/php')),
        'composer' => env('BOOST_COMPOSER_EXECUTABLE_PATH', base_path('bin/php').' /usr/local/bin/composer'),
        'npm' => env('BOOST_NPM_EXECUTABLE_PATH'),
        'vendor_bin' => env('BOOST_VENDOR_BIN_EXECUTABLE_PATH', base_path('bin/php').' '.base_path('vendor/bin').'/'),
        'current_directory' => env('BOOST_CURRENT_DIRECTORY_EXECUTABLE_PATH', base_path()),
    ],
];
