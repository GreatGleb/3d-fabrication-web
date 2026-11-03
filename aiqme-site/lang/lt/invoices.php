<?php

// Sąskaitos PDF (W-17, §3.5; правка 02.11, Г5 — надписи и порядок блоков образца счёта заказчика, верхний регистр как в
// нём). «Apmokėta» — решение пользователя 02.11 вместо «Apmokėti iki» и пени; подписи подвала — docs/progress.md, G-05.
return [
    'title' => 'SĄSKAITA FAKTŪRA',
    'title_vat' => 'PVM SĄSKAITA FAKTŪRA',
    'date' => 'Data : :date',
    'buyer' => 'PIRKĖJAS',
    'company_code' => 'ĮMONĖS KODAS',
    'vat_code' => 'PVM MOKĖTOJO KODAS',
    'order' => 'UŽSAKYMAS',
    'phone' => 'TEL.',
    'order_date' => 'UŽSAKYMO DATA',
    'delivery_terms' => 'PRISTATYMO SĄLYGOS (INCOTERMS 2010)',
    'description' => 'PAVADINIMAS',
    'quantity' => 'KIEKIS',
    'price' => 'KAINA',
    'amount' => 'SUMA',
    'total' => 'IŠ VISO',
    'vat' => 'PVM :rate %',
    'total_vat' => 'IŠ VISO SU PVM',
    'paid' => 'Apmokėta: :amount EUR',
    'bank' => 'Bankas',
    'swift' => 'SWIFT',
    'account' => 'A / S',
    'received' => 'PREKES / PASLAUGAS GAVAU. PRETENZIJŲ KIEKIUI IR KOKYBEI NETURIU.',
    'issued_by' => 'IŠRAŠĖ / IŠDAVĖ',
    'received_by' => 'GAVO',
    'signature' => '(PAREIGOS, VARDAS, PAVARDĖ, PARAŠAS)',
    'download' => 'Sąskaita :number (PDF)',
];
