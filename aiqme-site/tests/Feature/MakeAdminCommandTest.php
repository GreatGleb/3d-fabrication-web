<?php

namespace Tests\Feature;

use App\Console\Commands\MakeAdmin;
use App\Console\Support\Terminal;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeTerminal;
use Tests\TestCase;

class MakeAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-1';

    public function test_mass_assignment_cannot_grant_admin_rights(): void
    {
        $created = User::create([
            'name' => 'Client',
            'email' => 'client@example.test',
            'password' => self::PASSWORD,
            'is_admin' => true,
        ]);
        $this->assertFalse($created->fresh()->is_admin);

        $filled = new User;
        $filled->fill(['name' => 'Other', 'email' => 'other@example.test', 'password' => self::PASSWORD, 'is_admin' => true])->save();
        $this->assertFalse($filled->fresh()->is_admin);

        $created->update(['is_admin' => true]);
        $this->assertFalse($created->fresh()->is_admin);
        $this->assertSame(0, User::where('is_admin', true)->count());
    }

    public function test_hidden_terminal_input_creates_admin(): void
    {
        $this->useTerminal(new FakeTerminal(interactive: true));

        $this->artisan('aiqme:make-admin', ['email' => 'admin@example.test'])
            ->expectsQuestion('Пароль администратора', self::PASSWORD)
            ->expectsQuestion('Повторите пароль', self::PASSWORD)
            ->expectsOutputToContain('Администратор admin@example.test создан.')
            ->assertSuccessful();

        $admin = User::where('email', 'admin@example.test')->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertSame('admin', $admin->name);
    }

    public function test_hidden_input_with_mismatched_confirmation_creates_nothing(): void
    {
        $this->useTerminal(new FakeTerminal(interactive: true));

        $this->artisan('aiqme:make-admin', ['email' => 'admin@example.test'])
            ->expectsQuestion('Пароль администратора', self::PASSWORD)
            ->expectsQuestion('Повторите пароль', self::PASSWORD.'x')
            ->expectsOutputToContain('Пароли не совпадают')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_password_stdin_creates_admin_without_terminal(): void
    {
        $terminal = $this->useTerminal(new FakeTerminal(interactive: false, stdin: self::PASSWORD."\n"));

        $this->artisan('aiqme:make-admin', ['email' => 'admin@example.test', '--name' => 'AIQME', '--password-stdin' => true])
            ->expectsOutputToContain('Администратор admin@example.test создан.')
            ->assertSuccessful();

        $admin = User::where('is_admin', true)->sole();
        $this->assertSame('AIQME', $admin->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertSame(1, $terminal->reads);
    }

    public function test_without_terminal_and_without_password_stdin_fails_with_hint(): void
    {
        $this->useTerminal(new FakeTerminal(interactive: false, stdin: self::PASSWORD."\n"));

        $this->artisan('aiqme:make-admin', ['email' => 'admin@example.test'])
            ->expectsOutputToContain('--password-stdin')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_empty_stdin_creates_nothing(): void
    {
        $this->useTerminal(new FakeTerminal(interactive: false, stdin: null));

        $this->artisan('aiqme:make-admin', ['email' => 'admin@example.test', '--password-stdin' => true])
            ->expectsOutputToContain('в stdin нет пароля')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_short_password_is_rejected(): void
    {
        $this->useTerminal(new FakeTerminal(interactive: false, stdin: "short\n"));

        $this->artisan('aiqme:make-admin', ['email' => 'admin@example.test', '--password-stdin' => true])
            ->expectsOutputToContain('Пароль короче '.MakeAdmin::MIN_PASSWORD_LENGTH)
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_second_run_changes_password_of_the_only_admin(): void
    {
        $this->createAdminViaStdin('admin@example.test', self::PASSWORD);
        $adminId = User::where('is_admin', true)->sole()->id;

        $this->useTerminal(new FakeTerminal(interactive: false, stdin: "new-password-12345\n"));
        $this->artisan('aiqme:make-admin', ['--password-stdin' => true])
            ->expectsOutputToContain('Пароль администратора admin@example.test изменён.')
            ->assertSuccessful();

        $this->useTerminal(new FakeTerminal(interactive: true));
        $this->artisan('aiqme:make-admin', ['email' => 'ADMIN@example.test'])
            ->expectsQuestion('Пароль администратора', 'third-password-123')
            ->expectsQuestion('Повторите пароль', 'third-password-123')
            ->assertSuccessful();

        $admin = User::where('is_admin', true)->sole();
        $this->assertSame($adminId, $admin->id);
        $this->assertTrue(Hash::check('third-password-123', $admin->password));
        $this->assertSame(1, User::count());
    }

    public function test_second_admin_with_another_email_is_not_created(): void
    {
        $this->createAdminViaStdin('admin@example.test', self::PASSWORD);

        $this->useTerminal(new FakeTerminal(interactive: false, stdin: "another-password-1\n"));
        $this->artisan('aiqme:make-admin', ['email' => 'second@example.test', '--password-stdin' => true])
            ->expectsOutputToContain('второй не создаётся')
            ->assertFailed();

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check(self::PASSWORD, User::sole()->password));
    }

    public function test_existing_client_is_not_promoted(): void
    {
        $client = User::factory()->create(['email' => 'client@example.test']);

        $this->useTerminal(new FakeTerminal(interactive: false, stdin: self::PASSWORD."\n"));
        $this->artisan('aiqme:make-admin', ['email' => 'client@example.test', '--password-stdin' => true])
            ->assertFailed();

        $this->assertFalse($client->fresh()->is_admin);
        $this->assertSame(0, User::where('is_admin', true)->count());
    }

    public function test_database_rejects_second_admin_row(): void
    {
        $this->createAdminViaStdin('admin@example.test', self::PASSWORD);

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->make(['email' => 'second@example.test'])->forceFill(['is_admin' => true])->save();
    }

    public function test_password_cannot_be_passed_as_argument_or_option(): void
    {
        $definition = Artisan::all()['aiqme:make-admin']->getDefinition();

        $this->assertSame(['email'], array_keys($definition->getArguments()));
        $valueOptions = collect($definition->getOptions())
            ->filter(fn ($option) => $option->acceptValue())
            ->keys()
            ->reject(fn (string $name) => in_array($name, ['env'], true))
            ->values()
            ->all();
        $this->assertSame(['name'], $valueOptions);
        $this->assertFalse($definition->getOption('password-stdin')->acceptValue());
    }

    public function test_database_seeder_creates_no_users(): void
    {
        $this->seed();

        $this->assertSame(0, User::count());
    }

    private function createAdminViaStdin(string $email, string $password): void
    {
        $this->useTerminal(new FakeTerminal(interactive: false, stdin: $password."\n"));
        $this->artisan('aiqme:make-admin', ['email' => $email, '--password-stdin' => true])->assertSuccessful();
    }

    private function useTerminal(FakeTerminal $terminal): FakeTerminal
    {
        $this->app->instance(Terminal::class, $terminal);

        return $terminal;
    }
}
