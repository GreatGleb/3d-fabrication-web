<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;

/**
 * Вкладки админки W-08 (§1.4): заготовка Overview (корень и overview), services — услуги, content — тексты страниц
 * (page_text), audit — журнал. Список и общий редактор (?edit=<id>, ?new=1) — на одной странице, как в прототипе.
 * Остальные вкладки добавляют W-26, W-11, W-14, W-17, W-18; до них их адресов нет (404).
 */
class AdminController extends Controller
{
    /** Вкладки в порядке меню прототипа => иконка. */
    public const TABS = ['overview' => 'settings', 'services' => 'settings', 'content' => 'settings', 'audit' => 'settings'];

    public function overview(): View
    {
        return view('admin.overview', ['tab' => 'overview']);
    }

    public function services(Request $request): View
    {
        return $this->contentTab($request, 'services', ContentType::Service, canAdd: true);
    }

    public function content(Request $request): View
    {
        return $this->contentTab($request, 'content', ContentType::PageText, canAdd: false);
    }

    public function audit(): View
    {
        $entries = Activity::where('log_name', AuditLog::LOG_NAME)->with('subject')->latest('id')->limit(100)->get();

        return view('admin.audit', ['tab' => 'audit', 'entries' => $entries]);
    }

    private function contentTab(Request $request, string $tab, ContentType $type, bool $canAdd): View
    {
        $items = ContentItem::where('type', $type)->with('translations')->orderBy('position')->orderBy('id')->get();
        $editing = null;
        if ($request->filled('edit')) {
            $editing = $items->firstWhere('id', (int) $request->query('edit')) ?? abort(404);
        }

        return view('admin.content-tab', [
            'tab' => $tab,
            'type' => $type,
            'items' => $items,
            'editing' => $editing,
            'creating' => $editing === null && $canAdd && $request->boolean('new'),
            'canAdd' => $canAdd,
        ]);
    }
}
