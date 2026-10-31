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
 * خطٌّ فاصلٌ رفيعٌ تحت بطاقات RIBBON على كلّ شاشة — إلّا الصفَّ الأخير.
 *
 * ═══ ما يُحرس ═══
 *
 *   - خطٌّ ١px بلون `--rb-line`، طولُه ٨٨٪ في الوسط، في منتصف المسافة بين
 *     السعر والصفّ التالي، ولا يُلمس. أفقيٌّ وحده.
 *   - والصفُّ الأخير بلا خطّ أيًّا كان عددُ الأعمدة (١ إلى ٤) وعددُ البطاقات —
 *     تامًّا أو ناقصًا. وعددُ الأعمدة من عرض الشبكة محسوبًا من أعمدتها: يتبعها
 *     إن تغيّرت، وأعرضُ شبكةٍ في القالب لا تبلغ خمسة.
 *   - ابنُ الشبكة البطاقةُ أو غلافُها مع زرّ واتساب — والقاعدةُ تصيبه في الحالين.
 *   - وهو في قالب RIBBON وحده.
 *
 * والشكلُ نفسُه رُئي في WebKit (iPhone وiPad) وChromium (حاسوب) بعمودٍ إلى
 * أربعة وبطاقةٍ إلى تسع — هنا ما يُقرأ من الكود.
 */
class TheRibbonCardsAreDividedRowByRowTest extends TestCase
{
    use RefreshDatabase;

    private const LAYOUT = 'views/store/ribbon/layout.blade.php';

    private function css(): string
    {
        return file_get_contents(resource_path(self::LAYOUT));
    }

    /** أصغرُ عمودٍ وفجوتا الصفوف والأعمدة — من تعريف الشبكة نفسِه */
    private function grid(): array
    {
        $this->assertSame(1, preg_match('/\.rb-grid \{ display: grid; grid-template-columns: repeat\(auto-fill, minmax\(min\(100%, (\d+)px\), 1fr\)\); gap: (\d+)px (\d+)px; \}/', $this->css(), $m));

        return ['min' => (int) $m[1], 'row' => (int) $m[2], 'col' => (int) $m[3]];
    }

    /** ما تحت `@supports` — الخطُّ نفسُه */
    private function line(): array
    {
        $this->assertSame(1, preg_match('/@supports \(container-type: inline-size\) \{(.*?)\n        \}/s', $this->css(), $m));
        $this->assertSame(1, preg_match('/\.rb-grid > \*::after \{([^}]*)\}/', $m[1], $line));

        return [$m[1], $line[1]];
    }

    /** نطاقاتُ عدد الأعمدة: [من, إلى|null, ما يُطفأ فيها] بترتيبها */
    private function bands(): array
    {
        preg_match_all('/@container rb-grid (?:\(min-width: ([\d.]+)px\))?(?: and )?(?:\(max-width: ([\d.]+)px\))? \{ (.*?) \}\n/', $this->css(), $m, PREG_SET_ORDER);

        return array_map(fn ($b) => [$b[1] === '' ? 0.0 : (float) $b[1], $b[2] === '' ? null : (float) $b[2], $b[3]], $m);
    }

    public function test_a_thin_centred_line_sits_midway_under_every_card(): void
    {
        [$rules, $line] = $this->line();

        $this->assertStringContainsString('height: 1px;', $line);
        $this->assertStringContainsString('background: var(--rb-line);', $line);
        $this->assertStringContainsString('inset-inline: 6%;', $line, '٨٨٪ في الوسط');
        $this->assertStringContainsString('pointer-events: none;', $line, 'لا يُلمس فلا يفتح المنتج');
        $this->assertStringNotContainsString('width: 1px', $rules, 'ولا خطَّ عموديًّا بين الأعمدة');

        // في منتصف المسافة بين الصفوف — والمسافةُ أوسعُ حيث يُرسم الخطّ
        $this->assertSame(1, preg_match('/\.rb-grid \{ row-gap: (\d+)px; \}/', $rules, $gap));
        $this->assertSame(1, preg_match('/bottom: -(\d+)px;/', $line, $at));
        $this->assertSame((int) $gap[1] / 2, (int) $at[1]);
        $this->assertGreaterThan($this->grid()['row'], (int) $gap[1]);

        // والشرطُ على الشبكة نفسِها — لا على الشاشة وحواشي الصفحة
        $this->assertStringContainsString('.rb-grid { container: rb-grid / inline-size; }', $this->css());
    }

    public function test_the_column_count_is_read_from_the_grids_own_width(): void
    {
        ['min' => $min, 'col' => $col] = $this->grid();
        $bands = $this->bands();
        $this->assertCount(4, $bands, 'عمودٌ إلى أربعة');

        // ن عمودًا تتّسع من ن×أصغرِ عمود + (ن−١)×الفجوة — وتبقى حتّى يتّسع ن+١
        foreach ($bands as $i => [$from, $to]) {
            $n = $i + 1;
            $this->assertSame($n === 1 ? 0.0 : (float) ($n * $min + ($n - 1) * $col), $from, "{$n} أعمدة من");
            if ($n < 4) {
                $next = ($n + 1) * $min + $n * $col;
                $this->assertLessThan($next, $to, "{$n} أعمدة إلى");
                $this->assertGreaterThan($next - 1, $to);
            } else {
                $this->assertNull($to, 'الأخيرُ مفتوح');
            }
        }

        // وأعرضُ شبكةٍ في القالب أضيقُ من خمسة أعمدة — فالنطاقُ المفتوح أربعةٌ حقًّا
        $this->assertSame(1, preg_match('/\.rb-section \{ max-width: (\d+)px; margin: 0 auto; padding: [^;]* (\d+)px 0;/', $this->css(), $section));
        $this->assertSame(1, preg_match('/\.rb-wrap \{ max-width: (\d+)px; margin: 0 auto; padding: 0 (\d+)px;/', $this->css(), $wrap));
        $widest = max($section[1] - 2 * $section[2], $wrap[1] - 2 * $wrap[2]);
        $this->assertLessThan(5 * $min + 4 * $col, $widest);
    }

    public function test_the_last_row_has_no_line_whatever_the_columns_and_cards(): void
    {
        foreach ($this->bands() as $i => [, , $rules]) {
            $n = $i + 1;
            for ($count = 1; $count <= 13; $count++) {
                $lastRow = range(intdiv($count - 1, $n) * $n + 1, $count);
                $this->assertSame($lastRow, $this->hidden($rules, $n, $count), "{$n} أعمدة و{$count} بطاقة");
            }
        }
    }

    /**
     * ما تُطفئه قواعدُ النطاق من البطاقات ١…العدد — بمعنى المحدِّدات نفسِها:
     * `:last-child`، أو أوّلُ صفٍّ لم يبقَ بعده ن (`:nth-child(نn+1):nth-last-child(-n+ن)`) وما يليه (`~ *`).
     */
    private function hidden(string $rules, int $n, int $count): array
    {
        if ($n === 1) {
            $this->assertSame('.rb-grid > :last-child::after { content: none; }', $rules);

            return [$count];
        }
        $first = ".rb-grid > :nth-child({$n}n+1):nth-last-child(-n+{$n})";
        $this->assertSame("{$first}::after, {$first} ~ *::after { content: none; }", $rules);

        $hidden = [];
        for ($at = 1; $at <= $count; $at++) {
            $starts = ($at - 1) % $n === 0 && $count - $at + 1 <= $n;
            if ($starts || ($hidden !== [] && $at > $hidden[0])) {
                $hidden[] = $at;
            }
        }

        return $hidden;
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
