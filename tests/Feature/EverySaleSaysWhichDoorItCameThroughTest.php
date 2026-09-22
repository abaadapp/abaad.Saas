<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Books;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Permissions;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * كلُّ بيعةٍ تقول من أيّ بابٍ دخلت — في القائمة، وفي الدفتر، وفي الجرس.
 *
 * ═══ ما يحرسه هذا الملفّ ═══
 *
 * بابان يبيعان: المحلُّ والموقع. والفرقُ بينهما ليس تصنيفًا على الورق —
 * بيعةُ المنضدة وقعت بحضور بائعها وانتهت، وبيعةُ الموقع تقع بلا أحدٍ
 * وتنتظر من يجهّزها. فمن لا يفرّقهما لا يعرف أين يضع جهده ولا من يوقظ.
 *
 * وكان الفرقُ مكتوبًا في القاعدة (`orders.channel`) ولا يُقرأ في شاشة.
 */
class EverySaleSaysWhichDoorItCameThroughTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');

        /*
         * واللغةُ عربيّةٌ كما يقرؤها صاحبُ المتجر.
         *
         * الاختباراتُ تعمل بالإنجليزية افتراضًا (`APP_LOCALE=en`)، فحارسٌ
         * يوازن نصًّا عربيًّا يوازن ترجمتَه لا هو. وهذه الشاشاتُ تُقرأ
         * بالعربية في كلّ متجرٍ على الإنتاج.
         */
        $this->app->setLocale('ar');

        $this->business = Business::create(['name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066', 'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon']);
        Currency::create(['business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->business->id);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
        Setting::create(['business_id' => $this->business->id, 'key' => 'pay_credit', 'value' => '1']);

        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'سعود', 'email' => 'ribbon@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->business->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1', 'store_delivery_fee' => '0',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => "9 ص – 12 م\n4 م – 8 م",
        ]);

        $this->product = Product::create(['business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8, 'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ أبوابٌ تبيع ═══════════ */

    /** بيعةٌ من الموقع: يتمّها الزبون بنفسه */
    private function sellFromWebsite(int $qty = 1): Order
    {
        $this->postJson('/s/ribbon/checkout', [
            'items' => [['id' => $this->product->id, 'qty' => $qty]],
            'fulfil' => 'pickup', 'pay' => 'cod', 'name' => 'مريم', 'phone' => '96899110001',
            'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
        ])->assertOk();

        return Order::where('channel', SalesChannel::WEBSITE)->orderByDesc('id')->firstOrFail();
    }

    /** بيعةٌ من الصندوق — آجلةً كي تبقى «جديد» فتبلغ الجرس كما تبلغه بيعةُ الموقع */
    private function sellFromPos(int $qty = 1, bool $scheduled = true): Order
    {
        $customer = Customer::firstOrCreate(
            ['business_id' => $this->business->id, 'phone' => '96899220002'],
            ['name' => 'سالم', 'language' => 'ar', 'allow_credit_sales' => true, 'credit_limit' => 5000],
        );

        $this->actingAs($this->owner)->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => $this->product->name, 'qty' => $qty, 'price' => 20]],
            'payment_method' => 'نقدي', 'customer_id' => $customer->id, 'credit' => true, 'paid_now' => 0,
        ] + ($scheduled ? [
            // وطلبٌ يُجهَّز يلزمه موعدٌ ونوعُ تنفيذٍ واسمُ عميل — انظر FlowerOrder::validate
            'scheduled_for' => '2027-02-05 12:00:00',
            'fulfillment_type' => 'pickup',
            'customer' => 'سالم',
        ] : []))->assertOk();

        return Order::where('business_id', $this->business->id)
            ->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', '!=', SalesChannel::WEBSITE))
            ->orderByDesc('id')->firstOrFail();
    }

    /* ═══════════ الدفتر — نوعٌ لكلّ باب ═══════════ */

    /**
     * كلُّ بابٍ يختم حركتَه بنوعه — ولا يُترك العمود فارغًا.
     *
     * ═══ العطبُ الذي كشفه هذا الحارس ═══
     *
     * لم يكن أيُّ بابٍ يكتب `kind` أصلًا. فكلُّ بيعةٍ وقعت منذ أُضيف العمود
     * تُقرأ في «الحركة المالية» باسم «حركة»، ولا يبلغها مُرشِّحُ النوع، ولا
     * تعدّها `Transaction::scopeSales`. والأرقام كانت صادقة (المجاميع من
     * `type` لا من `kind`) — فلم يكن في الشاشة ما يقول إنّ شيئًا ضاع.
     */
    public function test_each_door_stamps_its_own_kind_on_the_movement(): void
    {
        $web = $this->sellFromWebsite();
        $pos = $this->sellFromPos();

        $this->assertSame(Transaction::WEB_SALE, Transaction::where('order_id', $web->id)->value('kind'));
        $this->assertSame(Transaction::SALE, Transaction::where('order_id', $pos->id)->value('kind'));

        // ولا صفَّ بيعٍ بلا نوع — وهو ما كان يقع في كلّ بيعة
        $this->assertSame(0, Transaction::whereNotNull('order_id')->whereNull('kind')->count());

        /*
         * والاسمان يفترقان في الشاشة.
         *
         * كان النوعان يُسمّيان «مبيعات» لو تشاركا الاسم — ومُرشِّحان باسمٍ
         * واحد لا يرشّحان شيئًا.
         */
        $this->assertNotSame(Books::label(Transaction::SALE), Books::label(Transaction::WEB_SALE));
        $this->assertSame('مبيعات الموقع الإلكتروني', Books::label(Transaction::WEB_SALE));

        // والنطاقُ الذي يقرأ المبيعات يبلغ البابين معًا
        $this->assertSame(2, Transaction::where('business_id', $this->business->id)->sales()->count());
    }

    /** و«الحركة المالية» تسمّي الصفَّ وتعرض النوعَ في مُرشِّحها */
    public function test_the_money_screen_names_the_website_sale_and_offers_it_as_a_filter(): void
    {
        $this->sellFromWebsite();
        $this->sellFromPos();

        $page = $this->actingAs($this->owner)->get(route('admin.finance.transactions'))
            ->assertOk()->viewData('page')['props'];

        $labels = array_column($page['rows'], 'kind_label');
        $this->assertContains('مبيعات الموقع الإلكتروني', $labels);
        $this->assertContains('مبيعات نقطة البيع', $labels);
        $this->assertNotContains('حركة', $labels, 'لا صفَّ بيعٍ بلا اسم');

        $kinds = array_column($page['kinds'], 'value');
        $this->assertContains(Transaction::WEB_SALE, $kinds);
        $this->assertContains(Transaction::SALE, $kinds);

        // ويُرشَّح به فعلًا — لا اسمًا في قائمةٍ لا تفصل شيئًا
        $filtered = $this->actingAs($this->owner)
            ->get(route('admin.finance.transactions', ['kind' => Transaction::WEB_SALE]))
            ->assertOk()->viewData('page')['props']['rows'];

        $this->assertCount(1, $filtered);
        $this->assertStringContainsString('الموقع الإلكتروني', $filtered[0]['description']);
    }

    /* ═══════════ المبيعات — المصدرُ يُقرأ ويُرشَّح ═══════════ */

    /** القائمةُ تحمل القناةَ رمزًا واسمًا، وتعدُّ ما جاء من الموقع على المُرشَّح كلِّه */
    public function test_the_sales_list_names_the_channel_and_counts_the_website(): void
    {
        $this->sellFromWebsite(2);
        $this->sellFromPos(1);

        $props = $this->actingAs($this->owner)->get(route('admin.orders.index'))
            ->assertOk()->viewData('page')['props'];

        $byChannel = collect($props['orders'])->keyBy('channel');
        $this->assertSame('الموقع الإلكتروني', $byChannel[SalesChannel::WEBSITE]['channel_label']);
        $this->assertSame('نقطة البيع', $byChannel[SalesChannel::POS]['channel_label']);

        // والعدُّ والمبلغ من الموقع وحده
        $this->assertSame(1, $props['websiteCount']);
        $this->assertSame(40.0, $props['websiteAmount']);

        // والقنواتُ تُعرض مُرشِّحًا — من مصدرها الواحد
        $this->assertSame(
            [SalesChannel::WEBSITE, SalesChannel::POS, SalesChannel::UNKNOWN],
            array_column($props['channelOptions'], 'value'),
        );
    }

    /**
     * والمُرشِّح يفصل فعلًا — والتصديرُ يتبعه.
     *
     * ولو بقي المُرشِّح في الشاشة ولم يُحمل في `filters` لَصدّر الزرُّ الواقفُ
     * بجواره القائمةَ كلَّها، ولقرأ التاجرُ ملفًّا غير الذي ينظر إليه.
     */
    public function test_the_channel_filter_narrows_the_list_and_travels_with_the_export(): void
    {
        $web = $this->sellFromWebsite();
        $pos = $this->sellFromPos();

        // وطلبٌ سبق العمود: قناتُه فارغةٌ لا مخترعة
        DB::table('orders')->where('id', $pos->id + 1)->update(['channel' => null]);
        $old = Order::create([
            'business_id' => $this->business->id, 'number' => 'OLD-1', 'customer_name' => 'قديم',
            'branch' => 'الخوير', 'total' => 5, 'subtotal' => 5, 'status' => 'مكتمل',
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'ordered_at' => now(), 'channel' => null,
        ]);

        $only = fn (string $c) => collect(
            $this->actingAs($this->owner)->get(route('admin.orders.index', ['channel' => $c]))
                ->assertOk()->viewData('page')['props']['orders'],
        )->pluck('id')->all();

        $this->assertSame([$web->number], $only(SalesChannel::WEBSITE));
        $this->assertSame([$pos->number], $only(SalesChannel::POS));
        // و«غير محدّدة» تبلغ الفراغَ في العمود — لا تردّ صفرًا على مئاتٍ قائمة
        $this->assertSame([$old->number], $only(SalesChannel::UNKNOWN));

        $carried = $this->actingAs($this->owner)
            ->get(route('admin.orders.index', ['channel' => SalesChannel::WEBSITE]))
            ->viewData('page')['props']['filters'];

        $this->assertSame(SalesChannel::WEBSITE, $carried['channel']);
    }

    /* ═══════════ الجرس — من يُوقَظ ولمَ ═══════════ */

    /**
     * طلبُ الموقع يُقال إنّه من الموقع — ولا يُخلط بطلبٍ كتبه كاشير.
     *
     * والنصُّ هو التمييزُ كلُّه: الجرسُ لا يرسم أيقونةً ولا لونًا، يرسم
     * سطرًا. فلو تشارك البابان النصَّ لَما فرّق بينهما شيء.
     */
    public function test_the_bell_says_a_website_order_came_from_the_website(): void
    {
        $web = $this->sellFromWebsite();
        $pos = $this->sellFromPos();

        $this->actingAs($this->owner);
        $texts = array_column(Demo::allNotifications(), 'text');

        $this->assertContains('طلبٌ جديد من الموقع الإلكتروني: '.$web->number, $texts);
        $this->assertContains('طلب '.$pos->number.' بانتظار التجهيز', $texts);
    }

    /**
     * ولا يُوقَظ له إلّا من أُذن له.
     *
     * ومتجرٌ فيه عشرةٌ يفتحون «المبيعات» لا يريد عشرةَ أجراسٍ تدقّ لطلبٍ
     * واحد — يريد من يجهّزه. وبيعةُ الصندوق تبقى لكلِّهم كما كانت: لا
     * يُقطع عن أحدٍ ما كان يصله.
     */
    public function test_only_the_granted_are_woken_by_a_website_order(): void
    {
        $web = $this->sellFromWebsite();
        $pos = $this->sellFromPos();

        $webLine = 'طلبٌ جديد من الموقع الإلكتروني: '.$web->number;
        $posLine = 'طلب '.$pos->number.' بانتظار التجهيز';

        $texts = function (User $u): array {
            $this->actingAs($u);

            return array_column(Demo::allNotifications(), 'text');
        };

        $make = fn (string $email, array $over) => User::create($over + [
            'business_id' => $this->business->id, 'name' => 'موظّف', 'email' => $email,
            'password' => bcrypt('x'), 'status' => 'نشط',
        ]);

        // المحاسبُ يفتح «المبيعات» بدوره ولا يجهّز الباقات
        $accountant = $make('acc@abaad.om', ['role' => 'accountant']);
        $this->assertNotContains($webLine, $texts($accountant));
        $this->assertContains($posLine, $texts($accountant), 'ولا يُقطع عنه ما كان يصله');

        // والبائعُ يجهّز — فيرثها بدوره
        $seller = $make('sell@abaad.om', ['role' => 'sales']);
        $this->assertContains($webLine, $texts($seller));

        // ومن خُصِّصت صلاحياتُه يدويًّا يُمنحها بالاسم
        $named = $make('named@abaad.om', ['role' => 'accountant', 'permissions' => ['orders']]);
        $this->assertNotContains($webLine, $texts($named));

        $named->update(['permissions' => ['orders', Permissions::ORDER_WEBSITE_NOTIFY]]);
        $this->assertContains($webLine, $texts($named->fresh()));

        // ومن لا يفتح «المبيعات» أصلًا لا يبلغه شيءٌ منها ولو مُنح الفعل
        $cashier = $make('cash@abaad.om', ['role' => 'cashier', 'permissions' => ['pos', Permissions::ORDER_WEBSITE_NOTIFY]]);
        $this->assertNotContains($webLine, $texts($cashier));
        $this->assertNotContains($posLine, $texts($cashier));
    }

    /** والصلاحيةُ تُعرض في «صلاحيات الموظفين» — وإلّا فهي مقبضٌ لا يُرى */
    public function test_the_grant_is_offered_in_the_employee_permissions_screen(): void
    {
        $this->assertArrayHasKey(Permissions::ORDER_WEBSITE_NOTIFY, Permissions::actionLabels());
        $this->assertSame('تنبيهُ طلبات الموقع الإلكتروني', Permissions::actionLabels()[Permissions::ORDER_WEBSITE_NOTIFY]);

        $props = $this->actingAs($this->owner)->get(route('admin.employees.create'))
            ->assertOk()->viewData('page')['props'];

        $this->assertArrayHasKey(Permissions::ORDER_WEBSITE_NOTIFY, $props['actions']);
    }

    /* ═══════════ الاستدراك — ما كُتب قبل العلاج ═══════════ */

    /**
     * والحركاتُ التي كُتبت بلا نوعٍ تُستدرك بقناة طلبها لا بالظنّ.
     *
     * وهي الحالةُ القائمة على الإنتاج فعلًا: كلُّ بيعةٍ منذ أُضيف العمود.
     */
    public function test_the_backfill_writes_the_kind_that_was_missed(): void
    {
        $web = $this->sellFromWebsite();
        $pos = $this->sellFromPos();

        // نعيدها إلى حالها قبل العلاج
        DB::table('transactions')->whereNotNull('order_id')->update(['kind' => null]);

        // ومصروفٌ بلا طلبٍ لا تدّعي الهجرةُ معرفتَه
        $expense = DB::table('transactions')->insertGetId([
            'business_id' => $this->business->id, 'reference' => 'EXP-1', 'description' => 'إيجار',
            'method' => 'نقدي', 'type' => 'مصروف', 'kind' => null, 'amount' => 100,
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        (require base_path('database/migrations/2026_09_22_180000_every_money_movement_names_the_door_its_sale_came_through.php'))->up();

        $this->assertSame(Transaction::WEB_SALE, Transaction::where('order_id', $web->id)->value('kind'));
        $this->assertSame(Transaction::SALE, Transaction::where('order_id', $pos->id)->value('kind'));
        $this->assertNull(DB::table('transactions')->where('id', $expense)->value('kind'));
    }
}
