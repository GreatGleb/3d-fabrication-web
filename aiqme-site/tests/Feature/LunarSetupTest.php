<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Lunar\Base\TelemetryServiceInterface;
use Tests\TestCase;

class LunarSetupTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        // Панель Filament включает статический флаг вставки ассетов Livewire; без сброса он переживает тест
        // и добавляет стили Livewire в ответы следующих тестов.
        Livewire::flushState();

        parent::tearDown();

        foreach ($this->savedEnv as $name => $value) {
            $this->setEnv($name, $value === false ? null : $value);
        }
    }

    public function test_lunar_panel_routes_are_absent_when_flag_is_off(): void
    {
        $this->assertFalse(config('aiqme.lunar_panel'));
        $this->assertSame([], $this->lunarRoutes());

        $this->get('/lunar')->assertNotFound();
        $this->get('/lunar/login')->assertNotFound();
    }

    public function test_lunar_panel_is_registered_locally_when_flag_is_on(): void
    {
        $this->bootWith(['APP_ENV' => 'local', 'AIQME_LUNAR_PANEL' => 'true']);

        $this->assertTrue(config('aiqme.lunar_panel'));
        $this->assertContains('lunar/login', $this->lunarRoutes());
        $this->get('/lunar/login')->assertOk();
    }

    public function test_lunar_panel_is_never_registered_in_production(): void
    {
        $this->bootWith(['APP_ENV' => 'production', 'AIQME_LUNAR_PANEL' => 'true']);

        $this->assertTrue($this->app->environment('production'));
        $this->assertSame([], $this->lunarRoutes());
        $this->assertFalse(AppServiceProvider::lunarPanelEnabled('production', true));
        $this->assertTrue(AppServiceProvider::lunarPanelEnabled('local', true));
        $this->assertFalse(AppServiceProvider::lunarPanelEnabled('local', false));
    }

    public function test_lunar_stripe_addon_is_not_installed_and_has_no_webhook(): void
    {
        $this->assertFalse(InstalledVersions::isInstalled('lunarphp/stripe'));
        $this->assertSame('v16.6.0', InstalledVersions::getPrettyVersion('stripe/stripe-php'));
        $this->assertSame('1.5.0', InstalledVersions::getPrettyVersion('lunarphp/core'));
        $this->assertSame('v3.8.10', InstalledVersions::getPrettyVersion('livewire/livewire'));

        $this->assertFalse(collect(Route::getRoutes()->getRoutes())->contains(fn ($route) => str_contains($route->uri(), 'stripe')));
        $this->post('/stripe/webhook')->assertNotFound();
    }

    public function test_lunar_telemetry_is_opted_out(): void
    {
        $this->assertFalse($this->app->make(TelemetryServiceInterface::class)->shouldRun());
    }

    /**
     * @return list<string>
     */
    private function lunarRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => $uri === 'lunar' || str_starts_with($uri, 'lunar/'))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function bootWith(array $variables): void
    {
        foreach ($variables as $name => $value) {
            $this->savedEnv[$name] ??= getenv($name);
            $this->setEnv($name, $value);
        }

        $this->refreshApplication();
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}
