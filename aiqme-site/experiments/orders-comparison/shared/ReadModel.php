<?php

namespace Comparison;

use Illuminate\Database\Eloquent\Model;

class ReadModel
{
    public static function cents(mixed $amount): int
    {
        return is_int($amount) ? $amount : $amount->value;
    }

    public static function state(Model $order): Model
    {
        return config('comparison.plain') ? $order : $order->production;
    }

    public static function orderClass(): string
    {
        return config('comparison.order_model');
    }
}
