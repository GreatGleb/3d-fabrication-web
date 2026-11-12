<?php

namespace App\Http\Controllers\Public;

use App\Domain\Orders\OrderFileFormat;
use App\Domain\Orders\OrderSubmission;
use App\Domain\Pricing\CalculatorSettings;
use App\Http\Controllers\Controller;
use App\Support\Dates;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Заявка на изготовление из шага 5 калькулятора (§3.1, W-12): один multipart-запрос fetch (файлы и параметры у каждой
 * позиции — способ и его параметры, количество, срок, дата, заметка — правка 02.11, Г6; контакты, способ получения, токен
 * отправки). Прежний общий формат (параметры печати, срок и notes на весь заказ) не принимается (Г8): без способа у позиции —
 * ошибка проверки. Частота по IP — middleware throttle:orders,
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
            'parts' => ['required', 'array', 'list', 'max:'.$limits['max_files']],
        ];
        $input = $request->all()['parts'] ?? null;
        foreach (is_array($input) ? array_keys($input) : [] as $index) {
            $rules += $this->partRules("parts.{$index}", $request, $settings, $limits);
        }
        $data = $request->validate($rules, [
            'required' => __('public.forms.required'),
            'email' => __('public.forms.email'),
            'max' => __('public.forms.max'),
            'in' => __('public.forms.choice'),
            'integer' => __('public.forms.choice'),
            'numeric' => __('public.forms.choice'),
            'date_format' => __('public.forms.choice'),
            'uuid' => __('public.forms.rejected'),
            'parts.required' => __('calculator.js.no_model'),
            'parts.max' => __('public.forms.files_count', ['max' => $limits['max_files']]),
            // Файл больше upload_max_filesize PHP приходит без содержимого (ошибка загрузки) — то же сообщение о лимите.
            'parts.*.file.uploaded' => __('public.forms.file_size', ['max' => $maxMb]),
            'parts.*.file.max' => __('public.forms.file_size', ['max' => $maxMb]),
            'parts.*.needed_by.date_format' => __('public.forms.date'),
            'parts.*.quantity.min' => __('calculator.submit.quantity'),
            'parts.*.quantity.max' => __('calculator.submit.quantity'),
            'parts.*.scale.min' => __('calculator.js.scale_range'),
            'parts.*.scale.max' => __('calculator.js.scale_range'),
            // Правка 05.11, H-05: толщина и глубина — числом в пределах.
            'parts.*.thickness.required' => __('calculator.submit.thickness'),
            'parts.*.thickness.numeric' => __('calculator.submit.thickness'),
            'parts.*.thickness.min' => __('calculator.submit.thickness'),
            'parts.*.thickness.max' => __('calculator.submit.thickness'),
            'parts.*.depth.required' => __('calculator.submit.depth'),
            'parts.*.depth.numeric' => __('calculator.submit.depth'),
            'parts.*.depth.min' => __('calculator.submit.depth'),
            'parts.*.depth.max' => __('calculator.submit.depth'),
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
            'parts' => array_map(fn (array $part): array => [
                'file' => $part['file'],
                'parameters' => $this->parameters($part, $settings),
            ], $data['parts']),
        ], $user);

        $request->session()->flash('order_submitted', [
            'number' => $result->meta->number,
            // Правка 05.11, H-04: сумм и позиций с оценкой на странице подтверждения нет.
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
     * Параметры печати позиции $prefix: материал, заполнение, пожелания.
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
     * 3D-форматов, единицы и масштаб — у STL, материалы — из настроек калькулятора. Толщина фрезеровки и глубина гравировки
     * — числом в пределах CalculatorSettings (правка 05.11, H-05); при материале «Reikia konsultacijos» толщина необязательна.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, int>  $limits
     * @return array<string, list<mixed>>
     */
    private function partRules(string $prefix, Request $request, array $settings, array $limits): array
    {
        $file = $request->file($prefix.'.file');
        $extension = $file instanceof UploadedFile ? OrderFileFormat::extension($file->getClientOriginalName()) : null;
        $printable = $extension === null || in_array($extension, OrderFileFormat::THREE_D, true);
        $rules = [
            $prefix.'.file' => ['required', 'file', 'max:'.intdiv($limits['max_file_bytes'], 1024), function (string $attribute, mixed $value, Closure $fail) use ($extension): void {
                if ($extension === null) {
                    $fail(__('calculator.js.unsupported', ['name' => basename($value->getClientOriginalName())]));
                }
            }],
            $prefix.'.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
        if ($extension === 'stl' || $extension === null) {
            $rules[$prefix.'.unit'] = ['required', Rule::in(['mm', 'cm', 'in'])];
            $rules[$prefix.'.scale'] = ['required', 'numeric', 'min:1', 'max:1000'];
        }
        $rules += [
            $prefix.'.method' => ['required', Rule::in($printable ? ['printing', 'milling', 'engraving'] : ['milling', 'engraving'])],
            $prefix.'.priority' => $this->choice('calculator.settings.terms'),
            // Правка 11.11, J-04: дата dd.mm.yyyy (и прежний Y-m-d), не раньше завтра.
            $prefix.'.needed_by' => ['nullable', Dates::RULE, function (string $attribute, mixed $value, Closure $fail): void {
                $iso = is_string($value) ? Dates::toIso($value) : null; // не строка — ошибку уже дал date_format
                if ($iso !== null && $iso <= today()->format('Y-m-d')) {
                    $fail(__('public.forms.date'));
                }
            }],
            $prefix.'.notes' => ['nullable', 'string', 'max:2000'],
        ];
        $names = fn (string $key): array => array_column($settings[$key], 'lt');
        [$thinnest, $thickest] = CalculatorSettings::THICKNESS_MM;
        [$shallowest, $deepest] = CalculatorSettings::DEPTH_MM;
        $consult = $request->input($prefix.'.material') === CalculatorSettings::MILLING_CONSULT;

        return $rules + match ($request->input($prefix.'.method')) {
            'printing' => $this->printingRules($prefix.'.', $settings),
            'milling' => [
                $prefix.'.material' => ['required', Rule::in($names('milling_materials'))],
                $prefix.'.thickness' => [$consult ? 'nullable' : 'required', 'numeric', 'min:'.$thinnest, 'max:'.$thickest],
            ],
            'engraving' => [
                $prefix.'.material' => ['required', Rule::in($names('engraving_materials'))],
                $prefix.'.depth' => ['required', 'numeric', 'min:'.$shallowest, 'max:'.$deepest],
            ],
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
                $key => isset($part[$key]) ? $part[$key] + 0 : null,
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
            'needed_by' => $part['priority'] === 'priority' ? Dates::toIso($part['needed_by'] ?? null) : null,
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
