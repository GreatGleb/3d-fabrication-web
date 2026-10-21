<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Forms\Models\ContactRequest;
use App\Domain\Forms\Models\TrainingRegistration;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Вкладки админки (§1.4): заготовка Overview (корень и overview), вкладки контента — списки типов и общий редактор
 * (?edit=<id>, ?new=<тип>) на одной странице, как в прототипе; translations — строки интерфейса (ui_text); audit — журнал.
 * W-08: overview, services (услуги; FAQ — W-26), content (page_text; статьи и новости — W-26), audit. W-26: products
 * (товары и бренды, панель категорий), categories (без пункта меню, как в прототипе — категории правятся из вкладки
 * товаров), projects, training, translations. W-09: заявки форм в overview и training, скачивание вложений. Остальные
 * вкладки добавляют W-14, W-17, W-18; до них их адресов нет. W-11: calculator — настройки калькулятора (Livewire CalculatorSettings).
 */
class AdminController extends Controller
{
    /** Вкладки в порядке меню прототипа => иконка. */
    public const TABS = [
        'overview' => 'settings', 'products' => 'settings', 'services' => 'settings', 'calculator' => 'settings',
        'projects' => 'settings',
        'content' => 'settings', 'training' => 'settings', 'translations' => 'settings', 'audit' => 'settings',
    ];

    /** Вкладки без пункта меню => пункт меню, который они подсвечивают. */
    public const HIDDEN_TABS = ['categories' => 'products'];

    /** Типы контента вкладки в порядке списков => можно ли добавить элемент («Add a draft +»). */
    public const SECTIONS = [
        'products' => ['product' => true, 'brand' => true],
        'categories' => ['category' => true],
        'services' => ['service' => true, 'faq_item' => true],
        'projects' => ['project' => true],
        'content' => ['article' => true, 'page_text' => false],
        'training' => ['training' => true],
    ];

    /** Группы строк интерфейса во вкладке translations (меню, подвал, страницы, формы); тексты админки не правятся. */
    public const UI_TEXT_GROUPS = ['shell', 'public', 'pages', 'account'];

    /**
     * Overview: заявки контактной формы (W-09) — последние 100 со статусом письма; ?request=<id> — заявка целиком
     * с вложениями (ссылка из письма AIQME). Метрики, заказы и предупреждения добавляет W-14.
     */
    public function overview(Request $request): View
    {
        return view('admin.overview', [
            'tab' => 'overview',
            'requests' => ContactRequest::with('event')->latest('id')->limit(100)->get(),
            'shown' => $request->filled('request') ? ContactRequest::with('event')->findOrFail((int) $request->query('request')) : null,
            'serviceTitles' => ContentItem::where('type', ContentType::Service)->with('translations')->get()
                ->mapWithKeys(fn (ContentItem $service): array => [$service->key => $service->translation(app()->getLocale())?->title ?? $service->key])
                ->put('equipment', __('public.contact.equipment')),
        ]);
    }

    /** Вложение заявки (§6.4): непрозрачный файл — только attachment с исходным именем, nosniff и private, no-store. */
    public function requestFile(ContactRequest $contactRequest, int $index): StreamedResponse
    {
        $file = $contactRequest->attachments[$index] ?? abort(404);
        abort_unless(Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['name'], [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function products(Request $request): View
    {
        return $this->contentTab($request, 'products', [
            'categories' => ContentItem::where('type', ContentType::Category)->with('translations')->orderBy('position')->orderBy('id')->get(),
        ]);
    }

    public function categories(Request $request): View
    {
        return $this->contentTab($request, 'categories');
    }

    public function services(Request $request): View
    {
        return $this->contentTab($request, 'services');
    }

    public function calculator(): View
    {
        return view('admin.calculator', ['tab' => 'calculator']);
    }

    public function projects(Request $request): View
    {
        return $this->contentTab($request, 'projects');
    }

    public function content(Request $request): View
    {
        return $this->contentTab($request, 'content');
    }

    /** Обучение: темы (W-26) и панель «Training registrations» прототипа — регистрации со статусом письма (W-09). */
    public function training(Request $request): View
    {
        return $this->contentTab($request, 'training', [
            'registrations' => TrainingRegistration::with('event')->latest('id')->limit(100)->get(),
        ]);
    }

    /** Строки интерфейса: группа (?group=), ключ на правку (?edit=<ключ>) — только строки lang/ выбранных групп. */
    public function translations(Request $request): View
    {
        $group = in_array($request->query('group'), self::UI_TEXT_GROUPS, true) ? (string) $request->query('group') : self::UI_TEXT_GROUPS[0];
        $keys = self::uiTextKeys($group);
        $editing = $request->filled('edit') ? (string) $request->query('edit') : null;
        abort_if($editing !== null && ! in_array($editing, self::uiTextKeys(strtok($editing, '.') ?: ''), true), 404);

        return view('admin.translations', ['tab' => 'translations', 'group' => $group, 'keys' => $keys, 'editing' => $editing]);
    }

    public function audit(): View
    {
        $entries = Activity::where('log_name', AuditLog::LOG_NAME)->with('subject')->latest('id')->limit(100)->get();

        return view('admin.audit', ['tab' => 'audit', 'entries' => $entries]);
    }

    /**
     * Ключи строк группы из lang/ (LT и EN), только строковые значения: фиксированный набор, не конструктор (§1.4).
     *
     * @return list<string>
     */
    public static function uiTextKeys(string $group): array
    {
        if (! in_array($group, self::UI_TEXT_GROUPS, true)) {
            return [];
        }
        $keys = [];
        foreach (['lt', 'en'] as $locale) {
            $lines = Lang::get($group, [], $locale, false);
            foreach (is_array($lines) ? Arr::dot($lines) : [] as $key => $value) {
                if (is_string($value)) {
                    $keys[$group.'.'.$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /** @param  array<string, mixed>  $extra */
    private function contentTab(Request $request, string $tab, array $extra = []): View
    {
        $sections = [];
        foreach (self::SECTIONS[$tab] as $type => $canAdd) {
            $sections[$type] = ContentItem::where('type', $type)->with('translations')->orderBy('position')->orderBy('id')->get();
        }
        $editing = null;
        if ($request->filled('edit')) {
            $editing = collect($sections)->flatten()->firstWhere('id', (int) $request->query('edit')) ?? abort(404);
        }
        $new = (string) $request->query('new', '');
        $creating = $editing === null && (self::SECTIONS[$tab][$new] ?? false) ? ContentType::from($new) : null;

        return view('admin.content-tab', [
            'tab' => $tab,
            'sections' => $sections,
            'editing' => $editing,
            'creating' => $creating,
            ...$extra,
        ]);
    }
}
