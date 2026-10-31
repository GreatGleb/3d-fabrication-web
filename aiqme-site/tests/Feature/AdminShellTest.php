<?php

namespace Tests\Feature;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Оболочка админки W-08 (§1.4, §6.1): вкладки overview (корень и overview), services, content, audit и вкладки W-26
 * (products, categories, projects, training, translations) — только администратору, гостю и клиенту 404 (в том числе
 * действия Livewire-редактора через /livewire/update); меню без Permissions, Telegram и CSV; без демо-надписей
 * прототипа; журнал публикаций.
 */
class AdminShellTest extends TestCase
{
    use RefreshDatabase;

    private const TABS = [
        'lt' => ['/lt/administravimas', '/lt/administravimas/overview', '/lt/administravimas/orders', '/lt/administravimas/products', '/lt/administravimas/services',
            '/lt/administravimas/calculator', '/lt/administravimas/customers', '/lt/administravimas/projects', '/lt/administravimas/content', '/lt/administravimas/training', '/lt/administravimas/translations',
            '/lt/administravimas/integrations', '/lt/administravimas/settings', '/lt/administravimas/audit'],
        'en' => ['/en/admin', '/en/admin/overview', '/en/admin/orders', '/en/admin/products', '/en/admin/services', '/en/admin/calculator', '/en/admin/customers', '/en/admin/projects', '/en/admin/content',
            '/en/admin/training', '/en/admin/translations', '/en/admin/integrations', '/en/admin/settings', '/en/admin/audit'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeContentSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();

        return $admin;
    }

    private function service(string $key = 'scanning'): ContentItem
    {
        return ContentItem::where('type', ContentType::Service)->where('key', $key)->firstOrFail();
    }

    public function test_every_w08_address_is_404_for_guest_and_customer_and_200_for_the_admin(): void
    {
        $id = $this->service()->id;
        $page = ContentItem::where('type', ContentType::PageText)->where('key', 'home')->firstOrFail()->id;
        $paths = array_merge(...array_values(self::TABS));
        $paths = array_merge($paths, ["/en/admin/services?edit=$id", '/en/admin/services?new=service', "/lt/administravimas/content?edit=$page", '/en/admin/services/',
            '/en/admin/categories', '/lt/administravimas/categories', '/en/admin/services?new=faq_item', '/en/admin/translations?group=public&edit=public.find_solution']);

        foreach ($paths as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->actingAs(User::factory()->create());
        foreach ($paths as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->actingAs($this->admin());
        foreach ($paths as $path) {
            $response = $this->get($path)->assertOk()->assertSee('<meta name="robots" content="noindex', false);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'), $path);
        }
        // Чужой тип и неизвестный элемент в ?edit — 404, а не редактор.
        $this->get("/en/admin/services?edit=$page")->assertNotFound();
        $this->get('/en/admin/content?edit=999999')->assertNotFound();
        // Строка интерфейса вне набора вкладки (тексты админки, несуществующий ключ) — 404.
        $this->get('/en/admin/translations?edit=admin.menu')->assertNotFound();
        $this->get('/en/admin/translations?edit=shell.nope')->assertNotFound();
    }

    public function test_livewire_editor_actions_are_404_without_the_admin(): void
    {
        $admin = $this->admin();
        $html = $this->actingAs($admin)->get('/en/admin/services?edit='.$this->service()->id)->assertOk()->getContent();
        $this->assertSame(1, preg_match('/wire:snapshot="([^"]+)"/', $html, $match));
        $snapshot = html_entity_decode($match[1]);
        $payload = fn (?string $method, array $updates = []): array => ['components' => [[
            'snapshot' => $snapshot, 'updates' => $updates, 'calls' => $method ? [['path' => '', 'method' => $method, 'params' => []]] : [],
        ]]];
        $update = route('default.livewire.update');

        // Тот же снимок от имени администратора принимается — значит, отказ ниже вызван именно правами.
        $this->actingAs($admin)->postJson($update, $payload(null), ['X-Livewire' => '1'])->assertOk();

        $this->actingAs(User::factory()->create())
            ->postJson($update, $payload('save', ['form.en.title' => 'Hacked']), ['X-Livewire' => '1'])->assertNotFound();
        auth()->logout();
        $this->postJson($update, $payload('publish', []), ['X-Livewire' => '1'])->assertNotFound();

        $this->assertDatabaseMissing('content_revisions', ['title' => 'Hacked']);
    }

    public function test_shell_follows_the_prototype_without_demo_texts_roles_or_excluded_tabs(): void
    {
        $this->actingAs($this->admin());
        foreach (['lt' => ['AIQME valdymo aplinka', 'Administravimo meniu', ['Apžvalga', 'Užsakymai', 'Produktai', 'Paslaugos', 'Kalkuliatorius', 'Klientai', 'Projektai', 'Informacija ir SEO', 'Mokymai', 'LT / EN turinys', 'Integracijos', 'Nustatymai', 'Veiksmų istorija']],
            'en' => ['AIQME management workspace', 'Administration menu', ['Overview', 'Orders', 'Products', 'Services', 'Calculator', 'Customers', 'Projects', 'Content &amp; SEO', 'Training', 'LT / EN content', 'Integrations', 'Settings', 'Audit log']]] as $locale => [$title, $menu, $tabs]) {
            foreach (self::TABS[$locale] as $path) {
                $html = $this->get($path)->assertOk()->getContent();
                $this->assertStringContainsString('<h1>'.$title.'</h1>', $html);
                $this->assertStringContainsString('<nav class="side-nav" aria-label="'.$menu.'">', $html);
                $this->assertStringContainsString('class="admin-layout"', $html);
                preg_match('~<nav class="side-nav".*?</nav>~s', $html, $nav);
                preg_match_all('~<a[^>]*href="[^"]*/(?:administravimas|admin)/(\w+)"[^>]*>.*?</svg>\s*([^<]+?)</a>~s', $nav[0], $links);
                $this->assertSame(['overview', 'orders', 'products', 'services', 'calculator', 'customers', 'projects', 'content', 'training', 'translations', 'integrations', 'settings', 'audit'], $links[1], $path);
                $this->assertSame($tabs, $links[2], $path);
                $this->assertSame(1, substr_count($html, 'aria-current="page"'), $path);
                foreach (['Demo administration', 'Demonstracinis administravimas', 'ADMINISTRATION DEMO', 'ADMINISTRAVIMO DEMONSTRACIJA',
                    'demo-role', 'Try a role', 'Išbandyti rolę', 'Production staff', 'Permissions', 'Prieigos teisės', 'admin/telegram', 'administravimas/telegram', 'CSV',
                    'Demonstration prototype', 'Demonstracinis prototipas', 'simulated', 'imituojama', 'Drafts in this session'] as $phrase) {
                    // Случайный CSP-nonce может содержать, например, «cSV» — его значение не текст страницы.
                    $this->assertStringNotContainsStringIgnoringCase($phrase, preg_replace('~nonce="[^"]*"~', '', $html), $path);
                }
                $this->assertStringContainsString('form="logout-form"', $html);
            }
        }

        $overview = $this->get('/en/admin')->getContent();
        $this->assertStringContainsString('<h2>Your working day, at a glance.</h2>', $overview);
        $this->assertStringContainsString('<h3 class="gap">Project enquiries</h3>', $overview, 'W-09: заявки формы вместо заглушки');
        $this->assertStringContainsString('No enquiries yet.', $overview);
        $this->assertStringNotContainsString('DEMO-1047', $overview);
        $this->assertStringContainsString('<h2>Darbo diena viename vaizde.</h2>', $this->get('/lt/administravimas/overview')->getContent());
    }

    public function test_excluded_and_later_tabs_are_not_routes_yet(): void
    {
        $this->actingAs($this->admin());
        foreach (['permissions', 'telegram', 'users', 'pricing', 'discounts', 'delivery', 'csv'] as $tab) {
            $this->get('/en/admin/'.$tab)->assertNotFound();
        }
    }

    public function test_service_and_page_lists_show_language_state_and_link_to_the_editor(): void
    {
        $this->actingAs($admin = $this->admin());
        $html = $this->get('/en/admin/services')->assertOk()->getContent();
        $this->assertStringContainsString('<h2>Services</h2>', $html);
        $this->assertStringContainsString('Add a draft +', $html);
        $this->assertSame(6 + 9, substr_count($html, '<span class="tag">Both languages</span>'), '6 услуг и 9 вопросов FAQ');
        foreach (['3D scanning', '3D modelling training'] as $title) {
            $this->assertStringContainsString('<td>'.$title.'</td>', $html);
        }
        $this->assertStringContainsString('href="'.route('en.admin.services', ['edit' => $this->service()->id]).'#content-editor"', $html);
        $this->assertStringNotContainsString('id="content-edit-form"', $html);

        // Снятие EN — в списке остаётся только LT.
        $scanning = $this->service();
        app(ContentService::class)->unpublish($admin, $scanning, 'en', $scanning->translation('en')->revision, Channel::Web);
        $html = $this->get('/lt/administravimas/services')->getContent();
        $this->assertSame(5 + 9, substr_count($html, '<span class="tag">Abi kalbos</span>'));
        $this->assertStringContainsString('<span class="tag">LT</span>', $html);

        // Статьи (3) и тексты страниц (8, политики — черновики); «Add a draft +» — только у статей (новые страницы — разработка).
        $html = $this->get('/en/admin/content')->assertOk()->getContent();
        $this->assertStringContainsString('<h2>Content &amp; SEO</h2>', $html);
        $this->assertStringContainsString('<h3>Page texts</h3>', $html);
        $this->assertSame(3 + 8, substr_count($html, '#content-editor">Edit</a>'));
        $this->assertSame(4, substr_count($html, '<span class="tag">Draft</span>'));
        $this->assertSame(1, substr_count($html, 'Add a draft +'));
        $this->assertStringContainsString('href="'.route('en.admin.content', ['new' => 'article']).'#content-editor"', $html);
        $this->assertStringContainsString('<td>Privacy &amp; file retention</td>', $html);
    }

    public function test_audit_tab_lists_publications_with_time_channel_object_and_details(): void
    {
        $this->actingAs($admin = $this->admin());
        Activity::query()->delete();
        $this->assertStringContainsString('<td colspan="5">No actions yet.</td>', $this->get('/en/admin/audit')->getContent());

        $scanning = $this->service();
        app(ContentService::class)->unpublish($admin, $scanning, 'en', $scanning->translation('en')->revision, Channel::Web);
        app(ContentService::class)->publish($admin, $scanning, 'en', $scanning->translation('en')->revision, Channel::Telegram);

        $html = $this->get('/en/admin/audit')->assertOk()->getContent();
        $this->assertStringContainsString('<h2>Audit history</h2>', $html);
        preg_match_all('~<tr>\s*<td>(\d{4}-\d\d-\d\d \d\d:\d\d)</td>\s*<td>([^<]*)</td>\s*<td>([^<]*)</td>\s*<td>([^<]*)</td>\s*<td>([^<]*)</td>~', str_replace("\n", '', $html), $rows, PREG_SET_ORDER);
        $this->assertCount(2, $rows);
        $this->assertSame(['Telegram', 'Published', '3D scanning (service)', 'locale: en · revision: 1'], array_slice($rows[0], 2));
        $this->assertSame(['Website', 'Unpublished', '3D scanning (service)', 'locale: en · revision: 1'], array_slice($rows[1], 2));
        $this->assertStringContainsString('<td>Paskelbimas atšauktas</td>', $this->get('/lt/administravimas/audit')->getContent());
    }
}
