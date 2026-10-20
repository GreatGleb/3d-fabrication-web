<?php

namespace Tests\Feature;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\ContentTypes;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Content\Models\UiText;
use App\Livewire\Admin\ContentEditor;
use App\Livewire\Admin\UiTextEditor;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Редакторы W-26 на общем компоненте W-08 (§1.4, §4.1, §4.2): проекты, статьи и новости, обучение, товары, бренды,
 * категории, FAQ — создание, правка, публикация и снятие по языкам с результатом на публичных страницах; атрибуты
 * элемента (meta) из ContentTypes с проверками; строки интерфейса (ui_text) — видны в шапке без изменения кода.
 */
class AdminEditorsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeContentSeeder::class);
        $this->admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $this->admin->save();
        $this->actingAs($this->admin);
        app()->setLocale('en');
    }

    private function create(string $type): Testable
    {
        return Livewire::test(ContentEditor::class, ['type' => $type]);
    }

    private function edit(ContentItem $item): Testable
    {
        return Livewire::test(ContentEditor::class, ['type' => $item->type->value, 'itemId' => $item->id]);
    }

    private function latest(ContentType $type): ContentItem
    {
        return ContentItem::where('type', $type)->latest('id')->firstOrFail();
    }

    public function test_project_with_related_service_application_and_youtube_is_published_per_language(): void
    {
        $editor = $this->create('project')
            ->assertSee('Related services')->assertSee('YouTube URL (optional)')->assertSee('Užduotis LT')->assertSee('Task EN')
            ->set('meta.services', ['printing'])
            ->set('meta.application', 'manufacturing')
            ->set('meta.youtube', 'https://www.youtube.com/watch?v=abc')
            ->set('form.lt.title', 'Nauja detalė')->set('form.lt.slug', 'nauja-detale')->set('form.lt.fields.task', 'Užduoties tekstas.')
            ->set('form.en.title', 'A new part')->set('form.en.slug', 'a-new-part')->set('form.en.fields.task', 'Task text.')
            ->set('form.en.fields.result', 'Result text.')
            ->call('save')->assertHasNoErrors();

        $project = $this->latest(ContentType::Project);
        $this->assertSame('nauja-detale', $project->key);
        $this->assertEquals(['services' => ['printing'], 'application' => 'manufacturing', 'youtube' => 'https://www.youtube.com/watch?v=abc'], $project->meta);
        $this->get('/en/projects/a-new-part')->assertNotFound();

        $editor->call('publish', 'en')->assertHasNoErrors()->assertSee('Published: EN.');
        $this->get('/en/projects/a-new-part')->assertOk()->assertSee('Task text.')->assertSee('Result text.')
            ->assertSee('href="https://www.youtube.com/watch?v=abc"', false);
        $this->get('/en/projects?application=manufacturing')->assertSee('A new part');
        $this->get('/en/projects?application=maintenance')->assertDontSee('A new part');
        $this->get('/lt/projektai/nauja-detale')->assertNotFound();

        $editor->call('publish', 'lt')->assertHasNoErrors();
        $this->get('/lt/projektai/nauja-detale')->assertOk()->assertSee('Užduoties tekstas.');
        $editor->call('unpublish', 'en')->assertHasNoErrors();
        $this->get('/en/projects/a-new-part')->assertNotFound();
        $this->get('/lt/projektai/nauja-detale')->assertOk();
    }

    public function test_project_attributes_are_checked_and_the_input_stays(): void
    {
        $project = ContentItem::where('type', ContentType::Project)->where('key', 'part-reconstruction')->firstOrFail();
        $before = $project->meta;
        $this->edit($project)
            ->assertSet('meta.services', ['modelling'])->assertSet('meta.application', 'maintenance')
            ->set('meta.youtube', 'javascript:alert(1)')
            ->set('meta.application', 'space')
            ->set('meta.services', ['scanning', 'nope'])
            ->call('save')
            ->assertHasErrors(['meta.youtube', 'meta.application', 'meta.services.1'])
            ->assertSet('meta.youtube', 'javascript:alert(1)')
            ->set('meta.youtube', 'http://example.com/video')->set('meta.application', '')->set('meta.services', ['scanning'])
            ->call('save')->assertHasErrors(['meta.youtube'])->assertHasNoErrors(['meta.application', 'meta.services.1']);
        $this->assertSame($before, $project->fresh()->meta, 'ошибочные атрибуты не сохраняются');
    }

    public function test_news_article_appears_in_news_and_opens_at_the_library_address_only(): void
    {
        $editor = $this->create('article')
            ->assertSee('Section')->assertSee('Category')
            ->set('meta.section', 'news')->set('meta.category', 'technology')
            ->set('form.lt.title', 'Nauja naujiena')->set('form.lt.slug', 'nauja-naujiena')->set('form.lt.fields.intro', 'Įžangos tekstas.')
            ->set('form.en.title', 'Fresh news')->set('form.en.slug', 'fresh-news')->set('form.en.fields.intro', 'Intro text.')
            ->set('form.en.fields.sections.0.heading', 'First heading')->set('form.en.fields.sections.0.text', 'First text.')
            ->call('save')->assertHasNoErrors();

        $article = $this->latest(ContentType::Article);
        $this->assertEquals(['section' => 'news', 'category' => 'technology'], $article->meta);
        $this->assertEquals([['heading' => 'First heading', 'text' => 'First text.']], $article->translation('en')->latestRevision()->fields['sections']);
        // Сохранённый раздел + одна пустая запись для следующего.
        $editor->assertSet('form.en.fields.sections', [['heading' => 'First heading', 'text' => 'First text.'], ['heading' => '', 'text' => '']]);
        $this->get('/en/news')->assertDontSee('Fresh news');

        $editor->set('form.en.fields.sections.1.heading', 'Second heading')->set('form.en.fields.sections.1.text', 'Second text.')
            ->call('publish', 'en')->assertHasNoErrors();
        $this->get('/en/news')->assertOk()->assertSee('Fresh news')->assertSee('Intro text.');
        $this->get('/en')->assertSee('Fresh news');
        $this->get('/en/library')->assertDontSee('Fresh news');
        $this->get('/en/library/fresh-news')->assertOk()->assertSee('First heading')->assertSee('Second text.');
        $this->get('/en/news/fresh-news')->assertNotFound();
        $this->get('/lt/naujienos/nauja-naujiena')->assertNotFound();
        $this->get('/lt/naujienos')->assertDontSee('Nauja naujiena');

        $editor->call('publish', 'lt')->assertHasNoErrors();
        $this->get('/lt/naujienos')->assertSee('Nauja naujiena');
        $this->get('/lt/biblioteka/nauja-naujiena')->assertOk();
        $this->get('/lt/naujienos/nauja-naujiena')->assertNotFound();

        // Очищенный раздел удаляется; смена раздела применяется сразу — статья уходит в библиотеку.
        $editor->set('form.en.fields.sections.0.heading', '')->set('form.en.fields.sections.0.text', '')
            ->set('meta.section', 'library')
            ->call('publish', 'en')->assertHasNoErrors();
        $this->assertEquals([['heading' => 'Second heading', 'text' => 'Second text.']], $article->translation('en')->fresh()->fields['sections']);
        $this->get('/en/news')->assertDontSee('Fresh news');
        $this->get('/en/library')->assertSee('Fresh news');
        $this->get('/en/library/fresh-news')->assertDontSee('First heading');

        $editor->call('unpublish', 'en');
        $this->get('/en/library/fresh-news')->assertNotFound();
        $this->get('/lt/biblioteka/nauja-naujiena')->assertOk();
    }

    public function test_training_topic_with_date_and_format_is_listed_only_when_published(): void
    {
        $this->get('/en/training')->assertSee('Information is being prepared');
        $editor = $this->create('training')
            ->assertSee('Training format')->assertSee('At the customer site')->assertDontSee('URL EN')->assertDontSee('SEO title EN')
            ->set('meta.date', '2027-02-03')->set('meta.format', 'company')
            ->set('form.en.title', 'Reverse engineering basics')->set('form.en.fields.description', 'Two days at your site.')
            ->call('save')->assertHasErrors(['form.lt.title']);
        $editor->set('form.lt.title', 'Atvirkštinės inžinerijos pagrindai')->call('save')->assertHasNoErrors();

        $training = $this->latest(ContentType::Training);
        $this->assertNull($training->key, 'у обучения нет slug и ключа');
        $this->assertEquals(['date' => '2027-02-03', 'format' => 'company'], $training->meta);
        $this->get('/en/training')->assertDontSee('Reverse engineering basics');

        $editor->call('publish', 'en')->assertHasNoErrors();
        $this->get('/en/training')->assertOk()->assertSee('Reverse engineering basics')->assertSee('2027-02-03')->assertSee('Two days at your site.');
        $this->get('/lt/mokymai')->assertDontSee('Atvirkštinės inžinerijos pagrindai');

        $editor->set('meta.date', '03.02.2027')->call('save')->assertHasErrors(['meta.date']);
        $editor->set('meta.date', '2027-03-04')->call('save')->assertHasNoErrors();
        $this->get('/en/training')->assertSee('2027-03-04');

        $editor->call('unpublish', 'en');
        $this->get('/en/training')->assertDontSee('Reverse engineering basics');
    }

    public function test_category_brand_and_product_are_created_and_shown_in_the_catalogue(): void
    {
        $this->get('/en/products')->assertSee('Information is being prepared');

        $this->create('category')
            ->set('form.lt.title', 'Skaitytuvai')->set('form.lt.slug', 'skaitytuvai')
            ->set('form.en.title', 'Readers')->set('form.en.slug', 'readers')
            ->call('save')->assertHasNoErrors()->call('publish', 'lt')->call('publish', 'en')->assertHasNoErrors();
        $category = $this->latest(ContentType::Category);
        $this->assertSame('skaitytuvai', $category->key);

        $this->create('brand')
            ->assertDontSee('URL EN')
            ->set('form.lt.title', 'NORDA')->set('form.en.title', 'NORDA')
            ->call('save')->assertHasNoErrors()->call('publish', 'en')->assertHasNoErrors();
        $brand = $this->latest(ContentType::Brand);
        $this->get('/en')->assertSee('NORDA');

        $product = $this->create('product')
            ->assertSee('Manufacturer')->assertSee('Readers')->assertSee('Features &amp; specifications', false)
            ->set('meta.category', 'skaitytuvai')->set('meta.brand', (string) $brand->id)
            ->set('form.lt.title', 'NORDA R1')->set('form.lt.slug', 'norda-r1')
            ->set('form.en.title', 'NORDA R1')->set('form.en.slug', 'norda-r1')
            ->set('form.en.fields.description', 'A compact reader.')->set('form.en.fields.compatibility', 'Works with NORDA CAD')
            ->call('save')->assertHasNoErrors();
        $item = $this->latest(ContentType::Product);
        $this->assertEquals(['category' => 'skaitytuvai', 'brand' => $brand->id], $item->meta);

        $product->call('publish', 'en')->assertHasNoErrors();
        $this->get('/en/products')->assertOk()->assertSee('NORDA R1')->assertDontSee('Information is being prepared');
        $this->get('/en/products?category=readers')->assertSee('NORDA R1');
        $this->get('/en/products?brand='.$brand->id)->assertSee('NORDA R1');
        $this->get('/en/products/norda-r1')->assertOk()->assertSee('A compact reader.')->assertSee('Works with NORDA CAD')->assertSee('Readers');
        $this->get('/lt/produktai/norda-r1')->assertNotFound();

        // Чужой бренд или категория в форме — ошибка, атрибуты не меняются.
        $product->set('meta.brand', '999999')->set('meta.category', 'unknown')->call('save')->assertHasErrors(['meta.brand', 'meta.category']);
        $this->assertEquals(['category' => 'skaitytuvai', 'brand' => $brand->id], $item->fresh()->meta);

        // Пока в форме ошибочные атрибуты, снятие с публикации не выполняется (сначала сохраняется изменённое).
        $product->call('unpublish', 'en')->assertHasErrors(['meta.brand']);
        $this->get('/en/products/norda-r1')->assertOk();
        $product->set('meta.brand', (string) $brand->id)->set('meta.category', 'skaitytuvai')->call('unpublish', 'en')->assertHasNoErrors();
        $this->get('/en/products/norda-r1')->assertNotFound();
    }

    public function test_faq_question_goes_to_its_group_page(): void
    {
        $editor = $this->create('faq_item')
            ->assertSee('FAQ group')->assertSee('3D scanning')->assertSee('General questions')
            ->set('meta.group', 'general')
            ->set('form.lt.title', 'Ar dirbate savaitgaliais?')->set('form.en.title', 'Do you work at weekends?')
            ->set('form.en.fields.answer', 'By arrangement.')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(['group' => 'general'], $this->latest(ContentType::FaqItem)->meta);
        $this->get('/en/faq')->assertDontSee('Do you work at weekends?');

        $editor->call('publish', 'en')->assertHasNoErrors();
        $this->get('/en/faq')->assertSee('Do you work at weekends?')->assertSee('By arrangement.');
        $this->get('/en/services/3d-scanning')->assertDontSee('Do you work at weekends?');

        $editor->set('meta.group', 'scanning')->call('save')->assertHasNoErrors();
        $this->get('/en/services/3d-scanning')->assertSee('Do you work at weekends?');
        $editor->set('meta.group', 'nope')->call('save')->assertHasErrors(['meta.group']);
    }

    public function test_every_content_type_is_described_in_content_types(): void
    {
        foreach (ContentType::cases() as $type) {
            $this->assertTrue(ContentTypes::isRegistered($type), $type->value);
            if ($type !== ContentType::PageText) {
                $item = new ContentItem(['type' => $type]);
                $this->assertSame($type->fields(), array_keys(ContentTypes::fields($item)), $type->value);
            }
        }
        $this->assertSame(['kind' => 'records', 'parts' => ['heading', 'text']], ContentTypes::fields(new ContentItem(['type' => ContentType::Article]))['sections']);
        $this->assertSame(['images' => false], ContentTypes::media(new ContentItem(['type' => ContentType::Project])));
        $this->assertSame(['images' => true], ContentTypes::media(new ContentItem(['type' => ContentType::Brand])));
        $this->assertSame([], ContentTypes::media(new ContentItem(['type' => ContentType::Training])));
        $this->assertSame(['meta.date', 'meta.format'], array_keys(ContentTypes::metaRules(new ContentItem(['type' => ContentType::Training]))));
    }

    public function test_project_photos_are_several_and_a_brand_logo_is_one(): void
    {
        $project = ContentItem::where('type', ContentType::Project)->firstOrFail();
        $editor = $this->edit($project)->assertSee('Files &amp; photos', false);
        foreach ([1, 2] as $index) {
            $editor->set('upload', UploadedFile::fake()->createWithContent("photo$index.png", $this->png()))
                ->set('alt.en', "Photo $index")->call('uploadMedia')->assertHasNoErrors();
        }
        $this->assertCount(2, $project->fresh()->getMedia('images'));

        $brand = $this->create('brand')->set('form.lt.title', 'VEKTA')->call('save')->assertHasNoErrors();
        foreach ([1, 2] as $index) {
            $brand->set('upload', UploadedFile::fake()->createWithContent("logo$index.png", $this->png()))
                ->call('uploadMedia')->assertHasNoErrors();
        }
        $this->assertCount(1, $this->latest(ContentType::Brand)->getMedia('images'), 'новый логотип заменяет прежний');
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_ui_text_edit_is_visible_in_the_header_without_code_changes(): void
    {
        $this->get('/en')->assertSee('/en/services" >Services</a>', false);

        $editor = Livewire::test(UiTextEditor::class, ['textKey' => 'shell.nav.services'])
            ->assertSet('values.lt', 'Paslaugos')->assertSet('values.en', 'Services')->assertSet('values.ru', 'Services')
            ->set('values.en', 'Our services')
            ->call('save')->assertHasNoErrors()->assertSee('Saved.');

        $this->get('/en')->assertSee('/en/services" >Our services</a>', false);
        $this->get('/lt')->assertSee('/lt/paslaugos" >Paslaugos</a>', false);
        $this->assertSame(1, UiText::count(), 'неизменённые языки не записываются');
        $this->get('/en/admin/translations?group=shell')->assertSee('<td>Our services</td>', false);

        // Пустое поле возвращает текст файла.
        $editor->set('values.en', '')->call('save')->assertHasNoErrors()->assertSet('values.en', 'Services');
        $this->assertSame(0, UiText::count());
        $this->get('/en')->assertSee('/en/services" >Services</a>', false);
    }

    public function test_ui_text_conflict_keeps_the_input_and_saves_nothing(): void
    {
        $editor = Livewire::test(UiTextEditor::class, ['textKey' => 'shell.footer.services'])
            ->set('values.lt', 'Mūsų paslaugos')->set('values.en', 'Our services');
        // Та же строка изменена в другом окне (или ботом) после открытия редактора.
        app(ContentService::class)->saveUiText($this->admin, 'en', 'shell.footer.services', 'From Telegram', 0, Channel::Telegram);

        $editor->call('save')->assertSee('Changed in another window.')->assertSet('values.en', 'Our services')->assertSet('values.lt', 'Mūsų paslaugos');
        $this->assertSame('From Telegram', UiText::where('locale', 'en')->value('value'));
        $this->assertNull(UiText::where('locale', 'lt')->first(), 'LT не сохранён при конфликте EN');
    }

    public function test_ui_text_editor_is_admin_only_and_limited_to_the_fixed_key_set(): void
    {
        Livewire::test(UiTextEditor::class, ['textKey' => 'admin.menu'])->assertStatus(404);
        Livewire::test(UiTextEditor::class, ['textKey' => 'shell.nope'])->assertStatus(404);

        $this->actingAs(User::factory()->create());
        Livewire::test(UiTextEditor::class, ['textKey' => 'shell.nav.services'])->assertStatus(404);
        $this->get('/en/admin/translations')->assertNotFound();
    }

    public function test_tabs_list_their_types_and_open_the_shared_editor(): void
    {
        $products = $this->get('/en/admin/products')->assertOk()->getContent();
        $this->assertStringContainsString('<h2>Products</h2>', $products);
        $this->assertStringContainsString('href="'.route('en.admin.products', ['new' => 'product']).'#content-editor">Add a product +</a>', $products);
        $this->assertStringContainsString('<h3>Brands</h3>', $products);
        $this->assertStringContainsString('href="'.route('en.admin.products', ['new' => 'brand']).'#content-editor">Add a draft +</a>', $products);
        $scanners = ContentItem::where('type', ContentType::Category)->where('key', 'scanners')->firstOrFail();
        $this->assertStringContainsString('<span>3D scanners</span>', $products);
        $this->assertStringContainsString('href="'.route('en.admin.categories', ['edit' => $scanners->id]).'#content-editor">Edit ↗</a>', $products);

        $categories = $this->get('/en/admin/categories?edit='.$scanners->id)->assertOk()->getContent();
        $this->assertStringContainsString('id="content-edit-form" data-type="category"', $categories);
        $this->assertMatchesRegularExpression('~<a class="active" href="[^"]*/en/admin/products" >~', $categories);

        $this->get('/en/admin/products?new=product')->assertSee('id="content-edit-form" data-type="product"', false);
        $this->get('/en/admin/products?new=page_text')->assertDontSee('id="content-edit-form"', false);
        $this->get('/en/admin/projects')->assertSee('A broken part. A new possibility.')->assertSee('<h2>Projects</h2>', false);
        $this->get('/en/admin/training')->assertSee('Getting started with 3D modelling')->assertSee('<h2>Training</h2>', false);
        $faq = ContentItem::where('type', ContentType::FaqItem)->firstOrFail();
        $this->get('/en/admin/services?edit='.$faq->id)->assertSee('id="content-edit-form" data-type="faq_item"', false);
        $this->get('/en/admin/projects?edit='.$faq->id)->assertNotFound();

        $translations = $this->get('/lt/administravimas/translations?group=public')->assertOk()->getContent();
        $this->assertStringContainsString('<h2>LT / EN turinys</h2>', $translations);
        $this->assertStringContainsString('<td>public.find_solution</td>', $translations);
        $this->assertStringNotContainsString('<td>admin.', $translations);
        $this->get('/en/admin/translations?edit=shell.nav.services')->assertSee('id="ui-text-form"', false);
    }
}
