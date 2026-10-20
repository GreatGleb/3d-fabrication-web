<?php

namespace Tests\Feature;

use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Livewire\Admin\ContentEditor;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\browser\ContentFactory;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Медиа прототипа по умолчанию (D-43, W-33): на БД сидера W-04 без загрузок места прототипа выводят файлы
 * public/defaults/ из config('aiqme.media_defaults'); загрузка в слот перекрывает умолчание, удаление возвращает его;
 * редактор админки показывает превью умолчания.
 */
class DefaultMediaTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = __DIR__.'/../../../docs/customer-prototype/source-backup-20261016/AIQME-site/dist/assets';

    /** Фрагменты спрайтов D-43: ключ услуги => [спрайт, x, y, ширина, высота]. */
    private const FRAGMENTS = [
        'scanning' => ['dark', 43, 441, 267, 121], 'modelling' => ['dark', 324, 441, 266, 121],
        'printing' => ['dark', 604, 441, 268, 121], 'milling' => ['dark', 473, 675, 398, 119],
        'engraving' => ['light', 940, 610, 195, 140], 'training' => ['light', 280, 610, 195, 140],
    ];

    private string $dir;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(PrototypeContentSeeder::class);
        $this->admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $this->admin->save();
        $this->dir = sys_get_temp_dir().'/aiqme-default-media-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function item(ContentType $type, string $key): ContentItem
    {
        return ContentItem::where('type', $type)->where('key', $key)->firstOrFail();
    }

    private function png(string $name, int $width = 64, int $height = 32): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $path = $this->dir.'/'.$name.'.png');

        return $path;
    }

    private function mp4(string $name): string
    {
        file_put_contents($path = $this->dir.'/'.$name.'.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");

        return $path;
    }

    private function src(string $html, string $query): ?string
    {
        return Html::xpath($html)->query($query)->item(0)?->getAttribute('src');
    }

    public function test_defaults_directory_holds_exactly_the_configured_files_with_d43_fragment_sizes(): void
    {
        $configured = collect(config('aiqme.media_defaults'))->flatten()->sort()->values()->all();
        $files = collect(glob(public_path('defaults/{,services/}*.*'), GLOB_BRACE))
            ->map(fn (string $file): string => 'defaults/'.ltrim(substr($file, strlen(public_path('defaults'))), '/'))->sort()->values()->all();
        $this->assertSame($configured, $files);
        $this->assertCount(15, $files);
        $this->assertLessThanOrEqual(6 * 1024 * 1024, array_sum(array_map(fn (string $file): int => filesize(public_path($file)), $files)));

        foreach (self::FRAGMENTS as $key => [, , , $width, $height]) {
            $this->assertSame([$width, $height], array_slice(getimagesize(public_path("defaults/services/$key.png")), 0, 2), $key);
        }
    }

    public function test_default_files_equal_the_prototype_assets_when_the_source_backup_is_present(): void
    {
        if (! is_dir(self::SOURCE)) {
            $this->markTestSkipped('не проверено (нет исходников source-backup)');
        }
        foreach (collect(config('aiqme.media_defaults'))->flatten() as $file) {
            if (! str_starts_with($file, 'defaults/services/')) {
                $this->assertSame(hash_file('sha256', self::SOURCE.'/'.basename($file)), hash_file('sha256', public_path($file)), $file);
            }
        }
        foreach (self::FRAGMENTS as $key => [$sheet, $x, $y, $width, $height]) {
            $sprite = imagecreatefrompng(self::SOURCE."/concept-$sheet.png");
            $fragment = imagecreatefrompng(public_path("defaults/services/$key.png"));
            foreach ([[0, 0], [$width - 1, $height - 1], [intdiv($width, 2), intdiv($height, 2)], [17, 93 % $height], [$width - 30, 5]] as [$px, $py]) {
                $this->assertSame(imagecolorsforindex($sprite, imagecolorat($sprite, $x + $px, $y + $py)),
                    imagecolorsforindex($fragment, imagecolorat($fragment, $px, $py)), "$key $px,$py");
            }
        }
    }

    public function test_pages_without_uploads_show_the_prototype_defaults_in_their_places(): void
    {
        $html = $this->get('/en')->assertOk()->getContent();
        $xpath = Html::xpath($html);
        $video = $xpath->query('//section[@class="hero"]//video[@id="hero-video"]')->item(0);
        $this->assertSame(asset('defaults/hero-video-poster.jpg'), $video->getAttribute('poster'));
        $this->assertSame([asset('defaults/hero-engineering-mobile.mp4'), asset('defaults/hero-engineering.mp4')],
            array_map(fn ($source) => $source->getAttribute('src'), iterator_to_array($xpath->query('.//source', $video))));
        $this->assertSame('(max-width: 700px)', $xpath->query('.//source', $video)->item(0)->getAttribute('media'));
        $link = $xpath->query('//div[@class="hero-media-controls"]/span/a')->item(0);
        $this->assertSame(['https://www.youtube.com/watch?v=1Nd_xg8QfXo', 'Bosch / The Apic ↗'], [$link->getAttribute('href'), Html::text($link)]);
        foreach (['Manufacturing illustration', 'Gamybos iliustracija', 'Illustrative', 'Demonstracinė iliustracija', 'DEMONSTRACINĖ ILIUSTRACIJA'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
        $this->assertSame(asset('defaults/logo.jpg'), $this->src($html, '//header//a[@class="logo"]/img'));
        $this->assertSame(asset('defaults/logo.jpg'), $this->src($html, '//footer//a[@class="logo"]/img'));
        $this->assertSame(asset('defaults/favicon.svg'), $xpath->query('//head/link[@rel="icon"]')->item(0)->getAttribute('href'));

        // Шесть услуг: главная, список, своя страница — фрагмент услуги, alt — название услуги, width/height файла.
        foreach (['/en', '/en/services', '/lt/paslaugos'] as $path) {
            $images = Html::xpath($this->get($path)->getContent())->query('//div[contains(@class, "service-feature-media")]/img');
            $this->assertCount(6, $images, $path);
            foreach ($images as $index => $img) {
                $key = array_keys(self::FRAGMENTS)[$index];
                $this->assertSame(asset("defaults/services/$key.png"), $img->getAttribute('src'), $path);
                $this->assertSame([(string) self::FRAGMENTS[$key][3], (string) self::FRAGMENTS[$key][4]], [$img->getAttribute('width'), $img->getAttribute('height')]);
                $this->assertSame('lazy', $img->getAttribute('loading'));
            }
        }
        $this->assertSame('3D scanning', Html::xpath($this->get('/en/services')->getContent())->query('//div[contains(@class, "service-feature-media")]/img')->item(0)->getAttribute('alt'));
        foreach (['/en/services/engraving' => ['engraving', 'en'], '/lt/paslaugos/3d-modeliavimo-mokymai' => ['training', 'lt']] as $path => [$key, $locale]) {
            $img = Html::xpath($this->get($path)->getContent())->query('//div[@class="media-frame"]/img')->item(0);
            $this->assertSame(asset("defaults/services/$key.png"), $img->getAttribute('src'), $path);
            $this->assertSame('eager', $img->getAttribute('loading'));
            $this->assertSame($this->item(ContentType::Service, $key)->translation($locale)->title, $img->getAttribute('alt'));
        }

        // «О нас»: engineering.jpg с пустым alt (в прототипе — фон).
        $img = Html::xpath($this->get('/en/about')->getContent())->query('//div[@class="split-image"]/img')->item(0);
        $this->assertSame([asset('defaults/engineering.jpg'), '', '2048', '341'],
            [$img->getAttribute('src'), $img->getAttribute('alt'), $img->getAttribute('width'), $img->getAttribute('height')]);

        // Логотип и favicon — и в админке.
        $admin = $this->actingAs($this->admin)->get('/en/admin')->assertOk()->getContent();
        $this->assertSame(asset('defaults/logo.jpg'), $this->src($admin, '//header//a[@class="logo"]/img'));
        $this->assertStringContainsString('<link rel="icon" href="'.asset('defaults/favicon.svg').'"', $admin);
    }

    public function test_project_photo_default_appears_only_after_publication_and_a_new_project_keeps_the_svg_placeholder(): void
    {
        $this->get('/en/projects/prototype')->assertNotFound();
        $this->assertStringNotContainsString('project-prototype.jpg', $this->get('/en/projects')->getContent());

        $factory = ContentFactory::as($this->admin);
        $factory->publishDraft(ContentType::Project, 'prototype');
        $factory->project('bracket', ['en' => ['Bracket reconstruction', 'bracket-reconstruction', 'Task.', 'Solution.', 'Result.']], ['modelling'], 'maintenance', 5);

        $img = Html::xpath($this->get('/en/projects/prototype')->assertOk()->getContent())->query('//div[@class="project-image"]/img')->item(0);
        $this->assertSame([asset('defaults/project-prototype.jpg'), 'An idea you can hold.'], [$img->getAttribute('src'), $img->getAttribute('alt')]);
        $list = Html::xpath($this->get('/en/projects')->getContent());
        $this->assertSame([asset('defaults/project-prototype.jpg')], array_map(fn ($node) => $node->getAttribute('src'), iterator_to_array($list->query('//div[@class="project-image"]/img'))));
        $this->assertCount(1, $list->query('//div[@class="project-image"]/*[name()="svg" and @class="placeholder-art"]'));
        // Пример на странице услуги печати — опубликованный проект с фото по умолчанию.
        $this->assertSame(asset('defaults/project-prototype.jpg'), $this->src($this->get('/en/services/3d-printing')->getContent(), '//a[contains(@class, "project-card")]//img'));
        // Демо-пометка результата черновика при публикации снята (D-21).
        $this->assertStringNotContainsString('Illustrative result', $this->get('/en/projects/prototype')->getContent());
    }

    public function test_upload_to_each_slot_overrides_its_default_and_removal_restores_it(): void
    {
        $content = app(ContentService::class);
        $factory = ContentFactory::as($this->admin);
        $factory->publishDraft(ContentType::Project, 'prototype');
        $home = $this->item(ContentType::PageText, 'home');
        $slots = [
            ['/en', $home, 'logo', 'defaults/logo.jpg', '//header//a[@class="logo"]/img/@src'],
            ['/en', $home, 'favicon', 'defaults/favicon.svg', '//head/link[@rel="icon"]/@href'],
            ['/en/about', $this->item(ContentType::PageText, 'about'), 'images', 'defaults/engineering.jpg', '//div[@class="split-image"]/img/@src'],
            ['/en/services/engraving', $this->item(ContentType::Service, 'engraving'), 'images', 'defaults/services/engraving.png', '//div[@class="media-frame"]/img/@src'],
            ['/en/projects/prototype', $this->item(ContentType::Project, 'prototype'), 'images', 'defaults/project-prototype.jpg', '//div[@class="project-image"]/img/@src'],
        ];
        foreach ($slots as [$path, $item, $collection, $default, $query]) {
            $media = $content->addMedia($this->admin, $item, $this->png($collection), $collection);
            $html = $this->get($path)->getContent();
            $this->assertStringNotContainsString($default, $html, "$path $collection");
            $this->assertStringStartsWith('/storage/', parse_url(Html::xpath($html)->query($query)->item(0)->nodeValue, PHP_URL_PATH), "$path $collection");

            $content->removeMedia($this->admin, $item, $media->id);
            $this->assertSame(asset($default), Html::xpath($this->get($path)->getContent())->query($query)->item(0)->nodeValue, "$path $collection");
        }
        $this->assertStringContainsString('type="image/png"', $this->withMedia($home, 'favicon', fn () => $this->get('/en')->getContent()));

        // Hero: свой постер или мобильный ролик без своего video перекрывают только свой слот набора умолчаний.
        foreach (['video_poster' => 'png', 'video_mobile' => 'mp4'] as $collection => $kind) {
            $html = $this->withMedia($home, $collection, fn () => $this->get('/en')->getContent(), $kind);
            $this->assertStringNotContainsString(ContentItem::mediaDefault(ContentType::PageText, 'home', $collection), $html, $collection);
            $this->assertStringContainsString('defaults/hero-engineering.mp4', $html, $collection);
        }

        // Своё video: ни одного умолчания hero и ни подписи, ни ссылки прототипа; после удаления — снова набор умолчаний.
        $video = $content->addMedia($this->admin, $home, $this->mp4('own'), 'video');
        $html = $this->get('/en')->getContent();
        $this->assertStringNotContainsString('/defaults/hero-', $html);
        $this->assertStringNotContainsString('1Nd_xg8QfXo', $html);
        $this->assertStringNotContainsString('Bosch / The Apic', $html);
        $sources = Html::xpath($html)->query('//video[@id="hero-video"]/source');
        $this->assertCount(1, $sources);
        $this->assertSame($video->getUrl(), $sources->item(0)->getAttribute('src'));
        $this->assertFalse(Html::xpath($html)->query('//video[@id="hero-video"]')->item(0)->hasAttribute('poster'));
        $content->removeMedia($this->admin, $home, $video->id);
        $html = $this->get('/en')->getContent();
        $this->assertStringContainsString(asset('defaults/hero-engineering.mp4'), $html);
        $this->assertStringContainsString('1Nd_xg8QfXo', $html);
    }

    /** Загружает файл в слот, выполняет $read и удаляет файл. */
    private function withMedia(ContentItem $item, string $collection, callable $read, string $kind = 'png'): string
    {
        $content = app(ContentService::class);
        $media = $content->addMedia($this->admin, $item, $kind === 'mp4' ? $this->mp4($collection) : $this->png($collection), $collection);
        $result = $read();
        $content->removeMedia($this->admin, $item->fresh(), $media->id);

        return $result;
    }

    public function test_admin_editor_previews_defaults_until_an_upload_and_again_after_removal(): void
    {
        ContentFactory::as($this->admin)->publishDraft(ContentType::Project, 'prototype');
        $this->actingAs($this->admin);
        app()->setLocale('en');
        $editor = fn (ContentType $type, string $key) => Livewire::test(ContentEditor::class, ['type' => $type->value, 'itemId' => $this->item($type, $key)->id]);

        $home = $editor(ContentType::PageText, 'home');
        foreach (['logo.jpg', 'favicon.svg', 'hero-engineering.mp4', 'hero-engineering-mobile.mp4', 'hero-video-poster.jpg'] as $file) {
            $home->assertSee(asset('defaults/'.$file), false);
        }
        $home->assertSee('Logo')->assertSee('Favicon (PNG)')->assertSee('(default)');
        $editor(ContentType::PageText, 'about')->assertSee(asset('defaults/engineering.jpg'), false);
        $editor(ContentType::Project, 'prototype')->assertSee(asset('defaults/project-prototype.jpg'), false);

        $service = $editor(ContentType::Service, 'milling')->assertSee('<img class="admin-media-preview" src="'.asset('defaults/services/milling.png').'"', false);
        $service->set('upload', UploadedFile::fake()->createWithContent('own.png', (string) file_get_contents($this->png('own'))))
            ->call('uploadMedia')->assertHasNoErrors()
            ->assertDontSee(asset('defaults/services/milling.png'), false);
        $media = $this->item(ContentType::Service, 'milling')->getFirstMedia('images');
        $service->assertSee('<img class="admin-media-preview" src="'.$media->getUrl().'"', false)
            ->call('removeMedia', $media->id)
            ->assertSee(asset('defaults/services/milling.png'), false);

        // Своё video главной скрывает умолчания видео, мобильного ролика и постера, но не логотипа.
        app(ContentService::class)->addMedia($this->admin, $this->item(ContentType::PageText, 'home'), $this->mp4('own'), 'video');
        $editor(ContentType::PageText, 'home')->assertDontSee('defaults/hero-', false)->assertSee(asset('defaults/logo.jpg'), false);
    }
}
