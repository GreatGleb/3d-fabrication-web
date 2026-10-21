<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\TaxClass;
use Lunar\Models\TaxZone;

/**
 * Базовые данные Lunar для заявок (§2.2, W-12): канал, языки lt (по умолчанию) и en, валюта EUR по умолчанию, группа
 * клиентов, налоговая зона и класс по умолчанию без ставок (сумма заказа = сумма строк; VAT считает счёт AIQME, §3.5).
 * Идемпотентна: записи ищутся по коду, handle или имени и создаются только при отсутствии — повторный запуск ничего не меняет. Запускается при развёртывании после
 * migrate (§8.3) и в тестах; не штатный установщик Lunar и не сидер-фикстуры эксперимента.
 */
#[Signature('aiqme:bootstrap')]
#[Description('Создать базовые данные Lunar AIQME (канал, языки, EUR, группа клиентов, налоговые зона и класс)')]
class Bootstrap extends Command
{
    public function handle(): int
    {
        DB::transaction(function (): void {
            Language::firstOrCreate(['code' => 'lt'], ['name' => 'Lietuvių', 'default' => true]);
            Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'default' => false]);
            Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'default' => true, 'enabled' => true]);
            Channel::firstOrCreate(['handle' => 'webstore'], ['name' => 'AIQME', 'default' => true, 'url' => config('app.url')]);
            CustomerGroup::firstOrCreate(['handle' => 'retail'], ['name' => 'Retail', 'default' => true]);
            TaxZone::firstOrCreate(['name' => 'Default Tax Zone'], ['zone_type' => 'country', 'price_display' => 'tax_inclusive', 'default' => true, 'active' => true]);
            TaxClass::firstOrCreate(['name' => 'Default Tax Class'], ['default' => true]);
        });
        $this->info('Lunar: канал, языки lt/en, EUR, группа клиентов, налоговые зона и класс — готовы.');

        return self::SUCCESS;
    }
}
