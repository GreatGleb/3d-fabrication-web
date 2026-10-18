<?php

namespace App\Services;

use App\Models\StlPart;
use Lunar\Validation\BaseValidator;

/** Request submission deliberately does not require checkout billing/shipping details. */
class RequestCartValidator extends BaseValidator
{
    public function validate(): bool
    {
        $cart = $this->parameters['cart'];
        if ($cart->completedOrder || $cart->lines->isEmpty() || $cart->lines->count() > 10) {
            return $this->fail('cart', 'Active nonempty basket required.');
        }
        foreach ($cart->lines as $line) {
            if (! $line->purchasable instanceof StlPart || $line->purchasable->user_id !== $cart->user_id || $line->quantity < 1 || $line->quantity > 100) {
                return $this->fail('cart', 'Private STL and valid quantity required.');
            }
        }

        return $this->pass();
    }
}
