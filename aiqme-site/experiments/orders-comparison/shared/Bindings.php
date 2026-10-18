<?php

namespace Comparison;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;
use Stripe\StripeClientInterface;

class Bindings
{
    public static function register(Application $app): void
    {
        $app->bind(StripeClientInterface::class, function () {
            $key = config('services.stripe.test_secret');
            if (! is_string($key) || ! str_starts_with($key, 'sk_test_')) {
                throw ValidationException::withMessages(['stripe' => 'Stripe test credentials are not configured. Live payments are disabled.']);
            }

            return new StripeClient($key);
        });
    }
}
