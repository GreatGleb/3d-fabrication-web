<?php

namespace App\Domain\Orders;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Domain\Integrations\IntegrationEvents;
use App\Domain\Orders\Models\OrderMeta;
use App\Domain\Orders\Models\ProductionState;
use App\Domain\Payments\Models\PaymentRequest;
use App\Models\OrderCharge;
use App\Models\ProductLine;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use Lunar\Models\Cart;
use Lunar\Models\Channel;
use Lunar\Models\Currency;
use Lunar\Models\Order;

/**
 * Заказ товаров «Pirkti» из корзины (правка 05.11, H-08). В одной транзакции: корзина Lunar из строк ProductLine (снимок
 * названия, количество, цена за единицу) и при доставке — служебной строки delivery по настройке «Pristatymo kaina
 * (prekėms)» → заказ того же журнала через RequestCartValidator с номером общей серии (OrderNumber), order_meta с
 * пометкой products и хешем гостевого токена, состояние сразу в стадии confirmed (цены подтверждены — это цены товаров),
 * платёжный запрос pending на всю сумму (intent — при открытии страницы оплаты, W-16) и событие «заявка отправлена»
 * (то же письмо AIQME, с позициями-товарами). Счёт после оплаты выпускает прежний механизм (IssueInvoice, W-17).
 */
final class ProductOrder
{
    use Localizable;

    public function __construct(
        private readonly PublishedContent $content,
        private readonly IntegrationEvents $events,
    ) {}

    /**
     * @param  array{locale: string, contact: array<string, ?string>, delivery: string, address: ?array<string, string>}  $data
     * @param  Collection<int, array{product: ContentTranslation, quantity: int, unit: int, total: int}>  $lines
     * @return array{SubmittedOrder, PaymentRequest}
     */
    public function place(array $data, Collection $lines, ?User $user): array
    {
        $other = $data['locale'] === 'lt' ? 'en' : 'lt';
        $titles = $this->content->list(ContentType::Product, $other)->pluck('title', 'content_item_id');

        return DB::transaction(function () use ($data, $lines, $user, $other, $titles): array {
            $cart = Cart::create([
                'user_id' => $user?->id,
                'channel_id' => Channel::getDefault()->id,
                'currency_id' => Currency::whereCode('EUR')->firstOrFail()->id,
            ]);
            $labels = [];
            foreach ($lines as $id => $line) {
                $cart->add(ProductLine::create([
                    'content_item_id' => $id,
                    'title' => [$data['locale'] => $line['product']->title, $other => $titles[$id] ?? $line['product']->title],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit'],
                ]), 1);
                // Письмо AIQME — на LT (как у заявок): литовское название товара.
                $labels[] = ($data['locale'] === 'lt' ? $line['product']->title : ($titles[$id] ?? $line['product']->title)).' × '.$line['quantity'].' — '.Number::currency($line['total'] / 100, in: 'EUR', locale: 'lt');
            }
            $charge = $data['delivery'] === 'ship' ? OrderCharge::create(['kind' => OrderCharge::DELIVERY, 'amount' => ProductCart::deliveryCents()]) : null;
            if ($charge) {
                $cart->add($charge, 1);
            }

            $order = $this->withLocale($data['locale'], fn (): Order => $cart->createOrder());
            $order->update(['placed_at' => now()]);
            $charge?->update(['order_id' => $order->id]);

            $token = $user ? null : OrderSubmission::guestToken();
            $meta = OrderMeta::create([
                'order_id' => $order->id,
                'number' => (int) $order->reference,
                'guest_token_hash' => $token === null ? null : hash('sha256', $token),
                'submission_token' => (string) Str::uuid(),
                'submission_digest' => hash('sha256', json_encode([$user?->id, $data, $labels], JSON_THROW_ON_ERROR)),
                'locale' => $data['locale'],
                'contact' => $data['contact'],
                'delivery' => $data['delivery'],
                'address' => $data['address'],
                'settings' => [],
                'products' => true,
            ]);
            ProductionState::create(['order_id' => $order->id, 'stage' => ProductionStage::Confirmed, 'confirmed_at' => now()]);
            $payment = PaymentRequest::create([
                'public_id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'amount' => $order->total->value,
                'currency' => 'EUR',
                'state' => PaymentRequest::PENDING,
            ]);
            $this->events->record('order.submitted', $order, [
                'kind' => 'order',
                'products' => true,
                'number' => $meta->number,
                'name' => $data['contact']['name'],
                'email' => $data['contact']['email'],
                'phone' => $data['contact']['phone'],
                'company' => $data['contact']['company'],
                'subject' => (string) $meta->number,
                'summary' => Str::limit(implode(', ', $labels), 200),
                'attachments' => 0,
                'files' => $labels,
                'specialist' => false,
                'amount' => number_format($order->total->value / 100, 2, '.', ''),
                'admin_url' => route('lt.admin', ['order' => $order->id]),
            ]);

            return [new SubmittedOrder($order, $meta, $token), $payment];
        });
    }
}
