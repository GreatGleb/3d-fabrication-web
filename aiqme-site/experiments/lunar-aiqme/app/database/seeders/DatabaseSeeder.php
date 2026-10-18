<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Lunar\Admin\Models\Staff;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Customer;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\TaxClass;
use Lunar\Models\TaxZone;
use Spatie\LaravelBlink\BlinkFacade;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Channel::firstOrCreate(['handle' => 'probe'], ['name' => 'Synthetic workshop', 'default' => true, 'url' => 'http://127.0.0.1:8857']);
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'default' => true]);
        Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'default' => true, 'enabled' => true]);
        TaxZone::firstOrCreate(['name' => 'Synthetic zone'], ['zone_type' => 'country', 'price_display' => 'tax_exclusive', 'default' => true, 'active' => true]);
        $group = CustomerGroup::firstOrCreate(['handle' => 'retail'], ['name' => 'Probe buyers', 'default' => true]);
        TaxClass::firstOrCreate(['name' => 'Synthetic no-tax fixture'], ['default' => true]);
        BlinkFacade::flush();
        foreach (['buyer', 'admin'] as $name) {
            $user = User::firstOrCreate(['email' => $name.'@example.test'], ['name' => ucfirst($name), 'password' => 'local-probe-only']);
            $user->forceFill(['is_admin' => $name === 'admin'])->save();
            if ($name === 'buyer' && ! $user->customers()->exists()) {
                $customer = Customer::create(['first_name' => 'Synthetic', 'last_name' => 'Buyer']);
                $customer->users()->attach($user);
                $customer->customerGroups()->attach($group);
            }
        }
        Staff::firstOrCreate(['email' => 'admin@example.test'], ['first_name' => 'Synthetic', 'last_name' => 'Admin', 'admin' => true, 'password' => 'local-probe-only']);
    }
}
