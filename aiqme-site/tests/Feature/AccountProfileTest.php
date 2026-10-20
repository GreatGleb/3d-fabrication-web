<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Вкладка «Addresses & company» (W-07, §1.3, §2.5): все поля необязательны, хранятся в customer_profiles (один на
 * пользователя); клиент видит и меняет только свой профиль; гостю — форма входа.
 */
class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    private const VALUES = [
        'name' => 'Jonas Petraitis', 'company' => 'UAB Detalė', 'company_code' => '300000001', 'vat_code' => 'LT100000001',
        'address' => 'Gedimino pr. 1', 'city' => 'Vilnius', 'postcode' => 'LT-01103',
    ];

    public function test_tab_shows_the_prototype_form_with_seven_optional_fields_and_sign_out(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('en.account.addresses'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->getContent();

        $this->assertStringContainsString('<h1>Customer account</h1>', $html);
        $this->assertStringContainsString('<h2>Addresses &amp; company details</h2>', $html);
        foreach (['Name', 'Company', 'Company number', 'VAT number', 'Address', 'City', 'Postal code'] as $label) {
            $this->assertMatchesRegularExpression('/<label class="field"[^>]*>'.preg_quote($label, '/').'\s*<input/', $html);
        }
        preg_match('/<form class="panel" id="address-form".*?<\/form>/s', $html, $form);
        $this->assertStringNotContainsString('required', $form[0]);
        $this->assertStringContainsString('>Save details</button>', $form[0]);
        $this->assertStringContainsString('form="logout-form">Sign out</button>', $html);
        $this->assertStringNotContainsStringIgnoringCase('demo', $html);
        $this->assertStringNotContainsString('Demonstration data', $html);
    }

    public function test_profile_is_saved_in_customer_profiles_and_shown_again(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->post(route('lt.account.addresses.update'), self::VALUES + ['user_id' => 999])
            ->assertRedirect(route('lt.account.addresses'))->assertSessionHas('status', 'Duomenys išsaugoti.');

        $this->assertDatabaseHas('customer_profiles', ['user_id' => $client->id] + self::VALUES);
        $page = $this->get(route('lt.account.addresses'))->assertOk();
        foreach (self::VALUES as $value) {
            $page->assertSee('value="'.e($value).'"', false);
        }

        // Повторное сохранение меняет ту же строку; пустые поля допустимы.
        $this->post(route('lt.account.addresses.update'), ['city' => 'Kaunas'])->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerProfile::count());
        $profile = $client->profile()->sole();
        $this->assertSame('Kaunas', $profile->city);
        $this->assertNull($profile->company);
    }

    public function test_too_long_value_is_rejected_without_saving(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('en.account.addresses.update'), ['company' => str_repeat('a', 256)])
            ->assertSessionHasErrors(['company' => 'Too long: up to 255 characters.']);
        $this->assertSame(0, CustomerProfile::count());
    }

    public function test_two_clients_see_and_change_only_their_own_profile(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $this->actingAs($a)->post(route('en.account.addresses.update'), self::VALUES);

        $this->actingAs($b)->get(route('en.account.addresses'))->assertOk()
            ->assertDontSee('UAB Detalė')->assertDontSee('LT100000001');
        $this->actingAs($b)->post(route('en.account.addresses.update'), ['company' => 'B company']);

        $this->assertSame('UAB Detalė', $a->profile()->sole()->company);
        $this->assertSame('B company', $b->profile()->sole()->company);
        $this->assertNull($b->profile()->sole()->vat_code);
        $this->actingAs($a)->get(route('en.account'))->assertSee('UAB Detalė')->assertDontSee('B company');
    }

    public function test_guest_gets_the_login_form_on_tabs_and_cannot_save(): void
    {
        foreach ([route('lt.account'), route('lt.account.addresses'), route('en.account.addresses')] as $url) {
            $this->get($url)->assertOk()->assertSee('id="auth-form" data-mode="login"', false)->assertDontSee('address-form');
        }
        $this->post(route('en.account.addresses.update'), self::VALUES)->assertRedirect(route('en.account.login'));
        $this->assertSame(0, CustomerProfile::count());
    }

    public function test_profile_is_deleted_with_its_user(): void
    {
        $client = User::factory()->create();
        $this->actingAs($client)->post(route('en.account.addresses.update'), self::VALUES);
        $client->delete();

        $this->assertSame(0, CustomerProfile::count());
    }
}
