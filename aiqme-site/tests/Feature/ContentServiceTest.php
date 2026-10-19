<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditLog;
use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** ContentService (W-04, §4.2): права, публикация по локали, ревизии, заглушки, журнал, переключатель языка. */
class ContentServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContentService $content;

    private PublishedContent $published;

    protected function setUp(): void
    {
        parent::setUp();
        $this->content = app(ContentService::class);
        $this->published = app(PublishedContent::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();

        return $admin;
    }

    private function service(User $admin): ContentItem
    {
        $item = $this->content->createItem($admin, ContentType::Service, 'scanning');
        $this->content->saveDraft($admin, $item, 'lt', ['title' => '3D skenavimas', 'slug' => '3d-skenavimas',
            'fields' => ['short' => 'Tikra detalė tampa skaitmeniniu 3D modeliu.']], 0, Channel::Web);
        $this->content->saveDraft($admin, $item, 'en', ['title' => '3D scanning', 'slug' => '3d-scanning',
            'fields' => ['short' => 'Turn a physical part into a digital 3D model.']], 0, Channel::Web);

        return $item;
    }

    private function assertRejected(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Ожидался отказ по полю '.$field);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_non_admin_cannot_write_or_publish_through_the_service(): void
    {
        $admin = $this->admin();
        $item = $this->service($admin);
        $client = User::factory()->create();

        $attempts = [
            fn () => $this->content->createItem($client, ContentType::Article, 'news-1'),
            fn () => $this->content->updateItem($client, $item, ['x' => 'y'], 5),
            fn () => $this->content->saveDraft($client, $item, 'lt', ['title' => 'Kita', 'slug' => 'kita'], 1, Channel::Web),
            fn () => $this->content->publish($client, $item, 'lt', 1, Channel::Telegram),
            fn () => $this->content->unpublish($client, $item, 'lt', 1, Channel::Web),
            fn () => $this->content->restore($client, $item, 'lt', 1, 1, Channel::Web),
            fn () => $this->content->saveUiText($client, 'lt', 'shell.search', 'X', 0, Channel::Web),
            fn () => $this->content->addMedia($client, $item, __FILE__, 'images'),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Неадминистратор выполнил запись через сервис');
            } catch (AuthorizationException) {
                // ожидаемо
            }
        }

        $this->assertNull($this->published->find(ContentType::Service, 'lt', '3d-skenavimas'));
        $this->assertSame(1, $item->translations()->where('locale', 'lt')->value('revision'));
        $this->assertSame(1, ContentItem::count());
        $this->assertDatabaseCount('ui_texts', 0);
        $this->assertSame(0, Activity::count());
    }

    public function test_saving_a_draft_does_not_publish_and_publishing_lt_does_not_publish_en(): void
    {
        $admin = $this->admin();
        $item = $this->service($admin);

        $this->assertTrue($this->published->showsPlaceholder(ContentType::Service, 'lt'));
        $this->assertNull($this->published->find(ContentType::Service, 'lt', '3d-skenavimas'));

        $this->content->publish($admin, $item, 'lt', 1, Channel::Web);

        $lt = $this->published->find(ContentType::Service, 'lt', '3d-skenavimas');
        $this->assertSame('3D skenavimas', $lt?->title);
        $this->assertSame('Tikra detalė tampa skaitmeniniu 3D modeliu.', $lt->field('short'));
        $this->assertNull($this->published->find(ContentType::Service, 'en', '3d-scanning'));
        // LT-текст под EN-адресом не подставляется (В-017).
        $this->assertNull($this->published->find(ContentType::Service, 'en', '3d-skenavimas'));
        $this->assertCount(1, $this->published->list(ContentType::Service, 'lt'));
        $this->assertCount(0, $this->published->list(ContentType::Service, 'en'));
        $this->assertFalse($this->published->showsPlaceholder(ContentType::Service, 'lt'));
        $this->assertTrue($this->published->showsPlaceholder(ContentType::Service, 'en'));
    }

    public function test_draft_edit_of_a_published_translation_stays_invisible_until_published_again(): void
    {
        $admin = $this->admin();
        $item = $this->service($admin);
        $this->content->publish($admin, $item, 'lt', 1, Channel::Web);

        $this->content->saveDraft($admin, $item, 'lt', ['title' => 'Skenavimas', 'slug' => 'skenavimas'], 1, Channel::Web);

        $this->assertSame('3D skenavimas', $this->published->find(ContentType::Service, 'lt', '3d-skenavimas')?->title);
        $this->assertNull($this->published->find(ContentType::Service, 'lt', 'skenavimas'));

        $this->content->publish($admin, $item, 'lt', 2, Channel::Web);

        $this->assertNull($this->published->find(ContentType::Service, 'lt', '3d-skenavimas'));
        $this->assertSame('Skenavimas', $this->published->find(ContentType::Service, 'lt', 'skenavimas')?->title);
    }

    public function test_stale_revision_is_rejected_and_nothing_is_overwritten(): void
    {
        $admin = $this->admin();
        $item = $this->service($admin);
        // Веб и Telegram открыли версию 1; веб сохранил первым.
        $this->content->saveDraft($admin, $item, 'lt', ['title' => 'Iš žiniatinklio', 'slug' => 'is-ziniatinklio'], 1, Channel::Web);

        try {
            $this->content->saveDraft($admin, $item, 'lt', ['title' => 'Iš Telegram', 'slug' => 'is-telegram'], 1, Channel::Telegram);
            $this->fail('Устаревшая ревизия принята');
        } catch (ValidationException $e) {
            $this->assertSame([__('content.stale')], $e->errors()['revision']);
        }
        $this->assertRejected(fn () => $this->content->publish($admin, $item, 'lt', 1, Channel::Telegram), 'revision');
        $this->assertRejected(fn () => $this->content->unpublish($admin, $item, 'lt', 0, Channel::Telegram), 'revision');

        $translation = $item->translation('lt')->fresh();
        $this->assertSame(2, $translation->revision);
        $this->assertSame('Iš žiniatinklio', $translation->latestRevision()->title);
        $this->assertFalse($translation->isPublished());
    }

    public function test_restoring_an_old_revision_creates_a_new_one(): void
    {
        $admin = $this->admin();
        $item = $this->service($admin);
        $this->content->saveDraft($admin, $item, 'lt', ['title' => 'Kitas', 'slug' => 'kitas'], 1, Channel::Web);

        $translation = $this->content->restore($admin, $item, 'lt', 1, 2, Channel::Web);

        $this->assertSame(3, $translation->revision);
        $this->assertSame(['3D skenavimas', 'Kitas', '3D skenavimas'], $translation->revisions()->orderBy('revision')->pluck('title')->all());
        $this->assertSame('3d-skenavimas', $translation->latestRevision()->slug);
    }

    public function test_publication_and_unpublication_are_logged_with_their_channel(): void
    {
        $admin = $this->admin();
        $item = $this->service($admin);

        $this->content->publish($admin, $item, 'en', 1, Channel::Telegram);
        $this->content->unpublish($admin, $item, 'en', 1, Channel::Web);

        $entries = Activity::where('log_name', AuditLog::LOG_NAME)->orderBy('id')->get();
        $this->assertSame(['content.published', 'content.unpublished'], $entries->pluck('event')->all());
        $this->assertSame(['telegram', 'web'], $entries->map(fn (Activity $a) => $a->properties['channel'])->all());
        $this->assertSame(['en', 'en'], $entries->map(fn (Activity $a) => $a->properties['locale'])->all());
        $this->assertTrue($entries->every(fn (Activity $a) => $a->causer_id === $admin->id
            && $a->subject_id === $item->translation('en')->id));
        $this->assertNull($this->published->find(ContentType::Service, 'en', '3d-scanning'));
        $this->assertTrue($this->published->showsPlaceholder(ContentType::Service, 'en'));
    }

    public function test_fields_are_fixed_by_type_and_hold_only_text(): void
    {
        $admin = $this->admin();
        $item = $this->service($admin);
        $draft = fn (array $data) => fn () => $this->content->saveDraft($admin, $item, 'lt', $data + ['title' => 'T', 'slug' => 'ok'], 1, Channel::Web);

        $this->assertRejected($draft(['fields' => ['process' => 'Laisvas blokas']]), 'fields');
        $this->assertRejected($draft(['fields' => ['short' => ['a' => ['b' => ['c' => ['d']]]]]]), 'fields');
        $this->assertRejected($draft(['fields' => ['short' => 5]]), 'fields');
        $this->assertRejected($draft(['html' => '<script>alert(1)</script>']), 'fields');
        $this->assertRejected($draft(['slug' => 'Su Tarpais']), 'slug');
        $this->assertRejected($draft(['title' => '  ']), 'title');
        $this->assertRejected(fn () => $this->content->saveDraft($admin, $item, 'de', ['title' => 'T', 'slug' => 't'], 0, Channel::Web), 'locale');

        $faq = $this->content->createItem($admin, ContentType::FaqItem, 'scanning-2', ['group' => 'scanning']);
        $this->assertRejected(fn () => $this->content->saveDraft($admin, $faq, 'lt', ['title' => 'K?', 'slug' => 'k'], 0, Channel::Web), 'slug');
        $this->assertSame(1, $item->translation('lt')->fresh()->revision);
    }

    public function test_page_text_blocks_are_fixed_by_the_page(): void
    {
        $admin = $this->admin();
        $this->content->import(['type' => 'page_text', 'key' => 'about', 'position' => 0, 'published' => true, 'meta' => [],
            'translations' => ['lt' => ['title' => 'Apie', 'slug' => null, 'fields' => ['intro_title' => 'Senas']]]]);
        $page = ContentItem::where('key', 'about')->first();

        $this->content->saveDraft($admin, $page, 'en', ['title' => 'About', 'fields' => ['intro_title' => 'New']], 0, Channel::Web);
        $this->assertRejected(fn () => $this->content->saveDraft($admin, $page, 'en',
            ['title' => 'About', 'fields' => ['new_block' => 'Naujas blokas']], 1, Channel::Web), 'fields');
    }

    public function test_publishing_a_slug_used_by_another_entry_of_the_same_locale_is_rejected(): void
    {
        $admin = $this->admin();
        $first = $this->service($admin);
        $this->content->publish($admin, $first, 'lt', 1, Channel::Web);
        $second = $this->content->createItem($admin, ContentType::Service, 'other');
        $this->content->saveDraft($admin, $second, 'lt', ['title' => 'Kita', 'slug' => '3d-skenavimas'], 0, Channel::Web);

        $this->assertRejected(fn () => $this->content->publish($admin, $second, 'lt', 1, Channel::Web), 'slug');
        $this->assertFalse($second->translation('lt')->fresh()->isPublished());
    }

    public function test_section_placeholder_disappears_with_the_first_publication_on_that_locale(): void
    {
        $admin = $this->admin();
        $news = ['section' => 'news'];
        $this->assertTrue($this->published->showsPlaceholder(ContentType::Article, 'lt', $news));

        $article = $this->content->createItem($admin, ContentType::Article, 'first-news', $news);
        $this->content->saveDraft($admin, $article, 'lt', ['title' => 'Naujiena', 'slug' => 'naujiena'], 0, Channel::Web);
        $this->assertTrue($this->published->showsPlaceholder(ContentType::Article, 'lt', $news));

        $this->content->publish($admin, $article, 'lt', 1, Channel::Web);

        $this->assertFalse($this->published->showsPlaceholder(ContentType::Article, 'lt', $news));
        $this->assertTrue($this->published->showsPlaceholder(ContentType::Article, 'en', $news));
        $this->assertTrue($this->published->showsPlaceholder(ContentType::Article, 'lt', ['section' => 'library']));
        $this->assertTrue($this->published->showsPlaceholder(ContentType::Brand, 'lt'));
        $this->assertTrue($this->published->showsPlaceholder(ContentType::Product, 'lt'));
        $this->assertTrue($this->published->showsPlaceholder(ContentType::Project, 'lt'));
    }

    public function test_language_switch_leads_to_the_published_alternative_or_the_section_list(): void
    {
        // Карточки услуг и статей добавляют W-05/W-06; здесь — маршруты-заготовки с тем же именем.
        Route::get('lt/paslaugos/{slug}', fn () => 'ok')->name('lt.services.show');
        Route::get('en/services/{slug}', fn () => 'ok')->name('en.services.show');
        Route::getRoutes()->refreshNameLookups();

        $admin = $this->admin();
        $item = $this->service($admin);
        $this->content->publish($admin, $item, 'lt', 1, Channel::Web);

        // EN не опубликован — список услуг EN.
        $this->assertSame(route('en.services'), $this->published->switchUrl($item, 'en'));

        $this->content->publish($admin, $item, 'en', 1, Channel::Web);
        $this->assertSame(config('app.url').'/en/services/3d-scanning', $this->published->switchUrl($item, 'en'));
        $this->assertSame(config('app.url').'/lt/paslaugos/3d-skenavimas', $this->published->switchUrl($item, 'lt'));

        $news = $this->content->createItem($admin, ContentType::Article, 'news-1', ['section' => 'news']);
        $this->assertSame(route('en.news'), $this->published->switchUrl($news, 'en'));
        $library = $this->content->createItem($admin, ContentType::Article, 'guide', ['section' => 'library']);
        $this->assertSame(route('lt.library'), $this->published->switchUrl($library, 'lt'));
        $training = $this->content->createItem($admin, ContentType::Training, 'topic');
        $this->assertSame(route('en.training'), $this->published->switchUrl($training, 'en'));
        $page = $this->content->createItem($admin, ContentType::PageText, 'no-such-page');
        $this->assertSame(route('en.home'), $this->published->switchUrl($page, 'en'));
    }

    public function test_russian_page_text_falls_back_to_english(): void
    {
        $this->content->import(['type' => 'page_text', 'key' => 'training', 'position' => 0, 'published' => true, 'meta' => [],
            'translations' => [
                'lt' => ['title' => 'Mokymai', 'slug' => null, 'fields' => ['intro_title' => 'Programa jums']],
                'en' => ['title' => 'Training', 'slug' => null, 'fields' => ['intro_title' => 'A programme for you']],
            ]]);

        $this->assertSame('A programme for you', $this->published->page('training', 'ru')?->field('intro_title'));
        $this->assertSame('Programa jums', $this->published->page('training', 'lt')?->field('intro_title'));
        $this->assertNull($this->published->page('about', 'lt'));
        $this->assertInstanceOf(ContentTranslation::class, $this->published->page('training', 'en'));
    }
}
