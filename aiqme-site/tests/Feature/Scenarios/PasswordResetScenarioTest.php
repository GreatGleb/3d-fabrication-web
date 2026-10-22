<?php

namespace Tests\Feature\Scenarios;

use App\Mail\ResetPasswordLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BrowserSession;
use Tests\TestCase;

/**
 * Сценарий 4 §9.3 (W-30, уровень L): клиент вошёл в двух «браузерах», запрашивает восстановление пароля, переходит по
 * ссылке из письма (Mail::fake) в третьем, задаёт новый пароль → обе прежние сессии завершены (тот же механизм и форма
 * теста, что W-07: следующий запрос прежней сессии ведёт на вход), вход новым паролем открывает кабинет, старый не
 * подходит. Сессии — в БД, у каждого «браузера» только своя cookie (BrowserSession).
 */
class PasswordResetScenarioTest extends TestCase
{
    use BrowserSession, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        Mail::fake();
    }

    /** Вход через форму в новом «браузере»; возвращает id его сессии. */
    private function signIn(string $password): string
    {
        $session = null;
        $this->browse('GET', route('en.account.login'), session: $session)->assertOk();
        $this->browse('POST', route('en.account.login.store'), ['email' => 'jonas@example.test', 'password' => $password], $session)
            ->assertSessionHasNoErrors()->assertRedirect();

        return $session;
    }

    public function test_reset_by_emailed_link_ends_old_sessions_and_the_new_password_signs_in(): void
    {
        User::factory()->create(['email' => 'jonas@example.test', 'password' => 'old-password-1']);
        $laptop = $this->signIn('old-password-1');
        $phone = $this->signIn('old-password-1');
        foreach ([$laptop, $phone] as $old) {
            $this->browse('GET', route('en.account'), session: $old)->assertOk()->assertSee('account-overview', false);
        }

        // Запрос ссылки: письмо в очереди на адрес клиента, ссылка — на странице его языка.
        $browser = null;
        $this->browse('GET', route('en.account.reset'), session: $browser)->assertOk();
        $this->browse('POST', route('en.account.reset.store'), ['email' => 'jonas@example.test'], $browser)
            ->assertRedirect(route('en.account.reset'))->assertSessionHas('status');
        $url = null;
        Mail::assertQueued(ResetPasswordLink::class, 1);
        Mail::assertQueued(ResetPasswordLink::class, function (ResetPasswordLink $mail) use (&$url): bool {
            $url = $mail->url();

            return $mail->hasTo('jonas@example.test');
        });
        $this->assertStringStartsWith(config('app.url').'/en/account/reset/', $url);

        // Новый пароль по ссылке из письма.
        $this->browse('GET', $url, session: $browser)->assertOk()->assertSee('value="jonas@example.test"', false);
        $this->browse('POST', $url, ['email' => 'jonas@example.test', 'password' => 'new-password-2'], $browser)
            ->assertRedirect(route('en.account.login'))->assertSessionHasNoErrors();

        // Прежние сессии: следующий запрос ведёт на вход, кабинет больше не открывается.
        foreach ([$laptop, $phone] as $old) {
            $this->browse('GET', route('en.account'), session: $old)->assertRedirect(route('en.account.login'));
            $this->browse('GET', route('en.account'), session: $old)->assertOk()->assertDontSee('account-overview', false);
        }

        // Старый пароль не подходит, новый открывает кабинет.
        $rejected = null;
        $this->browse('GET', route('en.account.login'), session: $rejected);
        $this->browse('POST', route('en.account.login.store'), ['email' => 'jonas@example.test', 'password' => 'old-password-1'], $rejected)
            ->assertSessionHasErrors('email');
        $fresh = $this->signIn('new-password-2');
        $this->browse('GET', route('en.account'), session: $fresh)->assertOk()->assertSee('account-overview', false);
    }
}
