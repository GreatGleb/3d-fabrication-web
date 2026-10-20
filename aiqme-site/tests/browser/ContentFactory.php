<?php

namespace Tests\browser;

use App\Domain\Audit\Channel;
use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Models\User;

/**
 * Данные публичных страниц W-05/W-06 для bin/browser-check и Feature-тестов — только через ContentService, как в админке:
 * опубликованные бренды, новости раздела «новости», медиа hero (видео, мобильное видео, постер, источник), проект LT+EN,
 * связанный с услугой, обучение, товары каталога с брендом и категорией (W-25).
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
     * Проект LT+EN (или только переданные локали): ['title', 'slug', 'task', 'solution', 'result'] на локаль.
     *
     * @param  array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>  $translations
     * @param  list<string>  $services  ключи услуг
     */
    public function project(string $key, array $translations, array $services, string $application, int $position = 0): ContentItem
    {
        return $this->published(ContentType::Project, $key, array_map(fn (array $text): array => [
            'title' => $text[0], 'slug' => $text[1], 'fields' => ['task' => $text[2], 'solution' => $text[3], 'result' => $text[4]],
        ], $translations), ['services' => $services, 'application' => $application], $position);
    }

    /**
     * Тема обучения: дата (meta), на локаль — [название, описание].
     *
     * @param  array<string, array{0: string, 1: string}>  $translations
     */
    public function training(string $key, string $date, array $translations, int $position = 0): ContentItem
    {
        return $this->published(ContentType::Training, $key, array_map(fn (array $text): array => [
            'title' => $text[0], 'fields' => ['description' => $text[1]],
        ], $translations), ['date' => $date, 'format' => 'online'], $position);
    }

    /**
     * Товар каталога (W-25): бренд (meta.brand — id бренда), категория (meta.category — ключ категории сидера W-04); на локаль —
     * [название, slug, описание, характеристики, совместимость].
     *
     * @param  array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>  $translations
     */
    public function product(string $key, ?ContentItem $brand, string $category, array $translations, int $position = 0): ContentItem
    {
        return $this->published(ContentType::Product, $key, array_map(fn (array $text): array => [
            'title' => $text[0], 'slug' => $text[1],
            'fields' => ['description' => $text[2], 'specifications' => $text[3], 'compatibility' => $text[4]],
        ], $translations), ['category' => $category, 'brand' => $brand?->id], $position);
    }

    /**
     * 4 товара LT+EN с брендом и категорией (W-25 checks): бренды — из homeTickers (создаются, если их ещё нет).
     *
     * @return list<ContentItem>
     */
    public function catalog(): array
    {
        $brand = fn (string $key, string $name, int $position): ContentItem => ContentItem::where('type', ContentType::Brand)
            ->where('key', $key)->first() ?? $this->brand($key, $name, $position);
        $norda = $brand('norda', 'NORDA', 1);
        $vekta = $brand('vekta', 'VEKTA', 2);

        return [
            $this->product('norda-scanner', $norda, 'scanners', [
                'lt' => ['NORDA S1 skeneris', 'norda-s1-skeneris', 'Optinis skeneris detalių matmenims užfiksuoti.', 'Tikslumas iki 0,05 mm.', 'Veikia su CAD programomis.'],
                'en' => ['NORDA S1 scanner', 'norda-s1-scanner', 'An optical scanner for capturing part geometry.', 'Accuracy up to 0.05 mm.', 'Works with CAD software.'],
            ], 1),
            $this->product('vekta-printer', $vekta, 'printers', [
                'lt' => ['VEKTA P2 spausdintuvas', 'vekta-p2-spausdintuvas', 'FDM spausdintuvas funkcinėms detalėms.', 'Darbo laukas 250 × 250 × 250 mm.', 'PLA, PETG ir ABS gijos.'],
                'en' => ['VEKTA P2 printer', 'vekta-p2-printer', 'An FDM printer for functional parts.', 'Build volume 250 × 250 × 250 mm.', 'PLA, PETG and ABS filament.'],
            ], 2),
            $this->product('vekta-petg', $vekta, 'materials', [
                'lt' => ['PETG gija', 'petg-gija', 'Tvirta gija mechaninėms detalėms.', 'Skersmuo 1,75 mm.', 'FDM spausdintuvai.'],
                'en' => ['PETG filament', 'petg-filament', 'A tough filament for mechanical parts.', 'Diameter 1.75 mm.', 'FDM printers.'],
            ], 3),
            $this->product('norda-software', $norda, 'software', [
                'lt' => ['NORDA CAD', 'norda-cad', 'Programa skenavimo duomenims apdoroti.', 'Licencija vienai darbo vietai.', 'Windows 11.'],
                'en' => ['NORDA CAD', 'norda-cad', 'Software for processing scan data.', 'Single-seat licence.', 'Windows 11.'],
            ], 4),
        ];
    }

    /** Проект, обучение и новость browser-check (W-06 checks): новости — из homeTickers. */
    public function publicSections(): void
    {
        $project = $this->project('bracket', [
            'lt' => ['Laikiklio atkūrimas', 'laikiklio-atkurimas', 'Reikėjo atkurti įrangos laikiklį.', 'Detalė nuskenuota ir sumodeliuota.', 'Pagamintas naujas laikiklis.'],
            'en' => ['Bracket reconstruction', 'bracket-reconstruction', 'An equipment bracket had to be restored.', 'The part was scanned and modelled.', 'A new bracket was made.'],
        ], ['modelling', 'scanning'], 'maintenance', 1);
        // YouTube-ссылка проекта (§1.2, «при наличии»): ссылка без встраивания, браузер её не загружает.
        $this->content->updateItem($this->admin, $project, [...$project->meta, 'youtube' => 'https://www.youtube.com/watch?v=example'], $project->position);
        $this->training('cad-basics', '2027-02-10', [
            'lt' => ['CAD pagrindai', 'Nuotoliniu būdu · grupė'],
            'en' => ['CAD basics', 'Online · group'],
        ], 1);
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
