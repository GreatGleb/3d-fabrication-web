<?php

// Страница оплаты платёжного запроса (W-16, §3.3). «Proceed to checkout» — из прототипа (app.js); остальные фразы в
// прототипе отсутствуют (его страница оплаты — демо-имитация, D-21) — нейтральные надписи, перечислены в progress.md.
return [
    'title' => 'Checkout',
    'proceed' => 'Proceed to checkout',
    'amount' => 'Payment amount',
    'pay' => 'Pay :amount',
    'states' => [
        'admin' => 'Administrators see this page without the payment form.',
        'processing' => 'The payment is being processed. The order is updated once Stripe confirms the payment.',
        'checking' => 'The payment is being checked. Please try again later.',
        'paid' => 'This payment request has been paid.',
        'closed' => 'This payment request is closed.',
        'unavailable' => 'Payment is temporarily unavailable. Please try again later.',
    ],
];
