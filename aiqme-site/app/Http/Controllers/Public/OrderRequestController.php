<?php

namespace App\Http\Controllers\Public;

use App\Domain\Orders\OrderSubmission;
use App\Domain\Pricing\CalculatorSettings;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Заявка на изготовление из шага 5 калькулятора (§3.1, W-12): один multipart-запрос fetch (файлы, параметры по файлу,
 * пожелания, текст клиента notes, контакты, способ получения, токен отправки). Частота по IP — middleware throttle:orders,
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
        $choice = fn (string $options): array => ['required', Rule::in(array_map('strval', array_keys(__($options))))];
        $data = $request->validate([
            'submission_token' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'company_code' => ['nullable', 'string', 'max:255'],
            'vat_code' => ['nullable', 'string', 'max:255'],
            'delivery' => $choice('calculator.checkout.deliveries'),
            'country' => ['exclude_unless:delivery,ship', ...$choice('calculator.countries')],
            'city' => ['exclude_unless:delivery,ship', 'required', 'string', 'max:255'],
            'address' => ['exclude_unless:delivery,ship', 'required', 'string', 'max:255'],
            'postcode' => ['exclude_unless:delivery,ship', 'required', 'string', 'max:50'],
            'material' => ['required', Rule::in(array_keys($this->settings->values()['materials']))],
            'infill' => ['required', Rule::in([...array_map('strval', range(0, 100)), 'consult'])],
            'color' => $choice('calculator.settings.colors'),
            'purpose' => $choice('calculator.settings.purposes'),
            'service' => $choice('calculator.settings.services'),
            'surface' => $choice('calculator.settings.surfaces'),
            'term' => $choice('calculator.settings.terms'),
            'notes' => ['nullable', 'string', 'max:2000'],
            'parts' => ['required', 'array', 'list', 'max:'.$limits['max_files']],
            'parts.*.file' => ['required', 'file', 'max:'.intdiv($limits['max_file_bytes'], 1024)],
            'parts.*.unit' => ['required', Rule::in(['mm', 'cm', 'in'])],
            'parts.*.scale' => ['required', 'numeric', 'min:1', 'max:1000'],
            'parts.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ], [
            'required' => __('public.forms.required'),
            'email' => __('public.forms.email'),
            'max' => __('public.forms.max'),
            'in' => __('public.forms.choice'),
            'integer' => __('public.forms.choice'),
            'numeric' => __('public.forms.choice'),
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

        // «Reikia konsultacijos» у заполнения: оценка — по заполнению по умолчанию, в детали — пометка консультации.
        $consult = $data['infill'] === 'consult';
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
            'material' => $data['material'],
            'infill' => $consult ? $this->settings->values()['default_infill'] : (int) $data['infill'],
            'infill_consult' => $consult,
            'preferences' => collect($data)->only(['color', 'purpose', 'service', 'surface', 'term'])->all(),
            'notes' => $data['notes'] ?? null,
            'parts' => array_map(fn (array $part): array => [
                'file' => $part['file'],
                'unit' => $part['unit'],
                'scale' => $part['scale'] + 0,
                'quantity' => (int) $part['quantity'],
            ], $data['parts']),
        ], $user);

        $request->session()->flash('order_submitted', [
            'number' => $result->meta->number,
            'total_cents' => $result->order->total->value,
            'guest_url' => $result->guestToken === null ? null : route($locale.'.order.guest', [$result->meta->number, $result->guestToken]),
        ]);

        return response()->json(['redirect' => route($locale.'.order.confirmation')], 201);
    }

    /** Подтверждение отправки: только сразу после неё (flash), иначе 404 — номер и ссылка повторно не показываются. */
    public function confirmation(Request $request): View
    {
        $submitted = $request->session()->get('order_submitted');
        abort_if($submitted === null, 404);

        return view('public.order-confirmation', ['submitted' => $submitted]);
    }
}
