<?php

namespace App\Http\Controllers\Public;

use App\Domain\Orders\OrderFileFormat;
use App\Domain\Orders\OrderSubmission;
use App\Domain\Pricing\CalculatorSettings;
use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Заявка на изготовление из шага 5 калькулятора (§3.1, W-12): один multipart-запрос fetch (файлы и параметры у каждой
 * позиции — правка 02.11, Г6; текст клиента notes, контакты, способ получения, токен отправки). Прежний общий формат
 * (параметры печати и срок на весь заказ) ещё принимается и копируется в каждую позицию — до G-08. Частота по IP — middleware throttle:orders,
 * honeypot — как у форм W-09 (ошибка формы, ложного успеха с номером нет). Клиентская сумма не принимается. Ответ — адрес страницы
 * подтверждения; номер и гостевая ссылка передаются ей через flash сессии и показываются один раз.
 */
class OrderRequestController extends Controller
{
    public function __construct(private readonly OrderSubmission $submission, private readonly CalculatorSettings $settings) {}

    public function store(Request $request): JsonResponse
    {
        $locale = app()->getLocale();
        if (filled($request->input(FormController::HONEYPOT))) {
            throw ValidationException::withMessages(['form' => __('public.forms.rejected')]);
        }
        $limits = config('aiqme.calculator');
        $maxMb = intdiv($limits['max_file_bytes'], 1024 * 1024);
        $settings = $this->settings->values();
        // Прежний общий формат шага 2 (общие параметры печати, правка 28.10) — пока его отправляет браузер; удаляет G-08.
        $legacy = $request->exists('material');
        $rules = [
            'submission_token' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'company_code' => ['nullable', 'string', 'max:255'],
            'vat_code' => ['nullable', 'string', 'max:255'],
            'delivery' => $this->choice('calculator.checkout.deliveries'),
            'country' => ['exclude_unless:delivery,ship', ...$this->choice('calculator.countries')],
            'city' => ['exclude_unless:delivery,ship', 'required', 'string', 'max:255'],
            'address' => ['exclude_unless:delivery,ship', 'required', 'string', 'max:255'],
            'postcode' => ['exclude_unless:delivery,ship', 'required', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'parts' => ['required', 'array', 'list', 'max:'.$limits['max_files']],
        ];
        if ($legacy) {
            $rules += [...$this->printingRules('', $settings), 'term' => $this->choice('calculator.settings.terms')];
        }
        $input = $request->all()['parts'] ?? null;
        foreach (is_array($input) ? array_keys($input) : [] as $index) {
            $rules += $this->partRules("parts.{$index}", $request, $legacy, $settings, $limits);
        }
        $data = $request->validate($rules, [
            'required' => __('public.forms.required'),
            'email' => __('public.forms.email'),
            'max' => __('public.forms.max'),
            'in' => __('public.forms.choice'),
            'integer' => __('public.forms.choice'),
            'numeric' => __('public.forms.choice'),
            'date_format' => __('public.forms.choice'),
            'after' => __('public.forms.choice'),
            'uuid' => __('public.forms.rejected'),
            'parts.required' => __('calculator.js.no_model'),
            'parts.max' => __('public.forms.files_count', ['max' => $limits['max_files']]),
            // Файл больше upload_max_filesize PHP приходит без содержимого (ошибка загрузки) — то же сообщение о лимите.
            'parts.*.file.uploaded' => __('public.forms.file_size', ['max' => $maxMb]),
            'parts.*.file.max' => __('public.forms.file_size', ['max' => $maxMb]),
            'parts.*.quantity.min' => __('calculator.submit.quantity'),
            'parts.*.quantity.max' => __('calculator.submit.quantity'),
            'parts.*.scale.min' => __('calculator.js.scale_range'),
            'parts.*.scale.max' => __('calculator.js.scale_range'),
        ]);

        $user = $request->user();
        $result = $this->submission->submit([
            'submission_token' => strtolower($data['submission_token']),
            'locale' => $locale,
            'contact' => [
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'company' => $data['company'] ?? null,
                'company_code' => $data['company_code'] ?? null,
                'vat_code' => $data['vat_code'] ?? null,
            ],
            'delivery' => $data['delivery'],
            'address' => $data['delivery'] === 'ship'
                ? ['country' => $data['country'], 'city' => $data['city'], 'address' => $data['address'], 'postcode' => $data['postcode']]
                : null,
            'notes' => $data['notes'] ?? null,
            'parts' => array_map(fn (array $part): array => [
                'file' => $part['file'],
                // Прежний формат: общие параметры печати и срок копируются в каждую позицию; notes остаётся у заказа.
                'parameters' => $this->parameters($legacy ? ['method' => 'printing', ...$part, ...$data, 'priority' => $data['term'], 'notes' => null] : $part, $settings),
            ], $data['parts']),
        ], $user);

        $request->session()->flash('order_submitted', [
            'number' => $result->meta->number,
            'total_cents' => $result->order->total->value,
            'guest_url' => $result->guestToken === null ? null : route($locale.'.order.guest', [$result->meta->number, $result->guestToken]),
        ]);

        return response()->json(['redirect' => route($locale.'.order.confirmation')], 201);
    }

    /** @return list<mixed> */
    private function choice(string $options): array
    {
        return ['required', Rule::in(array_map('strval', array_keys(__($options))))];
    }

    /**
     * Параметры печати (материал, заполнение, пожелания) — у позиции $prefix или, в прежнем формате, общие ($prefix '').
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, list<mixed>>
     */
    private function printingRules(string $prefix, array $settings): array
    {
        return [
            $prefix.'material' => ['required', Rule::in(array_keys($settings['materials']))],
            $prefix.'infill' => ['required', Rule::in([...array_map('strval', range(0, 100)), 'consult'])],
            $prefix.'color' => $this->choice('calculator.settings.colors'),
            $prefix.'purpose' => $this->choice('calculator.settings.purposes'),
            $prefix.'service' => $this->choice('calculator.settings.services'),
            $prefix.'surface' => $this->choice('calculator.settings.surfaces'),
        ];
    }

    /**
     * Правила позиции по формату файла и способу (правка 02.11, Г6): расширение из белого списка, печать — только для
     * 3D-форматов, единицы и масштаб — у STL, материалы, толщины и глубины — из настроек калькулятора.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, int>  $limits
     * @return array<string, list<mixed>>
     */
    private function partRules(string $prefix, Request $request, bool $legacy, array $settings, array $limits): array
    {
        $file = $request->file($prefix.'.file');
        $extension = $file instanceof UploadedFile ? OrderFileFormat::extension($file->getClientOriginalName()) : null;
        $printable = $extension === null || in_array($extension, OrderFileFormat::THREE_D, true);
        $rules = [
            $prefix.'.file' => ['required', 'file', 'max:'.intdiv($limits['max_file_bytes'], 1024), function (string $attribute, mixed $value, Closure $fail) use ($extension, $legacy, $printable): void {
                if ($extension === null) {
                    $fail(__('calculator.js.unsupported', ['name' => basename($value->getClientOriginalName())]));
                } elseif ($legacy && ! $printable) {
                    // Прежний формат — всегда печать: DXF и DWG в нём не принимаются.
                    $fail(__('public.forms.choice'));
                }
            }],
            $prefix.'.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
        if ($extension === 'stl' || $extension === null) {
            $rules[$prefix.'.unit'] = ['required', Rule::in(['mm', 'cm', 'in'])];
            $rules[$prefix.'.scale'] = ['required', 'numeric', 'min:1', 'max:1000'];
        }
        if ($legacy) {
            return $rules;
        }
        $rules += [
            $prefix.'.method' => ['required', Rule::in($printable ? ['printing', 'milling', 'engraving'] : ['milling', 'engraving'])],
            $prefix.'.priority' => $this->choice('calculator.settings.terms'),
            $prefix.'.needed_by' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            $prefix.'.notes' => ['nullable', 'string', 'max:2000'],
        ];
        $names = fn (string $key): array => array_column($settings[$key], 'lt');
        $sizes = fn (string $key): array => array_map('strval', $settings[$key]);

        return $rules + match ($request->input($prefix.'.method')) {
            'printing' => $this->printingRules($prefix.'.', $settings),
            'milling' => [$prefix.'.material' => ['required', Rule::in($names('milling_materials'))], $prefix.'.thickness' => ['required', Rule::in($sizes('milling_thicknesses_mm'))]],
            'engraving' => [$prefix.'.material' => ['required', Rule::in($names('engraving_materials'))], $prefix.'.depth' => ['required', Rule::in($sizes('engraving_depths_mm'))]],
            default => [],
        };
    }

    /**
     * Проверенные поля позиции → parameters StlPart. «Reikia konsultacijos» у заполнения: оценка — по заполнению по
     * умолчанию, в детали — пометка консультации. Дата нужна только при скорой очереди.
     *
     * @param  array<string, mixed>  $part
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function parameters(array $part, array $settings): array
    {
        $method = $part['method'];
        $parameters = ['method' => $method];
        if ($method === 'printing') {
            $consult = $part['infill'] === 'consult';
            $parameters += [
                'material' => $part['material'],
                'infill' => $consult ? $settings['default_infill'] : (int) $part['infill'],
                'infill_consult' => $consult,
            ];
        } else {
            $list = $settings[$method.'_materials'];
            $key = $method === 'milling' ? 'thickness' : 'depth';
            $parameters += [
                'material' => $part['material'],
                'material_en' => $list[array_search($part['material'], array_column($list, 'lt'), true)]['en'],
                $key => $part[$key] + 0,
            ];
        }
        if (array_key_exists('unit', $part)) {
            $parameters += ['unit' => $part['unit'], 'scale' => $part['scale'] + 0];
        }
        $parameters['quantity'] = (int) $part['quantity'];
        if ($method === 'printing') {
            $parameters += collect($part)->only(['color', 'purpose', 'service', 'surface'])->all();
        }

        return $parameters + [
            'term' => $part['priority'],
            'needed_by' => $part['priority'] === 'priority' ? ($part['needed_by'] ?? null) : null,
            'notes' => ($part['notes'] ?? null) ?: null,
        ];
    }

    /** Подтверждение отправки: только сразу после неё (flash), иначе 404 — номер и ссылка повторно не показываются. */
    public function confirmation(Request $request): View
    {
        $submitted = $request->session()->get('order_submitted');
        abort_if($submitted === null, 404);

        return view('public.order-confirmation', ['submitted' => $submitted]);
    }
}
