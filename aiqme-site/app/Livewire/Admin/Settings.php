<?php

namespace App\Livewire\Admin;

use App\Domain\Invoices\InvoiceNumber;
use App\Domain\Invoices\IssuePendingInvoices;
use App\Domain\Invoices\SellerSettings;
use App\Domain\Orders\ProductCart;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Вкладка Settings (§3.5, D-05, W-17): реквизиты продавца (название, код, VAT-код, адрес), статус плательщика VAT и
 * ставка (целые проценты) или пометка для счёта неплательщика, префикс серии (по умолчанию AIQ) и следующий номер
 * текущего года (правка 05.11, H-06: вместо первого номера серии; номер не больше выданного в серии года — ошибка).
 * Реквизитов по умолчанию нет; после сохранения ставится задание «ожидающие счета». Каждый запрос — только
 * администратору, иначе 404. «Pristatymo kaina (prekėms)» — стоимость доставки покупки товаров (правка 05.11, H-08), запись settings products. Необязательные поля счёта (правка 02.11, Г5): банк, SWIFT, IBAN, почта, телефон, сайт, условия поставки (по умолчанию EXW Vilnius) и «кто выписал»; пустое сохраняется как null.
 */
class Settings extends Component
{
    /** Необязательные поля счёта: свойство => ключ настроек. */
    private const OPTIONAL = ['bank' => 'bank', 'swift' => 'swift', 'iban' => 'iban', 'email' => 'email', 'phone' => 'phone',
        'website' => 'website', 'deliveryTerms' => 'delivery_terms', 'issuedBy' => 'issued_by'];

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

    public string $series = 'AIQ';

    /** Следующий номер счёта текущего года (правка 05.11, H-06). */
    public string $nextNumber = '1';

    public string $bank = '';

    public string $swift = '';

    public string $iban = '';

    public string $email = '';

    public string $phone = '';

    public string $website = '';

    public string $deliveryTerms = 'EXW Vilnius';

    public string $issuedBy = '';

    /** «Pristatymo kaina (prekėms)», EUR (правка 05.11, H-08; по умолчанию 0). */
    public string $productDelivery = '0.00';

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
            foreach (self::OPTIONAL as $property => $key) {
                if (array_key_exists($key, $values)) {
                    $this->{$property} = (string) $values[$key];
                }
            }
        }
        $this->nextNumber = (string) InvoiceNumber::upcoming($this->series);
        $this->productDelivery = number_format(ProductCart::deliveryCents() / 100, 2, '.', '');
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
            'nextNumber' => ['required', 'integer', 'between:1,999999999'],
            ...array_fill_keys(array_keys(self::OPTIONAL), ['nullable', 'string', 'max:255']),
            'email' => ['nullable', 'email', 'max:255'],
            'productDelivery' => ['required', 'regex:/^\d{1,6}([.,]\d{1,2})?$/D'],
        ], ['*' => __('admin.calculator.invalid')]);
        $last = InvoiceNumber::lastIssued($this->series);
        if ((int) $this->nextNumber <= $last) {
            $this->addError('nextNumber', __('admin.settings.next_number_issued', ['last' => $last]));

            return;
        }

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
            ...array_map(fn (string $property): ?string => trim($this->{$property}) === '' ? null : trim($this->{$property}), array_flip(self::OPTIONAL)),
        ]);
        InvoiceNumber::setNext($this->series, (int) $this->nextNumber);
        ProductCart::saveDeliveryCents((int) round((float) str_replace(',', '.', $this->productDelivery) * 100));
        IssuePendingInvoices::dispatch();
        $this->status = __('admin.calculator.saved');
    }

    public function render(): View
    {
        return view('admin.settings-form');
    }
}
