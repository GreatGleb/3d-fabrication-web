<?php

namespace App\Livewire\Admin;

use App\Domain\Invoices\IssuePendingInvoices;
use App\Domain\Invoices\SellerSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Вкладка Settings (§3.5, D-05, W-17): реквизиты продавца (название, код, VAT-код, адрес), статус плательщика VAT и
 * ставка (целые проценты) или пометка для счёта неплательщика, серия и первый номер счетов. Значений по умолчанию нет;
 * после сохранения ставится задание «ожидающие счета». Первый номер действует, пока по серии не выпущено ни одного
 * счёта. Каждый запрос — только администратору, иначе 404.
 */
class Settings extends Component
{
    #[Locked]
    public string $uiLocale;

    public string $name = '';

    public string $companyCode = '';

    public string $vatCode = '';

    public string $address = '';

    /** '1' — плательщик VAT, '0' — нет. */
    public string $vatPayer = '1';

    public string $vatRate = '';

    public string $vatNote = '';

    public string $series = '';

    public string $firstNumber = '1';

    public ?string $status = null;

    public function boot(): void
    {
        abort_unless(auth()->user() && Gate::allows('admin'), 404);
    }

    public function hydrate(): void
    {
        app()->setLocale($this->uiLocale);
    }

    public function mount(SellerSettings $settings): void
    {
        $this->uiLocale = app()->getLocale();
        $values = $settings->values();
        if ($values !== null) {
            $this->name = $values['name'];
            $this->companyCode = $values['company_code'];
            $this->vatCode = (string) $values['vat_code'];
            $this->address = $values['address'];
            $this->vatPayer = $values['vat_payer'] ? '1' : '0';
            $this->vatRate = $values['vat_payer'] ? (string) $values['vat_rate'] : '';
            $this->vatNote = (string) $values['vat_note'];
            $this->series = $values['series'];
            $this->firstNumber = (string) $values['first_number'];
        }
    }

    public function save(SellerSettings $settings): void
    {
        $this->status = null;
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'companyCode' => ['required', 'string', 'max:50'],
            'vatCode' => ['required_if:vatPayer,1', 'nullable', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:255'],
            'vatPayer' => ['required', 'in:0,1'],
            'vatRate' => ['required_if:vatPayer,1', 'nullable', 'integer', 'between:1,100'],
            'vatNote' => ['required_if:vatPayer,0', 'nullable', 'string', 'max:255'],
            'series' => ['required', 'regex:/^[A-Z0-9]{1,10}$/D'],
            'firstNumber' => ['required', 'integer', 'between:1,999999999'],
        ], ['*' => __('admin.calculator.invalid')]);

        $payer = $this->vatPayer === '1';
        $settings->save([
            'name' => trim($this->name),
            'company_code' => trim($this->companyCode),
            'vat_code' => trim($this->vatCode) === '' ? null : trim($this->vatCode),
            'address' => trim($this->address),
            'vat_payer' => $payer,
            'vat_rate' => $payer ? (int) $this->vatRate : 0,
            'vat_note' => $payer || trim($this->vatNote) === '' ? null : trim($this->vatNote),
            'series' => $this->series,
            'first_number' => (int) $this->firstNumber,
        ]);
        IssuePendingInvoices::dispatch();
        $this->status = __('admin.calculator.saved');
    }

    public function render(): View
    {
        return view('admin.settings-form');
    }
}
