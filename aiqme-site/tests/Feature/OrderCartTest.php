<?php

namespace Tests\Feature;

use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\OrderNumber;
use App\Models\OrderCharge;
use App\Models\StlFile;
use App\Models\StlPart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lunar\Exceptions\Carts\CartException;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\CustomerGroup;
use Lunar\Models\Language;
use Lunar\Models\TaxClass;
use Lunar\Models\TaxRate;
use Lunar\Models\TaxZone;
use Tests\TestCase;

/**
 * Корзина Lunar заявки W-12 (§2.2, §3.1): базовые данные aiqme:bootstrap (идемпотентно, без ставок налога) и
 * RequestCartValidator из эксперимента — лимит 10 строк и количество 1–100 только по StlPart, служебная строка fee не
 * считается, файл — владельца корзины и ещё без заказа (изоляция чужой корзины эксперимента).
 */
class OrderCartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function lunarSnapshot(): array
    {
        return collect(['lunar_languages', 'lunar_currencies', 'lunar_channels', 'lunar_customer_groups', 'lunar_tax_zones', 'lunar_tax_classes', 'lunar_tax_rates', 'lunar_tax_rate_amounts'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    public function test_bootstrap_creates_base_data_once_and_without_tax_rates(): void
    {
        $this->assertSame(['lt' => true, 'en' => false], Language::orderBy('id')->pluck('default', 'code')->all());
        $this->assertSame(['EUR', 2, true, true], [Currency::getDefault()->code, Currency::getDefault()->decimal_places, Currency::getDefault()->enabled, Currency::count() === 1]);
        $this->assertSame(['webstore', 'retail'], [Channel::getDefault()->handle, CustomerGroup::getDefault()->handle]);
        $this->assertTrue(TaxZone::getDefault()->active);
        $this->assertNotNull(TaxClass::getDefault());
        $this->assertSame(0, TaxRate::count());

        $before = $this->lunarSnapshot();
        $this->travel(5)->minutes();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        $this->assertSame($before, $this->lunarSnapshot());
    }

    private function cart(?User $user = null): Cart
    {
        return Cart::create(['user_id' => $user?->id, 'channel_id' => Channel::getDefault()->id, 'currency_id' => Currency::getDefault()->id]);
    }

    private function part(?User $owner = null, int $quantity = 1, int $cents = 12): StlPart
    {
        $file = StlFile::create(['user_id' => $owner?->id, 'path' => 'stl/'.Str::uuid().'.stl', 'original_name' => 'cube.stl', 'size' => 684,
            'sha256' => str_repeat('0', 64), 'triangles' => 12, 'analysis' => []]);

        return StlPart::create(['stl_file_id' => $file->id, 'parameters' => ['material' => 'PLA', 'quantity' => $quantity], 'measurement' => [], 'estimate' => $cents]);
    }

    private function assertRejected(Cart $cart): void
    {
        $orders = DB::table('lunar_orders')->count();
        try {
            $cart->createOrder();
            $this->fail('Корзина должна быть отклонена валидатором.');
        } catch (CartException) {
            $this->assertSame($orders, DB::table('lunar_orders')->count());
        }
    }

    public function test_ten_parts_plus_fee_make_an_order_and_the_total_is_the_sum_of_lines(): void
    {
        $cart = $this->cart();
        foreach (range(1, 10) as $i) {
            $cart->add($this->part(cents: $i), 1);
        }
        $cart->add(OrderCharge::create(['kind' => OrderCharge::FEE, 'amount' => 500]), 1);

        $order = $cart->createOrder();
        $this->assertSame(55 + 500, $order->total->value);
        $this->assertSame(0, $order->tax_total->value);
        $this->assertCount(11, $order->lines);
    }

    public function test_more_than_ten_parts_are_rejected(): void
    {
        $cart = $this->cart();
        foreach (range(1, 11) as $ignored) {
            $cart->add($this->part(), 1);
        }
        $this->assertRejected($cart);
    }

    public function test_cart_with_only_a_fee_is_rejected(): void
    {
        $cart = $this->cart();
        $cart->add(OrderCharge::create(['kind' => OrderCharge::FEE, 'amount' => 500]), 1);
        $this->assertRejected($cart);
    }

    public function test_quantity_lives_in_parameters_and_is_checked_there(): void
    {
        $cart = $this->cart();
        $cart->add($this->part(quantity: 101), 1);
        $this->assertRejected($cart);

        // Строка StlPart в корзине — всегда quantity = 1: другое количество не добавляется.
        $this->expectException(CartException::class);
        $this->cart()->add($this->part(quantity: 2), 2);
    }

    public function test_file_of_another_owner_or_already_ordered_cannot_be_attached(): void
    {
        $buyer = User::factory()->create();
        $cart = $this->cart($buyer);
        $cart->add($this->part(User::factory()->create()), 1);
        $this->assertRejected($cart);

        // Гостевая корзина: файл клиента и файл уже размещённого заказа отклоняются (null === null доступа не даёт).
        $cart = $this->cart();
        $cart->add($this->part($buyer), 1);
        $this->assertRejected($cart);

        $ordered = $this->cart();
        $ordered->add($part = $this->part(), 1);
        $order = $ordered->createOrder();
        $part->file->update(['order_id' => $order->id]);
        $cart = $this->cart();
        $cart->add($part, 1);
        $this->assertRejected($cart);
    }

    /** Правка 12.11, K-05: номер заказа — случайный 8-значный, не следующий за предыдущим; счётчик заказов не растёт. */
    public function test_order_reference_is_a_random_eight_digit_number(): void
    {
        $references = [];
        foreach (range(1, 3) as $ignored) {
            $cart = $this->cart();
            $cart->add($this->part(), 1);
            $references[] = (int) $cart->createOrder()->reference;
        }

        foreach ($references as $index => $reference) {
            $this->assertMatchesRegularExpression('/^[1-9]\d{7}$/', (string) $reference);
            if ($index > 0) {
                $this->assertNotSame(1, abs($reference - $references[$index - 1]), 'номера идут подряд: '.implode(', ', $references));
            }
        }
        $this->assertCount(3, array_unique($references));
        $this->assertSame(0, DB::table('counters')->where('name', 'orders')->count());
    }

    /** Правка 12.11, K-05: совпадение с reference заказа или order_meta.number — новая попытка (подменённый генератор). */
    public function test_order_number_retries_when_the_candidate_is_taken(): void
    {
        $candidates = [12345678, 12345678, 23456789, 34567890, 45678901];
        $this->app->bind(OrderNumber::class, function () use (&$candidates) {
            return new class($candidates) extends OrderNumber
            {
                /** @param  list<int>  $candidates */
                public function __construct(private array &$candidates) {}

                protected function candidate(): int
                {
                    return array_shift($this->candidates);
                }
            };
        });

        $cart = $this->cart();
        $cart->add($this->part(), 1);
        $first = $cart->createOrder();
        $this->assertSame('12345678', $first->reference);

        // 23456789 занят только в order_meta.number: reference первого заказа другой.
        OrderMeta::create(['order_id' => $first->id, 'number' => 23456789, 'submission_token' => (string) Str::uuid(), 'submission_digest' => str_repeat('0', 64),
            'locale' => 'lt', 'contact' => [], 'delivery' => 'pickup', 'settings' => []]);
        $cart = $this->cart();
        $cart->add($this->part(), 1);
        // Второй заказ: 12345678 (reference первого) и 23456789 (order_meta.number) заняты — берётся третий кандидат.
        $this->assertSame('34567890', $cart->createOrder()->reference);
        $this->assertSame([45678901], $candidates);
    }
}
