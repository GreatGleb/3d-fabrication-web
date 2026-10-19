<?php

namespace Tests\Feature;

use App\Domain\Content\ContentService;
use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Медиабиблиотека контента (W-04, §4.1, §4.4, §6.4): WebP-варианты, проверка сигнатуры, alt на локаль. */
class ContentMediaTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->dir = sys_get_temp_dir().'/aiqme-media-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();

        return $admin;
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
        $path = $this->dir.'/upload.png';
        imagepng($image, $path);

        return $path;
    }

    public function test_image_gets_webp_variants_of_several_widths_and_localized_alt(): void
    {
        $admin = $this->admin();
        $item = app(ContentService::class)->createItem($admin, ContentType::Service, 'scanning');

        $media = app(ContentService::class)->addMedia($admin, $item, $this->png(2000, 1000), 'images',
            ['lt' => '3D skenavimas', 'en' => '3D scanning'], ['text' => 'AIQME', 'url' => 'https://aiqme.com']);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $media->file_name);
        $this->assertSame('3D skenavimas', $media->getCustomProperty('alt.lt'));
        $this->assertSame('3D scanning', $media->getCustomProperty('alt.en'));
        $this->assertSame('https://aiqme.com', $media->getCustomProperty('source.url'));
        foreach (ContentItem::IMAGE_WIDTHS as $width) {
            $path = $media->getPath('w'.$width);
            $this->assertFileExists($path);
            $this->assertStringEndsWith('.webp', $path);
            [$actualWidth, , $type] = getimagesize($path);
            $this->assertSame(IMAGETYPE_WEBP, $type);
            $this->assertSame($width, $actualWidth);
        }
    }

    public function test_files_are_accepted_by_signature_only(): void
    {
        $admin = $this->admin();
        $service = app(ContentService::class);
        $item = $service->createItem($admin, ContentType::Project, 'project');
        $svg = $this->dir.'/logo.png';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $attempts = [
            [$svg, 'images'],                    // SVG с расширением .png
            [$this->png(10, 10), 'video'],       // изображение в коллекцию видео
            [$this->png(10, 10), 'gallery'],     // неизвестное назначение
        ];
        foreach ($attempts as [$path, $collection]) {
            try {
                $service->addMedia($admin, $item, $path, $collection);
                $this->fail('Принят файл '.basename($path).' в '.$collection);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('file', $e->errors());
            }
        }
        try {
            $service->addMedia($admin, $item, $this->png(10, 10), 'images', [], ['url' => 'javascript:alert(1)']);
            $this->fail('Принята ссылка javascript:');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('source', $e->errors());
        }
        $this->assertSame(0, $item->media()->count());
    }
}
