<?php

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
                Route::view(StaticPages::segment($page, $locale), 'pages.static', ['page' => $page])->name($page);
            }
        });
}

Route::get('sitemap.xml', fn () => response()
    ->view('seo.sitemap', ['pages' => StaticPages::inSitemap()])
    ->header('Content-Type', 'application/xml; charset=UTF-8'))->name('sitemap');

Route::get('robots.txt', fn () => response("User-agent: *\nDisallow:\n\nSitemap: ".route('sitemap')."\n")
    ->header('Content-Type', 'text/plain; charset=UTF-8'))->name('robots');
