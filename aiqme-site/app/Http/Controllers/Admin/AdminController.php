<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

/**
 * Вкладки админки (§1.4): заготовка Overview (корень и overview), вкладки контента — списки типов и общий редактор
 * (?edit=<id>, ?new=<тип>) на одной странице, как в прототипе; translations — строки интерфейса (ui_text); audit — журнал.
 * W-08: overview, services (услуги; FAQ — W-26), content (page_text; статьи и новости — W-26), audit. W-26: products
 * (товары и бренды, панель категорий), categories (без пункта меню, как в прототипе — категории правятся из вкладки
 * товаров), projects, training, translations. Остальные вкладки добавляют W-11, W-14, W-17, W-18; до них их адресов нет.
 */
class AdminController extends Controller
{
    /** Вкладки в порядке меню прототипа => иконка. */
    public const TABS = [
        'overview' => 'settings', 'products' => 'settings', 'services' => 'settings', 'projects' => 'settings',
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

    public function overview(): View
    {
        return view('admin.overview', ['tab' => 'overview']);
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

    public function projects(Request $request): View
    {
        return $this->contentTab($request, 'projects');
    }

    public function content(Request $request): View
    {
        return $this->contentTab($request, 'content');
    }

    public function training(Request $request): View
    {
        return $this->contentTab($request, 'training');
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
