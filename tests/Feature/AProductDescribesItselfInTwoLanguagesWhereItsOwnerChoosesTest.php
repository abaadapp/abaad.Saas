<?php

namespace Tests\Feature;

use App\Models\BranchStock;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Support\Demo;
use App\Support\ProductDescription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * وصفُ الصنف بلغتين، ويُعرض حيث يختار صاحبُه — مستقلًّا عن أين يُباع.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الإنشاءُ يحفظ الاسمين والوصفين، ووصفُ الموقع مفتوحٌ ووصفُ الصندوق مغلقٌ
 *     افتراضًا — كما كان كلُّ صنف.
 *   - شاشةُ التعديل تُفتح بما حُفظ. كانت تُفتح بوصفٍ فارغٍ دائمًا، فتمحو كلُّ
 *     حفظةٍ وصفَ الصنف.
 *   - تعديلُ لغةٍ لا يمسّ الأخرى، ولا القنوات ولا الكمّيّة ولا حركات المخزون.
 *   - قنواتُ البيع ومفتاحا الوصف لا يحرّك أحدُهما الآخر.
 *   - صفحةُ الصنف في RIBBON بلغتها، والإنجليزيّةُ تقع على العربيّ، والمطفأُ لا
 *     يُرسم، والنصُّ مهرَّب. والمتجرُ المبنيّ يطيع المفتاح كذلك.
 *   - الصندوقُ يصله نصُّ الكاشير بلغة شاشته حين يُفتح له وحده، والصنفُ يبقى
 *     فيه حين يُخفى وصفُه.
 *   - النسخُ ينقل الوصفين والمفتاحين.
 *
 * والنموذجُ في الشاشة: `a-product-describes-itself-where-its-owner-chooses.test.tsx`.
 */
class AProductDescribesItselfInTwoLanguagesWhereItsOwnerChoosesTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        $this->openRibbon();
        $this->rose->update([
            'name' => 'باقة ورد فاخرة', 'name_en' => 'Luxury Rose Bouquet',
            'description' => 'ورد أحمر طبيعيّ بلفّة حرير', 'description_en' => 'Fresh red roses in silk wrap',
        ]);
    }

    /** ما يرسله نموذجُ التعديل — كاملًا كما ترسله الشاشة */
    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'باقة ورد فاخرة', 'name_en' => 'Luxury Rose Bouquet',
            'description' => 'ورد أحمر طبيعيّ بلفّة حرير', 'description_en' => 'Fresh red roses in silk wrap',
            'price' => 20, 'cost' => 8, 'quantity' => 50, 'alert_qty' => 1,
            'active' => true, 'published' => true, 'pos_enabled' => true,
            'description_pos_enabled' => false, 'description_web_enabled' => true,
        ];
    }

    private function save(array $over = [])
    {
        return $this->actingAs($this->owner)->put(route('admin.products.update', $this->rose->id), $this->form($over));
    }

    private function page(string $lang): string
    {
        return $this->get('/s/ribbon/p/'.$this->rose->id.'?lang='.$lang)->assertOk()->getContent();
    }

    /** ما يصل الصندوقَ عن الصنف */
    private function atTheTill(): ?array
    {
        $this->actingAs($this->owner);

        return collect(Demo::products())->firstWhere('id', $this->rose->id);
    }

    /* ═══════════ الإنشاءُ والتعديل ═══════════ */

    public function test_a_new_product_keeps_both_names_and_descriptions_and_shows_its_description_online_only(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), [
            'name' => 'عطر', 'name_en' => 'Perfume', 'price' => 25,
            'description' => 'عطر شرقيّ', 'description_en' => 'An oriental perfume',
        ])->assertRedirect();

        $p = Product::where('business_id', $this->shop->id)->where('name', 'عطر')->firstOrFail();
        $this->assertSame('Perfume', $p->name_en);
        $this->assertSame('عطر شرقيّ', $p->description);
        $this->assertSame('An oriental perfume', $p->description_en);
        $this->assertTrue($p->description_web_enabled, 'وصفُ الموقع مفتوحٌ افتراضًا — كما كان كلُّ وصف');
        $this->assertFalse($p->description_pos_enabled, 'ووصفُ الصندوق مغلقٌ افتراضًا — لم يظهر فيه وصفٌ قطّ');
        $this->assertTrue($p->pos_enabled);
        $this->assertTrue($p->published);

        // وصنفٌ قديمٌ بلا المفتاحين يقرأ ما يقرؤه الجديد
        $old = Product::create(['business_id' => $this->shop->id, 'name' => 'قديم', 'price' => 1, 'cost' => 0, 'quantity' => 0, 'alert_qty' => 0, 'description' => 'وصفٌ قديم'])->fresh();
        $this->assertTrue($old->description_web_enabled);
        $this->assertFalse($old->description_pos_enabled);
        $this->assertNull($old->description_en);
        $this->assertSame('وصفٌ قديم', $old->description);
    }

    public function test_one_description_alone_is_kept_in_either_language(): void
    {
        $this->actingAs($this->owner)->post(route('admin.products.store'), ['name' => 'عربيّ فقط', 'price' => 5, 'description' => 'بالعربيّة'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('admin.products.store'), ['name' => 'إنجليزيّ فقط', 'price' => 5, 'description_en' => 'English only'])->assertRedirect();

        $ar = Product::where('name', 'عربيّ فقط')->firstOrFail();
        $en = Product::where('name', 'إنجليزيّ فقط')->firstOrFail();
        $this->assertSame(['بالعربيّة', null], [$ar->description, $ar->description_en]);
        $this->assertSame([null, 'English only'], [$en->description, $en->description_en]);
    }

    public function test_the_edit_screen_opens_with_what_was_saved(): void
    {
        $this->rose->update(['description_pos_enabled' => true, 'description_web_enabled' => false]);

        $this->actingAs($this->owner)->get(route('admin.products.edit', $this->rose->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('description', 'ورد أحمر طبيعيّ بلفّة حرير')
                ->where('descriptionEn', 'Fresh red roses in silk wrap')
                ->where('product.description_pos_enabled', true)
                ->where('product.description_web_enabled', false)
                ->where('product.pos_enabled', true)
                ->where('product.published', true));

        // وصفحةُ الصنف تعرض وصفَه لا نصًّا ثابتًا لم يكتبه أحد
        $this->actingAs($this->owner)->get(route('admin.products.show', $this->rose->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('description', 'ورد أحمر طبيعيّ بلفّة حرير')
                ->where('descriptionEn', 'Fresh red roses in silk wrap'));
    }

    public function test_editing_one_language_leaves_the_other_and_the_stock_alone(): void
    {
        // صنفٌ على رفّ فرعه كما في المتجر — فلا يُقرأ التوزيعُ الأوّل تحريكًا
        BranchStock::ensureAllocated($this->shop->id, $this->rose->id, 50);
        $moves = InventoryMovement::count();
        $shelf = (int) BranchStock::where('product_id', $this->rose->id)->sum('quantity');

        $this->save(['description' => 'ورد أحمر بلفّة ذهبيّة', 'description_en' => 'Fresh red roses in silk wrap'])->assertRedirect();
        $this->assertSame('ورد أحمر بلفّة ذهبيّة', $this->rose->fresh()->description);
        $this->assertSame('Fresh red roses in silk wrap', $this->rose->fresh()->description_en, 'تعديلُ العربيّ غيّر الإنجليزيّ');

        $this->save(['description' => 'ورد أحمر بلفّة ذهبيّة', 'description_en' => 'Red roses in gold wrap'])->assertRedirect();
        $this->assertSame('ورد أحمر بلفّة ذهبيّة', $this->rose->fresh()->description, 'تعديلُ الإنجليزيّ غيّر العربيّ');
        $this->assertSame('Red roses in gold wrap', $this->rose->fresh()->description_en);

        $fresh = $this->rose->fresh();
        $this->assertSame([50, true, true, true, false, true], [
            (int) $fresh->quantity, $fresh->active, $fresh->pos_enabled, $fresh->published,
            $fresh->description_pos_enabled, $fresh->description_web_enabled,
        ]);
        $this->assertSame($moves, InventoryMovement::count(), 'تعديلُ الوصف حرّك المخزون');
        $this->assertSame($shelf, (int) BranchStock::where('product_id', $this->rose->id)->sum('quantity'), 'تعديلُ الوصف غيّر رصيدَ الفرع');
    }

    public function test_a_save_that_does_not_send_the_descriptions_keeps_them(): void
    {
        $form = $this->form();
        unset($form['description'], $form['description_en'], $form['description_pos_enabled'], $form['description_web_enabled']);
        $this->rose->update(['description_pos_enabled' => true]);

        $this->actingAs($this->owner)->put(route('admin.products.update', $this->rose->id), $form)->assertRedirect();

        $fresh = $this->rose->fresh();
        $this->assertSame('ورد أحمر طبيعيّ بلفّة حرير', $fresh->description);
        $this->assertSame('Fresh red roses in silk wrap', $fresh->description_en);
        $this->assertTrue($fresh->description_pos_enabled, 'حفظةٌ لم ترسل المفتاحَ أطفأته');
        $this->assertTrue($fresh->description_web_enabled);
    }

    public function test_sales_channels_and_description_channels_do_not_move_each_other(): void
    {
        // إغلاقُ الصندوق لا يمسّ مفتاحَي الوصف
        $this->save(['pos_enabled' => false, 'description_pos_enabled' => true])->assertRedirect();
        $this->assertFalse($this->rose->fresh()->pos_enabled);
        $this->assertTrue($this->rose->fresh()->description_pos_enabled, 'يبقى محفوظًا لمن يعيد فتح القناة');

        // وإعادةُ فتحه تجد الوصفَ كما تُرك
        $this->save(['pos_enabled' => true, 'description_pos_enabled' => true])->assertRedirect();
        $this->assertTrue($this->rose->fresh()->description_pos_enabled);

        // وإطفاءُ وصف الموقع لا يُخفي الصنفَ منه
        $this->save(['description_web_enabled' => false])->assertRedirect();
        $this->assertTrue($this->rose->fresh()->published, 'إخفاءُ الوصف أخفى الصنف');
        $this->assertTrue($this->rose->fresh()->pos_enabled);
    }

    public function test_a_copy_carries_both_descriptions_and_where_they_show(): void
    {
        $this->rose->update(['description_pos_enabled' => true, 'description_web_enabled' => false]);

        $this->actingAs($this->owner)->post(route('admin.products.duplicate', $this->rose->id))->assertRedirect();

        $copy = Product::where('business_id', $this->shop->id)->where('id', '!=', $this->rose->id)->latest('id')->firstOrFail();
        $this->assertSame('ورد أحمر طبيعيّ بلفّة حرير', $copy->description);
        $this->assertSame('Fresh red roses in silk wrap', $copy->description_en);
        $this->assertTrue($copy->description_pos_enabled);
        $this->assertFalse($copy->description_web_enabled);
    }

    /* ═══════════ الموقع ═══════════ */

    public function test_the_ribbon_page_says_the_description_in_its_own_language(): void
    {
        $ar = $this->page('ar');
        $this->assertStringContainsString('ورد أحمر طبيعيّ بلفّة حرير', $ar);
        $this->assertStringNotContainsString('Fresh red roses in silk wrap', $ar, 'الصفحةُ العربيّة عرضت الوصفَ الإنجليزيّ');

        $en = $this->page('en');
        $this->assertStringContainsString('Fresh red roses in silk wrap', $en);
        $this->assertStringNotContainsString('ورد أحمر طبيعيّ بلفّة حرير', $en, 'الصفحةُ الإنجليزيّة عرضت الوصفين');

        // وبلا وصفٍ إنجليزيٍّ تعرض الإنجليزيّةُ العربيَّ — لا فراغ ولا ترجمةَ مخترَعة
        $this->rose->update(['description_en' => null]);
        $this->assertStringContainsString('ورد أحمر طبيعيّ بلفّة حرير', $this->page('en'));
    }

    public function test_a_description_turned_off_online_is_not_shown_while_the_product_still_sells(): void
    {
        $this->rose->update(['description_web_enabled' => false]);

        foreach (['ar', 'en'] as $lang) {
            $html = $this->page($lang);
            $this->assertStringNotContainsString('ورد أحمر طبيعيّ بلفّة حرير', $html, $lang);
            $this->assertStringNotContainsString('Fresh red roses in silk wrap', $html, $lang);
        }
        // والصنفُ نفسُه معروضٌ ويُباع
        $this->assertStringContainsString('Luxury Rose Bouquet', $this->page('en'));

        // والمتجرُ المبنيّ يطيع المفتاحَ نفسَه
        $this->shop->update(['storefront_theme' => null]);
        $this->assertStringNotContainsString('ورد أحمر طبيعيّ', $this->get('/s/ribbon')->assertOk()->getContent());
        $this->rose->update(['description_web_enabled' => true]);
        $this->assertStringContainsString('ورد أحمر طبيعيّ', $this->get('/s/ribbon')->assertOk()->getContent());
    }

    public function test_a_description_is_text_not_markup(): void
    {
        $this->rose->update(['description_en' => '<script>alert(1)</script><b>bold</b>']);

        $html = $this->page('en');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    /* ═══════════ الصندوق ═══════════ */

    public function test_the_till_reads_the_description_only_when_it_is_opened_to_it(): void
    {
        // مغلقٌ افتراضًا — والصنفُ نفسُه في الصندوق يُباع
        $row = $this->atTheTill();
        $this->assertNotNull($row);
        $this->assertTrue($row['pos_enabled']);
        $this->assertNull($row['pos_description']);

        $this->rose->update(['description_pos_enabled' => true]);
        $this->assertSame('ورد أحمر طبيعيّ بلفّة حرير', $this->atTheTill()['pos_description']);

        // وبلغة شاشة الكاشير — والإنجليزيّةُ تقع على العربيّ
        app()->setLocale('en');
        $this->assertSame('Fresh red roses in silk wrap', $this->atTheTill()['pos_description']);
        $this->rose->update(['description_en' => null]);
        $this->assertSame('ورد أحمر طبيعيّ بلفّة حرير', $this->atTheTill()['pos_description']);
    }

    public function test_a_product_not_sold_at_the_till_shows_no_description_there(): void
    {
        $this->rose->update(['description_pos_enabled' => true, 'pos_enabled' => false]);
        $this->assertNull($this->atTheTill()['pos_description'], 'وصفُ صنفٍ لا يُباع في الصندوق وصله');

        $this->rose->update(['pos_enabled' => true, 'active' => false]);
        $this->assertNull($this->atTheTill()['pos_description'], 'وصفُ صنفٍ موقوفٍ وصل الصندوق');
    }

    public function test_the_rule_itself(): void
    {
        $this->assertSame('عربيّ', ProductDescription::display('عربيّ', 'English', 'ar'));
        $this->assertSame('English', ProductDescription::display('عربيّ', 'English', 'en'));
        $this->assertSame('عربيّ', ProductDescription::display('عربيّ', '  ', 'en'));
        // والعربيّةُ تعرض العربيّ وحده — كاسم الصنف
        $this->assertSame('', ProductDescription::display(null, 'English', 'ar'));
    }
}
