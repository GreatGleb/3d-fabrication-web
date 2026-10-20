<?php

// Sign-in, registration, password reset and the customer account (W-07) — from the visible text of the prototype
// (verified-route-screens/en__account__*/dom.html, states/en-account-addresses) and t('LT','EN') calls in app.js.
// Demo labels (D-21: banner, "Demo password", "Use a sample password only…", "Sign into the demo account",
// "Simulate reset email", "Open an account example", "Save demo details") are not carried over; neutral labels
// that the prototype does not have (messages, new password, email) are listed in docs/progress.md.
return [
    'title' => 'Your AIQME account',
    'intro' => 'Your models, orders and documents in one place.',
    'customer_title' => 'Customer account',
    'login' => ['heading' => 'Sign in', 'submit' => 'Sign in'],
    'register' => ['heading' => 'Create an account', 'submit' => 'Create an account'],
    'reset' => ['heading' => 'Reset your password', 'submit' => 'Send reset link'],
    'new_password' => ['heading' => 'Reset your password', 'field' => 'New password *', 'submit' => 'Save new password'],
    'fields' => ['name' => 'Name *', 'email' => 'Email *', 'password' => 'Password *'],
    'links' => ['register' => 'Register', 'reset' => 'Forgot password?', 'login' => 'Sign in'],
    'guest' => 'Order as a guest',
    'messages' => [
        'failed' => 'Incorrect email or password.',
        'throttle' => 'Too many attempts. Try again in :seconds s.',
        'reset_sent' => 'If an account with this email exists, we have sent a password reset link.',
        'reset_invalid' => 'The reset link is invalid or has expired. Request a new one.',
        'password_reset' => 'Password changed. Sign in with your new password.',
        'required' => 'Fill in this field.',
        'email' => 'Enter a valid email address.',
        'password_min' => 'The password must be at least :min characters.',
        'email_taken' => 'An account with this email already exists.',
        'max' => 'Too long: up to :max characters.',
    ],
    'tabs' => ['label' => 'Account menu', 'addresses' => 'Addresses & company'],
    'logout' => 'Sign out',
    'addresses' => [
        'heading' => 'Addresses & company details',
        'fields' => [
            'name' => 'Name',
            'company' => 'Company',
            'company_code' => 'Company number',
            'vat_code' => 'VAT number',
            'address' => 'Address',
            'city' => 'City',
            'postcode' => 'Postal code',
        ],
        'submit' => 'Save details',
        'saved' => 'Details saved.',
    ],
    'admin' => ['title' => 'AIQME management workspace'],
    'reset_mail' => [
        'subject' => 'Reset your password',
        'intro' => 'We received a request to reset the password of your AIQME account.',
        'action' => 'Set a new password',
        'expires' => 'The link is valid for :minutes minutes and can be used once.',
        'ignore' => 'If you did not request this, ignore this email: your password will not change.',
    ],
];
