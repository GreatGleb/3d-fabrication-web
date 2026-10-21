<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Content\PublishedContent;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Калькулятор (§1.2, W-27): 5 шагов в браузере на ядре W-10. Тарифы, габарит и лимиты — config('aiqme.calculator')
 * (до W-11), передаются в JS data-атрибутами. Ветка специалиста — ссылка на контактную форму по контракту
 * предзаполнения §1.2: service — slug опубликованной услуги печати, message добавляет JS.
 */
class CalculatorController extends Controller
{
    use PageSeo;

    public function __construct(private readonly PublishedContent $content) {}

    public function __invoke(): View
    {
        $locale = app()->getLocale();
        $printing = $this->content->list(ContentType::Service, $locale)
            ->first(fn (ContentTranslation $service): bool => $service->item->key === 'printing');

        return view('public.calculator', [
            ...$this->seo('calculator', null, null, __('calculator.intro')),
            'settings' => config('aiqme.calculator'),
            'contactUrl' => route($locale.'.contact', $printing ? ['service' => $printing->slug] : []),
        ]);
    }
}
