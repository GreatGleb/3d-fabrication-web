<?php

// Prisijungimas, registracija, slaptažodžio atkūrimas ir kliento paskyra (W-07) — matomas prototipo tekstas
// (verified-route-screens/lt__paskyra__*/dom.html, states/lt-account-addresses) ir t('LT','EN') iš app.js.
// Demonstracinės prototipo užrašai (D-21) neperkeliami; neutralūs užrašai, kurių prototipe nėra, — docs/progress.md.
return [
    'title' => 'Jūsų AIQME paskyra',
    'intro' => 'Modeliai, užsakymai ir dokumentai vienoje vietoje.',
    'customer_title' => 'Kliento paskyra',
    'login' => ['heading' => 'Prisijungimas', 'submit' => 'Prisijungti'],
    'register' => ['heading' => 'Registracija', 'submit' => 'Sukurti paskyrą'],
    'reset' => ['heading' => 'Slaptažodžio atkūrimas', 'submit' => 'Siųsti atkūrimo nuorodą'],
    'new_password' => ['heading' => 'Slaptažodžio atkūrimas', 'field' => 'Naujas slaptažodis *', 'submit' => 'Išsaugoti naują slaptažodį'],
    'fields' => ['name' => 'Vardas *', 'email' => 'El. paštas *', 'password' => 'Slaptažodis *'],
    'links' => ['register' => 'Registruotis', 'reset' => 'Pamiršote slaptažodį?', 'login' => 'Prisijungti'],
    'guest' => 'Užsakyti be paskyros',
    'messages' => [
        'failed' => 'Neteisingas el. paštas arba slaptažodis.',
        'throttle' => 'Per daug bandymų. Bandykite po :seconds s.',
        'reset_sent' => 'Jei paskyra su šiuo el. pašto adresu yra, išsiuntėme slaptažodžio atkūrimo nuorodą.',
        'reset_invalid' => 'Atkūrimo nuoroda neteisinga arba nebegalioja. Paprašykite naujos.',
        'password_reset' => 'Slaptažodis pakeistas. Prisijunkite nauju slaptažodžiu.',
        'required' => 'Užpildykite šį lauką.',
        'email' => 'Įveskite teisingą el. pašto adresą.',
        'password_min' => 'Slaptažodį turi sudaryti bent :min simbolių.',
        'email_taken' => 'Paskyra su šiuo el. pašto adresu jau yra.',
        'max' => 'Per ilga reikšmė: iki :max simbolių.',
    ],
    'tabs' => ['label' => 'Paskyros meniu', 'addresses' => 'Adresai ir įmonė'],
    'logout' => 'Atsijungti',
    'addresses' => [
        'heading' => 'Adresai ir įmonės duomenys',
        'fields' => [
            'name' => 'Vardas',
            'company' => 'Įmonė',
            'company_code' => 'Įmonės kodas',
            'vat_code' => 'PVM kodas',
            'address' => 'Adresas',
            'city' => 'Miestas',
            'postcode' => 'Pašto kodas',
        ],
        'submit' => 'Išsaugoti duomenis',
        'saved' => 'Duomenys išsaugoti.',
    ],
    'admin' => ['title' => 'AIQME valdymo aplinka'],
    'reset_mail' => [
        'subject' => 'Slaptažodžio atkūrimas',
        'intro' => 'Gavome prašymą atkurti jūsų AIQME paskyros slaptažodį.',
        'action' => 'Nustatyti naują slaptažodį',
        'expires' => 'Nuoroda galioja :minutes min. ir tinka vieną kartą.',
        'ignore' => 'Jei to neprašėte, laišką ignoruokite: slaptažodis nepasikeis.',
    ],
];
