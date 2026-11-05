<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\PublishedContent;
use App\Domain\Orders\OrderAccess;
use App\Domain\Orders\ProductCart;
use App\Domain\Orders\ProductOrder;
use App\Domain\Payments\Models\PaymentRequest;
use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Корзина и оформление покупки товаров «Pirkti» (правка 05.11, H-08). Корзина — в сессии (ProductCart), гостю тоже:
 * «Į krepšelį» со страницы товара, изменение количества и удаление, итог с доставкой. Оформление — поля и проверки шага
 * «Užklausos pateikimas» калькулятора (телефон обязателен), доставка «Pristatymas visoje ES» / «Atsiėmimas». Товар,
 * снятый с публикации или переведённый в «Gauti pasiūlymą», убирается из корзины с сообщением, и заказ не создаётся,
 * пока покупатель не увидит новый итог. Заказ — ProductOrder; затем покупатель (гость — с правом на заказ в сессии)
 * сразу переходит на страницу оплаты Stripe платёжного запроса (W-16).
 */
class CartController extends Controller
{
    use PageSeo;

    public function __construct(private readonly ProductCart $cart, private readonly PublishedContent $content) {}

    public function show(Request $request): View
    {
        $locale = app()->getLocale();
        $lines = $this->cart->lines($locale, $removed);
        if ($removed) {
            session()->now('status', __('public.cart.removed'));
        }

        return view('public.cart', [
            ...$this->seo('cart', null, null, __('pages.cart.description')),
            'lines' => $lines,
            'delivery' => ProductCart::deliveryCents(),
            'buyer' => $this->buyer($request->user()),
        ]);
    }

    /**
     * «Į krepšelį» (правка 05.11, H-07): опубликованный на локали товар «Pirkti» добавляется в корзину, количество
     * складывается, не больше QUANTITY_MAX. Товар «Gauti pasiūlymą», неизвестный или не опубликованный — 404.
     */
    public function add(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product' => ['required', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.ProductCart::QUANTITY_MAX],
        ]);
        $product = $this->content->find(ContentType::Product, app()->getLocale(), $data['product']);
        abort_unless($product?->item->isForSale(), 404);
        $this->cart->add($product->content_item_id, (int) $data['quantity']);

        return redirect()->route(app()->getLocale().'.cart');
    }

    /** Изменение количества позиции корзины (0 — удалить). */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'item' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:0', 'max:'.ProductCart::QUANTITY_MAX],
        ], ['*' => __('calculator.submit.quantity')]);
        $this->cart->set((int) $data['item'], (int) $data['quantity']);

        return redirect()->route(app()->getLocale().'.cart');
    }

    /** Оформление: проверка полей, заказ и переход на страницу оплаты. */
    public function store(Request $request, ProductOrder $orders, OrderAccess $access): RedirectResponse
    {
        $locale = app()->getLocale();
        if (filled($request->input(FormController::HONEYPOT))) {
            throw ValidationException::withMessages(['form' => __('public.forms.rejected')]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'company_code' => ['nullable', 'string', 'max:255'],
            'vat_code' => ['nullable', 'string', 'max:255'],
            'delivery' => $this->choice('calculator.checkout.deliveries'),
            'country' => ['exclude_unless:delivery,ship', ...$this->choice('calculator.countries')],
            'city' => ['exclude_unless:delivery,ship', 'required', 'string', 'max:255'],
            'address' => ['exclude_unless:delivery,ship', 'required', 'string', 'max:255'],
            'postcode' => ['exclude_unless:delivery,ship', 'required', 'string', 'max:50'],
        ], [
            'required' => __('public.forms.required'),
            'email' => __('public.forms.email'),
            'max' => __('public.forms.max'),
            'in' => __('public.forms.choice'),
        ]);

        $lines = $this->cart->lines($locale, $removed);
        if ($removed) {
            return redirect()->route($locale.'.cart')->withInput()->with('status', __('public.cart.removed'));
        }
        if ($lines->isEmpty()) {
            return redirect()->route($locale.'.cart');
        }
        $total = $lines->sum('total') + ($data['delivery'] === 'ship' ? ProductCart::deliveryCents() : 0);
        if ($total < PaymentRequest::MINIMUM) {
            throw ValidationException::withMessages(['form' => __('public.cart.minimum', [
                'amount' => Number::currency(PaymentRequest::MINIMUM / 100, in: 'EUR', locale: $locale),
            ])]);
        }

        [$submitted, $payment] = $orders->place([
            'locale' => $locale,
            'contact' => [
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'company' => $data['company'] ?? null,
                'company_code' => $data['company_code'] ?? null,
                'vat_code' => $data['vat_code'] ?? null,
            ],
            'delivery' => $data['delivery'],
            'address' => $data['delivery'] === 'ship'
                ? ['country' => $data['country'], 'city' => $data['city'], 'address' => $data['address'], 'postcode' => $data['postcode']]
                : null,
        ], $lines, $request->user());
        $this->cart->clear();
        if ($submitted->guestToken !== null) {
            $access->grantGuest($submitted->meta);
        }

        return redirect()->route($locale.'.checkout', $payment->public_id);
    }

    /** @return list<mixed> */
    private function choice(string $options): array
    {
        return ['required', Rule::in(array_map('strval', array_keys(__($options))))];
    }

    /**
     * Профиль вошедшего клиента для формы (как в калькуляторе): имя, email, компания, коды и адрес; гостю — пусто.
     *
     * @return array<string, ?string>
     */
    private function buyer(?User $user): array
    {
        if ($user === null) {
            return [];
        }
        $profile = $user->profile?->only(CustomerProfile::FIELDS) ?? [];

        return [...$profile, 'name' => ($profile['name'] ?? null) ?: $user->name, 'email' => $user->email];
    }
}
