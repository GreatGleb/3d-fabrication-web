<?php

namespace Tests\Feature;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Исходный контент прототипа (W-04, D-11, D-19, D-21, D-32): prototype-content.json и сидер. */
class PrototypeContentSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Таблица 1 D-21 (публикуемые тексты сидера): [исходные фразы, новые фразы, остающиеся фразы].
     * Строка часов работы проверяется словами tikslinamas и to be confirmed (D-21 «Проверка»).
     *
     * Используется и проверкой страниц W-05 (PublicTextsTest).
     *
     * @var list<array{0: list<string>, 1: list<string>, 2: list<string>}>
     */
    public const D21_TABLE_1 = [
        [['Patikriname rezultatą ir paruošiame jį pristatyti arba atsiimti iš anksto apmokėjus.', 'We check the result and prepare it for delivery or prepaid collection.'],
            ['Patikriname rezultatą ir paruošiame jį pristatyti arba atsiimti.', 'We check the result and prepare it for delivery or collection.'], []],
        [['Gamybos iliustracija ·', 'Demonstracinė iliustracija', 'Manufacturing illustration ·', 'Illustrative image'], [], []],
        [['Planuojami prekių ženklai. Partnerystės ir atstovavimo statusai dar nepatvirtinti.', 'Planuojamas asortimentas. Atstovavimo statusai nepatvirtinti.',
            'Demonstraciniai AIQME informaciniai straipsniai.', 'Planned brands. Partnership and representation statuses are not yet confirmed.',
            'Planned range. Representation statuses are unconfirmed.', 'Illustrative AIQME informational articles.'], [], []],
        [['Demonstracinis vaizdas · tikros darbo aplinkos nuotraukos vieta', 'Illustrative image · placeholder for real workplace photography'], [], []],
        [['Viešai rodomos prototipo datos yra demonstracinės.', 'Public dates in this prototype are illustrative.'], [],
            ['Taip, programą ir darbo vietų paruošimą aptariame iš anksto.', 'Yes, we agree the programme and workstation requirements in advance.']],
        [['Paskyroje galima sekti užsakymus, išsaugoti modelius ir taikyti administratoriaus nustatytą nuolaidą.', 'An account supports order tracking, saved models and an administrator-configured discount.'],
            ['Paskyroje galima sekti užsakymus ir išsaugoti modelius.', 'An account supports order tracking and saved models.'], []],
        [['Taip, iš anksto apmokėjus ir suderinus atsiėmimo vietą bei laiką.', 'Yes, after prepayment and agreement on the collection location and time.'],
            ['Taip, suderinus atsiėmimo vietą bei laiką.', 'Yes, after agreement on the collection location and time.'], []],
        [['Demonstracinis prototipas. Kainos, analizė ir būsenos — pavyzdinės. Jokie duomenys nesiunčiami, tikri mokėjimai ar užsakymai nevykdomi.',
            'Demonstration prototype. Prices, analysis and statuses are illustrative. No data is sent and no real payments or orders are made.'], [], []],
        [['Darbo laikas:', 'tikslinamas', 'Opening hours:', 'to be confirmed'], [], []],
        [['Naudokite tik demonstracinius duomenis.', 'Use demonstration data only.'], [], ['* — būtini laukai.', '* — required fields.']],
        [['Failai niekur nesiunčiami. Tai pridėjimo demonstracija.', 'Files are not sent anywhere. This demonstrates attachment selection.'], [], []],
        [['Suprantu, kad tai demonstracinė užklausa ir ji nebus siunčiama.', 'I understand this is a demonstration enquiry and will not be sent.'], [], []],
        [['Peržiūrėti užklausos patvirtinimą', 'Preview enquiry confirmation'], ['Siųsti užklausą', 'Send enquiry'], []],
        [['Žmonės už sprendimų', 'Čia bus pateiktos tikros komandos nuotraukos, vardai ir atsakomybės. Duomenys nepateikti — išgalvotų darbuotojų ar sertifikatų nenaudojame.',
            'The people behind the solutions', 'Real team photographs, names and responsibilities will appear here. These have not been provided, so no fictional staff or credentials are presented.'], [], []],
    ];

    /** @return array{items: list<array<string, mixed>>} */
    private function data(): array
    {
        return json_decode((string) file_get_contents(PrototypeContentSeeder::DATA), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> Все строки значения (рекурсивно). */
    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        return is_array($value) ? array_merge([], ...array_map(fn ($v) => $this->strings($v), array_values($value))) : [];
    }

    public function test_d21_list_in_this_test_matches_table_1_of_the_plan(): void
    {
        $decisions = dirname(base_path()).'/docs/planning/edition-2/decisions.md';
        if (! is_file($decisions)) {
            $this->markTestSkipped('Нет docs/planning (образ без плана): сверка списка с таблицей D-21 пропущена.');
        }
        $text = (string) file_get_contents($decisions);
        $start = strpos($text, '**Таблица 1');
        $table = substr($text, $start, strpos($text, '**Таблица 2') - $start);
        preg_match_all('/«([^»]*)»/u', $table, $matches);
        $planned = array_values(array_unique(array_diff($matches[1], ['О нас'])));

        $ours = [];
        foreach (self::D21_TABLE_1 as [$from, $to, $kept]) {
            $ours = array_merge($ours, $from, $to, $kept);
        }
        // Остающееся первое предложение FAQ обучения таблица описывает словами, а не цитатой.
        $ours = array_values(array_diff($ours, self::D21_TABLE_1[4][2]));

        sort($planned);
        sort($ours);
        $this->assertSame($planned, $ours);
    }

    public function test_published_texts_contain_no_d21_source_phrase_and_contain_the_replacements(): void
    {
        $published = [];
        foreach ($this->data()['items'] as $item) {
            if ($item['published']) {
                $published = array_merge($published, $this->strings($item['translations']));
            }
        }
        $all = implode("\n", $published);

        foreach (self::D21_TABLE_1 as [$from, $to, $kept]) {
            foreach ($from as $phrase) {
                $this->assertStringNotContainsString($phrase, $all);
            }
            foreach (array_merge($to, $kept) as $phrase) {
                $this->assertStringContainsString($phrase, $all, 'Нет фразы в новом виде: '.$phrase);
            }
        }
        foreach (['tikslinamas', 'to be confirmed'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $all);
        }
    }

    public function test_json_holds_only_texts_without_markup_media_or_demo_catalogue(): void
    {
        $raw = (string) file_get_contents(PrototypeContentSeeder::DATA);
        $this->assertDoesNotMatchRegularExpression('/<\/?[a-z][^>]*>/i', $raw);
        $this->assertDoesNotMatchRegularExpression('/\.(jpe?g|png|webp|svg|mp4)\b|youtube|https?:/i', $raw);
        $this->assertStringNotContainsString('-demo', $raw);

        $types = array_unique(array_column($this->data()['items'], 'type'));
        sort($types);
        $this->assertSame(['article', 'category', 'faq_item', 'page_text', 'project', 'service', 'training'], $types);
        // Сидер читает только этот JSON, не evidence/ прототипа (чистый клон, образ W-20).
        $seeder = (string) file_get_contents((new \ReflectionClass(PrototypeContentSeeder::class))->getFileName());
        $this->assertDoesNotMatchRegularExpression('/evidence|dom\.html|data-from-source|base_path|\.\.\//', $seeder);
        $this->assertSame(database_path('seeders/data/prototype-content.json'), realpath(PrototypeContentSeeder::DATA));
    }

    public function test_seeder_imports_services_articles_categories_and_published_pages(): void
    {
        $this->seed(PrototypeContentSeeder::class);
        $published = app(PublishedContent::class);

        $services = ['lt' => ['3d-skenavimas', '3d-modeliavimas', '3d-spausdinimas', 'frezavimas', 'graviravimas', '3d-modeliavimo-mokymai'],
            'en' => ['3d-scanning', '3d-modelling', '3d-printing', 'cnc-machining', 'engraving', '3d-modelling-training']];
        foreach ($services as $locale => $slugs) {
            $list = $published->list(ContentType::Service, $locale);
            $this->assertSame($slugs, $list->pluck('slug')->all());
            foreach ($list as $service) {
                $this->assertNotEmpty($service->field('short'));
                $this->assertNotEmpty($service->field('label'));
                $this->assertCount(3, $service->field('benefits'));
                $this->assertCount(3, $service->field('problems'));
                $this->assertNotEmpty($service->field('provide'));
                $this->assertNotEmpty($service->field('receive'));
            }
        }
        $printing = $published->find(ContentType::Service, 'en', '3d-printing');
        $this->assertSame('ONE-OFF PARTS, PROTOTYPES & SERIES PRODUCTION', $printing->field('label'));
        $this->assertSame('Prototypes, functional components and small batches.', $printing->field('short'));

        foreach (['lt', 'en'] as $locale) {
            $articles = $published->list(ContentType::Article, $locale, ['section' => 'library']);
            $this->assertSame(['file-guide', 'material-selection', 'scan-to-cad'], $articles->pluck('slug')->all());
            $this->assertSame(['guides', 'materials', 'technology'], $articles->map(fn ($a) => $a->item->meta('category'))->all());
            $this->assertTrue($articles->every(fn ($a) => $a->published_at->isToday()));
            $this->assertTrue($published->showsPlaceholder(ContentType::Article, $locale, ['section' => 'news']));
            $this->assertSame(['scanners', 'printers', 'materials', 'accessories', 'software'],
                $published->list(ContentType::Category, $locale)->pluck('slug')->all());
            foreach (['home', 'about', 'contact', 'training'] as $page) {
                $this->assertNotNull($published->page($page, $locale), $page);
            }
            $this->assertTrue($published->showsPlaceholder(ContentType::Product, $locale));
            $this->assertTrue($published->showsPlaceholder(ContentType::Brand, $locale));
        }
        $this->assertSame(['3D scanners', 'Parts & accessories'], $published->list(ContentType::Category, 'en')->pluck('title')->only([0, 3])->values()->all());
        $this->assertSame('Programa jums arba jūsų komandai', $published->page('training', 'lt')->field('intro_title'));
        $this->assertSame('A programme for you or your team', $published->page('training', 'en')->field('intro_title'));

        // Блок команды «О нас» не импортирован (D-32).
        $about = json_encode($published->page('about', 'en')->fields, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('people behind', $about);
        $this->assertCount(3, $published->page('about', 'lt')->field('panels'));
    }

    public function test_training_policies_and_projects_are_drafts_copied_verbatim(): void
    {
        $this->seed(PrototypeContentSeeder::class);
        $published = app(PublishedContent::class);

        foreach (['lt', 'en'] as $locale) {
            $this->assertTrue($published->showsPlaceholder(ContentType::Training, $locale));
            $this->assertTrue($published->showsPlaceholder(ContentType::Project, $locale));
            foreach (['privacy', 'cookies', 'delivery', 'returns'] as $policy) {
                $this->assertNull($published->page($policy, $locale), $policy);
            }
        }
        $drafts = ContentTranslation::whereNull('published_at')->with('item')->get();
        $this->assertSame(['page_text' => 8, 'project' => 6, 'training' => 4],
            $drafts->countBy(fn ($t) => $t->type->value)->sortKeys()->all());

        // Черновики не правятся: демо-фразы остаются в тексте до правки администратором (D-21).
        $delivery = ContentItem::where('key', 'delivery')->first()->translation('en')->latestRevision();
        $this->assertStringContainsString('Collection requires prepayment', $delivery->fields['sections'][1]['text']);
        $project = ContentItem::where('key', 'part-reconstruction')->first();
        $this->assertSame(['modelling'], $project->meta('services'));
        $this->assertStringStartsWith('Illustrative result:', $project->translation('en')->latestRevision()->fields['result']);
        $topic = ContentItem::where('type', 'training')->where('key', 'topic-1')->first();
        $this->assertSame(['date' => '2026-11-12', 'format' => 'online'], $topic->meta);
        $this->assertSame('Online · group', $topic->translation('en')->latestRevision()->fields['description']);
    }

    public function test_faq_questions_are_not_repeated_between_groups(): void
    {
        $this->seed(PrototypeContentSeeder::class);
        $published = app(PublishedContent::class);

        foreach (['lt', 'en'] as $locale) {
            $faq = $published->list(ContentType::FaqItem, $locale);
            $this->assertCount(9, $faq);
            $questions = $faq->pluck('title')->all();
            $this->assertSame(array_unique($questions), $questions, 'Повтор вопроса FAQ ('.$locale.')');
            $groups = $faq->countBy(fn ($f) => $f->item->meta('group'))->all();
            $this->assertSame(['scanning' => 1, 'modelling' => 1, 'printing' => 1, 'milling' => 1, 'engraving' => 1,
                'training' => 1, 'services' => 1, 'general' => 2], $groups);
        }
        $this->assertSame(['What determines the lead time?'],
            $published->list(ContentType::FaqItem, 'en', ['group' => 'services'])->pluck('title')->all());
        $this->assertSame(['Do I need an account to order?', 'Can I collect in person?'],
            $published->list(ContentType::FaqItem, 'en', ['group' => 'general'])->pluck('title')->all());
        $this->assertSame(['Nuo ko priklauso terminas?'],
            $published->list(ContentType::FaqItem, 'lt', ['group' => 'services'])->pluck('title')->all());
    }

    public function test_repeated_run_overwrites_nothing(): void
    {
        $this->seed(PrototypeContentSeeder::class);
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        $service = ContentItem::where('type', 'service')->where('key', 'scanning')->first();
        app(ContentService::class)->saveDraft($admin, $service, 'lt', ['title' => 'Skenavimas', 'slug' => 'skenavimas'], 1, Channel::Web);
        app(ContentService::class)->publish($admin, $service, 'lt', 2, Channel::Web);
        app(ContentService::class)->unpublish($admin, ContentItem::where('key', 'home')->first(), 'en', 1, Channel::Web);
        $counts = [ContentItem::count(), ContentTranslation::count()];

        $this->seed(PrototypeContentSeeder::class);

        $this->assertSame($counts, [ContentItem::count(), ContentTranslation::count()]);
        $this->assertSame('Skenavimas', app(PublishedContent::class)->find(ContentType::Service, 'lt', 'skenavimas')?->title);
        $this->assertSame(2, $service->translation('lt')->fresh()->revision);
        $this->assertNull(app(PublishedContent::class)->page('home', 'en'));
    }
}
