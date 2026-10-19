<?php

use App\Http\Controllers\Public\ArticleController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\ProjectController;
use App\Http\Controllers\Public\ServiceController;
use App\Http\Controllers\Public\SitemapController;
use App\Http\Middleware\RestrictRussianToAdmin;
use App\Support\Seo\StaticPages;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('lt.home', status: 301));

foreach (StaticPages::LOCALES as $locale) {
    Route::prefix($locale)
        ->name($locale.'.')
        ->middleware($locale === 'ru' ? [RestrictRussianToAdmin::class] : [])
        ->group(function () use ($locale): void {
            foreach (array_keys(StaticPages::PAGES) as $page) {
                $segment = StaticPages::segment($page, $locale);
                match (true) {
                    // Страницы W-05/W-06 — из опубликованного контента; остальные — заготовки до своих задач.
                    in_array($page, ['home', 'services', 'about', 'contact', 'faq', 'training', 'search'], true) => Route::get($segment, [PageController::class, $page])->name($page),
                    in_array($page, ['information', 'news', 'library'], true) => Route::get($segment, [ArticleController::class, $page])->name($page),
                    $page === 'projects' => Route::get($segment, [ProjectController::class, 'index'])->name($page),
                    in_array($page, StaticPages::POLICIES, true) => Route::get($segment, [PageController::class, 'policy'])->defaults('page', $page)->name($page),
                    default => Route::view($segment, 'pages.static', ['page' => $page])->name($page),
                };
            }
            Route::get(StaticPages::segment('services', $locale).'/{slug}', [ServiceController::class, 'show'])->name('services.show');
            Route::get(StaticPages::segment('projects', $locale).'/{slug}', [ProjectController::class, 'show'])->name('projects.show');
            // Карточка любой статьи — только адрес библиотеки; /informacija/<slug>, /naujienos/<slug> — 404 (§4.1, §4.6).
            Route::get(StaticPages::segment('library', $locale).'/{slug}', [ArticleController::class, 'show'])->name('library.show');
        });
}

Route::get('sitemap.xml', SitemapController::class)->name('sitemap');

Route::get('robots.txt', fn () => response("User-agent: *\nDisallow:\n\nSitemap: ".route('sitemap')."\n")
    ->header('Content-Type', 'text/plain; charset=UTF-8'))->name('robots');
