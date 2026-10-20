<?php

namespace Tests\Feature;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\ContentTypes;
use App\Domain\Content\Models\ContentItem;
use App\Livewire\Admin\ContentEditor;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Общий редактор контента W-08 (§1.4, §4.2) на услугах и текстах страниц: создание, правка черновика, публикация и снятие
 * по языкам (видно на публичных страницах), SEO-поля, RU, конфликт ревизии без потери ввода, проверки полей.
 */
class AdminContentEditorTest extends TestCase
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

    private function item(ContentType $type, string $key): ContentItem
    {
        return ContentItem::where('type', $type)->where('key', $key)->firstOrFail();
    }

    private function editor(ContentItem $item): Testable
    {
        return Livewire::test(ContentEditor::class, ['type' => $item->type->value, 'itemId' => $item->id]);
    }

    public function test_a_new_service_is_created_as_a_draft_and_published_language_by_language(): void
    {
        $editor = Livewire::test(ContentEditor::class, ['type' => 'service'])
            ->assertSee('Content editor')->assertSee('Pavadinimas LT')->assertSee('Title EN')->assertSee('Title RU')
            ->assertDontSee('Publish LT')->assertDontSee('Files &amp; photos', false)
            ->set('form.lt.title', '3D matavimas')
            ->set('form.lt.slug', '3d-matavimas')
            ->set('form.lt.fields.short', 'Tikslūs matmenys.')
            ->set('form.lt.fields.benefits', "Pirmas punktas\n\n  Antras punktas  \nTrečias")
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Draft saved.');

        $service = $this->item(ContentType::Service, '3d-matavimas');
        $this->assertSame(7, $service->position);
        $lt = $service->translation('lt');
        $this->assertSame(1, $lt->revision);
        $this->assertFalse($lt->isPublished());
        $this->assertSame(['Pirmas punktas', 'Antras punktas', 'Trečias'], $lt->latestRevision()->fields['benefits']);
        $this->assertNull($service->translation('en'), 'пустой EN не создаётся');
        $this->assertSame($service->id, $editor->get('itemId'));
        $this->get('/lt/paslaugos/3d-matavimas')->assertNotFound();

        $editor->call('publish', 'lt')->assertHasNoErrors()->assertSee('Published: LT.');
        $this->get('/lt/paslaugos/3d-matavimas')->assertOk()->assertSee('Tikslūs matmenys.');
        $this->get('/lt/paslaugos')->assertSee('3D matavimas')->assertSee('Antras punktas');
        $this->get('/en/services')->assertDontSee('3D matavimas');

        $editor->set('form.en.title', '3D measuring')->set('form.en.slug', '3d-measuring')->set('form.en.fields.short', 'Accurate dimensions.')
            ->call('publish', 'en')->assertHasNoErrors();
        $this->get('/en/services/3d-measuring')->assertOk()->assertSee('Accurate dimensions.');
        $this->assertSame(1, $service->fresh()->translation('lt')->revision, 'публикация EN не меняет LT');

        $editor->call('unpublish', 'en')->assertSee('Unpublished: EN.');
        $this->get('/en/services/3d-measuring')->assertNotFound();
        $this->get('/lt/paslaugos/3d-matavimas')->assertOk();
    }

    public function test_editing_a_published_service_keeps_the_public_text_until_that_language_is_published(): void
    {
        $scanning = $this->item(ContentType::Service, 'scanning');
        $editor = $this->editor($scanning)
            ->assertSet('form.en.fields.short', 'Turn a physical part into a digital 3D model.')
            ->assertSet('form.en.fields.problems', "A discontinued or worn part\nA complex shape that is hard to measure\nA digital copy of a physical object")
            ->set('form.en.fields.short', 'Scan a part into an accurate model.')
            ->call('save')->assertHasNoErrors();

        $this->get('/en/services/3d-scanning')->assertOk()->assertSee('Turn a physical part into a digital 3D model.')
            ->assertDontSee('Scan a part into an accurate model.');
        $editor->assertSeeHtml('<span class="tag orange">Unpublished changes</span>');
        $this->assertSame(1, $scanning->translation('lt')->fresh()->revision, 'неизменённый LT не сохраняется');

        $editor->call('publish', 'en')->assertHasNoErrors();
        $this->get('/en/services/3d-scanning')->assertSee('Scan a part into an accurate model.');
        $this->get('/lt/paslaugos/3d-skenavimas')->assertSee('Tikra detalė tampa skaitmeniniu 3D modeliu.');
    }

    public function test_seo_fields_and_a_new_slug_take_effect_on_the_public_page(): void
    {
        $this->editor($this->item(ContentType::Service, 'milling'))
            ->set('form.en.seo_title', 'CNC milling in Vilnius')
            ->set('form.en.seo_description', 'Milled parts from your file.')
            ->set('form.en.slug', 'cnc-milling')
            ->call('publish', 'en')->assertHasNoErrors();

        $html = $this->get('/en/services/cnc-milling')->assertOk()->getContent();
        $this->assertStringContainsString('<title>CNC milling in Vilnius', $html);
        $this->assertStringContainsString('<meta name="description" content="Milled parts from your file.">', $html);
        $this->get('/en/services/cnc-machining')->assertNotFound();
    }

    public function test_slug_taken_by_another_service_is_rejected_on_publish_and_the_input_stays(): void
    {
        $this->editor($this->item(ContentType::Service, 'milling'))
            ->set('form.en.slug', '3d-scanning')
            ->call('publish', 'en')
            ->assertHasErrors(['form.en.slug'])
            ->assertSee('This address is already in use.')
            ->assertSet('form.en.slug', '3d-scanning');
        $this->get('/en/services/3d-scanning')->assertSee('3D scanning');
    }

    public function test_revision_conflict_shows_a_message_and_keeps_the_typed_text(): void
    {
        $scanning = $this->item(ContentType::Service, 'scanning');
        $editor = $this->editor($scanning)->set('form.en.fields.short', 'Typed in the browser.');

        // Тем временем тот же перевод меняет Telegram-бот (W-19) через ContentService.
        $other = ContentTypes::fromForm($scanning, ContentTypes::toForm($scanning, $scanning->translation('en')->latestRevision()->toArray()));
        $other['fields']['short'] = 'Changed in Telegram.';
        app(ContentService::class)->saveDraft($this->admin, $scanning, 'en', $other, 1, Channel::Telegram);

        $editor->call('save')
            ->assertSee('Changed in another window. Reload the page and try again.')
            ->assertSet('form.en.fields.short', 'Typed in the browser.')
            ->assertDontSee('Draft saved.');
        $latest = $scanning->translation('en')->fresh()->latestRevision();
        $this->assertSame(2, $latest->revision);
        $this->assertSame('Changed in Telegram.', $latest->fields['short']);

        // Публикация с устаревшей ревизией тоже отклоняется: опубликованное остаётся прежним.
        $editor->call('publish', 'en')->assertSee('Changed in another window.');
        $this->assertSame(1, $scanning->translation('en')->fresh()->published_revision);
        $this->get('/en/services/3d-scanning')->assertSee('Turn a physical part into a digital 3D model.');
    }

    public function test_conflict_on_one_language_saves_nothing(): void
    {
        $scanning = $this->item(ContentType::Service, 'scanning');
        $editor = $this->editor($scanning)->set('form.lt.fields.short', 'LT pakeitimas')->set('form.en.fields.short', 'EN change');
        app(ContentService::class)->unpublish($this->admin, $scanning, 'en', 1, Channel::Web);
        $other = ContentTypes::fromForm($scanning, ContentTypes::toForm($scanning, $scanning->translation('en')->latestRevision()->toArray()));
        app(ContentService::class)->saveDraft($this->admin, $scanning, 'en', $other, 1, Channel::Telegram);

        $editor->call('save')->assertSee('Changed in another window.');
        $this->assertSame(1, $scanning->translation('lt')->fresh()->revision, 'LT не сохранён при конфликте EN');
    }

    public function test_field_checks_come_from_content_types_and_keep_the_input(): void
    {
        Livewire::test(ContentEditor::class, ['type' => 'service'])
            ->set('form.lt.title', '')
            ->set('form.lt.slug', 'Bad Slug!')
            ->set('form.en.fields.short', str_repeat('x', 256))
            ->call('save')
            ->assertHasErrors(['form.lt.title', 'form.lt.slug', 'form.en.fields.short', 'form.en.title'])
            ->assertSet('form.lt.slug', 'Bad Slug!')
            ->assertSee('Fill in this field.');
        $this->assertSame(6, ContentItem::where('type', ContentType::Service)->count());

        app()->setLocale('lt');
        Livewire::test(ContentEditor::class, ['type' => 'service'])->call('save')
            ->assertHasErrors(['form.lt.title', 'form.lt.slug'])->assertSee('Užpildykite šį lauką.');
    }

    public function test_page_text_blocks_are_edited_in_place_and_published(): void
    {
        $home = $this->item(ContentType::PageText, 'home');
        $editor = $this->editor($home)
            ->assertSee('hero_title EN')
            ->assertSee('scenarios 1 · title EN')
            ->assertSet('form.en.fields.hero_stages', "SCAN\nDESIGN\nMANUFACTURE")
            ->assertDontSee('Adresas LT');
        $this->assertArrayNotHasKey('slug', $editor->get('form.en'));

        $editor->set('form.en.fields.hero_title', 'Parts made to measure')
            ->set('form.en.fields.scenarios.0.title', 'I only have a broken part.')
            ->set('form.en.fields.hero_stages', "SCAN\nMODEL\nMAKE")
            ->call('publish', 'en')->assertHasNoErrors();

        $html = $this->get('/en')->assertOk()->getContent();
        $this->assertStringContainsString('Parts made to measure', $html);
        $this->assertStringContainsString('I only have a broken part.', $html);
        $fields = $home->translation('en')->fresh()->fields;
        $this->assertSame(['SCAN', 'MODEL', 'MAKE'], $fields['hero_stages']);
        $this->assertSame(array_keys($home->pageBlocks()), array_keys($fields), 'набор блоков не меняется');
        $this->assertCount(3, $fields['scenarios']);
        $this->get('/lt')->assertDontSee('Parts made to measure');

        // Черновая политика публикуется из редактора: заглушка сменяется текстом.
        $privacy = $this->item(ContentType::PageText, 'privacy');
        $this->get('/en/privacy')->assertSee('Information is being prepared');
        $this->editor($privacy)->call('publish', 'en')->assertHasNoErrors();
        $this->get('/en/privacy')->assertDontSee('Information is being prepared');
    }

    public function test_untouched_form_round_trips_without_changes(): void
    {
        foreach ([$this->item(ContentType::Service, 'printing'), $this->item(ContentType::PageText, 'contact'), $this->item(ContentType::PageText, 'about')] as $item) {
            foreach (['lt', 'en'] as $locale) {
                $stored = $item->translation($locale)->latestRevision();
                $data = ContentTypes::fromForm($item, ContentTypes::toForm($item, $stored->toArray()));
                $this->assertEquals($stored->fields, $data['fields'], $item->key.' '.$locale);
                $this->assertSame($stored->title, $data['title']);
            }
            $this->editor($item)->call('save');
            $this->assertSame(1, $item->translation('lt')->fresh()->revision, 'сохранение без правок не создаёт версий');
        }
    }

    public function test_russian_is_edited_with_the_same_editor(): void
    {
        $scanning = $this->item(ContentType::Service, 'scanning');
        $editor = $this->editor($scanning)
            ->set('form.ru.title', '3D сканирование')->set('form.ru.slug', '3d-skanirovanie')->call('save')->assertHasNoErrors()
            ->call('publish', 'ru')->assertHasNoErrors();
        $this->assertTrue($scanning->translation('ru')->fresh()->isPublished());
        $this->get('/ru/services/3d-skanirovanie')->assertOk()->assertSee('3D сканирование');
        $editor->assertSeeHtml('data-locale="ru"');
    }

    public function test_service_fields_are_described_once(): void
    {
        $this->assertSame(array_keys(ContentTypes::SERVICE_FIELDS), ContentType::Service->fields());
        $this->assertTrue(ContentTypes::isRegistered(ContentType::Service));
        $this->assertTrue(ContentTypes::isRegistered(ContentType::PageText));
        $this->assertFalse(ContentTypes::isRegistered(ContentType::Project), 'остальные типы — W-26');

        $rules = ContentTypes::rules($this->item(ContentType::Service, 'scanning'));
        $this->assertContains('required', $rules['slug']);
        $this->assertContains('max:255', $rules['fields.short']);
        $home = ContentTypes::fields($this->item(ContentType::PageText, 'home'));
        $this->assertSame(['kind' => 'lines'], $home['hero_stages']);
        $this->assertSame(['kind' => 'pairs', 'parts' => ['title', 'text'], 'count' => 3], $home['scenarios']);
        $this->assertSame(['kind' => 'text'], $home['hero_title']);

        // Тип без описания не открывается редактором.
        $project = ContentItem::where('type', ContentType::Project)->firstOrFail();
        Livewire::test(ContentEditor::class, ['type' => 'project', 'itemId' => $project->id])->assertStatus(404);
        Livewire::test(ContentEditor::class, ['type' => 'service', 'itemId' => $project->id])->assertStatus(404);
        Livewire::test(ContentEditor::class, ['type' => 'page_text'])->assertStatus(404);
    }
}
