<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditLog;
use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\ContentTypes;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Forms\Models\ContactRequest;
use App\Domain\Integrations\Telegram\Models\TelegramBinding;
use App\Domain\Integrations\Telegram\Models\TelegramDialog;
use App\Livewire\Admin\Integrations;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Telegram-бот контента (W-19, §4.5, §6.5, §9.2 «Telegram») на фикстурах апдейтов и Http::fake (запросов к Telegram нет):
 * webhook вне группы web с secret_token, привязка кодом с экрана Integrations, чужие чаты и пользователи, дедупликация
 * update_id, диалог правки всех типов ContentTypes через ContentService (видно в вебе и наоборот), устаревшие кнопки
 * и конфликт ревизии, медиа по сигнатуре и лимиту, вырезание токена из ошибок, команды webhook и poll.
 */
class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-bot-token-not-real';

    private const SECRET = 'test-webhook-secret-not-real';

    private const ADMIN_TG = 5001;

    private User $admin;

    private int $updateId = 1000;

    /** @var array{file_path: string, file_size: int} */
    private array $fileInfo = ['file_path' => 'photos/file_1.jpg', 'file_size' => 100];

    private string $fileBody = '';

    /** Сбой Bot API в фейке: null, connection (сеть) или unauthorized (401). */
    private ?string $apiFailure = null;

    /** @var list<array<string, mixed>> ответ getUpdates */
    private array $pollUpdates = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.telegram.token' => self::TOKEN, 'services.telegram.webhook_secret' => self::SECRET]);
        $this->seed(PrototypeContentSeeder::class);
        $this->admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $this->admin->save();
        $this->fakeTelegram();
    }

    private function fakeTelegram(): void
    {
        Http::fake(function (Request $request) {
            if ($this->apiFailure === 'connection') {
                return Http::failedConnection()($request);
            }
            if ($this->apiFailure === 'unauthorized') {
                return Http::response(['ok' => false, 'description' => 'Unauthorized'], 401);
            }
            if (str_contains($request->url(), '/file/bot')) {
                return Http::response($this->fileBody);
            }

            return Http::response(['ok' => true, 'result' => match (basename($request->url())) {
                'getFile' => $this->fileInfo,
                'getUpdates' => $this->pollUpdates,
                default => true,
            }]);
        });
    }

    private function bind(int $telegramUserId = self::ADMIN_TG): void
    {
        TelegramBinding::create(['user_id' => $this->admin->id, 'telegram_user_id' => $telegramUserId, 'chat_id' => $telegramUserId, 'bound_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function message(string $text, int $from = self::ADMIN_TG, ?int $chat = null, string $chatType = 'private', array $extra = []): array
    {
        return ['update_id' => ++$this->updateId, 'message' => [
            'message_id' => $this->updateId, 'date' => time(), 'text' => $text,
            'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Admin', 'language_code' => 'en'],
            'chat' => ['id' => $chat ?? $from, 'type' => $chatType],
        ] + $extra];
    }

    /** @return array<string, mixed> */
    private function press(string $data, int $from = self::ADMIN_TG): array
    {
        return ['update_id' => ++$this->updateId, 'callback_query' => [
            'id' => 'cb'.$this->updateId, 'data' => $data,
            'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Admin', 'language_code' => 'en'],
            'message' => ['message_id' => 1, 'chat' => ['id' => $from, 'type' => 'private']],
        ]];
    }

    private function deliver(array $update, string $secret = self::SECRET): TestResponse
    {
        return $this->call('POST', '/webhooks/telegram', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => $secret,
        ], json_encode($update));
    }

    /**
     * Отправленные ботом сообщения: текст и кнопки (подпись => callback_data).
     *
     * @return list<array{text: string, buttons: array<string, string>}>
     */
    private function sent(): array
    {
        return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))
            ->map(fn (array $pair): array => [
                'text' => $pair[0]['text'],
                'buttons' => collect(Arr::flatten($pair[0]['reply_markup']['inline_keyboard'] ?? [], 1))->pluck('callback_data', 'text')->all(),
            ])->values()->all();
    }

    /** @return array{text: string, buttons: array<string, string>} */
    private function last(): array
    {
        return Arr::last($this->sent());
    }

    private function item(ContentType $type, string $key): ContentItem
    {
        return ContentItem::where('type', $type)->where('key', $key)->firstOrFail();
    }

    /** Кнопка правки поля по его пути в форме ContentTypes (как у бота: Arr::dot строк формы). */
    private function fieldButton(ContentItem $item, string $locale, string $path): string
    {
        $translation = $item->fresh()->translation($locale);
        $form = array_filter(Arr::dot(ContentTypes::toForm($item, $translation?->latestRevision()?->only(['title', 'slug', 'fields', 'seo_title', 'seo_description']))), 'is_string');

        return 'f:'.$item->id.':'.$locale.':'.($translation->revision ?? 0).':'.array_search($path, array_keys($form), true);
    }

    public function test_webhook_is_outside_the_web_group_and_rejects_a_wrong_secret_with_csrf_enabled(): void
    {
        // Проверка CSRF включена, как в рабочей среде (в PHPUnit middleware её пропускает).
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->post('/lt/kontaktai', [])->assertStatus(419);

        $this->assertNotContains('web', Route::getRoutes()->getByName('webhooks.telegram')->gatherMiddleware());
        foreach (['wrong-secret', ''] as $secret) {
            $response = $this->deliver($this->message('/start'), $secret)->assertForbidden();
            $this->assertFalse($response->headers->has('Set-Cookie'));
        }
        $this->assertSame(0, \DB::table('telegram_updates')->count(), 'подделка без эффекта');

        config(['services.telegram.webhook_secret' => '']);
        $this->deliver($this->message('/start'), '')->assertForbidden();
        config(['services.telegram.webhook_secret' => self::SECRET]);

        $response = $this->deliver($this->message('/start'))->assertOk();
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertSame(1, \DB::table('telegram_updates')->count());
        $this->deliver(['message' => []])->assertStatus(400);
    }

    public function test_link_code_from_integrations_binds_once_and_wrong_codes_bind_nothing_and_are_limited(): void
    {
        $this->actingAs($this->admin);
        $this->get('/en/admin/integrations')->assertOk()->assertSee('Integrations &amp; data flow', false)->assertSee('NOT CONNECTED');
        $screen = Livewire::test(Integrations::class)->call('telegramCode');
        $code = $screen->get('code');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $code, '32 байта base64url — не меньше 128 бит, до 64 символов');
        $screen->assertSee('/start '.$code);
        $binding = TelegramBinding::sole();
        $this->assertSame(hash('sha256', $code), $binding->code_hash);
        $this->assertStringNotContainsString($code, json_encode($binding->getAttributes()), 'хранится только SHA-256');

        $this->deliver($this->message('/start wrong-code-'.str_repeat('x', 20)))->assertOk();
        $this->assertNull($binding->fresh()->telegram_user_id);
        $this->assertSame(['The code is invalid or has expired.'], array_column($this->sent(), 'text'), 'ответ без данных и кнопок');

        // Пять неверных попыток с одного user.id блокируют и верный код; другой user.id не затронут.
        foreach (range(1, 4) as $attempt) {
            $this->deliver($this->message('/start nope'.$attempt));
        }
        $this->deliver($this->message('/start '.$code));
        $this->assertNull($binding->fresh()->telegram_user_id);

        $this->deliver($this->message('/start '.$code, from: 7007))->assertOk();
        $binding->refresh();
        $this->assertSame(7007, $binding->telegram_user_id);
        $this->assertSame(7007, $binding->chat_id);
        $this->assertNull($binding->code_hash);
        $this->assertSame('Chat linked.', $this->sent()[6]['text']);

        $this->deliver($this->message('/start '.$code, from: 7008));
        $this->assertSame(7007, $binding->fresh()->telegram_user_id, 'код одноразовый');
        $this->get('/en/admin/integrations')->assertSee('CONNECTED')->assertDontSee(self::TOKEN)->assertDontSee(self::SECRET);
    }

    public function test_expired_code_and_code_of_a_former_admin_do_not_bind(): void
    {
        $this->actingAs($this->admin);
        $code = Livewire::test(Integrations::class)->call('telegramCode')->get('code');
        $this->travel(11)->minutes();
        $this->deliver($this->message('/start '.$code));
        $this->assertNull(TelegramBinding::sole()->telegram_user_id);

        $this->travelBack();
        $code = Livewire::test(Integrations::class)->call('telegramCode')->get('code');
        $this->admin->forceFill(['is_admin' => false])->save();
        $this->deliver($this->message('/start '.$code, from: 7010));
        $this->assertNull(TelegramBinding::sole()->telegram_user_id);
    }

    public function test_other_users_chats_and_groups_get_nothing(): void
    {
        $this->bind();
        $scanning = $this->item(ContentType::Service, 'scanning');
        $revision = $scanning->translation('en')->revision;
        $updates = [
            $this->message('/start', from: 6001),
            $this->message('hello', from: 6001),
            $this->message('/start', chat: 6002),
            $this->message('/start', chatType: 'group', chat: -100),
            $this->press('t:service', from: 6001),
            $this->press('u:'.$scanning->id.':en:'.$revision, from: 6001),
        ];
        foreach ($updates as $update) {
            $this->deliver($update)->assertOk();
        }

        $this->assertSame([], $this->sent());
        Http::assertNothingSent();
        $this->assertTrue($scanning->fresh()->translation('en')->isPublished());
        $this->assertSame(0, TelegramDialog::count());
    }

    public function test_orders_customers_and_requests_are_not_available_in_the_bot(): void
    {
        $this->bind();
        $client = User::factory()->create(['email' => 'client-secret@example.test', 'name' => 'Client Person']);
        ContactRequest::create(['locale' => 'en', 'name' => 'Enquiry Person', 'email' => 'enquiry@example.test', 'service' => 'scanning',
            'description' => 'Private enquiry text', 'attachments' => []]);
        foreach (['/orders', '/customers', '/export', '/notify', '/search DEMO-1047', $client->email, 'Enquiry Person'] as $text) {
            $this->deliver($this->message($text));
        }
        foreach (['t:order', 't:user', 'i:'.$client->id.'x'] as $data) {
            $this->deliver($this->press($data));
        }

        $sent = $this->sent();
        $this->assertCount(10, $sent);
        foreach ($sent as $message) {
            $this->assertSame('Choose a content type.', $message['text'], 'на всё — только меню типов контента');
            $this->assertSame(['t:page_text', 't:service', 't:project', 't:article', 't:training', 't:product', 't:brand', 't:category', 't:faq_item'],
                array_values($message['buttons']));
        }
        $all = json_encode(Http::recorded()->map(fn (array $pair) => $pair[0]->data())->all());
        foreach (['client-secret@example.test', 'Client Person', 'enquiry@example.test', 'Private enquiry text'] as $private) {
            $this->assertStringNotContainsString($private, $all);
        }
    }

    public function test_edit_from_the_bot_is_a_draft_seen_in_the_web_editor_and_published_per_language(): void
    {
        $this->bind();
        $scanning = $this->item(ContentType::Service, 'scanning');
        $this->deliver($this->message('/start'));
        $this->assertSame('t:service', $this->last()['buttons']['Services']);
        $this->deliver($this->press('t:service'));
        $this->assertSame('i:'.$scanning->id, $this->last()['buttons']['3D skenavimas']);
        $this->deliver($this->press('i:'.$scanning->id));
        $this->assertSame('l:'.$scanning->id.':en', $this->last()['buttons']['EN']);
        $this->deliver($this->press('l:'.$scanning->id.':en'));
        $view = $this->last();
        $this->assertStringContainsString('Short description: Turn a physical part into a digital 3D model.', $view['text']);
        $revision = $scanning->translation('en')->revision;
        $this->assertSame('p:'.$scanning->id.':en:'.$revision, $view['buttons']['Publish EN']);

        $this->deliver($this->press($this->fieldButton($scanning, 'en', 'fields.short')));
        $this->assertStringStartsWith('Short description (EN). Send the new text.', $this->last()['text']);
        $this->deliver($this->message('Edited in Telegram.'));
        $this->assertSame('Draft saved.', $this->sent()[5]['text']);

        $translation = $scanning->fresh()->translation('en');
        $this->assertSame($revision + 1, $translation->revision);
        $this->assertSame('Edited in Telegram.', $translation->latestRevision()->fields['short']);
        $this->assertSame('telegram', $translation->latestRevision()->channel);
        $this->get('/en/services/3d-scanning')->assertDontSee('Edited in Telegram.');
        $this->actingAs($this->admin)->get('/en/admin/services?edit='.$scanning->id)->assertOk()->assertSee('Edited in Telegram.');

        $this->deliver($this->press('p:'.$scanning->id.':en:'.($revision + 1)));
        $this->assertSame('Published: EN.', $this->sent()[7]['text']);
        $this->get('/en/services/3d-scanning')->assertSee('Edited in Telegram.');
        $this->get('/lt/paslaugos/3d-skenavimas')->assertSee('Tikra detalė tampa skaitmeniniu 3D modeliu.');
        $entry = Activity::where('log_name', AuditLog::LOG_NAME)->latest('id')->first();
        $this->assertSame(['content.published', 'telegram'], [$entry->event, $entry->properties['channel']]);

        $this->deliver($this->press('v:'.$scanning->id.':en'));
        $this->assertStringStartsWith("Preview: 3D scanning\nPublished", $this->last()['text']);
        $this->deliver($this->press('u:'.$scanning->id.':en:'.($revision + 1)));
        $this->get('/en/services/3d-scanning')->assertNotFound();
        $this->get('/lt/paslaugos/3d-skenavimas')->assertOk();
    }

    public function test_web_edit_is_seen_in_the_bot_and_outdated_buttons_change_nothing(): void
    {
        $this->bind();
        $scanning = $this->item(ContentType::Service, 'scanning');
        $faq = ContentItem::where('type', ContentType::FaqItem)->orderBy('id')->firstOrFail();
        $revision = $scanning->translation('en')->revision;
        $oldField = $this->fieldButton($scanning, 'en', 'fields.short');
        $oldPublish = 'p:'.$scanning->id.':en:'.$revision;

        $form = ContentTypes::toForm($scanning, $scanning->translation('en')->latestRevision()->only(['title', 'slug', 'fields', 'seo_title', 'seo_description']));
        $form['fields']['short'] = 'Edited on the website.';
        app(ContentService::class)->saveDraft($this->admin, $scanning, 'en', ContentTypes::fromForm($scanning, $form), $revision, Channel::Web);

        $this->deliver($this->press($oldPublish));
        $this->assertSame('This button is outdated: the record has changed.', $this->sent()[0]['text']);
        $this->assertStringContainsString('Short description: Edited on the website.', $this->sent()[1]['text']);
        $this->get('/en/services/3d-scanning')->assertDontSee('Edited on the website.');

        $this->deliver($this->press($oldField));
        $this->assertSame('This button is outdated: the record has changed.', $this->sent()[2]['text']);
        $this->deliver($this->message('Should not be saved.'));
        $this->assertSame($revision + 1, $scanning->fresh()->translation('en')->revision);

        // Кнопка несёт id своей записи: устаревшая кнопка услуги не трогает FAQ, открытый позже.
        $this->deliver($this->press('i:'.$faq->id));
        $faqRevision = $faq->translation('en')->revision;
        $this->deliver($this->press($oldPublish));
        $this->assertSame($faqRevision, $faq->fresh()->translation('en')->revision);
        $this->assertSame($revision + 1, $scanning->fresh()->translation('en')->revision);
    }

    public function test_revision_conflict_during_typing_saves_nothing_and_shows_both_versions(): void
    {
        $this->bind();
        $scanning = $this->item(ContentType::Service, 'scanning');
        $revision = $scanning->translation('en')->revision;
        $this->deliver($this->press($this->fieldButton($scanning, 'en', 'fields.short')));

        $form = ContentTypes::toForm($scanning, $scanning->translation('en')->latestRevision()->only(['title', 'slug', 'fields', 'seo_title', 'seo_description']));
        $form['fields']['short'] = 'Web version.';
        app(ContentService::class)->saveDraft($this->admin, $scanning, 'en', ContentTypes::fromForm($scanning, $form), $revision, Channel::Web);

        $this->deliver($this->message('Bot version.'));
        $this->assertSame("Changed in another window. Your text was not saved.\n\nCurrent version:\nWeb version.\n\nYour text:\nBot version.", $this->sent()[1]['text']);
        $latest = $scanning->fresh()->translation('en')->latestRevision();
        $this->assertSame($revision + 1, $latest->revision);
        $this->assertSame('Web version.', $latest->fields['short']);
        $this->assertSame(0, TelegramDialog::count());
    }

    public function test_repeated_update_id_and_edited_message_have_no_effect(): void
    {
        $this->bind();
        $scanning = $this->item(ContentType::Service, 'scanning');
        $revision = $scanning->translation('en')->revision;
        $this->deliver($this->press($this->fieldButton($scanning, 'en', 'fields.short')));
        $text = $this->message('Once only.');
        $this->deliver($text)->assertOk();
        $this->deliver($this->press($this->fieldButton($scanning, 'en', 'fields.short')));
        $this->deliver($text)->assertOk();
        $this->assertSame($revision + 1, $scanning->fresh()->translation('en')->revision);
        $this->assertSame(3, \DB::table('telegram_updates')->count());

        $edited = $this->message('Edited text.');
        $edited['edited_message'] = $edited['message'];
        unset($edited['message']);
        $this->deliver($edited)->assertOk();
        $this->assertSame($revision + 1, $scanning->fresh()->translation('en')->revision);
        $this->assertSame(1, TelegramDialog::count(), 'контекст ожидания текста остался');
    }

    public function test_dialog_context_expires(): void
    {
        $this->bind();
        $scanning = $this->item(ContentType::Service, 'scanning');
        $revision = $scanning->translation('en')->revision;
        $this->deliver($this->press($this->fieldButton($scanning, 'en', 'fields.short')));
        $this->travel(31)->minutes();
        $this->deliver($this->message('Too late.'));
        $this->assertSame($revision, $scanning->fresh()->translation('en')->revision);
        $this->assertSame('Choose a content type.', $this->last()['text']);
    }

    public function test_dialog_covers_every_registered_type_from_content_types_within_telegram_limits(): void
    {
        $this->bind();
        $types = array_filter(ContentType::cases(), fn (ContentType $type): bool => ContentTypes::isRegistered($type));
        $this->assertCount(9, $types);
        foreach ($types as $type) {
            $this->deliver($this->press('t:'.$type->value));
            $items = ContentItem::where('type', $type)->get();
            $list = $this->last();
            $this->assertCount($items->count() + ($type === ContentType::PageText ? 1 : 2), $list['buttons'], $type->value);
            foreach ($items as $item) {
                $this->assertContains('i:'.$item->id, $list['buttons'], $type->value);
                $this->deliver($this->press('l:'.$item->id.':lt'));
                $view = $this->last();
                $form = array_filter(Arr::dot(ContentTypes::toForm($item, $item->translation('lt')?->latestRevision()?->only(['title', 'slug', 'fields', 'seo_title', 'seo_description']))), 'is_string');
                $fieldButtons = array_filter($view['buttons'], fn (string $data): bool => str_starts_with($data, 'f:'));
                $this->assertCount(count($form), $fieldButtons, $type->value.' '.$item->key);
                $this->assertLessThanOrEqual(100, count($view['buttons']), 'не больше 100 кнопок');
                $this->assertLessThanOrEqual(4000, mb_strlen($view['text']));
                foreach ($view['buttons'] as $data) {
                    $this->assertLessThanOrEqual(64, strlen($data), 'callback_data до 64 байт');
                }
            }
        }
    }

    public function test_new_project_with_attribute_is_published_in_lt_only(): void
    {
        $this->bind();
        $this->deliver($this->press('n:project'));
        $this->assertSame('Send the title (LT).', $this->last()['text']);
        $this->deliver($this->message('Naujas projektas'));
        $this->deliver($this->message('Bad Slug!'));
        $this->assertSame('Invalid value: key.', $this->last()['text']);
        $this->deliver($this->message('naujas-projektas'));
        $project = $this->item(ContentType::Project, 'naujas-projektas');
        $lt = $project->translation('lt');
        $this->assertSame([1, 'Naujas projektas', 'naujas-projektas'], [$lt->revision, $lt->latestRevision()->title, $lt->latestRevision()->slug]);
        $this->assertNull($project->translation('en'));

        $this->deliver($this->press('a:'.$project->id.':application'));
        $options = $this->last()['buttons'];
        $first = array_key_first($options);
        $this->deliver($this->press($options[$first]));
        $this->assertSame('Saved.', $this->sent()[count($this->sent()) - 2]['text']);
        $this->assertNotNull($project->fresh()->meta('application'));

        $this->deliver($this->press('p:'.$project->id.':lt:1'));
        $this->get('/lt/projektai/naujas-projektas')->assertOk()->assertSee('Naujas projektas');
        $this->get('/en/projects/naujas-projektas')->assertNotFound();
        $this->deliver($this->press('p:'.$project->id.':en:0'));
        $this->assertSame('Create a draft first.', $this->last()['text']);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_media_is_checked_by_signature_and_saved_under_a_server_name(): void
    {
        $this->bind();
        $scanning = $this->item(ContentType::Service, 'scanning');
        $this->deliver($this->message('photo', extra: ['document' => ['file_id' => 'f0', 'file_name' => 'early.png', 'file_size' => 100]]));
        $this->assertSame('Choose a record and the file purpose first.', $this->sent()[0]['text']);
        $this->assertCount(0, $scanning->getMedia('images'));

        $this->deliver($this->press('m:'.$scanning->id.':images'));
        $this->assertSame('Send a photo or video: JPEG, PNG, WebP or MP4, up to 20 MB.', $this->last()['text']);
        $this->fileBody = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->fileInfo = ['file_path' => 'documents/file_2.svg', 'file_size' => strlen($this->fileBody)];
        $this->deliver($this->message('', extra: ['document' => ['file_id' => 'f1', 'file_name' => 'logo.svg', 'mime_type' => 'image/svg+xml', 'file_size' => 80]]));
        $this->assertSame('Invalid file: JPEG, PNG, WebP or MP4 only, up to 20 MB.', $this->last()['text']);
        $this->fileBody = "\x00\x01PK not an image";
        $this->deliver($this->message('', extra: ['document' => ['file_id' => 'f2', 'file_name' => 'photo.jpg', 'file_size' => 20]]));
        $this->assertSame('Invalid file: JPEG, PNG, WebP or MP4 only, up to 20 MB.', $this->last()['text']);
        $this->assertCount(0, $scanning->fresh()->getMedia('images'));

        $this->fileBody = $this->png();
        $this->fileInfo = ['file_path' => 'documents/file_3.jpg', 'file_size' => strlen($this->fileBody)];
        $this->deliver($this->message('', extra: ['document' => ['file_id' => 'f3', 'file_name' => 'evil.php.jpg', 'file_size' => strlen($this->fileBody)]]));
        $media = $scanning->fresh()->getMedia('images');
        $this->assertCount(1, $media);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $media[0]->file_name, 'имя UUID, расширение по сигнатуре');
        Storage::disk('public')->assertExists($media[0]->getPathRelativeToRoot());
        $this->assertSame('File uploaded.', $this->sent()[5]['text']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/file/bot'.self::TOKEN.'/documents/file_3.jpg');
    }

    public function test_file_over_20_mb_gets_a_clear_refusal_without_download(): void
    {
        $this->bind();
        $home = $this->item(ContentType::PageText, 'home');
        $this->deliver($this->press('m:'.$home->id.':video'));
        $this->deliver($this->message('', extra: ['video' => ['file_id' => 'v1', 'file_size' => 21 * 1024 * 1024, 'duration' => 30]]));
        $this->assertSame('The bot accepts files up to 20 MB. Upload larger files in the web administration.', $this->last()['text']);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/getFile'));
        $this->assertCount(0, $home->fresh()->getMedia('video'));
    }

    public function test_network_error_does_not_leak_the_token_and_does_not_undo_the_edit(): void
    {
        $this->bind();
        $scanning = $this->item(ContentType::Service, 'scanning');
        $field = $this->fieldButton($scanning, 'en', 'fields.short');
        $this->deliver($this->press($field));
        $revision = $scanning->translation('en')->revision;

        $this->apiFailure = 'connection';
        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });
        $this->deliver($this->message('Saved despite Telegram.'))->assertOk();

        $this->assertSame('Saved despite Telegram.', $scanning->fresh()->translation('en')->latestRevision()->fields['short']);
        $this->assertSame($revision + 1, $scanning->fresh()->translation('en')->revision);
        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::TOKEN, $line);
        }
        $error = TelegramBinding::sole()->last_error;
        $this->assertStringContainsString('sendMessage: cURL error 6', $error);
        $this->assertStringContainsString('/bot[token]/sendMessage', $error);
        $this->assertStringNotContainsString(self::TOKEN, $error);

        $this->actingAs($this->admin)->get('/en/admin/integrations')->assertSee('Last error')->assertDontSee(self::TOKEN);
    }

    public function test_webhook_command_registers_the_address_secret_and_update_types(): void
    {
        config(['app.url' => 'https://aiqme.example']);
        $this->artisan('aiqme:telegram-webhook')->expectsOutput('Webhook: https://aiqme.example/webhooks/telegram')->assertSuccessful();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot'.self::TOKEN.'/setWebhook'
            && $request->data() === ['url' => 'https://aiqme.example/webhooks/telegram', 'secret_token' => self::SECRET, 'allowed_updates' => ['message', 'callback_query']]);

        $before = Http::recorded()->count();
        foreach (['', 'has space', 'bad/slash', str_repeat('a', 257)] as $secret) {
            config(['services.telegram.webhook_secret' => $secret]);
            $this->artisan('aiqme:telegram-webhook')->assertFailed();
        }
        config(['services.telegram.webhook_secret' => str_repeat('A', 256)]);
        $this->artisan('aiqme:telegram-webhook')->assertSuccessful();
        $this->assertSame($before + 1, Http::recorded()->count(), 'неверный секрет — без запросов');

        $this->apiFailure = 'unauthorized';
        $this->artisan('aiqme:telegram-webhook')
            ->expectsOutput('setWebhook: HTTP 401 Unauthorized')
            ->doesntExpectOutputToContain(self::TOKEN)
            ->assertFailed();
    }

    public function test_poll_command_works_only_locally_and_feeds_the_webhook_handler_with_deduplication(): void
    {
        $this->artisan('aiqme:telegram-poll --once')->assertFailed();
        Http::assertNothingSent();

        $this->bind();
        $seen = $this->message('/start');
        $this->deliver($seen);
        $this->pollUpdates = [$this->message('/start'), $seen];
        $before = Http::recorded()->count();

        $this->app['env'] = 'local';
        $this->artisan('aiqme:telegram-poll --once')->assertSuccessful();
        $methods = Http::recorded()->slice($before)->map(fn (array $pair): string => basename($pair[0]->url()))->values()->all();
        $this->assertSame(['deleteWebhook', 'getUpdates', 'sendMessage'], $methods, 'повтор update_id без ответа');
        Http::assertSent(fn (Request $request): bool => basename($request->url()) === 'getUpdates'
            && $request['offset'] === 0 && $request['allowed_updates'] === ['message', 'callback_query']);
        $this->assertSame(2, \DB::table('telegram_updates')->count());
    }
}
