<?php

// Mokėjimo puslapis (W-16, §3.3). «Pereiti prie apmokėjimo» — iš prototipo (app.js); kitos frazės — neutralios
// (prototipe mokėjimas tik imituojamas, D-21), išvardytos progress.md.
return [
    'title' => 'Apmokėjimas',
    'proceed' => 'Pereiti prie apmokėjimo',
    'amount' => 'Mokėjimo suma',
    'pay' => 'Mokėti :amount',
    'states' => [
        'admin' => 'Administratorius mato šį puslapį be mokėjimo formos.',
        'processing' => 'Mokėjimas apdorojamas. Užsakymas atnaujinamas, kai Stripe patvirtina mokėjimą.',
        'checking' => 'Mokėjimas tikrinamas. Pabandykite vėliau.',
        'paid' => 'Ši mokėjimo užklausa apmokėta.',
        'closed' => 'Ši mokėjimo užklausa uždaryta.',
        'unavailable' => 'Apmokėjimas laikinai negalimas. Pabandykite vėliau.',
    ],
];
