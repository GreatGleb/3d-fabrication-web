<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Domain\Pricing\CalculatorSettings;
use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Калькулятор (§1.2, W-27): шаги в браузере на ядре W-10 (правка 05.11, H-04: три шага, без цены). Названия материалов,
 * заполнение по умолчанию, габарит и списки фрезеровки — настройки админки (CalculatorSettings, W-11), лимиты файлов —
 * config('aiqme.calculator') (§6.4); всё передаётся в JS data-атрибутами, тарифы — нет. Профиль вошедшего клиента подставляется в форму заявки (W-12). Ветка специалиста — ссылка на контактную форму по контракту предзаполнения §1.2: service — slug опубликованной услуги печати, message добавляет JS.
 */
class CalculatorController extends Controller
{
    use PageSeo;

    public function __construct(private readonly PublishedContent $content, private readonly CalculatorSettings $settings) {}

    public function __invoke(Request $request): View
    {
        $locale = app()->getLocale();
        $printing = $this->content->list(ContentType::Service, $locale)
            ->first(fn (ContentTranslation $service): bool => $service->item->key === 'printing');

        $values = $this->settings->values();

        return view('public.calculator', [
            ...$this->seo('calculator', null, null, __('calculator.intro')),
            // Правка 05.11, H-04: тарифы (цена за грамм, платы) и плотности материалов в браузер не уходят — только названия
            // материалов и данные проверки файла; оценку считает сервер (EstimateService).
            'settings' => [
                ...Arr::except($values, ['rate_per_gram', 'order_fee_cents', 'file_fee_cents']),
                'materials' => array_keys($values['materials']),
                'max_file_bytes' => config('aiqme.calculator.max_file_bytes'),
                'max_files' => config('aiqme.calculator.max_files'),
            ],
            'contactUrl' => route($locale.'.contact', $printing ? ['service' => $printing->slug] : []),
            'buyer' => $this->buyer($request->user()),
        ]);
    }

    /**
     * Профиль вошедшего клиента для формы заявки (W-12, §2.5): имя (из профиля, иначе из аккаунта), email аккаунта,
     * компания, коды и адрес; гостю — пусто.
     *
     * @return array<string, ?string>
     */
    private function buyer(?User $user): array
    {
        if ($user === null) {
            return [];
        }
        $profile = $user->profile?->only(CustomerProfile::FIELDS) ?? [];

        return [...$profile, 'name' => ($profile['name'] ?? null) ?: $user->name, 'email' => $user->email];
    }
}
