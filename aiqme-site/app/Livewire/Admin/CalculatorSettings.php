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
 * Фрезеровка и гравировка (правка 02.11, Г7): материалы — строки «LT / EN» тем же способом (пустая строка — новый,
 * без обоих названий — удаляется, хотя бы один остаётся), предел — две стороны в мм. Списков толщин и глубин нет: их
 * вводит покупатель числом (правка 05.11, H-05).
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

    /** @var list<array{lt: string, en: string}> */
    public array $millingMaterials = [];

    /** @var list<array{lt: string, en: string}> */
    public array $engravingMaterials = [];

    public string $millingWidth = '';

    public string $millingLength = '';

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
        $milling = self::named($this->millingMaterials);
        $engraving = self::named($this->engravingMaterials);
        $this->millingMaterials = [...$milling, ['lt' => '', 'en' => '']];
        $this->engravingMaterials = [...$engraving, ['lt' => '', 'en' => '']];
        $name = ['nullable', 'string', 'max:60', 'regex:/\p{L}/u'];
        $this->validate([
            'materials.0.name' => ['required'],
            'materials.*.name' => ['nullable', 'string', 'max:40', 'regex:/\p{L}/u', 'distinct'],
            'materials.*.density' => ['required_with:materials.*.name', 'nullable', 'numeric', 'gt:0', 'max:30'],
            'ratePerGram' => ['required', 'regex:/^\d{1,4}(\.\d{1,4})?$/D'],
            'orderFee' => ['required', 'regex:/^\d{1,5}(\.\d{1,2})?$/D'],
            'fileFee' => ['required', 'regex:/^\d{1,5}(\.\d{1,2})?$/D'],
            'defaultInfill' => ['required', 'integer', 'between:0,100'],
            'maxDimension' => ['required', 'integer', 'between:10,2000'],
            'millingMaterials.0.lt' => ['required'],
            'millingMaterials.*.lt' => ['required_with:millingMaterials.*.en', ...$name, 'distinct'],
            'millingMaterials.*.en' => ['required_with:millingMaterials.*.lt', ...$name],
            'engravingMaterials.0.lt' => ['required'],
            'engravingMaterials.*.lt' => ['required_with:engravingMaterials.*.en', ...$name, 'distinct'],
            'engravingMaterials.*.en' => ['required_with:engravingMaterials.*.lt', ...$name],
            'millingWidth' => ['required', 'integer', 'between:1,100000'],
            'millingLength' => ['required', 'integer', 'between:1,100000'],
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
            'milling_materials' => $milling,
            'engraving_materials' => $engraving,
            'milling_max_mm' => [(int) $this->millingWidth, (int) $this->millingLength],
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
        $this->millingMaterials = [...$values['milling_materials'], ['lt' => '', 'en' => '']];
        $this->engravingMaterials = [...$values['engraving_materials'], ['lt' => '', 'en' => '']];
        [$this->millingWidth, $this->millingLength] = array_map('strval', $values['milling_max_mm']);
        $this->unconfirmed = $settings->unconfirmed();
    }

    /**
     * Строки материалов с названием (обрезанные); пустые строки отбрасываются.
     *
     * @param  list<array{lt: string, en: string}>  $rows
     * @return list<array{lt: string, en: string}>
     */
    private static function named(array $rows): array
    {
        $rows = array_map(fn (array $row): array => ['lt' => trim($row['lt'] ?? ''), 'en' => trim($row['en'] ?? '')], $rows);

        return array_values(array_filter($rows, fn (array $row): bool => $row['lt'] !== '' || $row['en'] !== ''));
    }

    /** EUR с не более чем двумя знаками (уже проверено) → целые центы без двоичного округления. */
    private static function cents(string $euros): int
    {
        [$whole, $fraction] = explode('.', $euros.'.');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
