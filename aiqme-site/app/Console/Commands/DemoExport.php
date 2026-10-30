<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\AdminController;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PrototypeContentSeeder;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Статическая демо-сборка для GitHub Pages (W-24, §1.6, §8.7): те же Blade-шаблоны на демо-данных в aiqme-site/demo/.
 * Отказ при APP_ENV=production (до любых действий) и если после создания aiqme_demo и переключения на неё активное
 * подключение смотрит в другую БД (до DemoSeeder и Auth::login). БД aiqme_demo создаётся перед сборкой и удаляется после;
 * рабочая БД не меняется. Страницы рендерит HTTP-ядро в процессе: публичные LT/EN со статусом 200 по §4.6
 * (tests/fixtures/prototype-routes.json), кабинет — от имени демо-клиента, админка — от имени демо-администратора
 * (Auth::login здесь — единственное исключение из запрета обходов входа, HTTP-маршрута нет). Постобработка: базовый путь
 * Pages, баннер «DEMO» и noindex, без Livewire и wire:*, без CSRF и серверных action — формы, кнопки действий и ссылки на
 * неэкспортированные адреса получают data-demo-form (сообщение показывает demo.js), виджет капчи ALTCHA удаляется.
 * Счетов в демо нет.
 */
#[Signature('aiqme:demo-export')]
#[Description('Собрать статическую демо-версию сайта для GitHub Pages в aiqme-site/demo/')]
class DemoExport extends Command
{
    public const BASE = '/3d-fabrication-web/aiqme-site/demo';

    /** Хост запросов в процессе; в выводе не остаётся. */
    private const HOST = 'http://aiqme-demo.invalid';

    /** Вкладки кабинета §4.6 без счетов (§8.7). */
    private const ACCOUNT_TABS = ['', 'overview', 'orders', 'models', 'addresses'];

    /** Строки §4.6 публичных адресов: 2 — 200, 6 — 200 после публикации (в демо — если опубликован), 11 — вход. */
    private const PUBLIC_ROWS = [2 => true, 6 => false, 11 => true];

    public function handle(Kernel $kernel): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('aiqme:demo-export не работает при APP_ENV=production.');

            return self::FAILURE;
        }

        $connection = (string) config('database.default');
        $original = config("database.connections.{$connection}.database");
        DB::statement('DROP DATABASE IF EXISTS '.DemoSeeder::DATABASE.' WITH (FORCE)');
        DB::statement('CREATE DATABASE '.DemoSeeder::DATABASE);
        config(["database.connections.{$connection}.database" => DemoSeeder::DATABASE]);
        DB::purge($connection);

        try {
            $active = DB::scalar('select current_database()');
            if ($active !== DemoSeeder::DATABASE) {
                $this->error('Подключение смотрит в БД «'.$active.'», а не в '.DemoSeeder::DATABASE.' (DB_URL?): сборка остановлена.');

                return self::FAILURE;
            }

            return $this->export($kernel);
        } finally {
            Auth::forgetGuards();
            DB::purge($connection);
            config(["database.connections.{$connection}.database" => $original]);
            DB::statement('DROP DATABASE IF EXISTS '.DemoSeeder::DATABASE.' WITH (FORCE)');
            File::deleteDirectory(base_path('build/demo-storage'));
        }
    }

    private function export(Kernel $kernel): int
    {
        // Демо-режим (§8.7): блоки оплаты и счетов скрыты; ни писем, ни очередей, ни внешних запросов; файлы — в build/.
        $this->laravel['env'] = 'demo';
        config([
            'app.env' => 'demo', 'cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array',
            'queue.default' => 'null', 'queue.connections.null' => ['driver' => 'null'],
            'filesystems.disks.local.root' => base_path('build/demo-storage/private'),
            'filesystems.disks.public.root' => base_path('build/demo-storage/public'),
        ]);
        Http::preventStrayRequests();

        foreach ([['migrate', ['--force' => true]], ['aiqme:bootstrap', []],
            ['db:seed', ['--class' => PrototypeContentSeeder::class, '--force' => true]],
            ['db:seed', ['--class' => DemoSeeder::class, '--force' => true]]] as [$command, $arguments]) {
            if ($this->callSilently($command, $arguments) !== self::SUCCESS) {
                throw new RuntimeException("{$command} завершилась с ошибкой.");
            }
        }

        URL::forceRootUrl(self::HOST.self::BASE);
        $pages = [];
        foreach ($this->publicPaths() as $path => $required) {
            $response = $this->render($kernel, $path);
            if ($response === null && $required) {
                throw new RuntimeException("{$path}: ожидался ответ 200.");
            }
            if ($response !== null) {
                $pages[$path] = $response;
            }
        }
        $signedIn = [
            DemoSeeder::CLIENT_EMAIL => ['lt/paskyra' => self::ACCOUNT_TABS, 'en/account' => self::ACCOUNT_TABS],
            DemoSeeder::ADMIN_EMAIL => array_fill_keys(['lt/administravimas', 'en/admin'],
                ['', ...array_diff(array_keys(AdminController::TABS), AdminController::NOT_IN_DEMO), ...array_keys(AdminController::HIDDEN_TABS)]),
        ];
        foreach ($signedIn as $email => $sections) {
            Auth::login(User::where('email', $email)->sole());
            foreach ($sections as $prefix => $tabs) {
                foreach ($tabs as $tab) {
                    $path = rtrim('/'.$prefix.'/'.$tab, '/');
                    $pages[$path] = $this->render($kernel, $path) ?? throw new RuntimeException("{$path}: ожидался ответ 200.");
                }
            }
        }

        $output = base_path('build/demo-export');
        File::deleteDirectory($output);
        foreach ($pages as $path => $html) {
            File::ensureDirectoryExists($output.$path);
            File::put($output.$path.'/index.html', $this->postprocess($html, $path, array_keys($pages)));
        }
        File::put($output.'/index.html', $this->redirectPage());
        $this->copyStatic($output);

        $demo = base_path('demo');
        File::deleteDirectory($demo);
        File::moveDirectory($output, $demo);
        $this->info('Демо-сборка: '.count($pages).' страниц в demo/ (базовый путь '.self::BASE.'/).');

        return self::SUCCESS;
    }

    /**
     * Публичные адреса §4.6 без завершающего «/» => обязателен ли ответ 200.
     *
     * @return array<string, bool>
     */
    private function publicPaths(): array
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/prototype-routes.json')), true, flags: JSON_THROW_ON_ERROR);
        $paths = [];
        foreach ($fixture['routes'] as $route) {
            // ?category=… — тот же статический список (§8.7), отдельного файла нет.
            if (array_key_exists($route['row'], self::PUBLIC_ROWS) && ! str_contains($route['path'], '?')) {
                $paths[rtrim($route['path'], '/')] = self::PUBLIC_ROWS[$route['row']];
            }
        }

        return $paths;
    }

    private function render(Kernel $kernel, string $path): ?string
    {
        $request = Request::create(self::HOST.$path);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        return (string) $response->getContent();
    }

    /** @param  list<string>  $exported */
    private function postprocess(string $html, string $path, array $exported): string
    {
        // Комментарии (в том числе «Livewire Styles/Scripts») в демо не нужны.
        $html = preg_replace('/<!--.*?-->/s', '', str_replace(self::HOST.self::BASE, self::BASE, $html));
        if (str_contains($html, self::HOST)) {
            throw new RuntimeException("{$path}: адрес без базового пути в выводе.");
        }
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $locale = $document->documentElement->getAttribute('lang') ?: 'lt';
        $private = preg_match('#^/(lt|en)/(paskyra|account|administravimas|admin)(/|$)#', $path) === 1;

        foreach ($document->querySelectorAll('script, style') as $node) {
            if (stripos($node->getAttribute('src') ?? '', 'livewire') !== false || preg_match('/livewire|wire:/i', $node->textContent)) {
                $node->remove();
            }
        }
        foreach ($document->querySelectorAll('meta[name="csrf-token"], input[name="_token"], input[name="submission_token"]') as $node) {
            $node->remove();
        }
        foreach ($document->querySelectorAll('*') as $element) {
            // Одноразовые nonce CSP на статических страницах не нужны и меняли бы каждую пересборку.
            $element->removeAttribute('nonce');
            $action = false;
            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (str_starts_with($attribute->name, 'wire:')) {
                    $action = $action || preg_match('/^wire:(click|submit|change)/', $attribute->name) === 1;
                    $element->removeAttribute($attribute->name);
                }
            }
            if ($action) {
                $element->setAttribute('data-demo-form', '');
            }
        }
        foreach ($document->querySelectorAll('form') as $form) {
            $this->demoForm($form, $locale);
        }
        $document->getElementById('quote')?->setAttribute('data-demo-form', '');
        foreach ($document->querySelectorAll('a[href]') as $link) {
            $href = $link->getAttribute('href');
            $target = parse_url($href, PHP_URL_PATH);
            if (! is_string($target) || ! str_starts_with($href, self::BASE.'/')) {
                continue;
            }
            $target = rtrim(substr($target, strlen(self::BASE)), '/');
            $static = preg_match('#^/(build|defaults)/#', $target) === 1;
            if ((! $static && ! in_array($target, $exported, true)) || ($private && str_contains($href, '?'))) {
                $link->setAttribute('data-demo-form', '');
            }
        }

        $robots = $document->querySelector('meta[name="robots"]');
        if ($robots === null) {
            $robots = $document->createElement('meta');
            $robots->setAttribute('name', 'robots');
            $document->head->append($robots);
        }
        $robots->setAttribute('content', 'noindex, nofollow');
        $script = $document->createElement('script');
        $script->setAttribute('src', self::BASE.'/demo.js');
        $script->setAttribute('defer', '');
        $document->head->append($script);
        $banner = HTMLDocument::createFromString('<!DOCTYPE html><body>'.$this->banner($locale), LIBXML_NOERROR)->body->firstElementChild;
        $document->body->prepend($document->importNode($banner, true));

        $result = $document->saveHtml();
        if (preg_match('/livewire|wire:/i', $result, $match)) {
            throw new RuntimeException("{$path}: в выводе осталось «{$match[0]}».");
        }

        return $result;
    }

    /**
     * Серверные формы и поиск — data-demo-form без action (§8.7); GET-фильтры списков остаются: ?category=… отдаёт тот
     * же статический список. Вход ведёт в демо-кабинет. Капча ALTCHA (правка 28.10 П3) из форм удаляется: в демо нет
     * сервера, выдающего задачи.
     */
    private function demoForm(Element $form, string $locale): void
    {
        foreach ($form->querySelectorAll('altcha-widget') as $widget) {
            $widget->remove();
        }
        $method = strtolower($form->getAttribute('method') ?? 'get');
        $search = $form->hasAttribute('data-live-search');
        if ($method === 'get' && ! $search && $form->getAttribute('action')) {
            return;
        }
        if (str_ends_with((string) $form->getAttribute('action'), '/login')) {
            $form->setAttribute('data-demo-redirect', self::BASE.'/'.$locale.'/'.($locale === 'lt' ? 'paskyra' : 'account').'/');
        }
        $form->removeAttribute('action');
        $form->removeAttribute('data-live-search');
        $form->setAttribute('data-demo-form', '');
    }

    private function banner(string $locale): string
    {
        $text = fn (string $key): string => e(__('demo.'.$key, locale: $locale));

        return '<div data-demo-banner data-message="'.$text('message').'" role="note" style="position:relative;z-index:1000;'
            .'padding:8px 16px;background:#ffd34d;color:#0a1119;font:600 14px/1.4 system-ui,sans-serif;text-align:center">'
            .'<b>'.$text('badge').'</b> · '.$text('notice').'</div>';
    }

    private function redirectPage(): string
    {
        $target = self::BASE.'/lt/';

        return '<!DOCTYPE html><html lang="lt"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow">'
            .'<meta http-equiv="refresh" content="0; url='.$target.'"><title>AIQME</title></head><body>'.$this->banner('lt')
            .'<p><a href="'.$target.'">AIQME</a></p></body></html>';
    }

    /** Ресурсы Vite (путь Web Worker — под базовым путём), медиа по умолчанию D-43, favicon и demo.js. */
    private function copyStatic(string $output): void
    {
        if (! is_file(public_path('build/manifest.json')) || is_file(public_path('hot'))) {
            throw new RuntimeException('Нет собранных ресурсов Vite (npm run build) или запущен dev-сервер (public/hot).');
        }
        File::copyDirectory(public_path('build'), $output.'/build');
        foreach (File::glob($output.'/build/assets/*.js') as $file) {
            File::put($file, str_replace('/build/assets/', self::BASE.'/build/assets/', File::get($file)));
        }
        File::copyDirectory(public_path('defaults'), $output.'/defaults');
        File::copy(public_path('favicon.ico'), $output.'/favicon.ico');
        File::copy(resource_path('js/demo.js'), $output.'/demo.js');
    }
}
