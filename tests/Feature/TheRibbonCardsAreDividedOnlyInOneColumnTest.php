<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * خطٌّ فاصلٌ بين بطاقات RIBBON حين تتتابع عمودًا واحدًا — على الهاتف.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الشرطُ عرضُ الشبكة نفسِها محسوبًا من أعمدتها: يتبعها إن تغيّرت، فلا
 *     يظهر الخطُّ بين عمودين ولا يغيب عن عمودٍ واحد.
 *   - خطٌّ ١px بلون `--rb-line`، طولُه ٨٨٪ في الوسط، في منتصف المسافة بين
 *     السعر والصورة التالية، ولا بعد الأخيرة، ولا يُلمس.
 *   - ابنُ الشبكة البطاقةُ أو غلافُها مع زرّ واتساب — والقاعدةُ تصيبه في الحالين.
 *   - وهو في قالب RIBBON وحده.
 *
 * والشكلُ نفسُه رُئي في WebKit بعرض iPhone وفي Chromium بعرض الحاسوب — هنا ما
 * يُقرأ من الكود.
 */
class TheRibbonCardsAreDividedOnlyInOneColumnTest extends TestCase
{
    use RefreshDatabase;

    private const LAYOUT = 'views/store/ribbon/layout.blade.php';

    private function css(): string
    {
        return file_get_contents(resource_path(self::LAYOUT));
    }

    /** ما داخل `@container rb-grid (…) { … }` — والشرطُ نفسُه */
    private function oneColumn(): array
    {
        $this->assertSame(1, preg_match('/@container rb-grid \(max-width: ([\d.]+)px\) \{(.*?)\n        \}/s', $this->css(), $m), 'قاعدةٌ واحدةٌ مشروطةٌ بعرض الشبكة');

        return [(float) $m[1], $m[2]];
    }

    public function test_the_line_appears_exactly_when_the_grid_holds_one_column(): void
    {
        $css = $this->css();

        $this->assertSame(1, preg_match('/\.rb-grid \{ display: grid; grid-template-columns: repeat\(auto-fill, minmax\(min\(100%, (\d+)px\), 1fr\)\); gap: (\d+)px (\d+)px; \}/', $css, $grid));
        [, $min, , $colGap] = array_map('intval', $grid);

        // عمودان يتّسعان من ضعف أصغر عمودٍ وبينهما الفجوة — فما دونها عمودٌ واحد
        [$max] = $this->oneColumn();
        $two = 2 * $min + $colGap;
        $this->assertLessThan($two, $max, 'لا خطَّ بين عمودين');
        $this->assertGreaterThan($two - 1, $max, 'ولا يغيب عن عمودٍ واحد');

        // والشرطُ على الشبكة نفسِها — لا على الشاشة وحواشي الصفحة
        $this->assertStringContainsString('.rb-grid { container: rb-grid / inline-size; }', $css);
    }

    public function test_a_thin_centred_line_sits_midway_and_never_after_the_last_card(): void
    {
        [, $rules] = $this->oneColumn();

        $this->assertSame(1, preg_match('/\.rb-grid > :not\(:last-child\) \{([^}]*)\}/', $rules, $card), 'كلُّ بطاقةٍ إلّا الأخيرة');
        $this->assertSame(1, preg_match('/\.rb-grid > :not\(:last-child\)::after \{([^}]*)\}/', $rules, $line));
        $this->assertStringNotContainsString(':last-child)', str_replace(':not(:last-child)', '', $rules), 'ولا شيءَ يُرسم بعد الأخيرة');

        $this->assertStringContainsString('height: 1px;', $line[1]);
        $this->assertStringContainsString('background: var(--rb-line);', $line[1]);
        $this->assertStringContainsString('inset-inline: 6%;', $line[1], '٨٨٪ في الوسط');
        $this->assertStringContainsString('pointer-events: none;', $line[1], 'لا يُلمس فلا يفتح المنتج');

        // في منتصف المسافة: فجوةُ الصفوف وما زيد تحت البطاقة
        $this->assertSame(1, preg_match('/\.rb-grid \{ display: grid;[^}]*gap: (\d+)px \d+px; \}/', $this->css(), $gap));
        $this->assertSame(1, preg_match('/margin-bottom: (\d+)px;/', $card[1], $extra));
        $this->assertSame(1, preg_match('/bottom: -(\d+)px;/', $line[1], $at));
        $this->assertSame(((int) $gap[1] + (int) $extra[1]) / 2, (int) $at[1]);
    }

    public function test_the_rule_reaches_the_card_with_or_without_its_whatsapp_button(): void
    {
        foreach ((array) config('storefront') as $key => $value) {
            if (str_starts_with($key, 'ribbon_') && is_array($value)) {
                config(["storefront.{$key}" => []]);
            }
        }
        $shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'city' => 'مسقط',
            'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($shop->id, 'website', ['store_on' => '1', 'store_whatsapp' => '+968 9111 2233']);
        $roses = Category::create(['business_id' => $shop->id, 'name' => 'الورد', 'name_en' => 'Flowers']);
        foreach (['باقة التوليب', 'باقة الجوري', 'صندوق الورد'] as $name) {
            Product::create([
                'business_id' => $shop->id, 'category_id' => $roses->id, 'name' => $name,
                'price' => 20, 'cost' => 8, 'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
            ]);
        }

        foreach (['rb-card-wrap' => [$shop->id], 'rb-card' => []] as $child => $asks) {
            config(['storefront.ribbon_floating_whatsapp_businesses' => $asks]);
            $dom = new DOMDocument;
            @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->get('/s/ribbon/shop?lang=ar')->assertOk()->getContent());
            $kids = (new DOMXPath($dom))->query('//div[@class="rb-grid"]/*');

            $this->assertSame(3, $kids->length);
            foreach ($kids as $kid) {
                /** @var DOMElement $kid */
                $this->assertSame($child, $kid->getAttribute('class'), 'ابنُ الشبكة الذي تصيبه القاعدة');
                $this->assertStringContainsString('data-testid="rb-product"', $dom->saveHTML($kid));
            }
        }
    }

    public function test_only_the_ribbon_template_draws_it(): void
    {
        $owners = collect(File::allFiles(resource_path()))
            ->filter(fn ($f) => str_contains($f->getContents(), '@container rb-grid'))
            ->map(fn ($f) => str_replace(resource_path().'/', '', $f->getPathname()))
            ->values()->all();

        $this->assertSame([self::LAYOUT], $owners);
    }
}
