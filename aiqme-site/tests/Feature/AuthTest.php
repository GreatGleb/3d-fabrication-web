<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BrowserSession;
use Tests\TestCase;

/**
 * Вход, регистрация, восстановление и выход (W-07, §1.3, §6.3) через формы, с сессиями в БД (драйвер database) и
 * cookie «браузера» (BrowserSession): нейтральные ошибки, ограничение попыток, письмо сброса в очереди,
 * завершение прежних сессий после сброса (auth.session), шифрование sessions.payload, защита админки и /ru/.
 */
class AuthTest extends TestCase
{
    use BrowserSession, RefreshDatabase;

    private const PASSWORD = 'old-password-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
    }

    private function client(string $email = 'jonas@aiqme.test'): User
    {
        return User::factory()->create(['email' => $email, 'password' => self::PASSWORD]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['email' => 'admin@aiqme.test', 'password' => self::PASSWORD]);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    /** Вход через форму в новом «браузере»; возвращает id его сессии. */
    private function signIn(string $email, string $password = self::PASSWORD, string $locale = 'lt'): string
    {
        $session = null;
        $this->browse('GET', route($locale.'.account.login'), session: $session)->assertOk();
        $this->browse('POST', route($locale.'.account.login.store'), ['email' => $email, 'password' => $password], $session)
            ->assertSessionHasNoErrors()->assertRedirect();

        return $session;
    }

    public function test_login_form_follows_the_prototype_with_real_password_rules_and_no_demo_labels(): void
    {
        foreach (['lt' => ['Prisijungimas', 'Prisijungti', '>Slaptažodis'], 'en' => ['Sign in', 'Sign in', '>Password']] as $locale => [$heading, $submit, $label]) {
            $html = $this->get(route($locale.'.account.login'))->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')->getContent();

            $this->assertStringContainsString('<h2>'.$heading.'</h2>', $html);
            $this->assertStringContainsString('>'.$submit.'</button>', $html);
            $this->assertStringContainsString($label, $html);
            $this->assertMatchesRegularExpression('/<input[^>]*name="password"[^>]*type="password"[^>]*required[^>]*minlength="8"[^>]*autocomplete="current-password"/', $html);
            $this->assertStringContainsString('<meta name="robots" content="noindex', $html);
            foreach (['demo', 'Demonstr', 'sample', 'pavyzd', 'example', 'minlength="6"', 'autocomplete="off"'] as $label) {
                $this->assertStringNotContainsStringIgnoringCase($label, $this->main($html), "$locale: $label");
            }
        }
        $register = $this->get(route('en.account.register'))->getContent();
        $this->assertMatchesRegularExpression('/name="password"[^>]*minlength="8"[^>]*autocomplete="new-password"/', $register);
        $this->assertStringContainsString('name="name"', $register);
        $this->assertStringNotContainsString('name="password"', $this->get(route('en.account.reset'))->getContent());
    }

    private function main(string $html): string
    {
        preg_match('/<main.*?<\/main>/s', $html, $main);

        return preg_replace('/<input type="hidden" name="_token"[^>]*>/', '', $main[0]);
    }

    public function test_login_with_the_right_password_regenerates_the_session_and_opens_the_account(): void
    {
        $this->client();
        $session = null;
        $this->browse('GET', route('en.account.login'), session: $session);
        $before = $session;

        $this->browse('POST', route('en.account.login.store'), ['email' => 'Jonas@AIQME.test ', 'password' => self::PASSWORD], $session)
            ->assertRedirect(route('en.account'));
        $this->assertNotSame($before, $session);

        $this->browse('GET', route('en.account'), session: $session)->assertOk()->assertSee('Welcome, ')->assertSee('id="account-overview"', false);
        // Админка клиенту — 404.
        $this->browse('GET', route('en.admin'), session: $session)->assertNotFound();
    }

    public function test_wrong_password_and_unknown_email_get_the_same_neutral_error(): void
    {
        $this->client();
        foreach (['jonas@aiqme.test', 'nobody@aiqme.test'] as $email) {
            $session = null;
            $this->browse('GET', route('lt.account.login'), session: $session);
            $this->browse('POST', route('lt.account.login.store'), ['email' => $email, 'password' => 'wrong-password'], $session)
                ->assertRedirect(route('lt.account.login'))
                ->assertSessionHasErrors(['email' => 'Neteisingas el. paštas arba slaptažodis.']);
            $this->browse('GET', route('lt.account'), session: $session)->assertOk()->assertSee('Prisijungimas')->assertDontSee('address-form');
        }
        $this->assertGuest();
    }

    public function test_login_is_throttled_per_email_and_ip_and_per_ip(): void
    {
        $this->client();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('en.account.login.store'), ['email' => 'jonas@aiqme.test', 'password' => 'wrong-'.$i])
                ->assertSessionHasErrors(['email' => 'Incorrect email or password.']);
        }
        // Шестая попытка — даже с верным паролем — отклонена; другой e-mail с того же IP пока проходит.
        $this->post(route('en.account.login.store'), ['email' => 'jonas@aiqme.test', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('email');
        $this->assertMatchesRegularExpression('/^Too many attempts\. Try again in \d+ s\.$/', session('errors')->first('email'));
        $this->assertGuest();

        $other = $this->client('ona@aiqme.test');
        $this->post(route('en.account.login.store'), ['email' => 'ona@aiqme.test', 'password' => self::PASSWORD])->assertRedirect(route('en.account'));
        Auth::logout();

        // IP: всего 20 неудачных попыток с разными адресами — дальше отклоняется и верный вход другого клиента.
        for ($i = 0; $i < 15; $i++) {
            $this->post(route('en.account.login.store'), ['email' => "x{$i}@aiqme.test", 'password' => 'wrong']);
        }
        $this->post(route('en.account.login.store'), ['email' => $other->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertGuest();

        // Через минуту ограничение снимается.
        $this->travel(61)->seconds();
        $this->post(route('en.account.login.store'), ['email' => 'jonas@aiqme.test', 'password' => self::PASSWORD])->assertRedirect(route('en.account'));
    }

    public function test_admin_signs_in_through_the_account_form_and_reaches_admin_and_russian_pages(): void
    {
        $this->admin();
        $session = $this->signIn('admin@aiqme.test', locale: 'en');

        // Overview содержит Livewire-компонент (W-14): Livewire добавляет no-cache, must-revalidate, max-age=0 к private, no-store.
        $cache = $this->browse('GET', route('en.admin'), session: $session)->assertOk()->assertSee('AIQME management workspace')
            ->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->browse('GET', route('lt.admin'), session: $session)->assertOk()->assertSee('AIQME valdymo aplinka');
        $this->browse('GET', '/ru/', session: $session)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_admin_and_russian_pages_are_404_for_guests_and_clients(): void
    {
        $this->client();
        $session = $this->signIn('jonas@aiqme.test');
        foreach (['/lt/administravimas/', '/en/admin', '/ru/', '/ru/services'] as $path) {
            $guest = null;
            $this->browse('GET', $path, session: $guest)->assertNotFound();
            $this->browse('GET', $path, session: $session)->assertNotFound();
        }
    }

    public function test_registration_creates_a_signed_in_client_without_admin_rights(): void
    {
        $session = null;
        $this->browse('GET', route('lt.account.register'), session: $session);
        $this->browse('POST', route('lt.account.register.store'), [
            'name' => 'Ona Onaitė', 'email' => 'Ona@AIQME.test', 'password' => 'long-enough-1', 'is_admin' => '1', 'altcha' => $this->altcha(),
        ], $session)->assertRedirect(route('lt.account'));

        $user = User::where('email', 'ona@aiqme.test')->sole();
        $this->assertFalse($user->is_admin);
        $this->assertSame('Ona Onaitė', $user->name);
        $this->assertTrue(Hash::check('long-enough-1', $user->password));
        $this->browse('GET', route('lt.account'), session: $session)->assertOk()->assertSee('Sveiki, Ona Onaitė.');
        $this->browse('GET', route('lt.admin'), session: $session)->assertNotFound();
    }

    public function test_registration_rejects_a_short_password_a_taken_email_and_missing_fields(): void
    {
        $this->client();
        $this->post(route('en.account.register.store'), ['name' => 'A', 'email' => 'a@aiqme.test', 'password' => '1234567', 'altcha' => $this->altcha()])
            ->assertSessionHasErrors(['password' => 'The password must be at least 8 characters.']);
        $this->post(route('en.account.register.store'), ['name' => 'A', 'email' => 'JONAS@aiqme.test', 'password' => '12345678', 'altcha' => $this->altcha()])
            ->assertSessionHasErrors(['email' => 'An account with this email already exists.']);
        $this->post(route('lt.account.register.store'), ['email' => 'not-an-email', 'password' => '', 'altcha' => $this->altcha()])
            ->assertSessionHasErrors(['name' => 'Užpildykite šį lauką.', 'email' => 'Įveskite teisingą el. pašto adresą.', 'password' => 'Užpildykite šį lauką.']);

        $this->assertSame(1, User::count());
        $this->assertGuest();
    }

    public function test_logout_invalidates_the_session_and_a_copied_cookie_no_longer_works(): void
    {
        $this->client();
        $session = $this->signIn('jonas@aiqme.test');
        $copied = $session;
        $this->browse('GET', route('lt.account.addresses'), session: $session)->assertOk()->assertSee('address-form', false);

        $this->browse('POST', route('lt.account.logout'), session: $session)->assertRedirect(route('lt.account.login'));
        $this->assertNotSame($copied, $session);
        $this->assertFalse(DB::table('sessions')->where('id', $copied)->exists());

        $this->browse('GET', route('lt.account.addresses'), session: $copied)->assertOk()
            ->assertSee('Prisijungimas')->assertDontSee('address-form', false);
        $this->browse('POST', route('lt.account.addresses.update'), ['company' => 'UAB X'], $copied)->assertRedirect(route('lt.account.login'));
        $this->assertSame(0, DB::table('customer_profiles')->count());
    }

    /** Ссылка из письма в очереди (Mail::fake) — адрес на языке страницы запроса. */
    private function requestResetLink(string $email, string $locale = 'lt'): string
    {
        $session = null;
        $this->browse('GET', route($locale.'.account.reset'), session: $session);
        $this->browse('POST', route($locale.'.account.reset.store'), ['email' => $email], $session)
            ->assertRedirect(route($locale.'.account.reset'))->assertSessionHas('status');

        $url = null;
        Mail::assertQueued(ResetPasswordLink::class, function (ResetPasswordLink $mail) use ($email, &$url): bool {
            $url = $mail->url();

            return $mail->hasTo($email);
        });

        return $url;
    }

    public function test_password_reset_by_emailed_link_ends_the_old_session(): void
    {
        Mail::fake();
        $this->client();
        $old = $this->signIn('jonas@aiqme.test');
        $this->browse('GET', route('lt.account'), session: $old)->assertOk()->assertSee('account-overview', false);

        $url = $this->requestResetLink('jonas@aiqme.test');
        $this->assertStringStartsWith(config('app.url').'/lt/paskyra/reset/', $url);

        $browser = null;
        $this->browse('GET', $url, session: $browser)->assertOk()->assertSee('>Naujas slaptažodis', false)->assertDontSee('Naujas slaptažodis *')
            ->assertSee('value="jonas@aiqme.test"', false);
        $this->browse('POST', $url, ['email' => 'jonas@aiqme.test', 'password' => 'new-password-2'], $browser)
            ->assertRedirect(route('lt.account.login'))
            ->assertSessionHas('status', 'Slaptažodis pakeistas. Prisijunkite nauju slaptažodžiu.');

        // Следующий запрос прежней сессии ведёт на вход (AuthenticateSession), а не в кабинет.
        $this->browse('GET', route('lt.account'), session: $old)->assertRedirect(route('lt.account.login'));
        $this->browse('GET', route('lt.account'), session: $old)->assertOk()->assertDontSee('account-overview', false);

        // Старый пароль больше не подходит, новый — подходит; ссылка одноразовая.
        $this->post(route('lt.account.login.store'), ['email' => 'jonas@aiqme.test', 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->signIn('jonas@aiqme.test', 'new-password-2');
        $this->post($url, ['email' => 'jonas@aiqme.test', 'password' => 'third-password-3'])
            ->assertSessionHasErrors(['email' => 'Atkūrimo nuoroda neteisinga arba nebegalioja. Paprašykite naujos.']);
        $this->assertTrue(Hash::check('new-password-2', User::sole()->password));
    }

    public function test_admin_session_also_ends_after_a_password_reset(): void
    {
        Mail::fake();
        $this->admin();
        $old = $this->signIn('admin@aiqme.test', locale: 'en');
        $this->browse('GET', route('en.admin'), session: $old)->assertOk();

        $url = $this->requestResetLink('admin@aiqme.test', 'en');
        $this->post($url, ['email' => 'admin@aiqme.test', 'password' => 'new-admin-pass'])->assertRedirect(route('en.account.login'));

        $this->browse('GET', route('en.admin'), session: $old)->assertRedirect(route('en.account.login'));
        $this->browse('GET', route('en.admin'), session: $old)->assertNotFound();
    }

    public function test_reset_request_answers_the_same_for_unknown_emails_and_sends_nothing(): void
    {
        Mail::fake();
        $this->client();

        $known = $this->post(route('en.account.reset.store'), ['email' => 'jonas@aiqme.test']);
        $unknown = $this->post(route('en.account.reset.store'), ['email' => 'nobody@aiqme.test']);
        // Повтор на тот же адрес раньше срока брокера — тот же ответ, второго письма нет.
        $again = $this->post(route('en.account.reset.store'), ['email' => 'jonas@aiqme.test']);

        foreach ([$known, $unknown, $again] as $response) {
            $response->assertSessionHasNoErrors()
                ->assertSessionHas('status', 'If an account with this email exists, we have sent a password reset link.');
        }
        Mail::assertQueuedCount(1);
        $this->from(route('en.account.reset'))->followingRedirects()->post(route('en.account.reset.store'), ['email' => 'nobody2@aiqme.test'])
            ->assertSee('If an account with this email exists, we have sent a password reset link.');
    }

    public function test_reset_requests_are_throttled_per_ip(): void
    {
        Mail::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('en.account.reset.store'), ['email' => "x{$i}@aiqme.test"])->assertSessionHasNoErrors();
        }
        $this->post(route('en.account.reset.store'), ['email' => 'x9@aiqme.test'])->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Too many attempts.', session('errors')->first('email'));
    }

    public function test_reset_link_expires_and_wrong_token_or_email_is_rejected(): void
    {
        Mail::fake();
        $this->client();
        $url = $this->requestResetLink('jonas@aiqme.test', 'en');

        $this->post(route('en.account.reset.update', ['token' => 'wrong-token']), ['email' => 'jonas@aiqme.test', 'password' => 'new-password-2'])
            ->assertSessionHasErrors(['email' => 'The reset link is invalid or has expired. Request a new one.']);
        $this->post($url, ['email' => 'nobody@aiqme.test', 'password' => 'new-password-2'])->assertSessionHasErrors('email');
        $this->post($url, ['email' => 'jonas@aiqme.test', 'password' => 'short'])
            ->assertSessionHasErrors(['password' => 'The password must be at least 8 characters.']);

        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
        $this->post($url, ['email' => 'jonas@aiqme.test', 'password' => 'new-password-2'])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::PASSWORD, User::sole()->password));
    }

    public function test_reset_mail_is_queued_in_the_page_language_with_link_and_expiry(): void
    {
        Mail::fake();
        $this->client();
        $this->requestResetLink('jonas@aiqme.test', 'en');

        Mail::assertQueued(ResetPasswordLink::class, function (ResetPasswordLink $mail): bool {
            $html = $mail->render();
            $mail->assertHasSubject('Reset your password');

            return $mail->locale === 'en'
                && str_contains($html, 'href="'.e($mail->url()).'"')
                && str_contains($html, 'The link is valid for 60 minutes and can be used once.');
        });
    }

    public function test_session_payload_is_encrypted_and_hides_the_reset_token(): void
    {
        $this->assertTrue(config('session.encrypt'));

        Mail::fake();
        $this->client();
        $url = $this->requestResetLink('jonas@aiqme.test');
        $token = basename(parse_url($url, PHP_URL_PATH));

        $session = null;
        $this->browse('GET', $url, session: $session)->assertOk();

        $payload = DB::table('sessions')->where('id', $session)->value('payload');
        $this->assertNotNull($payload);
        $this->assertStringNotContainsString($token, base64_decode($payload));
        $this->assertStringNotContainsString('_previous', base64_decode($payload));
    }
}
