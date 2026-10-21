<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderAccess;
use App\Domain\Orders\OrderSubmission;
use App\Models\StlFile;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Lunar\Models\Order;
use Tests\TestCase;

/**
 * Защищённая выдача STL и проверка прав на заказ (W-32, §6.1, §6.4, §9.2 «Права»): владелец — только вошедший клиент с
 * user_id заказа, администратор — всё; клиент B и посетитель — 404 на заказ клиента A и на гостевой заказ (user_id = null);
 * всё — для GET, HEAD и Range. Заголовки выдачи, экранирование имени и отключённый маршрут storage/{path}.
 */
class StlDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const METHODS = ['GET', 'HEAD', 'RANGE'];

    private User $clientA;

    private User $clientB;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('aiqme:bootstrap')->assertSuccessful();
        $this->seed(PrototypeContentSeeder::class);
        Storage::fake('local');
        $this->clientA = User::factory()->create();
        $this->clientB = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    /** Заказ через штатную отправку заявки W-12: куб 10 мм ASCII; гость — $user = null. */
    private function orderFile(?User $user, string $name = 'bracket.stl'): StlFile
    {
        $upload = UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/fixtures/stl/cube-10mm-ascii.stl')));
        $result = app(OrderSubmission::class)->submit([
            'submission_token' => (string) Str::uuid(),
            'locale' => 'en',
            'contact' => ['name' => 'Ona', 'email' => 'ona@example.test', 'phone' => '+370 600 00000', 'company' => null, 'company_code' => null, 'vat_code' => null],
            'delivery' => 'pickup',
            'address' => null,
            'material' => 'PLA',
            'infill' => 100,
            'preferences' => ['color' => 'black', 'quality' => 'standard', 'layer' => '0.2', 'finish' => 'none', 'term' => 'standard'],
            'parts' => [['file' => $upload, 'unit' => 'mm', 'scale' => 100, 'quantity' => 1]],
        ], $user);
        $this->assertSame($user?->id, $result->order->user_id);

        return StlFile::where('order_id', $result->order->id)->sole();
    }

    /** Запрос от имени посетителя (null) или вошедшего пользователя; RANGE — GET с Range: bytes=0-9. */
    private function fetch(string $method, int|string $id, ?User $user): TestResponse
    {
        Auth::forgetGuards();
        if ($user !== null) {
            $this->actingAs($user);
        }

        return match ($method) {
            'GET' => $this->get('/stl/'.$id),
            'HEAD' => $this->call('HEAD', '/stl/'.$id),
            'RANGE' => $this->get('/stl/'.$id, ['Range' => 'bytes=0-9']),
        };
    }

    private function body(TestResponse $response): string
    {
        ob_start();
        $response->baseResponse->sendContent();

        return (string) ob_get_clean();
    }

    private function assertDenied(StlFile $file, ?User $user): void
    {
        foreach (self::METHODS as $method) {
            $response = $this->fetch($method, $file->id, $user)->assertNotFound();
            $this->assertStringNotContainsString('attachment', (string) $response->headers->get('Content-Disposition'), $method);
            $this->assertStringNotContainsString('solid', $response->getContent() ?: '', $method);
        }
    }

    private function assertDelivered(StlFile $file, User $user): void
    {
        $bytes = Storage::disk('local')->get($file->path);

        $get = $this->fetch('GET', $file->id, $user)->assertOk();
        $this->assertSame($bytes, $this->body($get));
        $this->assertSame('application/octet-stream', $get->headers->get('Content-Type'));
        $this->assertSame('nosniff', $get->headers->get('X-Content-Type-Options'));
        $this->assertSame('no-store, private', $get->headers->get('Cache-Control'));
        $this->assertSame('attachment; filename='.$file->original_name, $get->headers->get('Content-Disposition'));

        $head = $this->fetch('HEAD', $file->id, $user)->assertOk();
        $this->assertSame((string) strlen($bytes), $head->headers->get('Content-Length'));
        $this->assertSame('', $this->body($head));
        $this->assertSame('no-store, private', $head->headers->get('Cache-Control'));

        $range = $this->fetch('RANGE', $file->id, $user)->assertStatus(206);
        $this->assertSame('bytes 0-9/'.strlen($bytes), $range->headers->get('Content-Range'));
        $this->assertSame(substr($bytes, 0, 10), $this->body($range));
        $this->assertSame('no-store, private', $range->headers->get('Cache-Control'));
    }

    public function test_client_order_stl_is_delivered_to_owner_and_admin_only(): void
    {
        $file = $this->orderFile($this->clientA);

        $this->assertDelivered($file, $this->clientA);
        $this->assertDelivered($file, $this->admin);
        $this->assertDenied($file, $this->clientB);
        $this->assertDenied($file, null);
    }

    public function test_guest_order_stl_has_no_owner_visitor_and_client_get_404(): void
    {
        $file = $this->orderFile(null);
        $this->assertNull($file->order->user_id);

        // null === null эксперимента дал бы доступ любому посетителю.
        $this->assertDenied($file, null);
        $this->assertDenied($file, $this->clientB);
        $this->assertDelivered($file, $this->admin);
    }

    public function test_order_access_service_owner_requires_signed_in_client_with_non_empty_user_id(): void
    {
        $access = new OrderAccess;
        $clientOrder = Order::findOrFail($this->orderFile($this->clientA)->order_id);
        $guestOrder = Order::findOrFail($this->orderFile(null)->order_id);

        $this->assertTrue($access->allows($clientOrder, $this->clientA));
        $this->assertFalse($access->allows($clientOrder, $this->clientB));
        $this->assertFalse($access->allows($clientOrder, null));
        $this->assertFalse($access->allows($guestOrder, null));
        $this->assertFalse($access->allows($guestOrder, $this->clientA));
        $this->assertTrue($access->allows($guestOrder, $this->admin));
    }

    public function test_unknown_file_and_file_without_order_are_404_even_for_admin(): void
    {
        $loose = StlFile::create(['user_id' => $this->clientA->id, 'path' => 'stl/'.Str::uuid().'.stl', 'original_name' => 'loose.stl',
            'size' => 3, 'sha256' => str_repeat('0', 64), 'triangles' => 0, 'analysis' => []]);
        Storage::disk('local')->put($loose->path, 'abc');

        foreach (self::METHODS as $method) {
            $this->fetch($method, 999999, $this->admin)->assertNotFound();
            $this->fetch($method, $loose->id, $this->admin)->assertNotFound();
            $this->fetch($method, $loose->id, $this->clientA)->assertNotFound();
        }
        $this->get('/stl/abc')->assertNotFound();
    }

    public function test_file_name_is_escaped_in_content_disposition(): void
    {
        $file = $this->orderFile($this->clientA, 'detalė "1";x.stl');
        $file->update(['original_name' => 'detalė "1";x\\y.stl']);

        $disposition = $this->fetch('GET', $file->id, $this->clientA)->assertOk()->headers->get('Content-Disposition');
        $this->assertSame('attachment; filename="detale \\"1\\";x_y.stl"; filename*=utf-8\'\'detal%C4%97%20%221%22%3Bx_y.stl', $disposition);
    }

    public function test_laravel_storage_route_is_disabled(): void
    {
        $file = $this->orderFile($this->clientA);
        $this->assertFalse(config('filesystems.disks.local.serve'));

        $this->actingAs($this->admin);
        $this->get('/storage/'.$file->path)->assertNotFound();
        $this->call('HEAD', '/storage/'.$file->path)->assertNotFound();
        $this->put('/storage/stl/new.stl', ['file' => 'x'])->assertNotFound();
        $this->assertSame([$file->path], Storage::disk('local')->allFiles());
    }
}
