<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/** Общие компоненты оболочки (W-03): разметка прототипа и поведение, на которое опираются страницы и JS. */
class ShellComponentsTest extends TestCase
{
    private function render(string $template, array $data = [], array $errors = []): string
    {
        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag($errors)));

        return Blade::render($template, $data);
    }

    public function test_field_shows_validation_error_linked_to_the_control_and_keeps_old_input(): void
    {
        $html = $this->render('<x-field name="email" type="email" label="E-mail" required />', [], ['email' => ['Neteisingas el. paštas.']]);

        $this->assertStringContainsString('<label class="field" for="field-email">E-mail', $html);
        $this->assertMatchesRegularExpression('~<input id="field-email" name="email" type="email"\s+value=""\s+aria-invalid="true" aria-describedby="field-email-error" required="required">~', $html);
        $this->assertStringContainsString('<small id="field-email-error">Neteisingas el. paštas.</small>', $html);

        $clean = $this->render('<x-field name="email" label="E-mail" value="a@aiqme.test" />');
        $this->assertStringNotContainsString('aria-invalid', $clean);
        $this->assertStringContainsString('value="a@aiqme.test"', $clean);

        $this->assertStringNotContainsString('value=', $this->render('<x-field name="password" type="password" label="P" value="secret-value" />'),
            'пароль не возвращается в разметку');
    }

    public function test_field_renders_select_with_selected_option_and_textarea(): void
    {
        $select = $this->render('<x-field name="service" type="select" label="S" :options="$options" value="b" />',
            ['options' => ['a' => 'Pirmas', 'b' => 'Antras']]);
        $this->assertMatchesRegularExpression('~<option value="b"\s+selected>Antras</option>~', $select);
        $this->assertDoesNotMatchRegularExpression('~<option value="a"\s+selected~', $select);

        $this->assertStringContainsString('<textarea id="field-message" name="message" >&lt;b&gt;</textarea>',
            $this->render('<x-field name="message" type="textarea" label="M" value="<b>" />'));
    }

    public function test_status_block_is_persistent_with_role_and_reads_the_session_status(): void
    {
        $this->assertSame('', trim($this->render('<x-status />')), 'без сообщения блока нет');
        $this->assertStringContainsString('<div class="notice success" role="status">Išsaugota</div>', $this->render('<x-status message="Išsaugota" />'));
        $this->assertStringContainsString('<div class="notice error" role="alert">Klaida</div>', $this->render('<x-status message="Klaida" type="error" />'));

        session()->flash('status', 'Užklausa gauta');
        $this->assertStringContainsString('role="status">Užklausa gauta</div>', $this->render('<x-status />'));
    }

    public function test_modal_is_hidden_dialog_with_label_and_close_button(): void
    {
        app()->setLocale('en');
        $html = $this->render('<x-modal id="training" title="Registration"><p>Body</p></x-modal>');

        $this->assertStringContainsString('<div class="search-overlay" id="training" data-modal hidden>', $html);
        $this->assertStringContainsString('role="dialog" aria-modal="true" aria-labelledby="training-title"', $html);
        $this->assertStringContainsString('<h2 id="training-title">Registration</h2>', $html);
        $this->assertStringContainsString('data-modal-close aria-label="Close"', $html);
        $this->assertStringNotContainsStringIgnoringCase('demo', $html, 'ярлык «AIQME / DEMO» и «Demo dialog» прототипа не переносятся');
    }

    public function test_buttons_cards_tables_empty_state_and_placeholder_use_prototype_classes(): void
    {
        $this->assertMatchesRegularExpression('~<a href="/x" class="btn secondary small">Go <svg class="icon"~',
            $this->render('<x-button href="/x" variant="secondary" small arrow>Go</x-button>'));
        $this->assertStringContainsString('<button type="submit" class="btn">Send</button>', $this->render('<x-button type="submit">Send</x-button>'));
        $this->assertStringContainsString('<a href="/p" class="card"><div class="card-body">C</div></a>', $this->render('<x-card href="/p">C</x-card>'));
        $this->assertStringContainsString('<div class="data-table"><table><tr><td>1</td></tr></table></div>', $this->render('<x-table><tr><td>1</td></tr></x-table>'));

        $empty = $this->render('<x-empty title="Nothing" text="Try again" />');
        $this->assertStringContainsString('<h3 class="gap">Nothing</h3>', $empty);
        $this->assertStringContainsString('<p>Try again</p>', $empty);

        app()->setLocale('lt');
        $this->assertStringContainsString('<h3 class="gap">Informacija ruošiama</h3>', $this->render('<x-placeholder />'));
        app()->setLocale('en');
        $this->assertStringContainsString('<h3 class="gap">Information is being prepared</h3>', $this->render('<x-placeholder />'));
    }
}
