<?php

// Užsakymo taisyklių pranešimai (OrderWorkflow, W-13) administratoriui; žr. lang/en/orders.php.
return [
    'workflow' => [
        'cancelled' => 'Užsakymas atšauktas.',
        'already_confirmed' => 'Kaina jau patvirtinta; keiskite ją nurodę priežastį.',
        'not_confirmed' => 'Pirmiausia patvirtinkite kainą.',
        'price_locked' => 'Pradėjus gamybą kainos keisti negalima.',
        'reason_required' => 'Nurodykite priežastį ir suderinimą su klientu.',
        'open_request' => 'Prieš keisdami kainą atšaukite atvirą mokėjimo užklausą.',
        'every_line' => 'Nurodykite kiekvienos eilutės kainą.',
        'line_price' => 'Netinkama eilutės kaina.',
        'delivery_required' => 'Pristatymo eilutės pašalinti negalima; nurodykite jos kainą.',
        'below_paid' => 'Kaina negali būti mažesnė už jau sumokėtą sumą.',
        'rest_too_small' => 'Likutis turi būti 0 arba ne mažesnis nei 0,50 EUR.',
        'request_too_small' => 'Mokėjimo užklausa turi būti ne mažesnė nei 0,50 EUR.',
        'request_too_large' => 'Mokėjimo užklausa viršija nesumokėtą sumą, kurios nedengia atviros užklausos.',
        'request_rest_too_small' => 'Po šios užklausos likusi suma turi būti 0 arba ne mažesnė nei 0,50 EUR.',
        'request_not_cancellable' => 'Šios mokėjimo užklausos čia atšaukti negalima.',
        'stage_invalid' => 'Toks etapo pakeitimas negalimas.',
        'debt_unconfirmed' => 'Užsakymas apmokėtas ne visas. Patvirtinkite gamybos pradžią su likusia skola.',
        'tracking_required' => 'Nurodykite vežėją ir siuntos sekimo numerį.',
        'tracking_pickup' => 'Atsiimamiems užsakymams sekimo numerio nėra.',
        'not_cancellable' => 'Įvykdyto ar atšaukto užsakymo atšaukti negalima.',
        'message_invalid' => 'Įveskite pranešimą (iki 5000 simbolių).',
        'reply_invalid' => 'Nežinomas atsakymas.',
    ],
];
