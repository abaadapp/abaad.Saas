<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * أصنافُ البوتيك تُسنَد من شاشته — ولا تُنقل من بوتيكٍ إلى بوتيكٍ في صمت.
 *
 * ═══ الحاجة ═══
 *
 * صاحبُ البوتيك يأتي بعشرين قطعة، وكان إسنادُها يقع في بطاقة كلّ صنفٍ على
 * حدة — عشرون رحلةً لأجل حقلٍ واحد. فصار للبوتيك قسمٌ يعرض ما يحمله اليوم،
 * وبابٌ يُضيف إليه، وزرٌّ ينزع منه.
 *
 * ═══ وما يُحرَس هنا ═══
 *
 * أوّلُه العزل: الميزةُ لمن فُتح له بابُها (`Boutiques::holds`) — ومن لا،
 * لا يرى شاشةً ولا يُستجاب لطلبه ولو كتب المسارَ بيده. وأصنافُ محلٍّ لا
 * تُعرض ولا تُسنَد في محلٍّ آخر، ولو حُشيت معرّفاتُها في الطلب يدويًّا.
 *
 * وثانيه أنّ النزعَ نزعٌ لا حذف: `boutique_id = null` وحدها — الصنفُ يبقى
 * بمخزونه، وبنودُ ما بِيع منه تحمل لقطتَها فلا يتغيّر كشفُ شهرٍ مضى.
 *
 * وثالثه أنّ صنفًا عند بوتيكٍ لا ينتقل إلى غيره بضغطةٍ لا تقول ذلك: كشفُ
 * الأوّل ينقص صنفًا كان يبيعه، ولا أحد سأله.
 */
class ABoutiquePicksItsGoodsFromItsOwnScreenTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Boutique $boutique;

    private Business $other;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        [$this->shop, $this->owner] = $this->shopWith(true, 'RIBBON', 'saud@abaad.om');
        [$this->other, $this->stranger] = $this->shopWith(false, 'محل آخر', 'them@abaad.om');

        $this->boutique = $this->boutiqueOf($this->shop, 'بوتيك لمى');
    }

    /** @return array{0: Business, 1: User} */
    private function shopWith(bool $hosts, string $name, string $email): array
    {
        $shop = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط',
            'boutiques_enabled' => $hosts,
        ]);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);

        $user = User::create([
            'business_id' => $shop->id, 'name' => 'مالك '.$name, 'email' => $email,
            'password' => 'secret', 'role' => 'admin', 'status' => 'نشط',
        ]);

        return [$shop, $user];
    }

    private function boutiqueOf(Business $shop, string $name): Boutique
    {
        return Boutique::create([
            'business_id' => $shop->id, 'name' => $name, 'commission_rate' => 20, 'active' => true,
        ]);
    }

    private function product(Business $shop, string $name, ?int $boutique = null, int $qty = 10): Product
    {
        return Product::create([
            'business_id' => $shop->id, 'name' => $name, 'sku' => 'SKU-'.$name,
            'price' => 20, 'cost' => 8, 'quantity' => $qty, 'alert_qty' => 2,
            'active' => true, 'boutique_id' => $boutique,
        ]);
    }

    /** @param  array<string, mixed>  $body */
    private function attach(array $body, ?User $as = null, ?int $boutiqueId = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->post(route('admin.boutiques.attach', $boutiqueId ?? $this->boutique->id), $body);
    }

    /* ═══════════════ العزلُ بين الشركات ═══════════════ */

    /** المحلُّ المؤهَّل يفتح الشاشة — وفيها أصنافُه وما يُختار منه */
    public function test_a_shop_that_hosts_boutiques_opens_the_screen(): void
    {
        $this->product($this->shop, 'عطر لمى', $this->boutique->id);

        $this->actingAs($this->owner)
            ->get(route('admin.boutiques.show', $this->boutique->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Boutiques/Show')
                ->has('products', 1)
                ->has('catalog.rows'));
    }

    /** ومن لا بوتيكَ عنده ولا مفتاح: لا شاشة */
    public function test_a_shop_without_the_key_sees_no_screen(): void
    {
        $theirs = $this->boutiqueOf($this->shop, 'ليست له');

        $this->actingAs($this->stranger)->get(route('admin.boutiques.index'))->assertNotFound();
        $this->actingAs($this->stranger)->get(route('admin.boutiques.show', $theirs->id))->assertNotFound();
    }

    /** ولا يُستجاب لطلبه ولو ناداه بيده */
    public function test_an_ineligible_shop_cannot_call_attach(): void
    {
        $mine = $this->product($this->shop, 'عطر لمى');

        $this->attach(['product_ids' => [$mine->id]], $this->stranger)->assertNotFound();

        $this->assertNull($mine->fresh()->boutique_id);
    }

    /**
     * وبوتيكُ محلٍّ لا يُسنَد إليه صنفُ محلٍّ آخر.
     *
     * والردُّ صمتٌ لا خطأ: الطلبُ يُقيَّد بـ`business_id` فلا يجد شيئًا
     * يُحدّثه. والمهمُّ أنّ الصنفَ لم يُمسّ.
     */
    public function test_a_boutique_cannot_take_a_product_of_another_shop(): void
    {
        $theirs = $this->product($this->other, 'بضاعتهم');

        $this->attach(['product_ids' => [$theirs->id]])->assertSessionHasNoErrors();

        $this->assertNull($theirs->fresh()->boutique_id);
    }

    /** ومعرّفاتٌ محشوّةٌ يدويًّا لا تُربط ولا تُفسد ما معها */
    public function test_forged_ids_of_another_shop_attach_nothing(): void
    {
        $mine = $this->product($this->shop, 'عطر لمى');
        $theirs = $this->product($this->other, 'بضاعتهم');

        $this->attach(['product_ids' => [$mine->id, $theirs->id]])->assertSessionHasNoErrors();

        $this->assertSame($this->boutique->id, (int) $mine->fresh()->boutique_id);
        $this->assertNull($theirs->fresh()->boutique_id);
    }

    /** ونافذةُ الاختيار لا تعرض أصنافَ محلٍّ آخر */
    public function test_the_picker_shows_only_this_shops_products(): void
    {
        $this->product($this->shop, 'عطر لمى');
        $this->product($this->other, 'بضاعتهم');

        $this->actingAs($this->owner)
            ->get(route('admin.boutiques.show', $this->boutique->id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('catalog.rows', 1)
                ->where('catalog.rows.0.name', 'عطر لمى'));
    }

    /** ولا بوتيكَ محلٍّ آخر يُفتح بمعرّفه */
    public function test_a_boutique_of_another_shop_is_not_reachable_by_id(): void
    {
        $theirs = $this->boutiqueOf($this->other, 'بوتيكهم');
        $mine = $this->product($this->shop, 'عطر لمى');

        $this->actingAs($this->owner)->get(route('admin.boutiques.show', $theirs->id))->assertNotFound();
        $this->attach(['product_ids' => [$mine->id]], null, $theirs->id)->assertNotFound();

        $this->assertNull($mine->fresh()->boutique_id);
    }

    /**
     * ولا يُعرض في قائمة البوتيك صنفُ محلٍّ آخر — ولو حمل معرّفَ بوتيكه.
     *
     * صفٌّ كهذا لا يُكتب من بابٍ في النظام (كلُّ بابٍ يُقيَّد بالشركة)،
     * لكنّ القراءةَ لا تتّكئ على ذلك: استيرادٌ قديم أو صفٌّ كُتب بيدٍ في
     * القاعدة يجعل بضاعةَ محلٍّ تُقرأ في شاشة محلٍّ آخر — وهو أسوأُ ما
     * يقع في نظامٍ متعدّد المستأجرين.
     */
    public function test_a_stray_row_of_another_shop_is_not_listed(): void
    {
        $theirs = $this->product($this->other, 'بضاعتهم');
        DB::table('products')
            ->where('id', $theirs->id)->update(['boutique_id' => $this->boutique->id]);

        $this->actingAs($this->owner)
            ->get(route('admin.boutiques.show', $this->boutique->id))
            ->assertInertia(fn (Assert $page) => $page->has('products', 0));
    }

    /** والمحذوفُ ناعمًا لا يُعرض خيارًا */
    public function test_a_soft_deleted_product_is_not_offered(): void
    {
        $gone = $this->product($this->shop, 'صنفٌ حُذف');
        $gone->delete();

        $this->actingAs($this->owner)
            ->get(route('admin.boutiques.show', $this->boutique->id))
            ->assertInertia(fn (Assert $page) => $page->has('catalog.rows', 0));
    }

    /* ═══════════════ لا نقلَ صامت ═══════════════ */

    /**
     * صنفٌ عند بوتيكٍ آخر لا ينتقل بضغطةٍ لا تقول ذلك.
     *
     * وهذا موضعُ الخطأ المحتمل: `update` لا تسأل عمّا كان، فصنفُ «لمى»
     * يصير صنفَ «ريم» ولا أحد يُخبر لمى — وكشفُها بعدها ينقص صنفًا كانت
     * تبيعه.
     */
    public function test_a_product_held_by_another_boutique_does_not_move(): void
    {
        $reem = $this->boutiqueOf($this->shop, 'بوتيك ريم');
        $hers = $this->product($this->shop, 'عطر ريم', $reem->id);

        $this->attach(['product_ids' => [$hers->id]])
            ->assertSessionHasErrors('product_ids');

        $this->assertSame($reem->id, (int) $hers->fresh()->boutique_id);
    }

    /** ولا ينتقل غيرُه معه: الطلبُ يُردّ كلُّه فلا يقع نصفُه */
    public function test_a_refused_move_attaches_nothing_beside_it(): void
    {
        $reem = $this->boutiqueOf($this->shop, 'بوتيك ريم');
        $hers = $this->product($this->shop, 'عطر ريم', $reem->id);
        $free = $this->product($this->shop, 'صنفٌ حرّ');

        $this->attach(['product_ids' => [$free->id, $hers->id]])
            ->assertSessionHasErrors('product_ids');

        $this->assertNull($free->fresh()->boutique_id);
        $this->assertSame($reem->id, (int) $hers->fresh()->boutique_id);
    }

    /** وصنفُه هو يُعاد إسنادُه إليه بلا شكوى — لا نقلَ فيه */
    public function test_re_attaching_its_own_product_is_no_move(): void
    {
        $mine = $this->product($this->shop, 'عطر لمى', $this->boutique->id);

        $this->attach(['product_ids' => [$mine->id]])->assertSessionHasNoErrors();

        $this->assertSame($this->boutique->id, (int) $mine->fresh()->boutique_id);
    }

    /* ═══════════════ النزع ═══════════════ */

    /** والنزعُ لا يقع إلّا على صنفِ هذا البوتيك */
    public function test_detach_touches_only_this_boutiques_product(): void
    {
        $reem = $this->boutiqueOf($this->shop, 'بوتيك ريم');
        $hers = $this->product($this->shop, 'عطر ريم', $reem->id);
        $mine = $this->product($this->shop, 'عطر لمى', $this->boutique->id);

        $this->attach(['product_ids' => [$mine->id, $hers->id], 'detach' => true])
            ->assertSessionHasNoErrors();

        $this->assertNull($mine->fresh()->boutique_id);
        // وصنفُ ريم لم يُمسّ: النزعُ من بوتيكٍ لا يطال ما ليس فيه
        $this->assertSame($reem->id, (int) $hers->fresh()->boutique_id);
    }

    /** والنزعُ نزعٌ لا حذف: الصنفُ يبقى بمخزونه وسعره */
    public function test_detaching_keeps_the_product_and_its_stock(): void
    {
        $mine = $this->product($this->shop, 'عطر لمى', $this->boutique->id, qty: 7);

        $this->attach(['product_ids' => [$mine->id], 'detach' => true])->assertSessionHasNoErrors();

        $fresh = $mine->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->boutique_id);
        $this->assertSame(7, (int) $fresh->quantity);
        $this->assertSame('20.000', (string) $fresh->price);
        $this->assertTrue((bool) $fresh->active);
    }

    /* ═══════════════ التاريخُ الماليّ لا يُمسّ ═══════════════ */

    /**
     * ولا يتغيّر بندُ بيعٍ مضى — لا بإسنادٍ ولا بنزع.
     *
     * البندُ يحمل لقطتَه: اسمُ البوتيك ونسبتُه ساعةَ البيع. فالكشفُ الذي
     * قُرئ في شهرٍ مضى يبقى كما قُرئ.
     */
    public function test_neither_attaching_nor_detaching_rewrites_a_past_sale(): void
    {
        $mine = $this->product($this->shop, 'عطر لمى', $this->boutique->id);

        $order = Order::create([
            'business_id' => $this->shop->id, 'customer_name' => 'زبونة', 'employee_name' => 'المالك',
            'user_id' => $this->owner->id, 'number' => 'INV-1', 'status' => 'مكتمل',
            'payment_status' => 'مدفوع', 'is_held' => false, 'payment_method' => 'نقدي',
            'subtotal' => 20, 'discount' => 0, 'tax' => 0, 'total' => 20, 'ordered_at' => now()->subMonth(),
        ]);

        $line = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $mine->id, 'name' => 'عطر لمى',
            'price' => 20, 'quantity' => 1, 'cost' => 0, 'total' => 20,
            'boutique_id' => $this->boutique->id, 'boutique_name' => 'بوتيك لمى', 'boutique_rate' => 20,
        ]);

        // تُقرأ من القاعدة لا من الذاكرة: القيمةُ المكتوبة تعود بصبغة
        // المحرّك (`0` تصير `'0.000'` على PostgreSQL)، فتُقارَن مقروءةً بمقروءة
        $before = $line->fresh()->only(['boutique_id', 'boutique_name', 'boutique_rate', 'total', 'cost']);

        $this->attach(['product_ids' => [$mine->id], 'detach' => true])->assertSessionHasNoErrors();
        $reem = $this->boutiqueOf($this->shop, 'بوتيك ريم');
        $this->attach(['product_ids' => [$mine->id]], null, $reem->id)->assertSessionHasNoErrors();

        $this->assertSame($before, $line->fresh()->only(array_keys($before)));
    }

    /* ═══════════════ الأداء ═══════════════ */

    /**
     * وعددُ الاستعلامات لا يتبع عددَ الأصناف.
     *
     * قائمةٌ تسأل القاعدةَ عن اسم بوتيكِ كلّ صفّ تعمل عند أربعة أصنافٍ
     * وتختنق عند أربعمئة — ولا يظهر ذلك في اختبارٍ يفحص ما يُعرض وحدَه.
     * فيُقاس العددُ مرّتين: مرّةً بأصنافٍ قليلة ومرّةً بأضعافها.
     */
    public function test_the_screen_does_not_ask_one_query_per_row(): void
    {
        $count = function (int $attached, int $loose): int {
            Product::where('business_id', $this->shop->id)->forceDelete();
            Boutique::where('business_id', $this->shop->id)->where('id', '!=', $this->boutique->id)->delete();

            for ($i = 0; $i < $attached; $i++) {
                $this->product($this->shop, 'صنفٌ مسنَد '.$i, $this->boutique->id);
            }
            for ($i = 0; $i < $loose; $i++) {
                $this->product($this->shop, 'صنفٌ حرّ '.$i);
            }

            $n = 0;
            \Illuminate\Support\Facades\DB::listen(function () use (&$n) {
                $n++;
            });

            $this->actingAs($this->owner)->get(route('admin.boutiques.show', $this->boutique->id))->assertOk();

            return $n;
        };

        $small = $count(2, 3);   // خمسةُ أصناف
        $large = $count(12, 25); // سبعةٌ وثلاثون

        /*
         * ولا تُطلب مساواةٌ حرفيّة: جلسةٌ تُفتح ومفاتيحُ تُقرأ تختلف بين
         * طلبٍ وطلب بواحدٍ أو اثنين، ولا علاقةَ لذلك بعدد الصفوف. والمقصودُ
         * أنّ اثنين وثلاثين صفًّا زائدًا لا تجرّ اثنين وثلاثين استعلامًا.
         */
        $this->assertLessThanOrEqual(
            $small + 2,
            $large,
            "الاستعلامات نمت مع الأصناف: {$small} ← {$large}",
        );
    }

    /* ═══════════════ الرحلةُ كاملةً ═══════════════ */

    /**
     * من إنشاء البوتيك إلى نزع صنفٍ منه — وما يبقى على حاله في الطريق.
     */
    public function test_the_whole_journey_from_creating_a_boutique_to_dropping_one_product(): void
    {
        // ١) محلٌّ مؤهَّل ينشئ بوتيكًا
        $this->actingAs($this->owner)->post(route('admin.boutiques.store'), [
            'name' => 'بوتيك دانة', 'commission_rate' => 15, 'active' => true,
        ])->assertSessionHasNoErrors();

        $dana = Boutique::where('name', 'بوتيك دانة')->firstOrFail();

        // ٢) وله ثلاثةُ أصناف
        $one = $this->product($this->shop, 'عطر', qty: 5);
        $two = $this->product($this->shop, 'شمعة', qty: 9);
        $three = $this->product($this->shop, 'بخور', qty: 3);

        // ٣) يُسنَد اثنان
        $this->attach(['product_ids' => [$one->id, $two->id]], null, $dana->id)
            ->assertSessionHasNoErrors();

        // ٤) وشاشتُه تعرضهما
        $this->actingAs($this->owner)
            ->get(route('admin.boutiques.show', $dana->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('products', 2)
                ->where('products.0.name', 'شمعة')
                ->where('products.0.quantity', 9)
                ->where('products.1.name', 'عطر'));

        // ٥) والثالثُ بلا بوتيك
        $this->assertNull($three->fresh()->boutique_id);

        // ٦) ثمّ يُنزع واحد
        $this->attach(['product_ids' => [$one->id], 'detach' => true], null, $dana->id)
            ->assertSessionHasNoErrors();

        // ٧) فيبقى الصنفُ بمخزونه
        $this->assertNull($one->fresh()->boutique_id);
        $this->assertSame(5, (int) $one->fresh()->quantity);
        $this->assertSame(1, Product::where('boutique_id', $dana->id)->count());

        // ٩) ومحلٌّ آخر لا يرى شيئًا من هذا
        $this->actingAs($this->stranger)->get(route('admin.boutiques.show', $dana->id))->assertNotFound();
    }
}
