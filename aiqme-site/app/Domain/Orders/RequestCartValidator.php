<?php

namespace App\Domain\Orders;

use App\Models\OrderCharge;
use App\Models\StlPart;
use Lunar\Validation\BaseValidator;

/**
 * Валидатор корзины заявки перед созданием заказа (lunar.cart.validators.order_create, из эксперимента): без платёжного
 * и адреса доставки Lunar. Строки — только StlPart и служебные OrderCharge, у каждой quantity = 1; лимит 10 строк и
 * количество 1–100 (parameters.quantity) — только по StlPart (§3.1). Файл строки принадлежит владельцу корзины (клиент или
 * гость — user_id null) и ещё не привязан к другому заказу: у гостя сравнение user_id само по себе ничего не доказывает (§6.1).
 */
class RequestCartValidator extends BaseValidator
{
    public function validate(): bool
    {
        $cart = $this->parameters['cart'];
        $parts = $cart->lines->filter(fn ($line) => $line->purchasable instanceof StlPart);
        if ($cart->completedOrder || $parts->isEmpty() || $parts->count() > 10) {
            return $this->fail('cart', 'Active nonempty basket of up to 10 files required.');
        }
        foreach ($cart->lines as $line) {
            if ($line->quantity !== 1 || ! ($line->purchasable instanceof StlPart || $line->purchasable instanceof OrderCharge)) {
                return $this->fail('cart', 'Only STL parts and charges, quantity 1.');
            }
        }
        foreach ($parts as $line) {
            $file = $line->purchasable->file;
            $quantity = $line->purchasable->parameters['quantity'] ?? null;
            if ($file->user_id !== $cart->user_id || $file->order_id !== null || ! is_int($quantity) || $quantity < 1 || $quantity > 100) {
                return $this->fail('cart', 'Private STL of the basket owner and quantity 1–100 required.');
            }
        }

        return $this->pass();
    }
}
