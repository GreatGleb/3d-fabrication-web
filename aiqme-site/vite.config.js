import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Без fonts: bunny() — сборка скачивала бы шрифт с fonts.bunny.net (вне реестров D-20).
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            // experiments/ — эталон, не часть приложения (§2.1); build/ — временные файлы.
            ignored: ['**/storage/framework/views/**', '**/experiments/**', '**/build/**', '**/demo/**'],
        },
    },
});
