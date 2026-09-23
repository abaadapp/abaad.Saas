<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * صنفٌ أُوقف يبقى موقوفًا — ولا تُعيده حفظةٌ لم تطلب ذلك.
 *
 * ═══ العطبُ الذي يحرسه هذا ═══
 *
 * `ProductController::update` تحرس حقلين صراحةً وتنسى ثالثًا:
 *
 *     if ($request->has('published'))   { ... }   // «نموذجٌ جزئيّ لا يعني أخفِه»
 *     if ($request->has('tracks_stock')) { ... }  // «كالعرض سواء»
 *     $data['active'] = $request->boolean('active', true);   ← بلا حارس
 *
 * فطلبٌ لا يحمل `active` يكتب `true`. والتعليقان فوقه يقولان القاعدة بلفظها
 * — ثمّ لا تُطبَّق على الثالث.
 *
 * ═══ وما يقع حين يقع ═══
 *
 * التاجر يُوقف صنفًا: موسمُه انتهى، أو مورّدُه توقّف، أو سعرُه غلط. ثمّ
 * تصله حفظةٌ جزئيّة من أيّ باب — فيعود الصنف إلى شاشة الصندوق، ويبيعه
 * الكاشير، ولا سطرَ في النظام يقول إنّ أحدًا أعاده.
 *
 * ولا بابَ اليوم يُرسل جزئيًّا إلى هذا المسار: نموذجُ المنتج يُرسل الحقل
 * دائمًا. فهذا حارسٌ على نيّةٍ مكتوبة لا على عطبٍ يقع الآن — وهو موضعُ
 * الحراسة الأرخص: قبل أن يُكتب البابُ الذي يوقظه.
 */
class AStoppedProductStaysStoppedTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function stopped(): Product
    {
        return Product::create([
            'business_id' => $this->business->id, 'name' => 'قميص شتويّ', 'price' => 10, 'cost' => 6,
            'quantity' => 20, 'alert_qty' => 5,
            'active' => false, 'published' => false, 'tracks_stock' => false,
        ]);
    }

    /** أقلُّ ما يقبله المسار: اسمٌ وسعر */
    private function partial(Product $product, array $extra = []): TestResponse
    {
        return $this->put(route('admin.products.update', $product->id), array_merge([
            'name' => $product->name,
            'price' => $product->price,
        ], $extra));
    }

    /* ═════════════ الثلاثةُ تُقاس بمقياسٍ واحد ═════════════ */

    public function test_a_partial_save_does_not_wake_a_stopped_product(): void
    {
        $product = $this->stopped();

        $this->partial($product)->assertSessionHasNoErrors();

        $this->assertFalse((bool) $product->fresh()->active, 'صنفٌ أُوقف عاد إلى شاشة الصندوق بحفظةٍ لم تطلب ذلك');
    }

    /** وأختاه — وهما محروستان اليوم، فتُقفل القاعدةُ على الثلاثة معًا */
    public function test_a_partial_save_does_not_publish_a_hidden_product(): void
    {
        $product = $this->stopped();

        $this->partial($product)->assertSessionHasNoErrors();

        $this->assertFalse((bool) $product->fresh()->published);
    }

    public function test_a_partial_save_does_not_rebind_a_product_to_the_stock_book(): void
    {
        $product = $this->stopped();

        $this->partial($product)->assertSessionHasNoErrors();

        $this->assertFalse((bool) $product->fresh()->tracks_stock);
    }

    /* ═════════════ وما أُرسل صراحةً يُطاع ═════════════ */

    /**
     * والحارسُ يمنع الصمتَ لا الأمرَ الصريح.
     *
     * ولولا هذه لَكان أبسطُ «إصلاحٍ» أن يُهمَل الحقل كلَّه — فلا يُوقف
     * تاجرٌ صنفًا أبدًا، ولا يشكو اختبار.
     */
    public function test_sending_the_field_still_stops_and_starts_the_product(): void
    {
        $product = $this->stopped();

        $this->partial($product, ['active' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $product->fresh()->active, 'لم يُشغَّل صنفٌ طُلب تشغيلُه');

        $this->partial($product, ['active' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $product->fresh()->active, 'لم يُوقَف صنفٌ طُلب إيقافُه');
    }

    /** ونموذجُ الشاشة كاملٌ — فما يفعله اليوم لا يتبدّل */
    public function test_the_full_form_from_the_screen_still_decides(): void
    {
        $product = $this->stopped();

        $this->put(route('admin.products.update', $product->id), [
            'name' => 'قميص شتويّ', 'price' => 12,
            'active' => '1', 'published' => '1', 'tracks_stock' => '1',
        ])->assertSessionHasNoErrors();

        $fresh = $product->fresh();

        $this->assertTrue((bool) $fresh->active);
        $this->assertTrue((bool) $fresh->published);
        $this->assertTrue((bool) $fresh->tracks_stock);
        $this->assertSame('12.000', (string) $fresh->price);
    }

    /**
     * ═══ وصنفٌ جديد يُولد حيًّا ═══
     *
     * حارسُ التعديل لا يجوز أن يقلب الإنشاء: من أضاف صنفًا ولم يلمس المفتاح
     * يريده يُباع اليوم — لا أن يبحث عن سببِ غيابه عن الصندوق.
     */
    public function test_a_new_product_is_born_active(): void
    {
        $this->post(route('admin.products.store'), [
            'name' => 'قميص صيفيّ', 'price' => 9,
        ])->assertSessionHasNoErrors();

        $fresh = Product::where('name', 'قميص صيفيّ')->firstOrFail();

        $this->assertTrue((bool) $fresh->active, 'صنفٌ جديد وُلد موقوفًا — يبحث صاحبُه عنه في الصندوق');
        $this->assertTrue((bool) $fresh->published);
    }
}
