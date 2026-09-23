<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\MarketingSettings;
use App\Support\OrderStatus;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الصورةُ ليست وعدًا — والمحلُّ يقول ذلك قبل أن يُدفع الثمن.
 *
 * ═══ ما يُحرَس هنا ═══
 *
 * محلُّ وردٍ يصوّر باقةً ثمّ يبيعها ثلاثين مرّة، وورد اليوم غيرُ ورد أمس.
 * فتصل الباقةُ مختلفةً قليلًا، ويقيس الزبون ما في يده على صورةٍ ظنّها عقدًا.
 *
 * وثلاثةٌ لا واحد، ولكلٍّ سببُه:
 *
 *   في عمود القرار بين الثمن وزرّ الإضافة — آخرُ ما يُقرأ قبل الضغطة.
 *   وكان تحت الصورة أوّلًا، وهو صحيحٌ على الجوّال: صورةٌ ثمّ سطرٌ ثمّ زرّ.
 *   ثمّ كشفت لقطةُ شاشةٍ على عرضٍ ١٢٨٠ أنّ العمودين يفترقان: الصورةُ
 *   بستّمئة بكسل والزرُّ عند ٣٦٣، فيقع السطرُ عند ٨٣٠ — **تحت الزرّ
 *   بأربعمئة**. والترتيبُ في المصدر كان صحيحًا، فلم يكشفه اختبار.
 *
 *   فوق زرّ الطلب — لحظةُ الدفع، وآخرُ ما يقرؤه قبلها.
 *
 *   في التأكيد — وهو ما يُقرأ ساعةَ الخلاف: الزبونُ يفتح الصندوق فيعود إلى
 *   آخر ما قاله له المحلّ، لا إلى صفحةِ منتجٍ مرّ عليها قبل ثلاثة أيّام.
 *
 * وأشدُّ ما يُحرَس أنّه **لا يمحو ملاحظة التوصيل**: تلك مشغولةٌ بنصٍّ صحيح
 * عند كلّ من ضبطها، وحقلٌ يبتلع حقلًا يجعل التاجر يختار أحدهما.
 */
class ASitesPhotoIsNotAPromiseTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Product $bouquet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->themed('RIBBON', 'ribbon');
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'ribbon@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->bouquet = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    /** متجرٌ بواجهةٍ خاصّة ومتجرُه مفتوح */
    private function themed(string $name, string $slug): Business
    {
        $business = Business::create([
            'name' => $name, 'type' => 'محل ورد', 'status' => 'نشط',
            'site_slug' => $slug, 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);

        Currency::create([
            'business_id' => $business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $business->id, 'key' => 'vat_enabled', 'value' => '0']);
        MarketingSettings::save($business->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        return $business;
    }

    private function note(string $text, ?Business $business = null): void
    {
        MarketingSettings::save(($business ?? $this->shop)->id, 'website', ['store_image_note' => $text]);
    }

    /** طلبٌ مكتمل — لتُفتح صفحةُ تأكيده */
    private function placedOrder(?Business $business = null): Order
    {
        $business ??= $this->shop;

        $customer = Customer::create([
            'business_id' => $business->id, 'name' => 'مريم', 'phone' => '96899110001',
        ]);

        $order = Order::create([
            'business_id' => $business->id,
            'branch_id' => Branch::where('business_id', $business->id)->value('id'),
            'customer_id' => $customer->id,
            'number' => 'WEB-'.$business->id,
            'status' => OrderStatus::PENDING,
            'is_held' => false,
            'payment_method' => 'نقدي',
            'payment_status' => 'غير مدفوع',
            'subtotal' => 20, 'total' => 20,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->bouquet->id, 'name' => 'باقة ورد',
            'price' => 20, 'quantity' => 1, 'total' => 20,
        ]);

        return $order;
    }

    /** الصفحاتُ الثلاث التي يُعرض فيها */
    private function pages(?Order $order = null, string $slug = 'ribbon'): array
    {
        $order ??= $this->placedOrder();

        return [
            'المنتج' => $this->get('/s/'.$slug.'/p/'.$this->bouquet->id)->assertOk()->getContent(),
            'الإتمام' => $this->get('/s/'.$slug.'/checkout')->assertOk()->getContent(),
            /* وصفحةُ التأكيد محروسةٌ برمزٍ يُشتقّ من الطلب — ولا تُفتح برقمه وحده */
            'التأكيد' => $this->get('/s/'.$slug.'/done/'.$order->id.'?t='.WebCheckout::token($order))
                ->assertOk()->getContent(),
        ];
    }

    /* ═════════════ فارغٌ ⇐ لا شيء ═════════════ */

    /**
     * ومن لم يكتبه لا يُرسم له صندوقٌ خاوٍ.
     *
     * محلُّ عطرٍ أو كيكٍ لا يحتاجه، وسطرٌ فارغٌ في ثلاث صفحاتٍ فراغٌ يُقرأ
     * عطبًا — ويُسأل عنه.
     */
    public function test_a_shop_that_wrote_nothing_gets_nothing(): void
    {
        foreach ($this->pages() as $page => $html) {
            $this->assertStringNotContainsString('rb-image-note', $html, 'صندوقٌ خاوٍ في صفحة '.$page);
        }
    }

    /* ═════════════ مكتوبٌ ⇐ في الثلاث ═════════════ */

    public function test_the_note_is_read_on_all_three_pages(): void
    {
        $this->note('قد يختلف صنفٌ أو لون حسب المتوفر');

        foreach ($this->pages() as $page => $html) {
            $this->assertStringContainsString(
                'قد يختلف صنفٌ أو لون حسب المتوفر',
                $html,
                'التنبيه غائبٌ عن صفحة '.$page,
            );
        }
    }

    /**
     * ═══ وفي عمود القرار بين الثمن والزرّ ═══
     *
     * الموضعُ هو المسألةُ كلُّها، و«قبل الزرّ في المصدر» لا يكفي لقياسه.
     *
     * كان السطرُ تحت الصورة — وعمودُ الصورة يسبق في المصدر، فمرّ اختبارٌ
     * يسأل «أيسبق الزرَّ؟». ثمّ فُتحت الصفحةُ على شاشةٍ عريضة: الصورةُ
     * عمودٌ بستّمئة بكسل، والزرُّ في العمود المقابل عند ٣٦٣، فالسطرُ عند
     * ٨٣٠ — تحت الزرّ. يُشترى المنتجُ بلا أن يُقرأ، وهو العطبُ عينُه الذي
     * كُتب السطرُ لأجله.
     *
     * فيُقاس بحدّين لا بواحد: **بعد الثمن** — أي في عمود القرار لا في عمود
     * الصورة — و**قبل زرّ الإضافة**. والحدّان معًا يمنعان عودتَه إلى حيث
     * كان.
     */
    public function test_on_the_product_page_it_sits_between_the_price_and_the_add_button(): void
    {
        $this->note('قد يختلف صنفٌ أو لون حسب المتوفر');

        $html = $this->get('/s/ribbon/p/'.$this->bouquet->id)->assertOk()->getContent();

        $price = mb_strpos($html, 'data-rb-price');
        $note = mb_strpos($html, 'rb-image-note');
        $add = mb_strpos($html, 'data-testid="rb-add"');

        $this->assertNotFalse($price);
        $this->assertNotFalse($note);
        $this->assertNotFalse($add);

        $this->assertGreaterThan($price, $note, 'التنبيهُ خارج عمود القرار — يهبط تحت الزرّ على الشاشة العريضة');
        $this->assertLessThan($add, $note, 'التنبيهُ بعد زرّ الإضافة — يُقرأ بعد القرار');
    }

    /** وفوق زرّ الطلب لا تحته — هذه لحظةُ الدفع */
    public function test_on_the_checkout_page_it_comes_before_the_place_button(): void
    {
        $this->note('قد يختلف صنفٌ أو لون حسب المتوفر');

        $html = $this->get('/s/ribbon/checkout')->assertOk()->getContent();

        $note = mb_strpos($html, 'rb-image-note');
        $place = mb_strpos($html, 'data-testid="rb-place"');

        $this->assertNotFalse($note);
        $this->assertNotFalse($place);
        $this->assertLessThan($place, $note, 'التنبيهُ بعد زرّ الطلب — يُقرأ بعد الدفع');
    }

    /* ═════════════ ولا يبتلع أخاه ═════════════ */

    /**
     * ملاحظةُ التوصيل تبقى كما هي.
     *
     * وهذا سببُ الحقل المستقلّ من أصله: لو كُتب التنبيهُ في `store_delivery_note`
     * لَاختار التاجرُ بين أن يقول متى يصل الطلب وأن يقول إنّ الورد يتغيّر.
     */
    public function test_it_does_not_swallow_the_delivery_note(): void
    {
        MarketingSettings::save($this->shop->id, 'website', [
            'store_delivery_note' => 'التوصيل داخل مسقط خلال اليوم نفسه',
            'store_image_note' => 'قد يختلف صنفٌ أو لون حسب المتوفر',
        ]);

        $html = $this->get('/s/ribbon/p/'.$this->bouquet->id)->assertOk()->getContent();

        $this->assertStringContainsString('التوصيل داخل مسقط خلال اليوم نفسه', $html);
        $this->assertStringContainsString('قد يختلف صنفٌ أو لون حسب المتوفر', $html);
    }

    /* ═════════════ ما يكتبه التاجر نصٌّ لا كود ═════════════ */

    /**
     * ونصُّ التاجر يُهرَّب — وهو نصُّه في موقعه هو.
     *
     * والخطرُ ليس أن يؤذي نفسه: حسابُ موظّفٍ يُسرَق، أو تاجرٌ يُقنَع بلصق
     * «كودِ تتبّعٍ» أُرسل إليه. والسطرُ يُرسم في **صفحة الدفع** — فالسرقةُ
     * تقع على بيانات زبائنه لا عليه.
     */
    public function test_a_shop_cannot_inject_a_script_into_its_own_storefront(): void
    {
        $this->note('<script>alert(1)</script>ورد اليوم');

        foreach ($this->pages() as $page => $html) {
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'كودٌ يُنفَّذ في صفحة '.$page);
            $this->assertStringContainsString('&lt;script&gt;', $html, 'النصُّ لم يصل مُهرَّبًا في صفحة '.$page);
        }
    }

    /* ═════════════ الحدُّ يُردّ ولا يُقصّ ═════════════ */

    /**
     * وما طال يُردّ برسالة.
     *
     * ومن كتب ثلاثمئةً فقُصّ له عند المئتين يقرأ جملتَه مبتورةً في وجه
     * زبونه — ولا شيء قال له إنّ شيئًا نقص.
     */
    public function test_a_note_longer_than_the_line_is_refused_not_trimmed(): void
    {
        MarketingSettings::save($this->shop->id, 'website', ['store_image_note' => 'سطرٌ قصير']);

        /*
         * والعنوانُ يُرسل مع الحقل — كما يُرسله النموذجُ في الشاشة.
         *
         * `saveStore` تكتب `site_slug` ممّا وصل، ففَقْدُه في طلبٍ جزئيّ يمحو
         * عنوانَ المتجر ويُطفئ موقعَه. والاختبارُ يُقلّد النموذج لا يخترع
         * طلبًا لا يُرسله أحد.
         */
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), [
                'site_slug' => 'ribbon',
                'store_image_note' => str_repeat('ا', 201),
            ])->assertSessionHasErrors('store_image_note');

        $this->assertSame(
            'سطرٌ قصير',
            MarketingSettings::group($this->shop->id, 'website')['store_image_note'],
            'كُتب فوق النصّ القديم رغم الرفض',
        );
    }

    /** ويُحفظ تحت مفتاحه هو */
    public function test_the_owner_writes_it_from_his_own_settings(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.marketing.store.save'), [
                'site_slug' => 'ribbon',
                'store_image_note' => 'كل باقة تُنسَّق يدويًّا من ورد اليوم',
            ])->assertSessionHasNoErrors();

        $this->assertSame(
            'كل باقة تُنسَّق يدويًّا من ورد اليوم',
            MarketingSettings::group($this->shop->id, 'website')['store_image_note'],
        );
    }

    /* ═════════════ والحقلُ في الشاشة موصولٌ بمفتاحه ═════════════ */

    /**
     * ═══ ولمَ يُقاس هذا من المصدر لا من المتصفّح ═══
     *
     * الجانبُ المرئيّ من هذا التغيير إدخالٌ واحد بلا منطق. وعطبُه الوحيدُ
     * الصامت مفتاحٌ يُكتب خطأً: يكتب التاجرُ سطرَه، وتقول الشاشة «حُفظ»،
     * ولا يظهر في موقعه شيء — والاختباراتُ أعلاه تمرّ كلُّها لأنّها تكتب
     * في القاعدة مباشرةً لا من النموذج.
     *
     * وصفحةُ الإعدادات لا تُركَّب في jsdom: هي شاشةٌ ضخمةٌ بأقسامٍ وألسنة،
     * وتركيبُها كلِّه لأجل حقلٍ واحد اختبارٌ بطيءٌ هشّ. فيُقاس الوصلُ نفسُه
     * — القراءةُ والكتابةُ بالمفتاح ذاته — وهو كلُّ ما يمكن أن يسقط.
     */
    public function test_the_settings_screen_binds_the_field_to_its_own_key(): void
    {
        $screen = (string) file_get_contents(resource_path('js/Pages/Admin/Settings/Index.tsx'));

        $this->assertStringContainsString(
            'storeForm.data.store_image_note',
            $screen,
            'الحقل لا يقرأ مفتاحه',
        );
        $this->assertStringContainsString(
            "setData('store_image_note', e.target.value)",
            $screen,
            'الحقل لا يكتب في مفتاحه',
        );
    }

    /* ═════════════ وعزلُ المستأجر ═════════════ */

    /**
     * تنبيهُ محلٍّ لا يُقرأ في موقع محلٍّ آخر.
     *
     * والمفتاحُ يُقرأ من `MarketingSettings::group($bid, ...)` — ومعرّفٌ
     * يُقرأ من الجلسة أو من الطلب خطأً يجعل موقعَ محلّ الورد يقول ما كتبه
     * محلُّ العطور.
     */
    public function test_one_shops_note_never_appears_on_another_shops_site(): void
    {
        $other = $this->themed('عطور', 'attar');
        $this->note('ورد اليوم يختلف عن الصورة');
        $this->note('عطرٌ لا يتغيّر أبدًا', $other);

        $html = $this->get('/s/ribbon/p/'.$this->bouquet->id)->assertOk()->getContent();

        $this->assertStringContainsString('ورد اليوم يختلف عن الصورة', $html);
        $this->assertStringNotContainsString('عطرٌ لا يتغيّر أبدًا', $html, 'تنبيهُ متجرٍ آخر في موقع هذا');
    }
}
