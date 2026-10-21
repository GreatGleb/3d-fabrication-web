<?php

namespace App\Livewire\Admin;

use App\Domain\Pricing\CalculatorSettings as Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Вкладка Calculator (§1.4, W-11): материалы и плотности, одна цена за грамм, плата за заказ и за файл (EUR, хранится
 * в центах), заполнение по умолчанию (%), габарит оборудования (10–2000 мм, D-24). Тарифы и габарит помечены
 * «не подтверждено», пока администратор их не изменит. Пустая строка материала — новый материал; материал с пустым
 * названием удаляется, хотя бы один должен остаться; в названии есть буква (иначе ключ JSON стал бы числом). Лимиты файлов не редактируются (§6.4). Каждый запрос — только администратору, иначе 404.
 */
class CalculatorSettings extends Component
{
    #[Locked]
    public string $uiLocale;

    /** @var list<array{name: string, density: string}> */
    public array $materials = [];

    public string $ratePerGram = '';

    public string $orderFee = '';

    public string $fileFee = '';

    public string $defaultInfill = '';

    public string $maxDimension = '';

    /** @var list<string> */
    #[Locked]
    public array $unconfirmed = [];

    public ?string $status = null;

    public function boot(): void
    {
        abort_unless(auth()->user() && Gate::allows('admin'), 404);
    }

    public function hydrate(): void
    {
        app()->setLocale($this->uiLocale);
    }

    public function mount(Settings $settings): void
    {
        $this->uiLocale = app()->getLocale();
        $this->load($settings);
    }

    public function save(Settings $settings): void
    {
        $this->status = null;
        $named = array_values(array_filter($this->materials, fn (array $row): bool => trim($row['name']) !== ''));
        $this->materials = [...$named, ['name' => '', 'density' => '']];
        $this->validate([
            'materials.0.name' => ['required'],
            'materials.*.name' => ['nullable', 'string', 'max:40', 'regex:/\p{L}/u', 'distinct'],
            'materials.*.density' => ['required_with:materials.*.name', 'nullable', 'numeric', 'gt:0', 'max:30'],
            'ratePerGram' => ['required', 'regex:/^\d{1,4}(\.\d{1,4})?$/D'],
            'orderFee' => ['required', 'regex:/^\d{1,5}(\.\d{1,2})?$/D'],
            'fileFee' => ['required', 'regex:/^\d{1,5}(\.\d{1,2})?$/D'],
            'defaultInfill' => ['required', 'integer', 'between:0,100'],
            'maxDimension' => ['required', 'integer', 'between:10,2000'],
        ], ['*' => __('admin.calculator.invalid')]);

        $settings->save([
            'materials' => array_combine(
                array_map(fn (array $row): string => trim($row['name']), $named),
                array_map(fn (array $row): float => (float) $row['density'], $named),
            ),
            'rate_per_gram' => $this->ratePerGram,
            'order_fee_cents' => self::cents($this->orderFee),
            'file_fee_cents' => self::cents($this->fileFee),
            'default_infill' => (int) $this->defaultInfill,
            'max_dimension_mm' => (int) $this->maxDimension,
        ]);
        $this->load($settings);
        $this->status = __('admin.calculator.saved');
    }

    public function render(): View
    {
        return view('admin.calculator-settings');
    }

    private function load(Settings $settings): void
    {
        $values = $settings->values();
        $this->materials = [];
        foreach ($values['materials'] as $name => $density) {
            $this->materials[] = ['name' => $name, 'density' => (string) $density];
        }
        $this->materials[] = ['name' => '', 'density' => ''];
        $this->ratePerGram = $values['rate_per_gram'];
        $this->orderFee = number_format($values['order_fee_cents'] / 100, 2, '.', '');
        $this->fileFee = number_format($values['file_fee_cents'] / 100, 2, '.', '');
        $this->defaultInfill = (string) $values['default_infill'];
        $this->maxDimension = (string) $values['max_dimension_mm'];
        $this->unconfirmed = $settings->unconfirmed();
    }

    /** EUR с не более чем двумя знаками (уже проверено) → целые центы без двоичного округления. */
    private static function cents(string $euros): int
    {
        [$whole, $fraction] = explode('.', $euros.'.');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
