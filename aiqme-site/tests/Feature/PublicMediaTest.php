<?php

namespace Tests\Feature;

use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\browser\ContentFactory;
use Tests\Support\Html;
use Tests\TestCase;

/** Вывод медиа (§4.4, W-05 done[1], done[7]) на фабричном медиа: изображения медиатеки, видео hero, заглушка без медиа. */
class PublicMediaTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(PrototypeContentSeeder::class);
        $this->dir = sys_get_temp_dir().'/aiqme-public-media-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    /** Единственный администратор (§2.4): второй строки is_admin БД не примет. */
    private function admin(): User
    {
        if ($admin = User::where('is_admin', true)->first()) {
            return $admin;
        }
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();

        return $admin;
    }

    private function png(string $name, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
        imagepng($image, $path = $this->dir.'/'.$name.'.png');

        return $path;
    }

    /** Минимальный файл с сигнатурой MP4 (ftyp): ContentService принимает видео по сигнатуре. */
    private function mp4(string $name): string
    {
        file_put_contents($path = $this->dir.'/'.$name.'.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");

        return $path;
    }

    public function test_service_image_is_rendered_with_webp_srcset_sizes_dimensions_lazy_below_the_fold_and_localized_alt(): void
    {
        $service = ContentItem::where('type', ContentType::Service)->where('key', 'scanning')->firstOrFail();
        app(ContentService::class)->addMedia($this->admin(), $service, $this->png('scan', 2000, 1000), 'images',
            ['lt' => 'Skenuojama detalė', 'en' => 'A part being scanned']);

        $img = Html::xpath($this->get('/en/services')->getContent())->query('//div[contains(@class, "service-feature-media")]/img')->item(0);
        $this->assertNotNull($img);
        $srcset = $img->getAttribute('srcset');
        foreach (['-w480.webp 480w', '-w960.webp 960w', '-w1600.webp 1600w'] as $variant) {
            $this->assertStringContainsString($variant, $srcset);
        }
        $this->assertSame('(max-width: 760px) 100vw, 50vw', $img->getAttribute('sizes'));
        $this->assertSame(['2000', '1000'], [$img->getAttribute('width'), $img->getAttribute('height')]);
        $this->assertSame('lazy', $img->getAttribute('loading'));
        $this->assertSame('A part being scanned', $img->getAttribute('alt'));

        // На странице услуги иллюстрация в первом экране — без lazy; og:image — WebP-вариант.
        $html = $this->get('/lt/paslaugos/3d-skenavimas')->getContent();
        $img = Html::xpath($html)->query('//div[@class="media-frame"]/img')->item(0);
        $this->assertSame('eager', $img->getAttribute('loading'));
        $this->assertSame('Skenuojama detalė', $img->getAttribute('alt'));
        $this->assertMatchesRegularExpression('~<meta property="og:image" content="[^"]+-w1600\.webp">~', $html);

        // Маленький оригинал: варианты не шире оригинала, без повторов ширины.
        $engraving = ContentItem::where('type', ContentType::Service)->where('key', 'engraving')->firstOrFail();
        app(ContentService::class)->addMedia($this->admin(), $engraving, $this->png('small', 700, 400), 'images');
        $small = Html::xpath($this->get('/en/services/engraving')->getContent())->query('//div[@class="media-frame"]/img')->item(0);
        $this->assertSame(['480w', '700w'], array_map(fn (string $s): string => explode(' ', trim($s))[1], explode(',', $small->getAttribute('srcset'))));
        $this->assertSame('', $small->getAttribute('alt'));
    }

    public function test_without_media_pages_show_the_own_svg_placeholder_and_no_prototype_images(): void
    {
        foreach (['/lt', '/en/services', '/en/services/3d-scanning', '/lt/apie-mus'] as $path) {
            $html = $this->get($path)->getContent();
            $xpath = Html::xpath($html);
            $this->assertGreaterThan(0, $xpath->query('//*[name()="svg" and @class="placeholder-art"]')->length, $path);
            $this->assertCount(0, $xpath->query('//main//img'), $path);
            $this->assertStringNotContainsString('/assets/', $html);
        }
        $html = $this->get('/en')->getContent();
        $this->assertCount(0, Html::xpath($html)->query('//video'), 'без медиа hero — только заглушка, без видео');
        $this->assertStringNotContainsString('hero-video-toggle', $html);
        $this->assertStringNotContainsString('youtube', $html);
    }

    public function test_hero_video_has_metadata_preload_mobile_source_poster_controls_and_the_media_source_caption(): void
    {
        ContentFactory::as($this->admin())->heroMedia($this->mp4('hero'), $this->mp4('hero-mobile'), $this->png('poster', 1920, 1080),
            ['text' => 'AIQME studija', 'url' => 'https://aiqme.com/video']);

        $html = $this->get('/en')->getContent();
        $xpath = Html::xpath($html);
        $video = $xpath->query('//section[@class="hero"]//video[@id="hero-video"]')->item(0);
        $this->assertNotNull($video);
        $this->assertSame('metadata', $video->getAttribute('preload'));
        $this->assertFalse($video->hasAttribute('autoplay'), 'автозапуск — только скриптом с учётом reduced-motion');
        $this->assertTrue($video->hasAttribute('muted') && $video->hasAttribute('loop') && $video->hasAttribute('playsinline'));
        $this->assertStringEndsWith('-w1600.webp', $video->getAttribute('poster'));
        $sources = $xpath->query('.//source', $video);
        $this->assertSame('(max-width: 700px)', $sources->item(0)->getAttribute('media'));
        $this->assertStringEndsWith('.mp4', $sources->item(0)->getAttribute('src'));
        $this->assertFalse($sources->item(1)->hasAttribute('media'));
        $this->assertNotSame($sources->item(0)->getAttribute('src'), $sources->item(1)->getAttribute('src'));

        $toggle = $xpath->query('//button[@id="hero-video-toggle"]')->item(0);
        $this->assertSame('▶ Play video', Html::text($toggle));
        $this->assertSame(['hero-video', 'Pause video'], [$toggle->getAttribute('aria-controls'), $toggle->getAttribute('data-pause')]);
        $link = $xpath->query('//div[@class="hero-media-controls"]/span/a')->item(0);
        $this->assertSame('https://aiqme.com/video', $link->getAttribute('href'));
        $this->assertSame('AIQME studija ↗', Html::text($link));
        $this->assertMatchesRegularExpression('~<meta property="og:image" content="[^"]+-w1600\.webp">~', $html);
    }
}
