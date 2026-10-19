<?php

namespace Tests\Feature;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** ui_text из БД поверх lang/ на уровне переводчика (W-04, §4.1, §4.3). */
class UiTextTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();

        return $admin;
    }

    public function test_database_value_replaces_the_lang_file_string_in_the_shell_header(): void
    {
        $this->get('/lt')->assertSee('/lt/paslaugos" >Paslaugos</a>', escape: false);

        app(ContentService::class)->saveUiText($this->admin(), 'lt', 'shell.nav.services', 'Mūsų paslaugos', 0, Channel::Web);

        $this->get('/lt')->assertOk()
            ->assertSee('/lt/paslaugos" >Mūsų paslaugos</a>', escape: false)
            ->assertDontSee('/lt/paslaugos" >Paslaugos</a>', escape: false)
            // Подвал берёт свой ключ (shell.footer.services) и не меняется.
            ->assertSee('/lt/paslaugos">Paslaugos</a>', escape: false)
            // Остальные пункты той же группы остаются из файла.
            ->assertSee('>Produktai</a>', escape: false);
        $this->get('/en')->assertSee('>Services</a>', escape: false);
    }

    public function test_russian_admin_page_shows_the_english_database_value_not_the_key(): void
    {
        $admin = $this->admin();
        app(ContentService::class)->saveUiText($admin, 'en', 'shell.nav.services', 'Our services', 0, Channel::Web);

        $this->actingAs($admin)->get('/ru')->assertOk()
            ->assertSee('<html lang="ru">', escape: false)
            ->assertSee('>Our services</a>', escape: false)
            ->assertDontSee('shell.nav');
        $this->assertSame('Our services', __('shell.nav.services', [], 'ru'));
    }

    public function test_override_can_be_removed_and_stale_revision_is_rejected(): void
    {
        $admin = $this->admin();
        $service = app(ContentService::class);
        $text = $service->saveUiText($admin, 'lt', 'shell.search', 'Ieškoti', 0, Channel::Web);
        $this->assertSame(1, $text->revision);
        $this->assertSame('Ieškoti', __('shell.search', [], 'lt'));

        try {
            $service->saveUiText($admin, 'lt', 'shell.search', 'Kitas', 0, Channel::Telegram);
            $this->fail('Устаревшая ревизия принята');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revision', $e->errors());
        }
        $this->assertSame('Ieškoti', __('shell.search', [], 'lt'));

        $this->assertNull($service->saveUiText($admin, 'lt', 'shell.search', null, 1, Channel::Web));
        $this->assertSame('Paieška', __('shell.search', [], 'lt'));
    }

    public function test_only_existing_interface_strings_can_be_overridden(): void
    {
        $admin = $this->admin();
        foreach (['shell.no_such_key', 'shell.nav', 'shell', 'Arbitrary text'] as $key) {
            try {
                app(ContentService::class)->saveUiText($admin, 'lt', $key, 'X', 0, Channel::Web);
                $this->fail('Принят ключ '.$key);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('key', $e->errors());
            }
        }
        $this->assertDatabaseCount('ui_texts', 0);
    }
}
