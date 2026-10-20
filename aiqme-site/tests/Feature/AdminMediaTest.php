<?php

namespace Tests\Feature;

use App\Domain\Content\ContentType;
use App\Domain\Content\MediaFile;
use App\Domain\Content\Models\ContentItem;
use App\Livewire\Admin\ContentEditor;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Медиа в общем редакторе (W-08, §6.4): предел — конфигурация (20 МБ по умолчанию, тот же в правилах временных
 * загрузок Livewire); формат — только JPEG, PNG, WebP, MP4 по сигнатуре в MediaFile (SVG не принимается); имя файла —
 * UUID с расширением по сигнатуре; один файл на коллекцию заменяется новым; подписи LT/EN.
 */
class AdminMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(PrototypeContentSeeder::class);
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        $this->actingAs($admin);
        app()->setLocale('en');
    }

    private function png(int $width = 40, int $height = 20): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function editor(string $type, string $key): Testable
    {
        $item = ContentItem::where('type', ContentType::from($type))->where('key', $key)->firstOrFail();

        return Livewire::test(ContentEditor::class, ['type' => $type, 'itemId' => $item->id]);
    }

    public function test_upload_limit_is_configuration_shared_with_livewire_temporary_uploads(): void
    {
        $this->assertSame(20480, config('aiqme.media_max_kb'));
        $this->assertSame(['required', 'file', 'max:20480'], config('livewire.temporary_file_upload.rules'));
        $this->editor('service', 'scanning')->assertSee('File (JPEG, PNG, WebP or MP4, up to 20 MB)');
    }

    public function test_png_named_html_is_stored_as_png_under_a_server_uuid_name_and_shown_on_the_service_page(): void
    {
        $editor = $this->editor('service', 'scanning')
            ->assertSee('Files &amp; photos', false)->assertSee('No files yet.')
            ->set('upload', UploadedFile::fake()->createWithContent('x.html', $this->png(1200, 600)))
            ->set('alt.lt', 'Skenuojama detalė')->set('alt.en', 'Part being scanned')
            ->call('uploadMedia')
            ->assertHasNoErrors()
            ->assertSee('File uploaded.');

        $scanning = ContentItem::where('key', 'scanning')->where('type', ContentType::Service)->first();
        $media = $scanning->getMedia('images');
        $this->assertCount(1, $media);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $media[0]->file_name);
        $this->assertSame(pathinfo($media[0]->file_name, PATHINFO_FILENAME), $media[0]->name);
        $this->assertSame('image/png', $media[0]->mime_type);
        $this->assertEquals(['lt' => 'Skenuojama detalė', 'en' => 'Part being scanned'], $media[0]->getCustomProperty('alt'));
        Storage::disk('public')->assertExists($media[0]->getPathRelativeToRoot());
        $editor->assertSee($media[0]->file_name);

        $html = $this->get('/en/services/3d-scanning')->assertOk()->getContent();
        $this->assertStringContainsString('alt="Part being scanned"', $html);
        $this->assertStringContainsString(pathinfo($media[0]->file_name, PATHINFO_FILENAME).'-w480.webp', $html);

        // Новая иллюстрация заменяет прежнюю (у услуги — одна).
        $editor->set('upload', UploadedFile::fake()->createWithContent('second.jpg', $this->png()))->call('uploadMedia')->assertHasNoErrors();
        $this->assertCount(1, $scanning->fresh()->getMedia('images'));
        $this->assertNotSame($media[0]->id, $scanning->fresh()->getFirstMedia('images')->id);

        $editor->call('removeMedia', $scanning->fresh()->getFirstMedia('images')->id)->assertSee('File removed.');
        $this->assertCount(0, $scanning->fresh()->getMedia('images'));
    }

    public function test_svg_html_and_wrong_collection_files_are_rejected_by_signature(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>';
        $mp4 = "\x00\x00\x00\x18ftypmp42".str_repeat("\x00", 64);
        $editor = $this->editor('service', 'scanning');
        foreach (['logo.png' => $svg, 'logo.svg' => $svg, 'photo.jpg' => '<html><body>hi</body></html>', 'clip.png' => $mp4, 'photo.mp4' => "\x00\x00\x00\x18ftypheic".str_repeat("\x00", 64), 'pic.mp4' => "\x00\x00\x00\x18ftypavif".str_repeat("\x00", 64)] as $name => $content) {
            $editor->set('upload', UploadedFile::fake()->createWithContent($name, $content))->call('uploadMedia')
                ->assertHasErrors(['upload'])->assertSee('Invalid file: JPEG, PNG, WebP or MP4 only, up to 20 MB.');
        }
        $this->assertSame(0, Media::count());

        // Главная: видео hero принимается в свою коллекцию; «images» у главной нет.
        $home = $this->editor('page_text', 'home')->assertSee('Mobile video');
        $home->set('collection', 'video')->set('upload', UploadedFile::fake()->createWithContent('hero.mov', $mp4))
            ->set('sourceText', 'AIQME')->set('sourceUrl', 'https://aiqme.com')->call('uploadMedia')->assertHasNoErrors();
        $video = ContentItem::where('key', 'home')->first()->getFirstMedia('video');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.mp4$/', $video->file_name);
        $this->assertEquals(['text' => 'AIQME', 'url' => 'https://aiqme.com'], $video->getCustomProperty('source'));
        $home->set('collection', 'images')->set('upload', UploadedFile::fake()->createWithContent('a.png', $this->png()))
            ->call('uploadMedia')->assertHasErrors(['collection']);
        $home->set('collection', 'video')->set('sourceText', 'X')->set('sourceUrl', 'javascript:alert(1)')
            ->set('upload', UploadedFile::fake()->createWithContent('b.mp4', $mp4))->call('uploadMedia')->assertHasErrors(['sourceUrl']);

        // У страницы без медиа (политика) раздела нет.
        $this->editor('page_text', 'privacy')->assertDontSee('Files &amp; photos', false);
    }

    public function test_size_limit_comes_from_configuration(): void
    {
        config(['aiqme.media_max_kb' => 1024]);
        $big = $this->png()."\x00".random_bytes(1024 * 1024);
        $this->editor('service', 'scanning')
            ->set('upload', UploadedFile::fake()->createWithContent('big.png', $big))->call('uploadMedia')
            ->assertHasErrors(['upload'])->assertSee('up to 1 MB');

        // Та же проверка вызывается вне Livewire (бот W-19 передаёт путь к скачанному файлу).
        $path = tempnam(sys_get_temp_dir(), 'aiqme');
        file_put_contents($path, $big);
        try {
            MediaFile::check($path, 'images');
            $this->fail('файл больше предела принят');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        } finally {
            unlink($path);
        }
    }

    public function test_media_file_check_uses_the_signature_not_the_name(): void
    {
        $dir = sys_get_temp_dir().'/aiqme-media-check-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/x.html', $this->png());
        file_put_contents($dir.'/y.png', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        file_put_contents($dir.'/empty.jpg', '');
        try {
            $this->assertSame('png', MediaFile::check($dir.'/x.html', 'images'));
            foreach (['y.png' => 'images', 'empty.jpg' => 'images', 'x.html' => 'video'] as $name => $collection) {
                try {
                    MediaFile::check($dir.'/'.$name, $collection);
                    $this->fail($name.' принят');
                } catch (ValidationException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            array_map('unlink', glob($dir.'/*'));
            rmdir($dir);
        }
    }
}
