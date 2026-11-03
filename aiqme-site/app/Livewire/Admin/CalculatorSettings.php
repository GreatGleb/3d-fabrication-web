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
 * без обоих названий — удаляется, хотя бы один остаётся), толщины и глубины — по одному числу > 0 в строке (точка или
 * запятая), предел — две стороны в мм.
 */
class CalculatorSettings extends Component
{
    /** Толщины и глубины: непустые строки по одному числу (до 4 цифр и до 2 знаков после точки или запятой). */
    private const SIZES = '/^\s*(\d{1,4}([.,]\d{1,2})?\s*(\R\s*|$))+$/D';

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

    /** Толщины фрезеровки, мм: по одной в строке. */
    public string $millingThicknesses = '';

    /** @var list<array{lt: string, en: string}> */
    public array $engravingMaterials = [];

    /** Глубины гравировки, мм: по одной в строке. */
    public string $engravingDepths = '';

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
            'millingThicknesses' => ['required', 'regex:'.self::SIZES],
            'engravingDepths' => ['required', 'regex:'.self::SIZES],
            'millingWidth' => ['required', 'integer', 'between:1,100000'],
            'millingLength' => ['required', 'integer', 'between:1,100000'],
        ], ['*' => __('admin.calculator.invalid')]);
        $thicknesses = self::sizes($this->millingThicknesses);
        $depths = self::sizes($this->engravingDepths);
        if (in_array(0, $thicknesses, true) || in_array(0, $depths, true)) {
            $this->addError(in_array(0, $thicknesses, true) ? 'millingThicknesses' : 'engravingDepths', __('admin.calculator.invalid'));

            return;
        }

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
            'milling_thicknesses_mm' => $thicknesses,
            'engraving_materials' => $engraving,
            'engraving_depths_mm' => $depths,
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
        $this->millingThicknesses = implode("\n", $values['milling_thicknesses_mm']);
        $this->engravingDepths = implode("\n", $values['engraving_depths_mm']);
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

    /**
     * Числа по одному в строке (уже проверено SIZES): целые — int, дробные — float, как в настройках.
     *
     * @return list<int|float>
     */
    private static function sizes(string $text): array
    {
        $lines = array_filter(array_map('trim', preg_split('/\R/', $text)), fn (string $line): bool => $line !== '');

        return array_values(array_map(function (string $line): int|float {
            $number = (float) str_replace(',', '.', $line);

            return floor($number) === $number ? (int) $number : $number;
        }, $lines));
    }

    /** EUR с не более чем двумя знаками (уже проверено) → целые центы без двоичного округления. */
    private static function cents(string $euros): int
    {
        [$whole, $fraction] = explode('.', $euros.'.');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
