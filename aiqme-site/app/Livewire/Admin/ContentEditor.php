<?php

namespace App\Livewire\Admin;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\ContentTypes;
use App\Domain\Content\MediaFile;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Content\Models\ContentTranslation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Общий редактор контента админки (§1.4, §4.2) для типов, зарегистрированных в ContentTypes: поля перевода LT/EN/RU,
 * slug и SEO, сохранение черновика, публикация и снятие по каждому языку, медиа элемента. Все записи — через
 * ContentService с ожидаемой ревизией перевода: при расхождении сохранение отклоняется, введённый текст остаётся в форме.
 * Каждый запрос компонента (в том числе /livewire/update) — только администратору, иначе 404.
 */
class ContentEditor extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $type;

    #[Locked]
    public ?int $itemId = null;

    #[Locked]
    public string $uiLocale;

    /** @var array<string, int> ожидаемая ревизия перевода по локали (0 — перевода ещё нет) */
    #[Locked]
    public array $revisions = [];

    /** @var array<string, array<string, mixed>> сохранённое состояние формы по локали — для поиска изменённых языков */
    #[Locked]
    public array $original = [];

    /** @var array<string, array<string, mixed>> */
    public array $form = [];

    public ?string $status = null;

    public ?string $conflict = null;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public string $collection = '';

    /** @var array<string, string> */
    public array $alt = ['lt' => '', 'en' => ''];

    public string $sourceText = '';

    public string $sourceUrl = '';

    public function boot(): void
    {
        abort_unless(auth()->user() && Gate::allows('admin'), 404);
    }

    public function hydrate(): void
    {
        app()->setLocale($this->uiLocale);
    }

    public function mount(string $type, ?int $itemId = null): void
    {
        $contentType = ContentType::tryFrom($type);
        abort_unless($contentType && ContentTypes::isRegistered($contentType), 404);
        if ($itemId !== null) {
            abort_unless(ContentItem::whereKey($itemId)->where('type', $contentType)->exists(), 404);
        }
        // Новый элемент — только у услуг: набор страниц (page_text) задан кодом (§4.1).
        abort_if($itemId === null && $contentType === ContentType::PageText, 404);
        $this->type = $type;
        $this->itemId = $itemId;
        $this->uiLocale = app()->getLocale();
        $this->load();
        $this->collection = (string) array_key_first(ContentTypes::media($this->item()));
    }

    /** «Išsaugoti» · «Save»: черновик каждого изменённого языка; опубликованное не меняется. */
    public function save(): void
    {
        if ($this->saveLocales($this->changedLocales())) {
            $this->status = __('admin.editor.saved');
        }
    }

    public function publish(string $locale): void
    {
        $this->changeLocale($locale, function (ContentService $content, ContentItem $item) use ($locale): void {
            $content->publish(auth()->user(), $item, $locale, $this->revisions[$locale], Channel::Web);
        }, 'admin.editor.published_locale');
    }

    public function unpublish(string $locale): void
    {
        $this->changeLocale($locale, function (ContentService $content, ContentItem $item) use ($locale): void {
            $content->unpublish(auth()->user(), $item, $locale, $this->revisions[$locale], Channel::Web);
        }, 'admin.editor.unpublished_locale');
    }

    public function uploadMedia(ContentService $content): void
    {
        $item = $this->existingItem();
        $media = ContentTypes::media($item);
        $this->validate([
            'upload' => ['required', 'file', 'max:'.MediaFile::maxKilobytes()],
            'collection' => ['required', Rule::in(array_keys($media))],
            'alt.*' => ['nullable', 'string', 'max:500'],
            'sourceText' => ['nullable', 'string', 'max:255'],
            'sourceUrl' => ['nullable', 'url:http,https', 'max:500'],
        ], [
            'upload.required' => __('account.messages.required'),
            'upload.*' => __('content.media_invalid', ['max' => intdiv(MediaFile::maxKilobytes(), 1024)]),
        ] + $this->messages(), [
            'alt.lt' => self::label('alt', 'lt'), 'alt.en' => self::label('alt', 'en'),
            'collection' => __('admin.media.collection'), 'sourceText' => __('admin.media.source'), 'sourceUrl' => __('admin.media.source_url'),
        ]);
        $source = trim($this->sourceText) === '' ? null
            : array_filter(['text' => trim($this->sourceText), 'url' => trim($this->sourceUrl) ?: null]);

        try {
            $content->addMedia(auth()->user(), $item, $this->upload->getRealPath(), $this->collection,
                array_filter(array_map('trim', $this->alt)), $source, replace: $media[$this->collection]);
        } catch (ValidationException $exception) {
            $this->addError('upload', collect($exception->errors())->flatten()->first());

            return;
        } finally {
            $this->upload->delete();
            $this->upload = null;
        }
        $this->reset('alt', 'sourceText', 'sourceUrl');
        $this->status = __('admin.media.uploaded');
    }

    public function removeMedia(ContentService $content, int $mediaId): void
    {
        $content->removeMedia(auth()->user(), $this->existingItem(), $mediaId);
        $this->status = __('admin.media.removed');
    }

    public function render(): View
    {
        $item = $this->item();
        $translations = $item->exists ? $item->translations()->get()->keyBy('locale') : collect();

        return view('admin.content-editor', [
            'item' => $item,
            'fields' => ContentTypes::fields($item),
            'states' => collect(ContentService::LOCALES)->mapWithKeys(fn (string $locale): array => [
                $locale => $this->state($translations->get($locale)),
            ]),
            'media' => ContentTypes::media($item),
            'files' => $item->exists ? $item->getMedia('*') : collect(),
        ]);
    }

    /** Сохранить язык (если изменён) и выполнить над ним действие публикации. */
    private function changeLocale(string $locale, callable $action, string $message): void
    {
        abort_unless(in_array($locale, ContentService::LOCALES, true), 404);
        $changed = in_array($locale, $this->changedLocales(), true);
        if (($changed && ! $this->saveLocales([$locale])) || $this->itemId === null) {
            return;
        }
        if ($this->revisions[$locale] === 0) {
            $this->addError('form.'.$locale.'.title', __('account.messages.required'));

            return;
        }

        $this->guard($locale, function () use ($action): void {
            DB::transaction(fn () => $action(app(ContentService::class), $this->item()));
        });
        if ($this->getErrorBag()->isEmpty() && $this->conflict === null) {
            $this->load();
            $this->status = __($message, ['locale' => strtoupper($locale)]);
        }
    }

    /**
     * Черновики языков одной транзакцией (новый элемент создаётся вместе с первым сохранением): расхождение ревизии
     * или ошибка на любом языке отменяет всё. Ввод остаётся в форме.
     *
     * @param  list<string>  $locales
     */
    private function saveLocales(array $locales): bool
    {
        $this->status = $this->conflict = null;
        $this->resetErrorBag();
        $item = $this->item();
        if (! $item->exists && ! in_array('lt', $locales, true)) {
            array_unshift($locales, 'lt');
        }

        $rules = [];
        foreach ($locales as $locale) {
            foreach (ContentTypes::rules($item) as $field => $fieldRules) {
                $rules['form.'.$locale.'.'.$field] = $fieldRules;
            }
        }
        $validator = Validator::make(['form' => $this->form], $rules, $this->messages(), $this->attributes($locales));
        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return false;
        }

        $saved = false;
        $this->guard($locales[0] ?? 'lt', function () use ($item, $locales, &$saved): void {
            $content = app(ContentService::class);
            $actor = auth()->user();
            DB::transaction(function () use ($content, $actor, $item, $locales): void {
                if (! $item->exists) {
                    $item = $this->createItem($content, $item);
                }
                foreach ($locales as $locale) {
                    $content->saveDraft($actor, $item, $locale, ContentTypes::fromForm($item, $this->form[$locale]), $this->revisions[$locale], Channel::Web);
                }
                $this->itemId = $item->id;
            });
            $saved = true;
        });
        if (! $saved) {
            return false;
        }
        $this->load();

        return true;
    }

    /** Новая услуга: ключ — slug LT (группа FAQ, связи проектов), порядок — после последней. */
    private function createItem(ContentService $content, ContentItem $item): ContentItem
    {
        $key = $this->form['lt']['slug'] ?? null;
        if ($key === null || $key === '' || ContentItem::where('type', $item->type)->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['slug' => __('content.slug_taken')]);
        }

        return $content->createItem(auth()->user(), $item->type, $key,
            position: (int) ContentItem::where('type', $item->type)->max('position') + 1);
    }

    /** Ошибки ContentService: расхождение ревизии — сообщение «изменено в другом окне», остальные — у поля языка. */
    private function guard(string $locale, callable $write): void
    {
        try {
            $write();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                if ($field === 'revision') {
                    $this->conflict = $messages[0];
                } else {
                    $this->addError('form.'.$locale.'.'.($field === 'fields' ? 'title' : $field), $messages[0]);
                }
            }
        }
    }

    /** @return list<string> языки, где форма отличается от сохранённого (пустой несозданный перевод не считается) */
    private function changedLocales(): array
    {
        return array_values(array_filter(ContentService::LOCALES, fn (string $locale): bool => $this->form[$locale] != $this->original[$locale]));
    }

    private function load(): void
    {
        $item = $this->item();
        foreach (ContentService::LOCALES as $locale) {
            $translation = $item->exists ? $item->translations()->where('locale', $locale)->first() : null;
            $this->revisions[$locale] = $translation->revision ?? 0;
            $this->original[$locale] = ContentTypes::toForm($item, $translation?->latestRevision()?->only(['title', 'slug', 'fields', 'seo_title', 'seo_description']));
        }
        $this->form = $this->original;
    }

    private function item(): ContentItem
    {
        return $this->itemId !== null ? ContentItem::findOrFail($this->itemId) : new ContentItem(['type' => ContentType::from($this->type)]);
    }

    private function existingItem(): ContentItem
    {
        abort_if($this->itemId === null, 404);

        return $this->item();
    }

    /** @return array{key: string, published: bool} */
    private function state(?ContentTranslation $translation): array
    {
        $key = match (true) {
            $translation === null || $translation->revision === 0 => 'none',
            ! $translation->isPublished() => 'draft',
            $translation->revision > $translation->published_revision => 'changes',
            default => 'published',
        };

        return ['key' => $key, 'published' => $translation?->isPublished() ?? false];
    }

    /** Подпись поля на языке поля, как в прототипе: «Pavadinimas LT», «Title EN»; RU — по-английски. */
    public static function label(string $field, string $locale): string
    {
        $labelLocale = $locale === 'lt' ? 'lt' : 'en';
        $key = collect(['admin.fields.'.$field, 'admin.media.'.$field])->first(fn (string $key): bool => trans()->has($key, $labelLocale, false));

        return ($key ? __($key, [], $labelLocale) : $field).' '.strtoupper($locale);
    }

    /** Сообщения проверки LT/EN: тексты W-07 (обязательное поле, длина) и ContentService (неверное значение). */
    protected function messages(): array
    {
        return [
            'required' => __('account.messages.required'),
            'max' => __('account.messages.max'),
            'string' => __('content.invalid', ['field' => ':attribute']),
            'regex' => __('content.invalid', ['field' => ':attribute']),
            'url' => __('content.invalid', ['field' => ':attribute']),
            'in' => __('content.invalid', ['field' => ':attribute']),
        ];
    }

    /** @param  list<string>  $locales */
    private function attributes(array $locales): array
    {
        $attributes = [];
        foreach ($locales as $locale) {
            foreach (array_keys(ContentTypes::rules($this->item())) as $field) {
                $name = str_starts_with($field, 'fields.') ? explode('.', $field)[1] : $field;
                $attributes['form.'.$locale.'.'.$field] = self::label($name, $locale);
            }
        }

        return $attributes;
    }
}
