<?php

// Sąskaitos PDF (W-17, §3.5). Iš prototipo (app.js): „Sąskaitos“, „Įmonės kodas“, „PVM mokėtojo kodas“, „Adresas“,
// „Kiekis“, „Suma be PVM“, „Iš viso“; kitų frazių prototipe nėra — neutralūs užrašai, išvardyti docs/progress.md.
return [
    'title' => 'Sąskaita faktūra',
    'title_vat' => 'PVM sąskaita faktūra',
    'number' => 'Nr. :number',
    'date' => 'Data: :date',
    'order' => 'Užsakymas Nr. :number',
    'seller' => 'Pardavėjas',
    'buyer' => 'Pirkėjas',
    'company_code' => 'Įmonės kodas',
    'vat_code' => 'PVM mokėtojo kodas',
    'address' => 'Adresas',
    'description' => 'Aprašymas',
    'quantity' => 'Kiekis',
    'net' => 'Suma be PVM',
    'vat' => 'PVM :rate %',
    'gross' => 'Iš viso',
    'download' => 'Sąskaita :number (PDF)',
];
