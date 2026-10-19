<?php

namespace Tests\browser;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Models\User;

/**
 * Данные публичных страниц W-05 для bin/browser-check и Feature-тестов — только через ContentService, как в админке:
 * опубликованные бренды, новости раздела «новости», медиа hero (видео, мобильное видео, постер, источник).
 * Тексты нейтральные, без слов-маркеров D-21; файлы медиа создаёт вызывающий во временном каталоге.
 */
final class ContentFactory
{
    public function __construct(private readonly ContentService $content, private readonly User $admin) {}

    public static function as(User $admin): self
    {
        return new self(app(ContentService::class), $admin);
    }

    /**
     * Создаёт элемент и публикует переводы: locale => ['title', 'slug'?, 'fields'?, 'seo_title'?, 'seo_description'?].
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function published(ContentType $type, string $key, array $translations, array $meta = [], int $position = 0): ContentItem
    {
        $item = $this->content->createItem($this->admin, $type, $key, $meta, $position);
        foreach ($translations as $locale => $data) {
            $translation = $this->content->saveDraft($this->admin, $item, $locale, $data, 0, Channel::Web);
            $this->content->publish($this->admin, $item, $locale, $translation->revision, Channel::Web);
        }

        return $item;
    }

    public function brand(string $key, string $name, int $position): ContentItem
    {
        return $this->published(ContentType::Brand, $key, ['lt' => ['title' => $name], 'en' => ['title' => $name]], position: $position);
    }

    /**
     * @param  array{0: string, 1: string}  $lt  [заголовок, вводный абзац]
     * @param  array{0: string, 1: string}  $en
     */
    public function news(string $key, array $lt, array $en, int $position): ContentItem
    {
        $translation = fn (array $text): array => ['title' => $text[0], 'slug' => $key, 'fields' => ['intro' => $text[1]]];

        return $this->published(ContentType::Article, $key, ['lt' => $translation($lt), 'en' => $translation($en)],
            ['section' => 'news', 'category' => 'guides'], $position);
    }

    /**
     * Медиа hero у page_text главной (§4.1, §7.3): видео, мобильное видео ≤700 px, постер и необязательный источник.
     *
     * @param  array{text: string, url?: string}|null  $source
     */
    public function heroMedia(string $video, string $mobile, string $poster, ?array $source = null): ContentItem
    {
        $home = ContentItem::where('type', ContentType::PageText)->where('key', 'home')->firstOrFail();
        $this->content->addMedia($this->admin, $home, $video, 'video', source: $source);
        $this->content->addMedia($this->admin, $home, $mobile, 'video_mobile');
        $this->content->addMedia($this->admin, $home, $poster, 'video_poster', ['lt' => 'AIQME', 'en' => 'AIQME']);

        return $home;
    }

    /** Бренды и новости browser-check: по два опубликованных (W-05 checks). */
    public function homeTickers(): void
    {
        $this->brand('norda', 'NORDA', 1);
        $this->brand('vekta', 'VEKTA', 2);
        $this->news('workshop-update', ['Dirbtuvių atnaujinimas', 'Atnaujinome gamybos zoną ir įrangą.'],
            ['Workshop update', 'We have updated the production area and equipment.'], 1);
        $this->news('new-materials', ['Naujos medžiagos', 'Sandėlyje — naujos spausdinimo medžiagos.'],
            ['New materials', 'New printing materials are now in stock.'], 2);
    }
}
