<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Public\ArticleController;
use App\Http\Controllers\Public\PageController;
use App\Http\Controllers\Public\ProductController;
use App\Http\Controllers\Public\ProjectController;
use App\Http\Controllers\Public\ServiceController;
use App\Http\Controllers\Public\SitemapController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\RestrictRussianToAdmin;
use App\Support\Seo\StaticPages;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('lt.home', status: 301));

foreach (StaticPages::LOCALES as $locale) {
    Route::prefix($locale)
        ->name($locale.'.')
        ->middleware($locale === 'ru' ? ['auth.session', RestrictRussianToAdmin::class, 'cache.headers:private;no_store'] : [])
        ->group(function () use ($locale): void {
            foreach (array_keys(StaticPages::PAGES) as $page) {
                $segment = StaticPages::segment($page, $locale);
                match (true) {
                    // Страницы W-05/W-06/W-25 — из опубликованного контента; остальные — заготовки до своих задач.
                    in_array($page, ['home', 'services', 'about', 'contact', 'faq', 'training', 'search'], true) => Route::get($segment, [PageController::class, $page])->name($page),
                    in_array($page, ['information', 'news', 'library'], true) => Route::get($segment, [ArticleController::class, $page])->name($page),
                    $page === 'projects' => Route::get($segment, [ProjectController::class, 'index'])->name($page),
                    $page === 'products' => Route::get($segment, [ProductController::class, 'index'])->name($page),
                    in_array($page, ['compare', 'cart'], true) => Route::get($segment, [ProductController::class, $page])->name($page),
                    in_array($page, StaticPages::POLICIES, true) => Route::get($segment, [PageController::class, 'policy'])->defaults('page', $page)->name($page),
                    default => Route::view($segment, 'pages.static', ['page' => $page])->name($page),
                };
            }
            Route::get(StaticPages::segment('services', $locale).'/{slug}', [ServiceController::class, 'show'])->name('services.show');
            Route::get(StaticPages::segment('projects', $locale).'/{slug}', [ProjectController::class, 'show'])->name('projects.show');
            Route::get(StaticPages::segment('products', $locale).'/{slug}', [ProductController::class, 'show'])->name('products.show');
            // Карточка любой статьи — только адрес библиотеки; /informacija/<slug>, /naujienos/<slug> — 404 (§4.1, §4.6).
            Route::get(StaticPages::segment('library', $locale).'/{slug}', [ArticleController::class, 'show'])->name('library.show');
        });
}

// Вход, регистрация, восстановление и кабинет (W-07, §1.3): /lt/paskyra/… · /en/account/…; админка (§1.4, §6.1):
// /lt/administravimas/ · /en/admin/ — 404 всем, кроме вошедшего администратора, вход — через форму кабинета.
// auth.session (AuthenticateSession) завершает прежние сессии после смены пароля сбросом (§6.3).
foreach (StaticPages::PUBLIC_LOCALES as $locale) {
    Route::prefix($locale.'/'.($locale === 'lt' ? 'paskyra' : 'account'))
        ->middleware('cache.headers:private;no_store')
        ->group(function () use ($locale): void {
            $name = $locale.'.account';
            Route::get('login', [LoginController::class, 'create'])->name($name.'.login');
            Route::post('login', [LoginController::class, 'store'])->name($name.'.login.store');
            Route::get('register', [RegisterController::class, 'create'])->name($name.'.register');
            Route::post('register', [RegisterController::class, 'store'])->name($name.'.register.store');
            Route::get('reset', [PasswordResetController::class, 'create'])->name($name.'.reset');
            Route::post('reset', [PasswordResetController::class, 'store'])->name($name.'.reset.store');
            Route::get('reset/{token}', [PasswordResetController::class, 'edit'])->name($name.'.reset.token');
            Route::post('reset/{token}', [PasswordResetController::class, 'update'])->name($name.'.reset.update');

            Route::middleware('auth.session')->group(function () use ($name): void {
                Route::get('/', [AccountController::class, 'addresses'])->name($name);
                Route::get('addresses', [AccountController::class, 'addresses'])->name($name.'.addresses');
                Route::post('addresses', [AccountController::class, 'updateAddresses'])->middleware('auth')->name($name.'.addresses.update');
                Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name($name.'.logout');
            });
        });

    Route::prefix($locale.'/'.($locale === 'lt' ? 'administravimas' : 'admin'))
        ->middleware(['auth.session', EnsureAdmin::class, 'cache.headers:private;no_store'])
        ->group(function () use ($locale): void {
            // Вкладки W-08; остальные вкладки §1.4 добавляют свои задачи (до них — 404).
            Route::get('/', [AdminController::class, 'overview'])->name($locale.'.admin');
            foreach (array_keys(AdminController::TABS) as $tab) {
                Route::get($tab, [AdminController::class, $tab])->name($locale.'.admin.'.$tab);
            }
        });
}

Route::get('sitemap.xml', SitemapController::class)->name('sitemap');

Route::get('robots.txt', fn () => response("User-agent: *\nDisallow:\n\nSitemap: ".route('sitemap')."\n")
    ->header('Content-Type', 'text/plain; charset=UTF-8'))->name('robots');
