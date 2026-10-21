<?php

// Сообщения правил заказа (OrderWorkflow, W-13) для администратора. В прототипе этих текстов нет — короткие нейтральные
// формулировки (rules[8]), перечислены в docs/progress.md.
return [
    'workflow' => [
        'cancelled' => 'The order is cancelled.',
        'already_confirmed' => 'The price is already confirmed; change it with a reason.',
        'not_confirmed' => 'Confirm the price first.',
        'price_locked' => 'The price cannot be changed after production has started.',
        'invoice_issued' => 'The invoice has been issued, so the price cannot be changed.',
        'reason_required' => 'Enter the reason and the customer agreement.',
        'open_request' => 'Cancel the open payment request before changing the price.',
        'every_line' => 'Enter a price for every line.',
        'line_price' => 'Invalid line price.',
        'delivery_required' => 'The delivery line cannot be removed; enter its price.',
        'below_paid' => 'The price cannot be lower than the amount already paid.',
        'rest_too_small' => 'The remaining amount must be 0 or at least 0.50 EUR.',
        'request_too_small' => 'The payment request must be at least 0.50 EUR.',
        'request_too_large' => 'The payment request exceeds the unpaid amount not covered by open requests.',
        'request_rest_too_small' => 'The amount left after this request must be 0 or at least 0.50 EUR.',
        'request_not_cancellable' => 'This payment request cannot be cancelled here.',
        'request_not_checkable' => 'Status can be checked in Stripe only for a request being checked, being cancelled or open with a started payment.',
        'stage_invalid' => 'This stage change is not allowed.',
        'debt_unconfirmed' => 'The order is not fully paid. Confirm starting production with an outstanding balance.',
        'tracking_required' => 'Enter the carrier and the tracking number.',
        'tracking_pickup' => 'Collection orders have no tracking number.',
        'not_cancellable' => 'A completed or cancelled order cannot be cancelled.',
        'message_invalid' => 'Enter a message (up to 5000 characters).',
        'not_guest' => 'This is a registered customer order: it has no guest link.',
        'reply_invalid' => 'Unknown reply.',
    ],
];
