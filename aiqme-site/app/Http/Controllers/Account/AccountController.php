<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Кабинет клиента (§1.3). Гостю на любой вкладке — форма входа (200, §4.6). W-07 — вкладка «Addresses & company»
 * (customer_profiles, §2.5); Overview, Orders, Saved models и Invoices добавляет W-15, до неё корень кабинета
 * показывает эту вкладку.
 */
class AccountController extends Controller
{
    public function addresses(Request $request): View
    {
        if (! $request->user()) {
            return view('account.auth', ['mode' => 'login']);
        }

        return view('account.addresses', ['profile' => $request->user()->profile ?? new CustomerProfile]);
    }

    public function updateAddresses(Request $request): RedirectResponse
    {
        $rules = array_fill_keys(CustomerProfile::FIELDS, ['nullable', 'string', 'max:255']);
        // Форма отправляет весь профиль: незаполненное поле очищается.
        $data = [...array_fill_keys(CustomerProfile::FIELDS, null), ...$request->validate($rules, ['max' => __('account.messages.max')])];

        $request->user()->profile()->updateOrCreate([], $data);

        return redirect()->route(app()->getLocale().'.account.addresses')->with('status', __('account.addresses.saved'));
    }
}
