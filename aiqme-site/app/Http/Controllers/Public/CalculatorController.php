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

/**
 * Калькулятор (§1.2, W-27): 5 шагов в браузере на ядре W-10. Тарифы, заполнение по умолчанию и габарит — настройки
 * админки (CalculatorSettings, W-11), лимиты файлов — config('aiqme.calculator') (§6.4); всё передаётся в JS
 * data-атрибутами. Профиль вошедшего клиента подставляется в форму заявки (W-12). Ветка специалиста — ссылка на контактную форму по контракту предзаполнения §1.2: service — slug опубликованной услуги печати, message добавляет JS.
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

        return view('public.calculator', [
            ...$this->seo('calculator', null, null, __('calculator.intro')),
            'settings' => [
                ...$this->settings->values(),
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
