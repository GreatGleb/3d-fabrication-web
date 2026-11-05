<?php

namespace Tests\Feature;

use App\Domain\Content\ContentType;
use App\Domain\Content\Models\ContentItem;
use App\Http\Controllers\Public\ProductController;
use App\Livewire\Admin\ContentEditor;
use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\browser\ContentFactory;
use Tests\Support\Html;
use Tests\TestCase;

/**
 * Правка 05.11, H-07 — товар «Pirkti» / «Gauti pasiūlymą»: режим продажи (по умолчанию «Gauti pasiūlymą», так остаются
 * прежние товары), цена с PVM (обязательна при «Pirkti»), «Komplektacija ir palaikymas» и PDF-документы в редакторе;
 * страница товара в обоих режимах LT/EN, цена в списке и сравнении, POST «Į krepšelį» только для товара «Pirkti».
 */
class ProductSaleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(PrototypeContentSeeder::class);
        $this->admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $this->admin->save();
    }

    private function catalog(): ContentFactory
    {
        $factory = ContentFactory::as($this->admin);
        $factory->catalog();

        return $factory;
    }

    private function editor(ContentItem $item, string $locale = 'lt'): Testable
    {
        $this->actingAs($this->admin);
        app()->setLocale($locale);

        return Livewire::test(ContentEditor::class, ['type' => 'product', 'itemId' => $item->id]);
    }

    private function product(string $key): ContentItem
    {
        return ContentItem::where('type', ContentType::Product)->where('key', $key)->firstOrFail();
    }

    private function pdf(int $bytes = 600): string
    {
        return str_pad((string) file_get_contents(base_path('tests/fixtures/product-document.pdf')), $bytes, ' ');
    }

    public function test_editor_has_sale_mode_quote_by_default_and_requires_a_price_to_buy(): void
    {
        $this->catalog();
        $printer = $this->product('vekta-printer');

        $editor = $this->editor($printer)
            ->assertSee('Pardavimo būdas')->assertSee('Kaina, EUR su PVM')->assertSee('Komplektacija ir palaikymas LT')
            ->assertSee('Dokumentai (PDF)')
            ->assertSet('meta.sale', 'quote')->assertSet('meta.price', '');
        $html = Html::xpath($editor->html());
        $this->assertSame(['Gauti pasiūlymą', 'Pirkti'], Html::texts($html, '//select[@name="meta.sale"]/option'));
        $this->assertSame('number', $html->query('//input[@name="meta.price"]')->item(0)->getAttribute('type'));

        // «Pirkti» без цены, с неверной ценой — ошибка, атрибуты не меняются.
        $editor->set('meta.sale', 'buy')->call('save')->assertHasErrors('meta.price')->assertSee('Užpildykite šį lauką.');
        $editor->set('meta.price', '12.345')->call('save')->assertHasErrors('meta.price');
        $editor->set('meta.price', '0')->call('save')->assertHasErrors('meta.price');
        $this->assertEquals(['category' => 'printers', 'brand' => $printer->meta('brand')], $printer->fresh()->meta);

        $editor->set('meta.price', '1234.5')->call('save')->assertHasNoErrors()->assertSee('Juodraštis išsaugotas.');
        $printer->refresh();
        $this->assertSame('buy', $printer->meta('sale'));
        $this->assertSame(123450, $printer->meta('price'));
        $this->assertTrue($printer->isForSale());
        $this->editor($printer, 'en')->assertSet('meta.sale', 'buy')->assertSet('meta.price', '1234.50')
            ->assertSee('Sales mode')->assertSee('Price, EUR incl. VAT');

        // Назад в «Gauti pasiūlymą»: режим по умолчанию не хранится, товар не продаётся (цена остаётся до нового включения).
        $this->editor($printer)->set('meta.sale', 'quote')->call('save')->assertHasNoErrors();
        $printer->refresh();
        $this->assertNull($printer->meta('sale'));
        $this->assertFalse($printer->isForSale());
        $this->assertNull($printer->salePrice());
    }

    public function test_existing_products_stay_quote_and_saving_text_does_not_add_a_sale_mode(): void
    {
        $this->catalog();
        $scanner = $this->product('norda-scanner');
        $before = $scanner->meta;

        $this->editor($scanner)->set('form.lt.fields.included', "Skeneris ir kalibravimo plokštė.\nMokymai vietoje.")
            ->call('save')->assertHasNoErrors();
        $scanner->refresh();
        $this->assertEquals($before, $scanner->meta);
        $this->assertFalse($scanner->isForSale());
        $this->assertSame("Skeneris ir kalibravimo plokštė.\nMokymai vietoje.",
            $scanner->translations()->where('locale', 'lt')->first()->latestRevision()->fields['included']);
        // Черновик не публикуется сам: на сайте — общая фраза; после публикации — текст товара.
        $this->assertSame(['Komplektacija, garantijos sąlygos, montavimas ir techninis palaikymas tikslinami konkrečiame pasiūlyme.'],
            Html::texts(Html::xpath($this->get('/lt/produktai/norda-s1-skeneris')->getContent()), '//div[@class="panel"][2]/p'));
        $this->editor($scanner)->call('publish', 'lt')->assertHasNoErrors();
        $this->assertSame(['Skeneris ir kalibravimo plokštė. Mokymai vietoje.'],
            Html::texts(Html::xpath($this->get('/lt/produktai/norda-s1-skeneris')->getContent()), '//div[@class="panel"][2]/p'));
    }

    public function test_documents_are_pdf_only_up_to_20_mb_and_keep_the_original_name(): void
    {
        $this->catalog();
        $printer = $this->product('vekta-printer');

        $editor = $this->editor($printer, 'en')->set('collection', 'documents')
            ->set('upload', UploadedFile::fake()->createWithContent('VEKTA P2 manual.pdf', $this->pdf()))
            ->call('uploadMedia')->assertHasNoErrors()->assertSee('VEKTA P2 manual.pdf');
        $this->assertStringContainsString('application/pdf', Html::xpath($editor->html())->query('//input[@name="upload"]')->item(0)->getAttribute('accept'));
        $editor->set('collection', 'documents')
            ->set('upload', UploadedFile::fake()->createWithContent('safety.pdf', $this->pdf()))
            ->call('uploadMedia')->assertHasNoErrors();
        $documents = $printer->fresh()->getMedia('documents');
        $this->assertSame(['VEKTA P2 manual.pdf', 'safety.pdf'], $documents->pluck('name')->all());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.pdf$/', $documents[0]->file_name);
        $this->assertSame('application/pdf', $documents[0]->mime_type);

        // PNG под именем .pdf, PDF в фото, файл больше 20 МБ — не принимаются.
        $png = UploadedFile::fake()->image('x.png')->getContent();
        $editor->set('collection', 'documents')->set('upload', UploadedFile::fake()->createWithContent('fake.pdf', $png))
            ->call('uploadMedia')->assertHasErrors('upload');
        $editor->set('collection', 'images')->set('upload', UploadedFile::fake()->createWithContent('photo.pdf', $this->pdf()))
            ->call('uploadMedia')->assertHasErrors('upload');
        $editor->set('collection', 'documents')->set('upload', UploadedFile::fake()->createWithContent('big.pdf', $this->pdf(20480 * 1024 + 1)))
            ->call('uploadMedia')->assertHasErrors('upload');
        $this->assertCount(2, $printer->fresh()->getMedia('documents'));
        $this->assertCount(0, $printer->fresh()->getMedia('images'));

        // На странице товара — ссылки по имени файла; удаление последнего документа убирает блок.
        $xpath = Html::xpath($this->get('/en/products/vekta-p2-printer')->assertOk()->getContent());
        $this->assertSame(['Included items & support', 'Documents'], Html::texts($xpath, '//div[@class="panel"][2]/h3'));
        $links = $xpath->query('//div[@class="panel"][2]//ul/li/a');
        $this->assertSame(['VEKTA P2 manual.pdf', 'safety.pdf'], Html::texts($xpath, '//div[@class="panel"][2]//ul/li/a'));
        $this->assertSame($documents[0]->getUrl(), $links->item(0)->getAttribute('href'));
        foreach ($documents as $document) {
            $editor->call('removeMedia', $document->id);
        }
        $xpath = Html::xpath($this->get('/lt/produktai/vekta-p2-spausdintuvas')->getContent());
        $this->assertSame(['Komplektacija ir palaikymas'], Html::texts($xpath, '//div[@class="panel"][2]/h3'));
        $this->assertStringNotContainsString('Informacija ruošiama', $xpath->document->saveHTML($xpath->query('//main')->item(0)));
    }

    public function test_buy_product_page_shows_price_quantity_and_add_to_cart_in_both_languages(): void
    {
        $this->catalog()->saleProduct();
        $item = $this->product('vekta-pla');

        $lt = Html::xpath($this->get('/lt/produktai/pla-gija')->assertOk()->getContent());
        $this->assertSame(['24,00 €'], Html::texts($lt, '//main//div[@class="price"]'));
        $form = $lt->query('//main//form[@method="post"]')->item(0);
        $this->assertSame(route('lt.cart.add'), $form->getAttribute('action'));
        $this->assertSame('pla-gija', $lt->query('.//input[@name="product"]', $form)->item(0)->getAttribute('value'));
        $quantity = $lt->query('.//input[@name="quantity"]', $form)->item(0);
        $this->assertSame(['number', '1', '1', (string) ProductController::QUANTITY_MAX],
            [$quantity->getAttribute('type'), $quantity->getAttribute('value'), $quantity->getAttribute('min'), $quantity->getAttribute('max')]);
        $this->assertSame('Kiekis', trim($quantity->parentNode->firstChild->textContent));
        $this->assertSame(['Į krepšelį'], Html::texts($lt, '//main//form//button[@type="submit"]'));
        $main = Html::text($lt->query('//main')->item(0));
        $this->assertStringNotContainsString('Individualus pasiūlymas', $main);
        $this->assertStringNotContainsString('Gauti pasiūlymą', $main);
        $this->assertStringNotContainsString('Užklausoje nurodykite', $main);
        $this->assertSame(['Ritė 1 kg.'], Html::texts($lt, '//div[@class="panel"][2]/p'));
        $this->assertSame(['pla-gija-duomenys.pdf'], Html::texts($lt, '//div[@class="panel"][2]//ul/li/a'));

        $en = Html::xpath($this->get('/en/products/pla-filament')->assertOk()->getContent());
        $this->assertSame(['€24.00'], Html::texts($en, '//main//div[@class="price"]'));
        $this->assertSame(['Add to cart'], Html::texts($en, '//main//form//button[@type="submit"]'));
        $this->assertSame(route('en.cart.add'), $en->query('//main//form[@method="post"]')->item(0)->getAttribute('action'));
        $this->assertStringNotContainsString('Tailored quotation', Html::text($en->query('//main')->item(0)));
        $this->assertSame(['One 1 kg spool.'], Html::texts($en, '//div[@class="panel"][2]/p'));

        // Цена задана, но режим «Gauti pasiūlymą» — страница как прежде, без формы корзины.
        $item->forceFill(['meta' => array_diff_key($item->meta, ['sale' => true])])->save();
        $quote = Html::xpath($this->get('/en/products/pla-filament')->getContent());
        $this->assertSame(['Tailored quotation'], Html::texts($quote, '//main//div[@class="price"]'));
        $this->assertCount(0, $quote->query('//main//form[@method="post"] | //input[@name="quantity"]'));
        $this->assertCount(1, $quote->query('//main//a[normalize-space()="Request a quote"]'));
    }

    public function test_catalog_and_compare_show_the_price_of_buy_products_only(): void
    {
        $this->catalog()->saleProduct();

        $cards = fn (string $url): array => array_map(fn ($card): array => [
            Html::text($card->getElementsByTagName('h3')->item(0)),
            Html::texts(Html::xpath('<div>'.$card->ownerDocument->saveHTML($card).'</div>'), '//div[@class="price"] | //a[contains(@class, "btn")]'),
        ], iterator_to_array(Html::xpath($this->get($url)->assertOk()->getContent())->query('//div[@id="product-results"]/div')));

        $lt = collect($cards('/lt/produktai?category=materials'))->pluck(1, 0)->all();
        $this->assertSame(['24,00 €', 'Pirkti'], $lt['PLA gija']);
        $this->assertSame(['Pagal užklausą', 'Gauti pasiūlymą'], $lt['PETG gija']);
        $en = collect($cards('/en/products?category=materials'))->pluck(1, 0)->all();
        $this->assertSame(['€24.00', 'Buy'], $en['PLA filament']);
        $this->assertSame(['On request', 'Request a quote'], $en['PETG filament']);

        $compare = Html::xpath($this->get('/en/compare?products=pla-filament,petg-filament')->assertOk()->getContent());
        $this->assertSame(['Price', '€24.00', 'On request'], Html::texts($compare, '//tr[td[1][normalize-space()="Price"]]/td'));
    }

    public function test_add_to_cart_accepts_only_published_buy_products(): void
    {
        $this->catalog()->saleProduct();
        $pla = $this->product('vekta-pla');

        $this->post('/lt/krepselis', ['product' => 'pla-gija', 'quantity' => 2])->assertRedirect(route('lt.cart'));
        $this->post('/lt/krepselis', ['product' => 'pla-gija', 'quantity' => 3])->assertRedirect(route('lt.cart'));
        $this->assertSame([$pla->id => 5], session(ProductController::CART_SESSION));
        $this->post('/en/cart', ['product' => 'pla-filament', 'quantity' => 100])->assertRedirect(route('en.cart'));
        $this->assertSame([$pla->id => ProductController::QUANTITY_MAX], session(ProductController::CART_SESSION));

        // Товар «Gauti pasiūlymą», slug другой локали, неизвестный — 404; количество вне 1–100 — ошибка; корзина не меняется.
        $this->post('/en/cart', ['product' => 'petg-filament', 'quantity' => 1])->assertNotFound();
        $this->post('/en/cart', ['product' => 'pla-gija', 'quantity' => 1])->assertNotFound();
        $this->post('/en/cart', ['product' => 'nothing', 'quantity' => 1])->assertNotFound();
        $this->post('/en/cart', ['product' => 'pla-filament', 'quantity' => 0])->assertSessionHasErrors('quantity');
        $this->post('/en/cart', ['product' => 'pla-filament', 'quantity' => 101])->assertSessionHasErrors('quantity');
        $this->assertSame([$pla->id => ProductController::QUANTITY_MAX], session(ProductController::CART_SESSION));

        // Переведённый в «Gauti pasiūlymą» товар больше не добавляется.
        $pla->forceFill(['meta' => array_diff_key($pla->meta, ['sale' => true])])->save();
        $this->post('/lt/krepselis', ['product' => 'pla-gija', 'quantity' => 1])->assertNotFound();
    }
}
