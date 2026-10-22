<?php

namespace Tests\Feature\Scenarios;

use App\Domain\Integrations\Sheets\SheetsClient;
use App\Domain\Integrations\Sheets\TokenProvider;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Tests\Support\FakeSheets;

/**
 * Общее для сквозных сценариев §9.3 (W-30): администратор, «новый посетитель» и подключённые Sheets — фиктивные ключ и ID,
 * заглушка токена W-18 и фейк сетки FakeSheets вместо HTTP-клиента (запросов к Google нет).
 */
trait ScenarioSupport
{
    private FakeSheets $sheets;

    private function connectSheets(): void
    {
        config(['aiqme.sheets.key' => 'test-key-not-real', 'aiqme.sheets.spreadsheet_id' => 'test-spreadsheet-not-real']);
        $this->app->instance(TokenProvider::class, new class implements TokenProvider
        {
            public function token(): string
            {
                return 'test-google-token-not-real';
            }
        });
        $this->sheets = new FakeSheets;
        $this->app->instance(SheetsClient::class, $this->sheets);
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create(['email' => 'admin@aiqme.test']);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    /** Новый посетитель: без входа и с пустой сессией. */
    private function visitor(): static
    {
        Auth::forgetGuards();
        $this->flushSession();

        return $this;
    }

    /**
     * Типы событий листа Events по порядку Event ID.
     *
     * @return list<string>
     */
    private function eventTypes(): array
    {
        $rows = $this->sheets->rows('Events');
        usort($rows, fn (array $a, array $b): int => (int) $a[0] <=> (int) $b[0]);

        return array_column($rows, 3);
    }
}
