<?php

namespace App\Domain\Integrations\Telegram;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\ContentTypes;
use App\Domain\Content\MediaFile;
use App\Domain\Content\Models\ContentItem;
use App\Domain\Content\Models\ContentTranslation;
use App\Domain\Integrations\Telegram\Models\TelegramBinding;
use App\Domain\Integrations\Telegram\Models\TelegramDialog;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Диалог бота контента (§4.5): только личный чат привязанного администратора (user.id и chat.id привязки), остальным —
 * ничего. Привязка — /start <код> (код с экрана Integrations; неверные попытки ограничены по user.id). Один общий
 * диалог для всех типов: поля и проверки — ContentTypes (как в веб-редакторе), запись — ContentService с каналом
 * telegram: тип → запись или новая → язык → поле → текст → черновик → предпросмотр → опубликовать/снять LT или EN.
 * Кнопки несут id записи (и ревизию перевода): устаревшая кнопка отклоняется. Атрибуты записи (meta) — кнопками
 * вариантов или текстом, медиа — фото, видео или файл в контексте записи (≤ aiqme.media_max_kb, проверка MediaFile).
 * Удаления нет; заказов, клиентов и платежей в боте нет. edited_message не обрабатывается. Ответы — после записи;
 * сбой Telegram правку не отменяет.
 */
final class TelegramBot
{
    public const LOCALES = ['lt', 'en'];

    /** Срок контекста диалога, минут. */
    private const DIALOG_MINUTES = 30;

    /** Неверных кодов привязки с одного Telegram user.id за BIND_DECAY секунд. */
    private const BIND_ATTEMPTS = 5;

    private const BIND_DECAY = 600;

    private const PREVIEW_CHARS = 60;

    private int $chatId = 0;

    public function __construct(private readonly TelegramClient $client, private readonly ContentService $content) {}

    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        $callback = $update['callback_query'] ?? null;
        $from = $message['from'] ?? $callback['from'] ?? null;
        $chat = $message['chat'] ?? $callback['message']['chat'] ?? null;
        if (! is_array($from) || ! is_array($chat) || ($chat['type'] ?? null) !== 'private'
            || ! is_int($from['id'] ?? null) || ! is_int($chat['id'] ?? null)) {
            return;
        }
        app()->setLocale(($from['language_code'] ?? null) === 'lt' ? 'lt' : 'en');
        $this->chatId = $chat['id'];
        $text = is_string($message['text'] ?? null) ? trim($message['text']) : null;

        if ($text !== null && preg_match('~^/start(?:@\w+)?\s+(\S+)$~', $text, $start)) {
            $this->bind($from['id'], $start[1]);

            return;
        }
        $admin = $this->admin($from['id']);
        if ($admin === null) {
            return;
        }

        try {
            if (is_array($callback)) {
                $this->safely(fn () => $this->client->call('answerCallbackQuery', ['callback_query_id' => (string) ($callback['id'] ?? '')]));
                $this->button($admin, is_string($callback['data'] ?? null) ? $callback['data'] : '');
            } elseif (is_array($message) && (isset($message['photo']) || isset($message['video']) || isset($message['document']))) {
                $this->media($admin, $message);
            } elseif ($text !== null) {
                $this->text($admin, $text);
            }
        } catch (ValidationException $exception) {
            $this->send(implode("\n", Arr::flatten($exception->errors())));
        } catch (TelegramException) {
            // Сбой Bot API (getFile, скачивание) уже в журнале и последней ошибке привязки.
        }
    }

    /** /start <код>: код с экрана Integrations (SHA-256, срок), неверный — нейтральный ответ без данных. */
    private function bind(int $telegramUserId, string $code): void
    {
        $limit = 'telegram-bind:'.$telegramUserId;
        $binding = TelegramBinding::current();
        $valid = ! RateLimiter::tooManyAttempts($limit, self::BIND_ATTEMPTS)
            && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $code)
            && $binding->exists && $binding->code_hash !== null && $binding->code_expires_at?->isFuture()
            && hash_equals($binding->code_hash, hash('sha256', $code))
            && $binding->user !== null && Gate::forUser($binding->user)->allows('admin');
        if (! $valid) {
            RateLimiter::hit($limit, self::BIND_DECAY);
            $this->send(__('telegram.bind_invalid'));

            return;
        }
        RateLimiter::clear($limit);
        $binding->update([
            'telegram_user_id' => $telegramUserId, 'chat_id' => $this->chatId, 'bound_at' => now(),
            'code_hash' => null, 'code_expires_at' => null,
        ]);
        TelegramDialog::whereKey($this->chatId)->delete();
        $this->send(__('telegram.bound'));
        $this->menu();
    }

    /** Администратор привязки, если апдейт из его личного чата и он всё ещё администратор; иначе null. */
    private function admin(int $telegramUserId): ?User
    {
        $binding = TelegramBinding::current();
        if (! $binding->exists || $binding->telegram_user_id !== $telegramUserId || $binding->chat_id !== $this->chatId) {
            return null;
        }
        $user = $binding->user;

        return $user !== null && Gate::forUser($user)->allows('admin') ? $user : null;
    }

    private function button(User $admin, string $data): void
    {
        $parts = explode(':', $data);
        $item = isset($parts[1]) && ctype_digit($parts[1]) ? ContentItem::find((int) $parts[1]) : null;
        $locale = in_array($parts[2] ?? null, self::LOCALES, true) ? $parts[2] : null;
        $revision = isset($parts[3]) && ctype_digit($parts[3]) ? (int) $parts[3] : null;
        $this->forget();

        match (true) {
            $parts[0] === 't' && ($type = $this->type($parts[1] ?? '')) !== null => $this->items($type),
            $parts[0] === 'n' && ($type = $this->type($parts[1] ?? '')) !== null && $type !== ContentType::PageText => $this->ask(
                ['action' => 'new', 'type' => $type->value], __('telegram.ask_title', ['locale' => 'LT'])),
            $item === null => $this->menu(),
            $parts[0] === 'i' => $this->item($item),
            $parts[0] === 'l' && $locale !== null => $this->translation($item, $locale),
            $parts[0] === 'v' && $locale !== null => $this->preview($item, $locale),
            $parts[0] === 'f' && $locale !== null && $revision !== null => $this->askField($item, $locale, $revision, (int) ($parts[4] ?? -1)),
            in_array($parts[0], ['p', 'u'], true) && $locale !== null && $revision !== null => $this->publish($admin, $item, $locale, $revision, $parts[0] === 'p'),
            $parts[0] === 'a' => $this->askMeta($item, $parts[2] ?? ''),
            $parts[0] === 'o' => $this->choose($admin, $item, $parts[2] ?? '', $parts[3] ?? ''),
            $parts[0] === 'm' && array_key_exists($parts[2] ?? '', ContentTypes::media($item)) => $this->ask(
                ['action' => 'media', 'item' => $item->id, 'collection' => $parts[2]],
                __('telegram.ask_media', ['max' => intdiv(MediaFile::maxKilobytes(), 1024)])),
            default => $this->menu(),
        };
    }

    private function text(User $admin, string $text): void
    {
        $state = $this->state();
        match ($state['action'] ?? null) {
            'new' => $this->createItem($admin, $state, $text),
            'field' => $this->saveField($admin, $state, $text),
            'meta' => $this->saveMeta($admin, ContentItem::find($state['item']), $state['name'], $text),
            default => $this->menu(),
        };
    }

    /** Типы контента (все зарегистрированные в ContentTypes). */
    private function menu(): void
    {
        $buttons = [];
        foreach (ContentType::cases() as $type) {
            if (ContentTypes::isRegistered($type)) {
                $buttons[] = [$this->keyboardButton(__('telegram.types.'.$type->value), 't:'.$type->value)];
            }
        }
        $this->send(__('telegram.menu'), $buttons);
    }

    private function items(ContentType $type): void
    {
        $buttons = ContentItem::where('type', $type)->with('translations')->orderBy('position')->orderBy('id')->get()
            ->map(fn (ContentItem $item): array => [$this->keyboardButton($this->title($item), 'i:'.$item->id)])->all();
        if ($type !== ContentType::PageText) {
            $buttons[] = [$this->keyboardButton(__('admin.list.add'), 'n:'.$type->value)];
        }
        $buttons[] = [$this->keyboardButton(__('telegram.back'), 'menu')];
        $this->send(__('telegram.types.'.$type->value), $buttons);
    }

    /** Запись: состояние языков, атрибуты (meta) и медиа; кнопки языков, атрибутов и назначений медиа. */
    private function item(ContentItem $item): void
    {
        $item->load('translations');
        $lines = [$this->title($item).' · '.__('telegram.types.'.$item->type->value)];
        $buttons = [array_map(fn (string $locale): array => $this->keyboardButton(strtoupper($locale), 'l:'.$item->id.':'.$locale), self::LOCALES)];
        foreach (self::LOCALES as $locale) {
            $lines[] = strtoupper($locale).': '.$this->stateLabel($item->translation($locale));
        }
        $form = ContentTypes::metaToForm($item);
        foreach (ContentTypes::meta($item) as $name => $field) {
            $value = (array) $form[$name];
            $shown = array_map(fn (string $choice): string => $field['options'][$choice] ?? $choice, array_filter($value, fn (string $choice): bool => $choice !== ''));
            $lines[] = __('admin.meta.'.$name).': '.($shown === [] ? '—' : implode(', ', $shown));
            $buttons[] = [$this->keyboardButton(__('admin.meta.'.$name), 'a:'.$item->id.':'.$name)];
        }
        foreach (array_keys(ContentTypes::media($item)) as $collection) {
            $count = $item->getMedia($collection)->count();
            $lines[] = __('admin.media.collections.'.$collection).': '.$count;
            $buttons[] = [$this->keyboardButton(__('telegram.add_media', ['collection' => __('admin.media.collections.'.$collection)]), 'm:'.$item->id.':'.$collection)];
        }
        $buttons[] = [$this->keyboardButton(__('telegram.back'), 't:'.$item->type->value)];
        $this->send(implode("\n", $lines), $buttons);
    }

    /** Перевод: последняя версия по полям ContentTypes, номера полей — кнопки правки; предпросмотр и публикация. */
    private function translation(ContentItem $item, string $locale): void
    {
        $translation = $item->translations()->where('locale', $locale)->first();
        $revision = $translation->revision ?? 0;
        $fields = $this->fields($item, $translation);
        $lines = [$this->title($item).' · '.strtoupper($locale), $this->stateLabel($translation), ''];
        $numbers = [];
        foreach (array_keys($fields) as $index => $path) {
            $lines[] = ($index + 1).'. '.$this->label($path).': '.$this->short($fields[$path]);
            $numbers[] = $this->keyboardButton((string) ($index + 1), 'f:'.$item->id.':'.$locale.':'.$revision.':'.$index);
        }
        $buttons = array_chunk($numbers, 8);
        if ($revision > 0) {
            $buttons[] = [$this->keyboardButton(__('telegram.preview_button'), 'v:'.$item->id.':'.$locale)];
            $buttons[] = [
                $this->keyboardButton(__('admin.editor.publish', ['locale' => strtoupper($locale)]), 'p:'.$item->id.':'.$locale.':'.$revision),
                $this->keyboardButton(__('admin.editor.unpublish', ['locale' => strtoupper($locale)]), 'u:'.$item->id.':'.$locale.':'.$revision),
            ];
        }
        $buttons[] = [$this->keyboardButton(__('telegram.back'), 'i:'.$item->id)];
        $this->send(implode("\n", $lines), $buttons);
    }

    /** Предпросмотр последней версии перевода целиком. */
    private function preview(ContentItem $item, string $locale): void
    {
        $translation = $item->translations()->where('locale', $locale)->first();
        if ($translation === null || $translation->revision === 0) {
            $this->send(__('telegram.no_draft'));

            return;
        }
        $draft = ! $translation->isPublished() || $translation->revision > $translation->published_revision;
        $lines = [__('telegram.preview', ['title' => $translation->latestRevision()->title]), $draft ? __('telegram.preview_draft') : $this->stateLabel($translation)];
        foreach ($this->fields($item, $translation) as $path => $value) {
            if ($value !== '') {
                $lines[] = "\n".$this->label($path).":\n".$value;
            }
        }
        $this->send(implode("\n", $lines), [[$this->keyboardButton(__('telegram.back'), 'l:'.$item->id.':'.$locale)]]);
    }

    private function askField(ContentItem $item, string $locale, int $revision, int $index): void
    {
        $translation = $item->translations()->where('locale', $locale)->first();
        $fields = $this->fields($item, $translation);
        $path = array_keys($fields)[$index] ?? null;
        if (($translation->revision ?? 0) !== $revision || $path === null) {
            $this->outdated($item, $locale);

            return;
        }
        $this->ask(['action' => 'field', 'item' => $item->id, 'locale' => $locale, 'revision' => $revision, 'path' => $path],
            __('telegram.ask_field', ['field' => $this->label($path), 'locale' => strtoupper($locale)])
            ."\n\n".($fields[$path] === '' ? '—' : $fields[$path]));
    }

    /** Новый текст поля → новая версия-черновик (ContentService::saveDraft с ожидаемой ревизией кнопки). */
    private function saveField(User $admin, array $state, string $text): void
    {
        $item = ContentItem::find($state['item']);
        if ($item === null) {
            $this->menu();

            return;
        }
        $translation = $item->translations()->where('locale', $state['locale'])->first();
        $form = ContentTypes::toForm($item, $translation?->latestRevision()?->only(['title', 'slug', 'fields', 'seo_title', 'seo_description']));
        Arr::set($form, $state['path'], $text);
        try {
            $this->content->saveDraft($admin, $item, $state['locale'], ContentTypes::fromForm($item, $form), $state['revision'], Channel::Telegram);
        } catch (ValidationException $exception) {
            if (! array_key_exists('revision', $exception->errors())) {
                throw $exception;
            }
            // Конфликт ревизии (§4.2): правка не сохраняется, бот показывает обе версии.
            $this->forget();
            $current = $this->fields($item, $item->translations()->where('locale', $state['locale'])->first())[$state['path']] ?? '';
            $this->send(__('telegram.stale')."\n\n".__('telegram.stale_current').":\n".($current === '' ? '—' : $current)
                ."\n\n".__('telegram.stale_yours').":\n".$text);
            $this->translation($item, $state['locale']);

            return;
        }
        $this->forget();
        $this->send(__('admin.editor.saved'));
        $this->translation($item, $state['locale']);
    }

    /** Новая запись: заголовок LT, у типов со slug — затем адрес LT (он же ключ записи, как в веб-редакторе). */
    private function createItem(User $admin, array $state, string $text): void
    {
        $type = ContentType::from($state['type']);
        if ($type->hasSlug() && ! isset($state['title'])) {
            $this->ask($state + ['title' => $text], __('telegram.ask_slug', ['locale' => 'LT']));

            return;
        }
        $title = $state['title'] ?? $text;
        $slug = $type->hasSlug() ? $text : null;
        if ($slug !== null && ContentItem::where('type', $type)->where('key', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => __('content.slug_taken')]);
        }
        $item = DB::transaction(function () use ($admin, $type, $title, $slug): ContentItem {
            $item = $this->content->createItem($admin, $type, $slug, position: (int) ContentItem::where('type', $type)->max('position') + 1);
            $form = ContentTypes::toForm($item, null);
            $form['title'] = $title;
            if ($slug !== null) {
                $form['slug'] = $slug;
            }
            $this->content->saveDraft($admin, $item, 'lt', ContentTypes::fromForm($item, $form), 0, Channel::Telegram);

            return $item;
        });
        $this->forget();
        $this->send(__('admin.editor.saved'));
        $this->translation($item, 'lt');
    }

    private function publish(User $admin, ContentItem $item, string $locale, int $revision, bool $publish): void
    {
        $translation = $item->translations()->where('locale', $locale)->first();
        if ($translation === null || $translation->revision === 0) {
            $this->send(__('telegram.no_draft'));

            return;
        }
        if ($translation->revision !== $revision) {
            $this->outdated($item, $locale);

            return;
        }
        $publish
            ? $this->content->publish($admin, $item, $locale, $revision, Channel::Telegram)
            : $this->content->unpublish($admin, $item, $locale, $revision, Channel::Telegram);
        $this->send(__($publish ? 'admin.editor.published_locale' : 'admin.editor.unpublished_locale', ['locale' => strtoupper($locale)]));
        $this->translation($item, $locale);
    }

    /** Атрибут записи: варианты select/choices — кнопками, дата и ссылка — текстом. */
    private function askMeta(ContentItem $item, string $name): void
    {
        $field = ContentTypes::meta($item)[$name] ?? null;
        if ($field === null) {
            $this->item($item);

            return;
        }
        if (! isset($field['options'])) {
            $this->ask(['action' => 'meta', 'item' => $item->id, 'name' => $name], __('telegram.ask_meta', ['field' => __('admin.meta.'.$name)]));

            return;
        }
        $selected = (array) ContentTypes::metaToForm($item)[$name];
        $buttons = [];
        foreach (array_keys($field['options']) as $index => $value) {
            $mark = in_array((string) $value, $selected, true) ? '✓ ' : '';
            $buttons[] = [$this->keyboardButton($mark.$field['options'][$value], 'o:'.$item->id.':'.$name.':'.$index)];
        }
        $buttons[] = [$this->keyboardButton(__('telegram.back'), 'i:'.$item->id)];
        $this->send(__('admin.meta.'.$name), $buttons);
    }

    /** Вариант атрибута: select — выбрать (повторно — снять), choices — отметить или снять. */
    private function choose(User $admin, ContentItem $item, string $name, string $index): void
    {
        $field = ContentTypes::meta($item)[$name] ?? null;
        $value = ctype_digit($index) ? array_keys($field['options'] ?? [])[(int) $index] ?? null : null;
        if ($value === null) {
            $this->item($item);

            return;
        }
        $current = (array) ContentTypes::metaToForm($item)[$name];
        $chosen = in_array((string) $value, $current, true)
            ? array_values(array_diff($current, [(string) $value]))
            : ($field['kind'] === 'choices' ? [...$current, (string) $value] : [(string) $value]);
        $this->saveMeta($admin, $item, $name, $field['kind'] === 'choices' ? $chosen : ($chosen[0] ?? ''));
    }

    private function saveMeta(User $admin, ?ContentItem $item, string $name, mixed $value): void
    {
        if ($item === null || ! array_key_exists($name, ContentTypes::meta($item))) {
            $this->menu();

            return;
        }
        $form = [$name => $value] + ContentTypes::metaToForm($item);
        Validator::make(['meta' => $form], ContentTypes::metaRules($item), ['*' => __('content.invalid', ['field' => __('admin.meta.'.$name)])])->validate();
        $this->content->updateItem($admin, $item, ContentTypes::metaFromForm($item, $form), $item->position);
        $this->forget();
        $this->send(__('admin.ui_text.saved'));
        $this->item($item->refresh());
    }

    /** Фото, видео или файл в контексте записи (кнопка назначения медиа): ≤ лимита, формат — MediaFile по сигнатуре. */
    private function media(User $admin, array $message): void
    {
        $state = $this->state();
        $item = ($state['action'] ?? null) === 'media' ? ContentItem::find($state['item']) : null;
        if ($item === null) {
            $this->send(__('telegram.media_context'));
            $this->menu();

            return;
        }
        $file = isset($message['photo']) && is_array($message['photo']) ? end($message['photo']) : ($message['video'] ?? $message['document'] ?? null);
        $limit = MediaFile::maxKilobytes() * 1024;
        if (! is_array($file) || ! is_string($file['file_id'] ?? null) || (int) ($file['file_size'] ?? 0) > $limit) {
            $this->send(__('telegram.media_too_large', ['max' => intdiv(MediaFile::maxKilobytes(), 1024)]));

            return;
        }
        $info = $this->client->call('getFile', ['file_id' => $file['file_id']]);
        if (! is_string($info['file_path'] ?? null) || (int) ($info['file_size'] ?? 0) > $limit) {
            $this->send(__('telegram.media_too_large', ['max' => intdiv(MediaFile::maxKilobytes(), 1024)]));

            return;
        }
        $path = tempnam(sys_get_temp_dir(), 'tg-media-');
        try {
            $this->client->download($info['file_path'], $path);
            $this->content->addMedia($admin, $item, $path, $state['collection'], replace: ContentTypes::media($item)[$state['collection']]);
        } finally {
            @unlink($path);
        }
        $this->forget();
        $this->send(__('admin.media.uploaded'));
        $this->item($item->refresh());
    }

    /**
     * Поля перевода в виде формы ContentTypes (как у веб-редактора), развёрнутые в пути: title, slug, seo_*,
     * fields.<имя> или fields.<имя>.<номер>.<часть> (у records последняя запись — пустая, для новой).
     *
     * @return array<string, string>
     */
    private function fields(ContentItem $item, ?ContentTranslation $translation): array
    {
        return array_filter(Arr::dot(ContentTypes::toForm($item, $translation?->latestRevision()?->only(['title', 'slug', 'fields', 'seo_title', 'seo_description']))), 'is_string');
    }

    /** Подпись пути поля: «Section 2 · Heading»; блоки page_text без перевода — по имени. */
    private function label(string $path): string
    {
        $segments = explode('.', $path);
        $name = $segments[0] === 'fields' ? $segments[1] : $segments[0];
        $label = trans()->has('admin.fields.'.$name) ? __('admin.fields.'.$name) : $name;
        if (isset($segments[3])) {
            $part = trans()->has('admin.fields.'.$segments[3]) ? __('admin.fields.'.$segments[3]) : $segments[3];
            $label .= ' '.((int) $segments[2] + 1).' · '.$part;
        }

        return $label;
    }

    private function title(ContentItem $item): string
    {
        $translation = $item->translation('lt') ?? $item->translation('en');
        $title = $translation?->title ?? $translation?->latestRevision()?->title ?? (string) $item->key;

        return $this->short($title !== '' ? $title : '#'.$item->id);
    }

    private function stateLabel(?ContentTranslation $translation): string
    {
        $key = match (true) {
            $translation === null || $translation->revision === 0 => 'none',
            ! $translation->isPublished() => 'draft',
            $translation->revision > $translation->published_revision => 'changes',
            default => 'published',
        };

        return __('admin.editor.state.'.$key);
    }

    private function type(string $value): ?ContentType
    {
        $type = ContentType::tryFrom($value);

        return $type !== null && ContentTypes::isRegistered($type) ? $type : null;
    }

    private function outdated(ContentItem $item, string $locale): void
    {
        $this->send(__('telegram.outdated'));
        $this->translation($item, $locale);
    }

    private function short(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? '—' : mb_strimwidth($value, 0, self::PREVIEW_CHARS, '…');
    }

    /** @return array<string, mixed> контекст диалога, если не истёк */
    private function state(): array
    {
        $dialog = TelegramDialog::find($this->chatId);

        return $dialog !== null && $dialog->expires_at->isFuture() ? $dialog->state : [];
    }

    private function ask(array $state, string $question): void
    {
        TelegramDialog::updateOrCreate(['chat_id' => $this->chatId], ['state' => $state, 'expires_at' => now()->addMinutes(self::DIALOG_MINUTES)]);
        $this->send($question);
    }

    private function forget(): void
    {
        TelegramDialog::whereKey($this->chatId)->delete();
    }

    /** @return array{text: string, callback_data: string} */
    private function keyboardButton(string $label, string $data): array
    {
        return ['text' => $label, 'callback_data' => $data];
    }

    /** Ответ после записи; сбой Telegram уже в журнале и последней ошибке (TelegramClient) и правку не отменяет. */
    private function send(string $text, array $buttons = []): void
    {
        $params = ['chat_id' => $this->chatId, 'text' => mb_substr($text, 0, 4000)];
        if ($buttons !== []) {
            $params['reply_markup'] = ['inline_keyboard' => $buttons];
        }
        $this->safely(fn () => $this->client->call('sendMessage', $params));
    }

    private function safely(callable $call): void
    {
        try {
            $call();
        } catch (TelegramException) {
        }
    }
}
