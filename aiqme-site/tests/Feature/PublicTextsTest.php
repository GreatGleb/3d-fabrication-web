<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PrototypeContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Tests\browser\ContentFactory;
use Tests\Support\Html;
use Tests\TestCase;

/** Тексты страниц W-05/W-06/W-25: таблица 1 D-21 на отрендеренных страницах и отсутствие LT-строк ui_text на EN-страницах (§7.2). */
class PublicTextsTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = [
        'lt' => ['/lt', '/lt/paslaugos', '/lt/apie-mus', '/lt/kontaktai', '/lt/duk', '/lt/paslaugos/3d-skenavimas', '/lt/paslaugos/3d-modeliavimas',
            '/lt/paslaugos/3d-spausdinimas', '/lt/paslaugos/frezavimas', '/lt/paslaugos/graviravimas', '/lt/paslaugos/3d-modeliavimo-mokymai',
            '/lt/privatumas', '/lt/slapukai', '/lt/pristatymas', '/lt/grazinimas',
            '/lt/projektai', '/lt/projektai/laikiklio-atkurimas', '/lt/informacija', '/lt/biblioteka', '/lt/naujienos',
            '/lt/biblioteka/file-guide', '/lt/mokymai', '/lt/paieska', '/lt/paieska?q=3D', '/lt/paieska?q=zzzz',
            '/lt/produktai', '/lt/produktai/norda-s1-skeneris', '/lt/palyginimas?products=norda-s1-skeneris,petg-gija', '/lt/palyginimas', '/lt/krepselis'],
        'en' => ['/en', '/en/services', '/en/about', '/en/contact', '/en/faq', '/en/services/3d-scanning', '/en/services/3d-modelling',
            '/en/services/3d-printing', '/en/services/cnc-machining', '/en/services/engraving', '/en/services/3d-modelling-training',
            '/en/privacy', '/en/cookies', '/en/delivery', '/en/returns',
            '/en/projects', '/en/projects/bracket-reconstruction', '/en/information', '/en/library', '/en/news',
            '/en/library/file-guide', '/en/training', '/en/search', '/en/search?q=3D', '/en/search?q=zzzz',
            '/en/products', '/en/products/norda-s1-scanner', '/en/compare?products=norda-s1-scanner,petg-filament', '/en/compare', '/en/cart'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PrototypeContentSeeder::class);
        $admin = User::factory()->create()->forceFill(['is_admin' => true]);
        $admin->save();
        // С лентами, проектом, обучением (W-06) и товарами (W-25): их тексты и подписи тоже проверяются.
        ContentFactory::as($admin)->homeTickers();
        ContentFactory::as($admin)->publicSections();
        ContentFactory::as($admin)->catalog();
    }

    /** Видимый текст страницы и значения атрибутов, которые читает пользователь или экранный диктор. */
    private function visible(string $path): string
    {
        $xpath = Html::xpath($this->get($path)->assertOk()->getContent());
        $parts = [Html::text($xpath->query('//body')->item(0))];
        foreach ($xpath->query('//@aria-label | //@alt | //@title | //@placeholder') as $attribute) {
            $parts[] = $attribute->nodeValue;
        }

        return implode("\n", $parts);
    }

    public function test_pages_contain_no_d21_source_phrase_and_show_the_replacements(): void
    {
        $all = '';
        foreach (Arr::flatten(self::PAGES) as $path) {
            $text = $this->visible($path);
            $all .= $text;
            foreach (PrototypeContentSeederTest::D21_TABLE_1 as [$sources]) {
                foreach ($sources as $source) {
                    $this->assertStringNotContainsString($source, $text, $path.': исходная фраза D-21');
                }
            }
            // Таблица 2 D-21 (заметки автора в шаблонах W-05/W-06).
            foreach (['Planuojami prekių ženklai', 'Planned technology brands', 'DEMONSTRACINIS TVARKARAŠTIS', 'ILLUSTRATIVE SCHEDULE',
                'Visi šiame puslapyje rodomi projektai', 'demonstraciniai inžinerinių', 'Demonstracinis pavyzdys', 'nepatvirtintas atliktas projektas',
                'Techniniai gamintojų dokumentai ir jų vertimai', 'Manufacturer technical documents and translations',
                'Gamintojų instrukcijos ir techninių duomenų lapai', 'Manufacturer manuals and technical data sheets',
                'Registruotis į demonstracinį mokymą', 'Register for a demo session', 'DEMONSTRACINĖ ILIUSTRACIJA', 'ILLUSTRATIVE IMAGE',
                'DEMONSTRACINIS PAVYZDYS', 'ILLUSTRATIVE EXAMPLE'] as $source) {
                $this->assertStringNotContainsString($source, $text, $path);
            }
        }
        foreach (PrototypeContentSeederTest::D21_TABLE_1 as [, $replacements, $kept]) {
            foreach ([...$replacements, ...$kept] as $phrase) {
                $this->assertStringContainsString($phrase, $all, 'фраза в новом виде выводится');
            }
        }
        $this->assertStringContainsString('Prekių ženklai', $this->visible('/lt'));
        $this->assertStringContainsString('Technology brands', $this->visible('/en'));
    }

    /**
     * Автотест по словарю (§7.2): на EN-страницах нет ни одного элемента, чей целый видимый текст — LT-строка ключа
     * ui_text (lang/lt), отличающаяся от EN; одинаковые («AIQME», «FDM») не проверяются.
     */
    public function test_english_pages_have_no_lithuanian_ui_text_strings(): void
    {
        $lithuanian = [];
        foreach (glob(lang_path('lt/*.php')) as $file) {
            $group = basename($file, '.php');
            foreach (Arr::dot(Lang::get($group, [], 'lt')) as $key => $value) {
                $english = Lang::get($group.'.'.$key, [], 'en');
                if (is_string($value) && $value !== $english && trim($value) !== '') {
                    $lithuanian[trim(preg_replace('/\s+/u', ' ', $value))] = $group.'.'.$key;
                }
            }
        }
        $this->assertArrayHasKey('Rasti sprendimą ↗', $lithuanian, 'словарь собран из lang/lt');

        foreach (self::PAGES['en'] as $path) {
            $xpath = Html::xpath($this->get($path)->getContent());
            foreach ($xpath->query('//body//*') as $element) {
                $text = Html::text($element);
                $this->assertArrayNotHasKey($text, $lithuanian, $path.': LT-строка '.($lithuanian[$text] ?? '').' на EN-странице');
            }
            foreach ($xpath->query('//@aria-label | //@alt | //@title | //@placeholder') as $attribute) {
                $this->assertArrayNotHasKey(trim($attribute->nodeValue), $lithuanian, $path.': LT-строка в атрибуте');
            }
        }
    }
}
