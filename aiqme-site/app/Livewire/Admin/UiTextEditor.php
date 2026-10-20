<?php

namespace App\Livewire\Admin;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\Models\UiText;
use App\Http\Controllers\Admin\AdminController;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Translation\FileLoader;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Правка строки интерфейса (ui_text, §4.1) во вкладке translations: значение LT/EN/RU поверх lang/, видно на сайте сразу
 * (без публикации, как задумано W-04). Пустое поле или текст, равный тексту файла, удаляет переопределение. Запись —
 * ContentService::saveUiText() с ожидаемой ревизией по языку: расхождение — сообщение «изменено в другом окне», ввод
 * остаётся в форме, ни один язык не сохраняется. Каждый запрос — только администратору, иначе 404.
 */
class UiTextEditor extends Component
{
    #[Locked]
    public string $textKey;

    #[Locked]
    public string $uiLocale;

    /** @var array<string, int> ревизия переопределения по локали (0 — его нет) */
    #[Locked]
    public array $revisions = [];

    /** @var array<string, string> */
    #[Locked]
    public array $original = [];

    /** @var array<string, string> */
    public array $values = [];

    public ?string $status = null;

    public ?string $conflict = null;

    public function boot(): void
    {
        abort_unless(auth()->user() && Gate::allows('admin'), 404);
    }

    public function hydrate(): void
    {
        app()->setLocale($this->uiLocale);
    }

    public function mount(string $textKey): void
    {
        abort_unless(in_array($textKey, AdminController::uiTextKeys(strtok($textKey, '.') ?: ''), true), 404);
        $this->textKey = $textKey;
        $this->uiLocale = app()->getLocale();
        $this->load();
    }

    public function save(ContentService $content): void
    {
        $this->status = $this->conflict = null;
        $this->resetErrorBag();
        $this->validate(['values.*' => ['nullable', 'string', 'max:2000']], ['max' => __('account.messages.max'), 'string' => __('account.messages.max')]);
        $changed = array_filter(ContentService::LOCALES, fn (string $locale): bool => trim($this->values[$locale]) !== $this->original[$locale]);

        try {
            DB::transaction(function () use ($content, $changed): void {
                foreach ($changed as $locale) {
                    $value = trim($this->values[$locale]);
                    $content->saveUiText(auth()->user(), $locale, $this->textKey,
                        $value === '' || $value === $this->fileValue($locale) ? null : $value, $this->revisions[$locale], Channel::Web);
                }
            });
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                if ($field === 'revision') {
                    $this->conflict = $messages[0];
                } else {
                    $this->addError('values.lt', $messages[0]);
                }
            }

            return;
        }
        $this->load();
        $this->status = __('admin.ui_text.saved');
    }

    public function render(): View
    {
        return view('admin.ui-text-editor');
    }

    private function load(): void
    {
        $overrides = UiText::where('key', $this->textKey)->get()->keyBy('locale');
        foreach (ContentService::LOCALES as $locale) {
            $this->revisions[$locale] = $overrides->get($locale)->revision ?? 0;
            $this->original[$locale] = $overrides->get($locale)->value ?? $this->fileValue($locale);
        }
        $this->values = $this->original;
    }

    /** Текст из lang/ без переопределений; у RU файла нет — EN (fallback_locale, §4.3). */
    private function fileValue(string $locale): string
    {
        [$group, $name] = explode('.', $this->textKey, 2);
        $value = Arr::get((new FileLoader(app('files'), lang_path()))->load($locale, $group), $name);

        return is_string($value) ? $value : ($locale === 'en' ? '' : $this->fileValue('en'));
    }
}
