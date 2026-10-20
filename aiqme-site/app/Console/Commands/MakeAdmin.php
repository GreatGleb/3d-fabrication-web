<?php

namespace App\Console\Commands;

use App\Console\Support\Terminal;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

/**
 * Создаёт единственного администратора или меняет пароль существующего (§2.5, D-29).
 * Пароль не принимается ни аргументом, ни опцией: в терминале — скрытый ввод,
 * без терминала — одна строка из stdin по флагу --password-stdin.
 */
#[Signature('aiqme:make-admin
    {email? : Email администратора (обязателен, если администратора ещё нет)}
    {--name= : Имя администратора (по умолчанию — часть email до @)}
    {--password-stdin : Прочитать пароль одной строкой из stdin (без терминала)}')]
#[Description('Создать единственного администратора AIQME или сменить его пароль')]
class MakeAdmin extends Command
{
    public const MIN_PASSWORD_LENGTH = 12;

    public function handle(Terminal $terminal): int
    {
        $admin = User::query()->where('is_admin', true)->first();
        // E-mail — строчными, как при регистрации и входе (W-07).
        $email = $this->argument('email') === null ? null : Str::lower($this->argument('email'));

        if ($admin === null && $email === null) {
            $this->error('Администратора ещё нет: укажите email, например: bin/php artisan aiqme:make-admin admin@example.com');

            return self::FAILURE;
        }

        if ($admin !== null && $email !== null && strcasecmp($admin->email, $email) !== 0) {
            $this->error("Администратор уже есть ({$admin->email}); второй не создаётся. Чтобы сменить пароль, запустите команду с его email или без email.");

            return self::FAILURE;
        }

        if ($admin === null) {
            $validator = Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']]);
            if ($validator->fails()) {
                $this->error($validator->errors()->first('email'));

                return self::FAILURE;
            }

            if (User::query()->where('email', $email)->exists()) {
                $this->error("Пользователь {$email} уже зарегистрирован как клиент; права администратора ему не выдаются. Укажите другой email.");

                return self::FAILURE;
            }
        }

        $password = $this->readPassword($terminal);
        if ($password === null) {
            return self::FAILURE;
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $this->error('Пароль короче '.self::MIN_PASSWORD_LENGTH.' символов; администратор не изменён.');

            return self::FAILURE;
        }

        if ($admin !== null) {
            $admin->forceFill(['password' => $password])->save();
            $this->info("Пароль администратора {$admin->email} изменён.");

            return self::SUCCESS;
        }

        $user = new User;
        $user->forceFill([
            'name' => $this->option('name') ?: strstr($email, '@', true),
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
        ]);

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            // Частичный уникальный индекс users_single_admin: параллельный запуск уже создал администратора.
            $this->error('Администратор уже создан другим запуском команды; второй не создаётся.');

            return self::FAILURE;
        }

        $this->info("Администратор {$email} создан.");

        return self::SUCCESS;
    }

    private function readPassword(Terminal $terminal): ?string
    {
        if ($this->option('password-stdin')) {
            $password = $terminal->readLine();
            if ($password === null || $password === '') {
                $this->error('--password-stdin: в stdin нет пароля (ожидается одна непустая строка).');

                return null;
            }

            return $password;
        }

        if (! $terminal->isInteractive() || ! $this->input->isInteractive()) {
            $this->error('Нет терминала для скрытого ввода пароля. Передайте пароль одной строкой в stdin с флагом --password-stdin, например: bin/php artisan aiqme:make-admin admin@example.com --password-stdin < файл-с-паролем');

            return null;
        }

        $password = password(label: 'Пароль администратора', required: true);
        $confirmation = password(label: 'Повторите пароль', required: true);

        if (! hash_equals($password, $confirmation)) {
            $this->error('Пароли не совпадают; администратор не изменён.');

            return null;
        }

        return $password;
    }
}
