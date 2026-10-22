<?php

namespace Tests\Feature\Scenarios;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Integrations\Telegram\Models\TelegramBinding;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PageHead;
use Tests\TestCase;

/**
 * Сценарий 5 §9.3 (W-30, уровень L): администратор создаёт и публикует проект в Telegram только на LT — фикстуры
 * апдейтов W-19 в POST /webhooks/telegram с secret_token, ответы Bot API — Http::fake (запросов к Telegram нет) → 200 на
 * /lt/, 404 на /en/ и без hreflang EN; затем публикует EN через ContentService, как редактор W-26 (канал web) → EN-страница
 * открывается, у обеих версий взаимный hreflang.
 */
class TelegramProjectScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_TG = 5001;

    private int $updateId = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.telegram.token' => 'test-bot-token-not-real', 'services.telegram.webhook_secret' => 'test-webhook-secret-not-real']);
        $this->seed(PrototypeContentSeeder::class);
        Http::fake(fn (Request $request) => Http::response(['ok' => true, 'result' => true]));
    }

    /** @return array<string, mixed> */
    private function message(string $text): array
    {
        return ['update_id' => ++$this->updateId, 'message' => [
            'message_id' => $this->updateId, 'date' => time(), 'text' => $text,
            'from' => ['id' => self::ADMIN_TG, 'is_bot' => false, 'first_name' => 'Admin', 'language_code' => 'en'],
            'chat' => ['id' => self::ADMIN_TG, 'type' => 'private'],
        ]];
    }

    /** @return array<string, mixed> */
    private function press(string $data): array
    {
        return ['update_id' => ++$this->updateId, 'callback_query' => [
            'id' => 'cb'.$this->updateId, 'data' => $data,
            'from' => ['id' => self::ADMIN_TG, 'is_bot' => false, 'first_name' => 'Admin', 'language_code' => 'en'],
            'message' => ['message_id' => 1, 'chat' => ['id' => self::ADMIN_TG, 'type' => 'private']],
        ]];
    }

    private function deliver(array $update): void
    {
        $this->call('POST', '/webhooks/telegram', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'test-webhook-secret-not-real',
        ], json_encode($update))->assertOk();
    }

    /**
     * Последнее сообщение бота: текст и кнопки (подпись => callback_data).
     *
     * @return array{text: string, buttons: array<string, string>}
     */
    private function lastReply(): array
    {
        $pair = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))->last();

        return [
            'text' => $pair[0]['text'],
            'buttons' => collect(Arr::flatten($pair[0]['reply_markup']['inline_keyboard'] ?? [], 1))->pluck('callback_data', 'text')->all(),
        ];
    }

    public function test_project_published_in_telegram_for_lt_only_then_in_english_on_the_web_gets_hreflang(): void
    {
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        TelegramBinding::create(['user_id' => $admin->id, 'telegram_user_id' => self::ADMIN_TG, 'chat_id' => self::ADMIN_TG, 'bound_at' => now()]);

        // Telegram: новый проект (название LT и ключ), применение и публикация LT.
        $this->deliver($this->press('n:project'));
        $this->assertSame('Send the title (LT).', $this->lastReply()['text']);
        $this->deliver($this->message('Skenuotas laikiklis'));
        $this->deliver($this->message('skenuotas-laikiklis'));
        $project = ContentItem::where('type', ContentType::Project)->where('key', 'skenuotas-laikiklis')->sole();
        $this->assertNull($project->translation('en'));
        $this->deliver($this->press('a:'.$project->id.':application'));
        $this->deliver($this->press(Arr::first($this->lastReply()['buttons'])));
        $this->assertNotNull($project->fresh()->meta('application'));
        $lt = '/lt/projektai/skenuotas-laikiklis';
        $this->get($lt)->assertNotFound();
        $this->deliver($this->press('p:'.$project->id.':lt:'.$project->fresh()->translation('lt')->revision));
        $this->assertStringStartsWith("Skenuotas laikiklis · LT\nPublished", $this->lastReply()['text']);
        $this->assertTrue(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage') && $request['text'] === 'Published: LT.')->isNotEmpty());

        // Только LT: страница LT открыта, EN — 404, hreflang без EN; в списке EN проекта нет.
        $page = $this->get($lt)->assertOk()->assertSee('Skenuotas laikiklis')->getContent();
        $this->assertSame(['lt' => config('app.url').$lt, 'x-default' => config('app.url').$lt], PageHead::hreflang($page));
        $this->get('/en/projects/skenuotas-laikiklis')->assertNotFound();
        $this->get('/en/projects')->assertOk()->assertDontSee('Skenuotas laikiklis');

        // Веб: EN-черновик и публикация через ContentService, как в редакторе W-26.
        $content = app(ContentService::class);
        $draft = $content->saveDraft($admin, $project->fresh(), 'en', [
            'title' => 'Scanned bracket', 'slug' => 'scanned-bracket',
            'fields' => ['task' => 'Recreate a worn bracket.', 'solution' => 'Scanned and modelled in CAD.', 'result' => 'Printed replacement in PETG.'],
        ], 0, Channel::Web);
        $this->get('/en/projects/scanned-bracket')->assertNotFound();
        $content->publish($admin, $project->fresh(), 'en', $draft->revision, Channel::Web);

        $en = '/en/projects/scanned-bracket';
        $english = $this->get($en)->assertOk()->assertSee('Scanned bracket')->assertSee('Printed replacement in PETG.')->getContent();
        $expected = ['lt' => config('app.url').$lt, 'en' => config('app.url').$en, 'x-default' => config('app.url').$lt];
        $this->assertSame($expected, PageHead::hreflang($english));
        $this->assertSame($expected, PageHead::hreflang($this->get($lt)->assertOk()->getContent()));
        $this->get('/en/projects')->assertOk()->assertSee('Scanned bracket');
    }
}
