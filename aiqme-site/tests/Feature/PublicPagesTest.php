<?php

namespace Tests\Feature;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\browser\ContentFactory;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Публичные страницы W-05 (§1.2, §4.1) на исходном контенте сидера W-04: главная, услуги, «О нас», контакты, FAQ, политики.
 */
class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    /** Услуги в порядке прототипа: ключ => [slug LT, slug EN]. */
    private const SERVICES = [
        'scanning' => ['3d-skenavimas', '3d-scanning'],
        'modelling' => ['3d-modeliavimas', '3d-modelling'],
        'printing' => ['3d-spausdinimas', '3d-printing'],
        'milling' => ['frezavimas', 'cnc-machining'],
        'engraving' => ['graviravimas', 'engraving'],
        'training' => ['3d-modeliavimo-mokymai', '3d-modelling-training'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeContentSeeder::class);
    }

    /** Единственный администратор (§2.4): второй строки is_admin БД не примет. */
    private function admin(): User
    {
        if ($admin = User::where('is_admin', true)->first()) {
            return $admin;
        }
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();

        return $admin;
    }

    private function item(ContentType $type, string $key): ContentItem
    {
        return ContentItem::where('type', $type)->where('key', $key)->firstOrFail();
    }

    /** Публикует новую версию перевода с изменёнными полями (как правка в админке). */
    private function republish(ContentItem $item, string $locale, array $fields): void
    {
        $content = app(ContentService::class);
        $admin = $this->admin();
        $draft = $item->translation($locale)->latestRevision();
        $saved = $content->saveDraft($admin, $item, $locale, [
            'title' => $draft->title, 'slug' => $draft->slug, 'fields' => array_replace($draft->fields, $fields),
        ], $draft->revision, Channel::Web);
        $content->publish($admin, $item, $locale, $saved->revision, Channel::Web);
    }

    public function test_home_has_hero_scenarios_process_services_categories_and_cta_from_content_without_the_scan_scene(): void
    {
        $xpath = Html::xpath($this->get('/en')->assertOk()->getContent());

        $this->assertSame('From your idea or part to a finished solution.', Html::text($xpath->query('//section[@class="hero"]//h1')->item(0)));
        // Правка 05.11, H-01: ряда этапов «01 SCAN → 02 DESIGN → 03 MANUFACTURE» нет (было три этапа), подзаголовок — новый.
        $this->assertSame(0, $xpath->query('//div[@class="stages"]')->length);
        $this->assertSame('One partner for every stage of production', Html::text($xpath->query('//section[@class="hero"]//p')->item(0)));
        $this->assertSame('Upload files and get a quote', Html::text($xpath->query('//section[@class="hero"]//div[@class="buttons"]/a')->item(0)));
        $this->assertSame([route('en.calculator'), route('en.contact')], array_map(fn ($a) => $a->getAttribute('href'),
            iterator_to_array($xpath->query('//section[@class="hero"]//div[@class="buttons"]/a'))));
        $this->assertSame(
            [route('en.services.show', ['slug' => '3d-modelling']), route('en.calculator'), route('en.products')],
            array_map(fn ($a) => $a->getAttribute('href'), iterator_to_array($xpath->query('//div[@class="scenario"]/a'))),
        );
        // Правка 05.11, H-02: новые этапы «Darbo eiga» (было Your challenge, Agreed solution, Reviewed production, The result for you).
        $this->assertSame(['Request assessment', 'Manufacturing', 'Quality control', 'Handover'],
            Html::texts($xpath, '//section[@class="section dark"]//div[@class="process"]/div/h3'));
        $this->assertSame(array_map(fn (array $slugs) => route('en.services.show', ['slug' => $slugs[1]]), array_values(self::SERVICES)),
            array_map(fn ($a) => $a->getAttribute('href'), iterator_to_array($xpath->query('//div[@class="service-feature-actions"]/a[contains(@class, "btn")]'))));
        $this->assertSame(route('en.products', ['category' => 'scanners']), $xpath->query('//a[@class="category-row"]')->item(0)->getAttribute('href'));
        $this->assertCount(5, $xpath->query('//a[@class="category-row"]'));
        $this->assertSame('Your next step is simple.', Html::text($xpath->query('//section[@class="cta-band"]//h2')->item(0)));

        // Сцены «СКАН → CAD → ДЕТАЛЬ» и крыльчатки нет (D-40): строка этапов — только текст.
        $html = $this->get('/en')->getContent();
        foreach (['gear.js', 'scene', 'impeller', '<canvas'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    /** Правка 02.11, Г1: надпись над заголовком hero на LT литовская, EN прежняя; иконки карточек — SVG заказчика по порядку. */
    public function test_home_hero_eyebrow_is_lithuanian_on_lt_and_scenario_icons_are_the_customer_svgs(): void
    {
        // Правка 05.11, H-01: LT-надпись «3D INŽINERIJA & TECHNOLOGIJOS» (было «3D INŽINERIJA IR TECHNOLOGIJOS»).
        foreach (['/lt' => '3D INŽINERIJA & TECHNOLOGIJOS', '/en' => '3D ENGINEERING & TECHNOLOGY'] as $path => $eyebrow) {
            $xpath = Html::xpath($this->get($path)->assertOk()->getContent());
            $this->assertSame($eyebrow, Html::text($xpath->query('//section[@class="hero"]//div[@class="eyebrow"]')->item(0)), $path);
            $this->assertSame(
                [asset('icons/home/01-scan-to-gear-cube.svg'), asset('icons/home/02-file-to-gear-cube.svg'), asset('icons/home/03-gear-and-cube.svg')],
                array_map(fn ($img) => $img->getAttribute('src'), iterator_to_array($xpath->query('//div[@class="scenario"]/span[@class="scenario-icon"]/img[@alt=""]'))),
                $path,
            );
            $this->assertSame(0, $xpath->query('//span[@class="scenario-icon"]//svg')->length, "$path: прежние линейные иконки");
        }
        // Файлы — копии docs/planning/edits-20261102/icons/home/ байт в байт.
        $this->assertSame([
            '162ac36b415d96fbbcec975c198916f6b90e4f8ad54491490d50cad1dd82837d',
            'aa0d4dde2426c29bb15df8568ccfd5d8113b07df0c8d649ece65fa7b305ac864',
            'abf7f60d7f0afbcfacd4beb4b3724639113ef2ec1650b5f88f9171a11d7e814a',
        ], array_map(fn (string $name): string => hash_file('sha256', public_path("icons/home/$name.svg")),
            ['01-scan-to-gear-cube', '02-file-to-gear-cube', '03-gear-and-cube']));
    }

    /** Правка 02.11, Г1: миграция данных меняет прежний hero_eyebrow LT (опубликованный и черновик), правку администратора не трогает. */
    public function test_hero_eyebrow_migration_changes_only_the_previous_lithuanian_text(): void
    {
        $migration = require database_path('migrations/2026_11_02_000000_set_home_hero_eyebrow_lt.php');
        $home = $this->item(ContentType::PageText, 'home');
        $eyebrows = function () use ($home): array {
            $result = [];
            foreach (['lt', 'en'] as $locale) {
                $translation = $home->translation($locale)->fresh();
                $result[$locale] = [$translation->field('hero_eyebrow'), $translation->latestRevision()->fields['hero_eyebrow']];
            }

            return $result;
        };
        $old = '3D ENGINEERING & TECHNOLOGY';
        $new = '3D INŽINERIJA IR TECHNOLOGIJOS';
        // Правка 05.11, H-01: новая установка — уже текст H-01 (миграция 2026_11_05_100000), эта миграция его не трогает.
        $this->assertSame(['lt' => ['3D INŽINERIJA & TECHNOLOGIJOS', '3D INŽINERIJA & TECHNOLOGIJOS'], 'en' => [$old, $old]], $eyebrows(), 'новая установка — из prototype-content.json');
        $migration->up();
        $this->assertSame(['lt' => ['3D INŽINERIJA & TECHNOLOGIJOS', '3D INŽINERIJA & TECHNOLOGIJOS'], 'en' => [$old, $old]], $eyebrows());

        // (а) База как до правки: прежний текст в опубликованной версии и черновике LT → новый; EN не меняется; повтор ничего не меняет.
        $translation = $home->translation('lt');
        $translation->forceFill(['fields' => [...$translation->fields, 'hero_eyebrow' => $old]])->save();
        $draft = $translation->latestRevision();
        $draft->forceFill(['fields' => [...$draft->fields, 'hero_eyebrow' => $old]])->save();
        $migration->up();
        $this->assertSame(['lt' => [$new, $new], 'en' => [$old, $old]], $eyebrows());
        $migration->up();
        $this->assertSame(['lt' => [$new, $new], 'en' => [$old, $old]], $eyebrows());
        $this->get('/lt')->assertSee($new);

        // down() при новом тексте возвращает прежний.
        $migration->down();
        $this->assertSame(['lt' => [$old, $old], 'en' => [$old, $old]], $eyebrows());

        // (б) Текст администратора (опубликован) сохраняется в up() и down().
        $this->republish($home, 'lt', ['hero_eyebrow' => 'MŪSŲ TEKSTAS']);
        $migration->up();
        $this->assertSame(['MŪSŲ TEKSTAS', 'MŪSŲ TEKSTAS'], $eyebrows()['lt']);
        $migration->down();
        $this->assertSame(['MŪSŲ TEKSTAS', 'MŪSŲ TEKSTAS'], $eyebrows()['lt']);
        $this->assertSame([$old, $old], $eyebrows()['en']);

        // (в) Записи home нет — без ошибки.
        ContentItem::query()->delete();
        $migration->up();
        $this->assertSame(0, ContentItem::count());
    }

    /** Правка 05.11, H-01: тексты hero LT/EN, ряд этапов, кнопки CTA и подвал на LT; ряда этапов нет и на LT. */
    public function test_h01_hero_texts_without_stages_and_upload_files_buttons(): void
    {
        $lt = Html::xpath($this->get('/lt')->assertOk()->getContent());
        $this->assertSame('Vienas partneris visiems gamybos etapams', Html::text($lt->query('//section[@class="hero"]//p')->item(0)));
        $this->assertSame(0, $lt->query('//div[@class="stages"] | //button[@id="hero-video-toggle"]')->length);
        $this->assertSame(['Įkelkite failus ir gaukite pasiūlymą', 'Susisiekti dėl projekto'], Html::texts($lt, '//section[@class="hero"]//div[@class="buttons"]/a'));
        $this->assertSame('Įkelti failus', Html::text($lt->query('//section[@class="cta-band"]//a')->item(0)));
        $this->assertContains('Įkelti failus ↗', Html::texts($lt, '//div[@class="service-feature-actions"]/a'));
        $en = Html::xpath($this->get('/en')->assertOk()->getContent());
        $this->assertSame('Upload files', Html::text($en->query('//section[@class="cta-band"]//a')->item(0)));
        $this->assertContains('Upload files ↗', Html::texts($en, '//div[@class="service-feature-actions"]/a'));
        foreach (['SKENUOTI', 'PROJEKTUOTI', 'Sustabdyti video', 'Įkelti modelį'] as $removed) {
            $this->get('/lt')->assertDontSee($removed);
        }
    }

    /** Правка 05.11, H-01: миграция меняет прежние hero_eyebrow/hero_text и удаляет прежний hero_stages (обе версии), правку администратора не трогает, down возвращает. */
    public function test_h01_hero_migration_changes_only_previous_values(): void
    {
        $migration = require database_path('migrations/2026_11_05_100000_update_home_hero_h1.php');
        $home = $this->item(ContentType::PageText, 'home');
        $old = [
            'lt' => ['hero_eyebrow' => '3D INŽINERIJA IR TECHNOLOGIJOS', 'hero_text' => 'Skenuojame, projektuojame ir koordinuojame gamybą. Vienas partneris visam procesui — nuo pirmo klausimo iki patikrinto rezultato.', 'hero_stages' => ['SKENUOTI', 'PROJEKTUOTI', 'GAMINTI']],
            'en' => ['hero_eyebrow' => '3D ENGINEERING & TECHNOLOGY', 'hero_text' => 'Scanning, design and coordinated manufacturing. One partner for the whole process, from your first question to a checked result.', 'hero_stages' => ['SCAN', 'DESIGN', 'MANUFACTURE']],
        ];
        $new = [
            'lt' => ['hero_eyebrow' => '3D INŽINERIJA & TECHNOLOGIJOS', 'hero_text' => 'Vienas partneris visiems gamybos etapams', 'hero_stages' => null],
            'en' => ['hero_eyebrow' => '3D ENGINEERING & TECHNOLOGY', 'hero_text' => 'One partner for every stage of production', 'hero_stages' => null],
        ];
        /** Значения трёх полей в опубликованной версии и черновике. */
        $values = function (string $locale) use ($home): array {
            $translation = $home->translation($locale)->fresh();
            $pick = fn (array $fields): array => ['hero_eyebrow' => $fields['hero_eyebrow'] ?? null, 'hero_text' => $fields['hero_text'] ?? null, 'hero_stages' => $fields['hero_stages'] ?? null];

            return [$pick($translation->fields), $pick($translation->latestRevision()->fields)];
        };
        /** База как до правки: прежние значения в обеих версиях. */
        $writeOld = function (string $locale) use ($home, $old): void {
            $translation = $home->translation($locale);
            $place = function (array $fields) use ($old, $locale): array {
                $result = [];
                foreach ($fields as $name => $value) {
                    $result[$name] = $old[$locale][$name] ?? $value;
                    if ($name === 'hero_text') {
                        $result['hero_stages'] = $old[$locale]['hero_stages'];
                    }
                }

                return $result;
            };
            $translation->forceFill(['fields' => $place($translation->fields)])->save();
            $draft = $translation->latestRevision();
            $draft->forceFill(['fields' => $place($draft->fields)])->save();
        };

        foreach (['lt', 'en'] as $locale) {
            $this->assertSame([$new[$locale], $new[$locale]], $values($locale), "новая установка — из prototype-content.json: $locale");
            $writeOld($locale);
        }
        $this->assertArrayHasKey('hero_stages', $home->pageBlocks());
        $this->get('/lt')->assertSee('Skenuojame, projektuojame ir koordinuojame gamybą.');

        // (а) Применена: новые тексты, hero_stages удалено из обеих версий и из первой (поля редактора); повтор ничего не меняет.
        $migration->up();
        $migration->up();
        foreach (['lt', 'en'] as $locale) {
            $this->assertSame([$new[$locale], $new[$locale]], $values($locale), "up: $locale");
        }
        $this->assertArrayNotHasKey('hero_stages', $home->pageBlocks());
        $this->get('/lt')->assertSee('Vienas partneris visiems gamybos etapams')->assertDontSee('Skenuojame, projektuojame');

        // Откат: прежние значения в обеих версиях и поле hero_stages в первой версии.
        $migration->down();
        foreach (['lt', 'en'] as $locale) {
            $this->assertSame([$old[$locale], $old[$locale]], $values($locale), "down: $locale");
        }
        $this->assertArrayHasKey('hero_stages', $home->pageBlocks());

        // (б) Пропущена: текст и этапы администратора (опубликованы) сохраняются в up() и down(); соседний прежний текст меняется.
        $this->republish($home, 'lt', ['hero_text' => 'Mūsų tekstas.', 'hero_stages' => ['VIENAS', 'DU']]);
        $migration->up();
        $this->assertSame([['hero_eyebrow' => '3D INŽINERIJA & TECHNOLOGIJOS', 'hero_text' => 'Mūsų tekstas.', 'hero_stages' => ['VIENAS', 'DU']]], array_unique($values('lt'), SORT_REGULAR));
        $migration->down();
        $this->assertSame([['hero_eyebrow' => '3D INŽINERIJA IR TECHNOLOGIJOS', 'hero_text' => 'Mūsų tekstas.', 'hero_stages' => ['VIENAS', 'DU']]], array_unique($values('lt'), SORT_REGULAR));

        // (в) Записи home нет — без ошибки.
        ContentItem::query()->delete();
        $migration->up();
        $migration->down();
        $this->assertSame(0, ContentItem::count());
    }

    /** Правка 05.11, H-02: надписи разделов главной без «0n / », карточка 2, «Darbo eiga», раздел услуг и первый пункт 3D skenavimas. */
    public function test_h2_home_texts_and_service_photos_without_numbers(): void
    {
        $expected = [
            '/lt' => [
                'eyebrows' => ['PRADĖKIME NUO JŪSŲ', 'DARBO EIGA', 'PASLAUGOS', 'PARTNERIAI IR TECHNOLOGIJOS', 'ĮRANGA IR MEDŽIAGOS', 'NAUJIENOS IR ĮŽVALGOS'],
                'card' => 'Įkelkite failą, pasirinkite gamybos parametrus ir gaukite preliminarų pasiūlymą.',
                'process' => ['Aiškus ir nuoseklus gamybos<br>procesas.', 'AIQME koordinuoja visą procesą. Jums nereikia patiems derinti skirtingų tiekėjų.'],
                'steps' => [
                    ['Užklausos įvertinimas', 'Pagal jūsų pateiktą failą, eskizą, nuotrauką, idėją ar pačią detalę įvertiname ir suderiname gamybos procesą, medžiagą, kainą bei terminą.'],
                    ['Gamyba', 'Paruošiame skaitmeninį modelį ir pagal suderintus reikalavimus pagaminame detalę.'],
                    ['Kokybės kontrolė', 'Patikriname pagamintos detalės kokybę, matmenis ir atitiktį suderintiems techniniams reikalavimams.'],
                    ['Perdavimas', 'Pagaminta detalė perduodama užsakovui arba pristatoma nurodytu adresu.'],
                ],
                'services' => ['Skirtingos technologijos<br>jūsų užduočiai.', 'Sujungiame skaitmeninį modeliavimą ir fizinę gamybą. Pasirinkite reikiamą paslaugą arba patikėkite mums visą procesą.'],
                'scanning' => 'Objekto geometrija perkeliama į skaitmeninį 3D modelį.',
            ],
            '/en' => [
                'eyebrows' => ['START WITH YOUR NEED', 'OUR PROCESS', 'SERVICES', 'PARTNERS & TECHNOLOGY', 'EQUIPMENT & MATERIALS', 'NEWS & INSIGHTS'],
                'card' => 'Upload a file, choose the manufacturing parameters and get a preliminary quote.',
                'process' => ['A clear and consistent<br>manufacturing process.', 'AIQME coordinates the whole process. You don\'t need to coordinate different suppliers yourself.'],
                'steps' => [
                    ['Request assessment', 'Based on the file, sketch, photo, idea or the part itself, we assess and agree on the manufacturing process, material, price and lead time.'],
                    ['Manufacturing', 'We prepare the digital model and manufacture the part to the agreed requirements.'],
                    ['Quality control', 'We check the quality, dimensions and compliance of the finished part with the agreed technical requirements.'],
                    ['Handover', 'The finished part is handed over to the customer or delivered to the specified address.'],
                ],
                'services' => ['Different technologies<br>for your task.', 'We combine digital modelling and physical manufacturing. Choose the service you need or entrust us with the whole process.'],
                'scanning' => 'The object\'s geometry is transferred into a digital 3D model.',
            ],
        ];
        foreach ($expected as $path => $texts) {
            $xpath = Html::xpath($this->get($path)->assertOk()->getContent());
            $this->assertSame($texts['eyebrows'], Html::texts($xpath, '//main//section[not(@class="hero")]//div[@class="eyebrow"]'), $path);
            $this->assertSame($texts['card'], Html::text($xpath->query('//div[@class="scenario"][2]/p')->item(0)), $path);
            foreach (['process' => '//section[@class="section dark"]', 'services' => '//section[.//div[@class="service-features"]]'] as $block => $section) {
                $h2 = $xpath->query($section.'//div[@class="section-head"]//h2')->item(0);
                $this->assertSame($texts[$block][0], str_replace("\n", '', implode('', array_map(fn ($node) => $node->ownerDocument->saveHTML($node), iterator_to_array($h2->childNodes)))), "$path $block");
                $this->assertSame($texts[$block][1], Html::text($xpath->query($section.'//div[@class="section-head"]/p')->item(0)), "$path $block");
            }
            // Номера 01–04 этапов остаются.
            $this->assertSame(['01', '02', '03', '04'], Html::texts($xpath, '//div[@class="process"]/div/strong'), $path);
            $this->assertSame(array_column($texts['steps'], 0), Html::texts($xpath, '//div[@class="process"]/div/h3'), $path);
            $this->assertSame(array_column($texts['steps'], 1), Html::texts($xpath, '//div[@class="process"]/div/p'), $path);
            $this->assertSame($texts['scanning'], Html::text($xpath->query('//article[@aria-labelledby="service-heading-scanning"]//ul/li[1]/span')->item(0)), $path);
        }
        // Список услуг и страница услуги: тот же первый пункт 3D skenavimas и те же этапы.
        $services = Html::xpath($this->get('/lt/paslaugos')->assertOk()->getContent());
        $this->assertSame($expected['/lt']['scanning'], Html::text($services->query('//article[@aria-labelledby="service-heading-scanning"]//ul/li[1]/span')->item(0)));
        $services = Html::xpath($this->get('/en/services')->assertOk()->getContent());
        $this->assertSame($expected['/en']['scanning'], Html::text($services->query('//article[@aria-labelledby="service-heading-scanning"]//ul/li[1]/span')->item(0)));
        $service = Html::xpath($this->get('/lt/paslaugos/3d-skenavimas')->assertOk()->getContent());
        $this->assertSame(array_column($expected['/lt']['steps'], 0), Html::texts($service, '//div[@class="process"]/div/h3'));
    }

    /** Правка 05.11, H-02: миграция данных меняет прежние тексты (оба прежних варианта карточки 2), правку администратора не трогает, down возвращает. */
    public function test_h2_text_migration_changes_only_the_previous_texts(): void
    {
        $migration = require database_path('migrations/2026_11_05_200000_update_texts_h2.php');
        $fields = [
            [ContentType::PageText, 'home', 'lt', 'help_eyebrow', '01 / PRADĖKIME NUO JŪSŲ', 'PRADĖKIME NUO JŪSŲ'],
            [ContentType::PageText, 'home', 'en', 'news_eyebrow', '06 / NEWS & INSIGHTS', 'NEWS & INSIGHTS'],
            [ContentType::PageText, 'home', 'lt', 'scenarios.1.text', 'Įkelkite modelį, pasirinkite parametrus ir peržiūrėkite preliminarią kainą.', 'Įkelkite failą, pasirinkite gamybos parametrus ir gaukite preliminarų pasiūlymą.'],
            [ContentType::PageText, 'home', 'en', 'process_title', "One challenge.\nA clear path to the result.", "A clear and consistent\nmanufacturing process."],
            [ContentType::PageText, 'home', 'lt', 'process_steps.3.title', 'Rezultatas jums', 'Perdavimas'],
            [ContentType::PageText, 'home', 'en', 'process_steps.0.text', 'Send a file, sketch, photo or the part itself. We will advise on a suitable way to make it and agree the price and timing.', 'Based on the file, sketch, photo, idea or the part itself, we assess and agree on the manufacturing process, material, price and lead time.'],
            [ContentType::PageText, 'home', 'lt', 'services_text', 'Sujungiame skaitmeninį modelį ir fizinę gamybą. Pasirinkite vieną paslaugą arba patikėkite mums visą darbų seką.', 'Sujungiame skaitmeninį modeliavimą ir fizinę gamybą. Pasirinkite reikiamą paslaugą arba patikėkite mums visą procesą.'],
            [ContentType::Service, 'scanning', 'en', 'benefits.0', 'Capture an object’s shape and surface as a 3D model.', 'The object\'s geometry is transferred into a digital 3D model.'],
            [ContentType::Service, 'scanning', 'lt', 'benefits.0', 'Objekto forma ir paviršius perkeliami į 3D modelį.', 'Objekto geometrija perkeliama į skaitmeninį 3D modelį.'],
        ];
        /** Значения поля в опубликованной версии и черновике. */
        $values = function (ContentType $type, string $key, string $locale, string $path): array {
            $translation = $this->item($type, $key)->translation($locale)->fresh();

            return [data_get($translation->fields, $path), data_get($translation->latestRevision()->fields, $path)];
        };
        /** Записывает значение поля в обе версии напрямую (база как до правки). */
        $write = function (ContentType $type, string $key, string $locale, string $path, string $value): void {
            $translation = $this->item($type, $key)->translation($locale);
            $published = $translation->fields;
            data_set($published, $path, $value);
            $translation->forceFill(['fields' => $published])->save();
            $draft = $translation->latestRevision();
            $draftFields = $draft->fields;
            data_set($draftFields, $path, $value);
            $draft->forceFill(['fields' => $draftFields])->save();
        };

        foreach ($fields as [$type, $key, $locale, $path, $old, $new]) {
            $this->assertSame([$new, $new], $values($type, $key, $locale, $path), "новая установка — из prototype-content.json: $key $locale $path");
            $write($type, $key, $locale, $path, $old);
        }

        // (а) База как до правки: прежний текст → новый в обеих версиях; повтор ничего не меняет; down возвращает прежний.
        $migration->up();
        $migration->up();
        foreach ($fields as [$type, $key, $locale, $path, $old, $new]) {
            $this->assertSame([$new, $new], $values($type, $key, $locale, $path), "up: $key $locale $path");
        }
        $this->get('/lt')->assertSee('PRADĖKIME NUO JŪSŲ')->assertDontSee('01 / PRADĖKIME NUO JŪSŲ');
        $migration->down();
        foreach ($fields as [$type, $key, $locale, $path, $old, $new]) {
            $this->assertSame([$old, $old], $values($type, $key, $locale, $path), "down: $key $locale $path");
        }

        // (б) Текст карточки 2 до правки 02.11 (Г2) тоже заменяется; down возвращает вариант 02.11.
        $write(ContentType::PageText, 'home', 'en', 'scenarios.1.text', 'Upload your model, choose settings and review the price.');
        $migration->up();
        $this->assertSame(array_fill(0, 2, 'Upload a file, choose the manufacturing parameters and get a preliminary quote.'),
            $values(ContentType::PageText, 'home', 'en', 'scenarios.1.text'));
        $migration->down();
        $this->assertSame(array_fill(0, 2, 'Upload your model, choose settings and review a preliminary price.'),
            $values(ContentType::PageText, 'home', 'en', 'scenarios.1.text'));

        // (в) Текст администратора (опубликован через админку) сохраняется в up() и down(); соседние поля меняются.
        $this->republish($this->item(ContentType::PageText, 'home'), 'lt', ['process_eyebrow' => 'MŪSŲ EIGA']);
        $this->republish($this->item(ContentType::Service, 'scanning'), 'en', ['benefits' => ['Our benefit.', 'Two.', 'Three.']]);
        $migration->up();
        $this->assertSame(['MŪSŲ EIGA', 'MŪSŲ EIGA'], $values(ContentType::PageText, 'home', 'lt', 'process_eyebrow'));
        $this->assertSame(['Our benefit.', 'Our benefit.'], $values(ContentType::Service, 'scanning', 'en', 'benefits.0'));
        $this->assertSame(['PRADĖKIME NUO JŪSŲ', 'PRADĖKIME NUO JŪSŲ'], $values(ContentType::PageText, 'home', 'lt', 'help_eyebrow'));
        $migration->down();
        $this->assertSame(['MŪSŲ EIGA', 'MŪSŲ EIGA'], $values(ContentType::PageText, 'home', 'lt', 'process_eyebrow'));
        $this->assertSame(['Our benefit.', 'Our benefit.'], $values(ContentType::Service, 'scanning', 'en', 'benefits.0'));
        $this->assertSame(['01 / PRADĖKIME NUO JŪSŲ', '01 / PRADĖKIME NUO JŪSŲ'], $values(ContentType::PageText, 'home', 'lt', 'help_eyebrow'));

        // (г) Записей нет — без ошибки.
        ContentItem::query()->delete();
        $migration->up();
        $migration->down();
        $this->assertSame(0, ContentItem::count());
    }

    /** Правка 02.11, Г2: новые тексты главной, 3D spausdinimas и FAQ services-1 (LT и EN); блока «Vienas kontaktas» нет ни на одной услуге. */
    public function test_g2_texts_are_shown_and_service_pages_have_no_one_contact_block(): void
    {
        // Правка 05.11, H-02: текст карточки 2 главной заменён снова — проверка в test_h2_home_texts_and_service_photos_without_numbers.
        $this->get('/lt')->assertOk()->assertDontSee('peržiūrėkite kainą.');
        $this->get('/en')->assertOk()->assertDontSee('review the price.');

        $lt = Html::xpath($this->get('/lt/paslaugos/3d-spausdinimas')->assertOk()->getContent());
        $this->assertSame('Prototipai, funkcinės detalės ir detalių serijos.', Html::text($lt->query('//section[@class="page-top"]//p')->item(0)));
        $this->assertSame('Reikia vienos detalės ar detalių serijos', Html::text($lt->query('//ul[@class="list-clean"]/li')->item(1)));
        $en = Html::xpath($this->get('/en/services/3d-printing')->assertOk()->getContent());
        $this->assertSame('Prototypes, functional components and part series.', Html::text($en->query('//section[@class="page-top"]//p')->item(0)));
        $this->assertSame('A one-off component or a series of parts', Html::text($en->query('//ul[@class="list-clean"]/li')->item(1)));

        $answers = ['lt' => ['Nuo ko priklauso terminas?', 'Nuo detalės dydžio, sudėtingumo, kiekio ir pasirinktos medžiagos.'],
            'en' => ['What determines the lead time?', 'It depends on the part’s size, complexity, quantity and the chosen material.']];
        foreach (self::SERVICES as [$ltSlug, $enSlug]) {
            foreach (['lt' => '/lt/paslaugos/'.$ltSlug, 'en' => '/en/services/'.$enSlug] as $locale => $path) {
                $html = $this->get($path)->assertOk()->getContent();
                $xpath = Html::xpath($html);
                [$question, $answer] = $answers[$locale];
                $this->assertSame([$answer], Html::texts($xpath, '//details[@class="faq"][summary="'.$question.'"]/p'), $path);
                foreach (['Vienas kontaktas visam darbui.', 'Aptarti panašų projektą', 'One point of contact for the whole job.', 'Discuss a similar project'] as $removed) {
                    $this->assertStringNotContainsString($removed, $html, $path);
                }
                $this->assertStringNotContainsString('public.service.', $html, $path);
            }
        }
    }

    /** Правка 02.11, Г2: миграция данных меняет прежние тексты (опубликованные и черновик), правку администратора не трогает, down возвращает. */
    public function test_g2_text_migration_changes_only_the_previous_texts(): void
    {
        $migration = require database_path('migrations/2026_11_02_100000_update_texts_g2.php');
        $fields = [
            [ContentType::PageText, 'home', 'lt', 'scenarios.1.text', 'Įkelkite modelį, pasirinkite parametrus ir peržiūrėkite kainą.', 'Įkelkite modelį, pasirinkite parametrus ir peržiūrėkite preliminarią kainą.'],
            [ContentType::PageText, 'home', 'en', 'scenarios.1.text', 'Upload your model, choose settings and review the price.', 'Upload your model, choose settings and review a preliminary price.'],
            [ContentType::Service, 'printing', 'lt', 'short', 'Prototipai, funkcinės detalės ir mažos serijos.', 'Prototipai, funkcinės detalės ir detalių serijos.'],
            [ContentType::Service, 'printing', 'en', 'short', 'Prototypes, functional components and small batches.', 'Prototypes, functional components and part series.'],
            [ContentType::Service, 'printing', 'lt', 'problems.1', 'Reikia vienos detalės ar mažos serijos', 'Reikia vienos detalės ar detalių serijos'],
            [ContentType::Service, 'printing', 'en', 'problems.1', 'A one-off component or a small batch', 'A one-off component or a series of parts'],
            [ContentType::FaqItem, 'services-1', 'lt', 'answer', 'Nuo modelio būklės, proceso, kiekio ir konkretaus užsakymo apimties. Terminą patvirtiname pasiūlyme.', 'Nuo detalės dydžio, sudėtingumo, kiekio ir pasirinktos medžiagos.'],
            [ContentType::FaqItem, 'services-1', 'en', 'answer', 'The model condition, process, quantity and scope determine timing. We confirm the lead time in the proposal.', 'It depends on the part’s size, complexity, quantity and the chosen material.'],
        ];
        /** Значения поля в опубликованной версии и черновике. */
        $values = function (ContentType $type, string $key, string $locale, string $path): array {
            $translation = $this->item($type, $key)->translation($locale)->fresh();

            return [data_get($translation->fields, $path), data_get($translation->latestRevision()->fields, $path)];
        };
        /** Записывает значение поля в обе версии напрямую (база как до правки). */
        $write = function (ContentType $type, string $key, string $locale, string $path, string $value): void {
            $translation = $this->item($type, $key)->translation($locale);
            $published = $translation->fields;
            data_set($published, $path, $value);
            $translation->forceFill(['fields' => $published])->save();
            $draft = $translation->latestRevision();
            $draftFields = $draft->fields;
            data_set($draftFields, $path, $value);
            $draft->forceFill(['fields' => $draftFields])->save();
        };

        foreach ($fields as [$type, $key, $locale, $path, $old, $new]) {
            if ($path !== 'scenarios.1.text') { // правка 05.11, H-02: в prototype-content.json у карточки 2 уже текст H-02
                $this->assertSame([$new, $new], $values($type, $key, $locale, $path), "новая установка — из prototype-content.json: $key $locale $path");
            }
            $write($type, $key, $locale, $path, $old);
        }

        // (а) База как до правки: прежний текст → новый в обеих версиях; повтор ничего не меняет; down возвращает прежний.
        $migration->up();
        $migration->up();
        foreach ($fields as [$type, $key, $locale, $path, $old, $new]) {
            $this->assertSame([$new, $new], $values($type, $key, $locale, $path), "up: $key $locale $path");
        }
        $this->get('/lt/paslaugos/3d-spausdinimas')->assertSee('Prototipai, funkcinės detalės ir detalių serijos.');
        $migration->down();
        foreach ($fields as [$type, $key, $locale, $path, $old, $new]) {
            $this->assertSame([$old, $old], $values($type, $key, $locale, $path), "down: $key $locale $path");
        }

        // (б) Текст администратора (опубликован через админку) сохраняется в up() и down(); соседние поля не трогаются.
        $this->republish($this->item(ContentType::FaqItem, 'services-1'), 'lt', ['answer' => 'Mūsų atsakymas.']);
        $this->republish($this->item(ContentType::Service, 'printing'), 'en', ['short' => 'Our short text.']);
        $migration->up();
        $this->assertSame(['Mūsų atsakymas.', 'Mūsų atsakymas.'], $values(ContentType::FaqItem, 'services-1', 'lt', 'answer'));
        $this->assertSame(['Our short text.', 'Our short text.'], $values(ContentType::Service, 'printing', 'en', 'short'));
        $this->assertSame(['A one-off component or a series of parts', 'A one-off component or a series of parts'],
            $values(ContentType::Service, 'printing', 'en', 'problems.1'));
        $migration->down();
        $this->assertSame(['Mūsų atsakymas.', 'Mūsų atsakymas.'], $values(ContentType::FaqItem, 'services-1', 'lt', 'answer'));
        $this->assertSame(['Our short text.', 'Our short text.'], $values(ContentType::Service, 'printing', 'en', 'short'));

        // (в) Только черновик администратора: опубликованный прежний текст меняется, черновик — нет.
        $write(ContentType::PageText, 'home', 'lt', 'scenarios.1.text', $fields[0][4]);
        $draft = $this->item(ContentType::PageText, 'home')->translation('lt')->latestRevision();
        $draftFields = $draft->fields;
        data_set($draftFields, 'scenarios.1.text', 'Juodraštis.');
        $draft->forceFill(['fields' => $draftFields])->save();
        $migration->up();
        $this->assertSame([$fields[0][5], 'Juodraštis.'], $values(ContentType::PageText, 'home', 'lt', 'scenarios.1.text'));

        // (г) Записей нет — без ошибки.
        ContentItem::query()->delete();
        $migration->up();
        $migration->down();
        $this->assertSame(0, ContentItem::count());
    }

    public function test_service_cards_start_with_the_title_and_have_no_number_on_the_photo(): void
    {
        // Правка 28.10 (П4): подписи «● NN / label» над названием нет; поле label в данных остаётся.
        // Правка 05.11, H-02: номеров 01–06 в углу фото нет (было: шесть span.service-feature-number); фото на месте.
        foreach (['/en', '/lt', '/lt/paslaugos', '/en/services'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $xpath = Html::xpath($html);
            $this->assertCount(0, $xpath->query('//div[@class="service-feature-copy"]/div[@class="eyebrow"]'), $path);
            $this->assertCount(0, $xpath->query('//*['.Html::hasClass('service-feature-number').']'), $path);
            $this->assertCount(6, $xpath->query('//div['.Html::hasClass('service-feature-media').']'), $path);
            $this->assertCount(0, $xpath->query('//div['.Html::hasClass('service-feature-media').']/span'), $path);
            $first = array_map(fn ($node) => $node->nodeName, iterator_to_array($xpath->query('//div[@class="service-feature-copy"]/*[1]')));
            $this->assertSame(array_fill(0, 6, 'h3'), $first, $path);
            if (str_starts_with($path, '/lt')) {
                $this->assertStringNotContainsString('VIENETINIAI GAMINIAI, PROTOTIPAI IR SERIJINĖ GAMYBA', $html, $path);
            }
        }
    }

    public function test_home_brands_and_news_show_a_placeholder_until_published_then_a_ribbon_of_news_only(): void
    {
        $xpath = Html::xpath($this->get('/lt')->getContent());
        foreach (['partner-section', 'news-section'] as $section) {
            $this->assertSame('Informacija ruošiama', Html::text($xpath->query('//section['.Html::hasClass($section).']//div[@class="empty"]/h3')->item(0)));
        }
        $this->assertCount(0, $xpath->query('//div[@class="partner-marquee" or @class="news-marquee"]'));
        // Статьи библиотеки на главной не выводятся (D-26).
        $this->assertStringNotContainsString('Kaip paruošti failą 3D spausdinimui?', $this->get('/lt')->getContent());

        ContentFactory::as($this->admin())->homeTickers();
        foreach (['lt' => ['Prekių ženklai', 'Planuojami prekių ženklai'], 'en' => ['Technology brands', 'Planned technology brands']] as $locale => [$label, $old]) {
            $html = $this->get('/'.$locale)->getContent();
            $xpath = Html::xpath($html);
            $this->assertSame($label, $xpath->query('//div[@class="partner-marquee-group" and @role="list"]')->item(0)->getAttribute('aria-label'));
            $this->assertStringNotContainsString($old, $html);
            $this->assertSame(['NORDA', 'VEKTA'], Html::texts($xpath, '//div[@class="partner-marquee-group" and @role="list"]/div'));
            $this->assertCount(1, $xpath->query('//div[@class="partner-marquee-group" and @aria-hidden="true"]'));
            $this->assertCount(2, $xpath->query('//div[@class="news-marquee-group" and @role="list"]/a'));
            $this->assertCount(0, $xpath->query('//section['.Html::hasClass('news-section').']//div[@class="empty"]'));
        }
        $this->assertStringNotContainsString('How to prepare a file for 3D printing', $this->get('/en')->getContent());
    }

    public function test_services_list_and_each_service_by_its_own_locale_slug(): void
    {
        $xpath = Html::xpath($this->get('/lt/paslaugos')->assertOk()->getContent());
        $this->assertSame(['3D skenavimas', '3D modeliavimas ir detalių atkūrimas', '3D spausdinimas', 'Frezavimas', 'Graviravimas', '3D modeliavimo mokymai'],
            Html::texts($xpath, '//div[@class="service-features"]//h3'));

        foreach (self::SERVICES as [$lt, $en]) {
            $this->get('/lt/paslaugos/'.$lt)->assertOk();
            $this->get('/en/services/'.$en)->assertOk();
            $this->get('/en/services/'.$lt)->assertNotFound();
            $this->get('/lt/paslaugos/'.$en)->assertNotFound();
        }
    }

    public function test_service_page_uses_shared_process_its_faq_then_services_group_and_no_example_while_projects_are_drafts(): void
    {
        $this->republish($this->item(ContentType::PageText, 'home'), 'en', ['process_steps' => [
            ['title' => 'Changed step', 'text' => 'Shared process text.'],
        ]]);
        $html = $this->get('/en/services/3d-scanning')->assertOk()->getContent();
        $xpath = Html::xpath($html);

        $this->assertCount(0, $xpath->query('//section[@class="page-top"]//div[@class="eyebrow"]')); // правка 30.10, Г2
        $this->assertSame('Turn a physical part into a digital 3D model.', Html::text($xpath->query('//section[@class="page-top"]//p')->item(0)));
        $this->assertSame(['Changed step'], Html::texts($xpath, '//div[@class="section dark panel"]//h3'));
        $this->assertSame(['Is a scan immediately ready for manufacturing?', 'What determines the lead time?'], Html::texts($xpath, '//details[@class="faq"]/summary'));
        // Правка 02.11, Г2: блока «One point of contact» нет; без примера нет и div.grid.two — FAQ сразу после раздела процесса.
        $this->assertSame(0, $xpath->query('//div[@class="grid two"]')->length);
        $this->assertSame(['div.split', 'div.grid.two.gap', 'section.section', 'h2.gap', 'details.faq', 'details.faq'], array_map(
            fn ($el) => implode('.', [$el->nodeName, ...array_filter(explode(' ', $el->getAttribute('class')))]),
            iterator_to_array($xpath->query('//div[contains(@class, "page-content")]/*')),
        ));
        $this->assertStringNotContainsString('Application example', $html);
        $this->assertStringNotContainsString('project-card', $html);
        $this->assertStringNotContainsString('Illustrative image', $html);
        $this->assertSame(route('en.contact', ['service' => '3d-scanning']), $xpath->query('//div[@class="split"]//a[contains(@class, "btn")]')->item(0)->getAttribute('href'));
        $this->assertSame(route('en.calculator'), Html::xpath($this->get('/en/services/3d-printing')->getContent())
            ->query('//div[@class="split"]//a[contains(@class, "btn")]')->item(0)->getAttribute('href'));
    }

    public function test_service_pages_do_not_link_draft_projects_and_show_the_example_once_a_project_is_published(): void
    {
        foreach (self::SERVICES as [$lt, $en]) {
            $this->get('/lt/paslaugos/'.$lt)->assertDontSee('/lt/projektai/', false);
            $this->get('/en/services/'.$en)->assertDontSee('/en/projects/', false);
        }

        $project = $this->item(ContentType::Project, 'part-reconstruction');
        app(ContentService::class)->publish($this->admin(), $project, 'en', $project->translation('en')->revision, Channel::Web);

        $xpath = Html::xpath($this->get('/en/services/3d-modelling')->getContent());
        $this->assertSame('Application example', Html::text($xpath->query('//div[@class="wrap page-content"]/div[@class="section-head"]/h2')->item(0)));
        $card = $xpath->query('//div[@class="grid two"]/a[@class="card project-card"]')->item(0);
        $this->assertSame('A broken part. A new possibility.', Html::text($xpath->query('.//h3', $card)->item(0)));
        $this->assertSame('3D modelling & reverse engineering', Html::text($xpath->query('.//div[@class="card-body"]/p', $card)->item(0)));
        // Пример — только у услуг проекта; LT-перевод проекта не опубликован — на LT примера нет.
        $this->get('/en/services/3d-scanning')->assertDontSee('Application example');
        $this->get('/lt/paslaugos/3d-modeliavimas')->assertDontSee('Pritaikymo pavyzdys');
    }

    public function test_training_service_has_topics_block_with_placeholder_and_the_shared_training_intro_panel(): void
    {
        $this->republish($this->item(ContentType::PageText, 'training'), 'lt', ['intro_title' => 'Programa jūsų komandai']);
        $html = $this->get('/lt/paslaugos/3d-modeliavimo-mokymai')->assertOk()->getContent();
        $xpath = Html::xpath($html);

        // Блок тем и дат — сразу после первого div.grid.two.gap (§1.2).
        $this->assertSame('gap', $xpath->query('//div[@class="wrap page-content"]/div[@class="grid two gap"]/following-sibling::*[1]')->item(0)->getAttribute('class'));
        $topics = $xpath->query('//div[@class="wrap page-content"]/div[@class="gap"]')->item(0);
        $this->assertSame('Mokymų temos ir datos', Html::text($xpath->query('.//h2', $topics)->item(0)));
        $this->assertSame('Informacija ruošiama', Html::text($xpath->query('.//div[@class="empty"]/h3', $topics)->item(0)));
        $this->assertSame('Programa jūsų komandai', Html::text($xpath->query('.//div[@class="inset gap"]/h3', $topics)->item(0)));
        $this->assertSame(route('lt.contact'), $xpath->query('.//div[@class="inset gap"]/a', $topics)->item(0)->getAttribute('href'));
        foreach (['DEMONSTRACINIS TVARKARAŠTIS', '2026-11-12', '3D modeliavimo pradžia', 'demonstracin'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    public function test_faq_page_lists_service_groups_in_service_order_then_general_without_the_services_group(): void
    {
        $xpath = Html::xpath($this->get('/en/faq')->assertOk()->getContent());

        $this->assertSame([
            'Is a scan immediately ready for manufacturing?', 'Can we start from a photo?', 'Which process should I choose?',
            'Can you use my stock material?', 'Can you prepare the layout?', 'Can you train our team on site?',
            'Do I need an account to order?', 'Can I collect in person?',
        ], Html::texts($xpath, '//div[@class="wrap page-content article"]/details[@class="faq"]/summary'));

        $this->item(ContentType::Service, 'milling')->update(['position' => 0]);
        $this->assertSame('Can you use my stock material?', Html::texts(Html::xpath($this->get('/en/faq')->getContent()), '//details/summary')[0]);
    }

    public function test_about_has_intro_and_panels_without_team_block_or_author_note(): void
    {
        foreach (['/lt/apie-mus' => 'Žmonės už sprendimų', '/en/about' => 'The people behind the solutions'] as $path => $team) {
            $html = $this->get($path)->assertOk()->getContent();
            $xpath = Html::xpath($html);
            $this->assertCount(3, $xpath->query('//div[@class="grid three gap"]/div[@class="panel"]'));
            $this->assertCount(0, $xpath->query('//div[@class="inset gap"]'));
            $this->assertSame(asset('defaults/engineering.jpg'), $xpath->query('//div[@class="split-image"]/img')->item(0)->getAttribute('src'));
            foreach ([$team, 'Čia bus pateiktos tikros komandos', 'Real team photographs', 'išgalvotų', 'fictional', 'Demonstracinis vaizdas', 'Illustrative image'] as $absent) {
                $this->assertStringNotContainsString($absent, $html);
            }
        }
    }

    public function test_contact_page_has_details_embedded_map_with_link_and_enquiry_form_without_demo_lines(): void
    {
        // Правка 28.10 П5: спутниковая карта Google Maps (без ключа, язык страницы) сразу при открытии, ссылка — под ней.
        foreach (['/en/contact' => ['en', 'AIQME location map', 'Open map ↗'], '/lt/kontaktai' => ['lt', 'AIQME vieta žemėlapyje', 'Atidaryti žemėlapį ↗']] as $path => [$locale, $title, $link]) {
            $xpath = Html::xpath($this->get($path)->assertOk()->getContent());
            $this->assertCount(1, $xpath->query('//iframe'), $path);
            $iframe = $xpath->query('//div[@class="contact-map"]/iframe')->item(0);
            $this->assertNotNull($iframe, $path);
            $src = $iframe->getAttribute('src');
            $this->assertSame(trans('public.contact.map_embed_url', [], $locale), $src, $path);
            $this->assertStringStartsWith('https://www.google.com/maps?', $src, $path);
            $this->assertStringContainsString('output=embed', $src, $path);
            $this->assertStringContainsString('&hl='.$locale.'&', $src, $path);
            $this->assertSame($title, $iframe->getAttribute('title'), $path);
            $this->assertFalse($iframe->hasAttribute('loading'), $path);
            $this->assertFalse($iframe->hasAttribute('referrerpolicy'), $path);
            $anchor = $xpath->query('//div[@class="contact-map"]/iframe/following-sibling::a')->item(0);
            $this->assertNotNull($anchor, "$path: ссылка после карты");
            $this->assertSame($link, Html::text($anchor), $path);
            $this->assertSame('https://www.google.com/maps?q=54.641192%2C25.268099', $anchor->getAttribute('href'), $path);
        }

        $html = $this->get('/en/contact')->assertOk()->getContent();
        $xpath = Html::xpath($html);

        $this->assertSame('tel:+37068884084', $xpath->query('//ul[contains(@class, "contact-details")]//a')->item(0)->getAttribute('href'));
        $this->assertSame('mailto:info@aiqme.com', $xpath->query('//ul[contains(@class, "contact-details")]//a')->item(1)->getAttribute('href'));
        $this->assertCount(5, $xpath->query('//ul[contains(@class, "contact-details")]/li'));
        $this->assertCount(2, $xpath->query('//ul[contains(@class, "contact-details")]//a'), 'код компании и PVM — текст, не tel:');
        $this->assertSame('Open map ↗', Html::text($xpath->query('//div[@class="contact-map"]/a')->item(0)));
        $this->assertSame(['name', 'company', 'email', 'phone', 'service', 'description', 'attachments[]', 'deadline', 'website_hp'],
            array_map(fn ($field) => $field->getAttribute('name'), iterator_to_array($xpath->query('//form[@id="contact-form"]//*[@name and @name != "_token"]'))));
        $this->assertSame(['Choose a service', '3D scanning', '3D modelling & reverse engineering', '3D printing', 'CNC machining', 'Engraving', '3D modelling training', 'Equipment quotation'],
            Html::texts($xpath, '//select[@name="service"]/option'));
        $this->assertSame('Send enquiry', Html::text($xpath->query('//form[@id="contact-form"]//button[@type="submit"]')->item(0)));
        $this->assertCount(0, $xpath->query('//form[@id="contact-form"]/p'), 'form_note не выводится с 30.10 (Г4)');
        $this->assertCount(0, $xpath->query('//input[@type="checkbox"]'));
    }

    public function test_policies_show_a_placeholder_while_drafts_and_the_published_text_afterwards(): void
    {
        $html = $this->get('/en/privacy')->assertOk()->getContent();
        $this->assertSame('Information is being prepared', Html::text(Html::xpath($html)->query('//div[@class="wrap page-content article"]//h3')->item(0)));
        foreach (['Illustrative policy area', 'This is not a final set', 'Your files are not public', 'Clear this demonstration'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }

        $policy = $this->item(ContentType::PageText, 'delivery');
        $this->republish($policy, 'lt', ['intro' => 'Pristatymo sąlygos.', 'notice' => '', 'sections' => [['heading' => 'Pristatymas', 'text' => 'Siunčiame **visoje** ES.']]]);
        $xpath = Html::xpath($this->get('/lt/pristatymas')->getContent());
        $this->assertSame('Pristatymo sąlygos.', Html::text($xpath->query('//section[@class="page-top"]//p')->item(0)));
        $this->assertSame('Pristatymas', Html::text($xpath->query('//div[@class="wrap page-content article"]/h2')->item(0)));
        $this->assertSame('visoje', Html::text($xpath->query('//div[@class="wrap page-content article"]/p/strong')->item(0)));
        $this->get('/en/delivery')->assertSee('Information is being prepared');
    }
}
