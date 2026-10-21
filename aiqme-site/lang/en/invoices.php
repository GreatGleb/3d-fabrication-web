<?php

// Счёт PDF (W-17, §3.5). Из прототипа (app.js): «Invoices», «Company number», «VAT number», «Address», «Quantity»,
// «Total excl. VAT», «Total»; остальные фразы в прототипе отсутствуют (его счёт — демо-модалка, D-21) — нейтральные
// надписи, перечислены в docs/progress.md.
return [
    'title' => 'Invoice',
    'title_vat' => 'VAT invoice',
    'number' => 'No. :number',
    'date' => 'Date: :date',
    'order' => 'Order No. :number',
    'seller' => 'Seller',
    'buyer' => 'Buyer',
    'company_code' => 'Company number',
    'vat_code' => 'VAT number',
    'address' => 'Address',
    'description' => 'Description',
    'quantity' => 'Quantity',
    'net' => 'Total excl. VAT',
    'vat' => 'VAT :rate%',
    'gross' => 'Total',
    'download' => 'Invoice :number (PDF)',
];
