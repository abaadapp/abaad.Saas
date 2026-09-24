<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use App\Support\Reports;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «كم باع موقعي؟» — والجوابُ في الدفتر وفي التقرير معًا.
 *
 * ═══ العطب ═══
 *
 * القناةُ كانت مكتوبةً على الطلب (`orders.channel`) وتُقرأ في شاشة الطلبات
 * والمواسم — ولا يبلغ الدفترَ منها حرف: بيعةُ الصندوق وبيعةُ الموقع تُقيَّدان
 * في «إيراد المبيعات» نفسِه. فمن سأل محاسبَه «ما نصيبُ المتجر الإلكترونيّ من
 * إيرادي؟» لم يجد في دفتره جوابًا، ولا في «ملخّص المبيعات» مُرشِّحًا.
 *
 * ═══ وما يُحرَس ═══
 *
 * ورقةٌ للموقع تحت «الإيرادات» نفسِها — فلا يتبدّل مجموعُ الإيراد بحرف؛
 * ومُرشِّحُ قناةٍ على التقرير يحمل نفسه في الفترة وفي الملفّات؛ و**أنّ
 * المصروفات لا تُقسَم على القنوات** — وهي أخطرُ ما هنا.
 */
class TheBookTellsWhatTheWebsiteSoldTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-10 10:00:00');
        $this->app->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط']);

        MarketingSettings::save($this->shop->id, 'website', ['store_on' => '1', 'store_pay_cod' => '1']);

        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);
        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 10, 'category_id' => $cat->id,
            'cost' => 4, 'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** بيعةٌ بقناتها — تُكتب ويُرحَّل قيدُها كما يُرحَّل قيدُ أيّ بيعة */
    private function sell(?string $channel, float $total, int $qty = 1): Order
    {
        $customer = Customer::firstOrCreate(
            ['business_id' => $this->shop->id, 'phone' => '96899110001'],
            ['name' => 'مريم', 'language' => 'ar'],
        );

        $order = Order::create([
            'business_id' => $this->shop->id, 'number' => 'INV-'.uniqid(),
            'customer_id' => $customer->id, 'customer_name' => 'مريم',
            'branch_id' => Branch::where('business_id', $this->shop->id)->value('id'),
            'channel' => $channel,
            'employee_name' => $channel === SalesChannel::WEBSITE ? 'الموقع الإلكتروني' : 'سعود',
            'user_id' => $channel === SalesChannel::WEBSITE ? null : $this->owner->id,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => $total,
            'ordered_at' => now(), 'status' => 'مكتمل',
        ]);

        $order->items()->create([
            'product_id' => $this->product->id,
            'name' => $this->product->name, 'quantity' => $qty,
            'price' => $total / max(1, $qty), 'cost' => 4, 'total' => $total,
        ]);

        Books::recordSale($order->refresh());

        return $order;
    }

    private function leaf(string $key): Account
    {
        return Account::where('business_id', $this->shop->id)->where('system_key', $key)->firstOrFail();
    }

    private function credited(string $key): float
    {
        return (float) JournalLine::where('account_id', $this->leaf($key)->id)->sum('credit');
    }

    /* ═══════════ الدفتر ═══════════ */

    /**
     * ═══ وورقةُ الموقع أختُ «إيراد المبيعات» لا ابنتُه ═══
     *
     * والسببُ أنّ الدفترَ لا يُعاد كتابتُه: مبيعاتُ كلّ متجرٍ مرحَّلةٌ إلى
     * 4100 منذ أوّل يوم. ولو صار أبًا لَامتنع الترحيلُ إليه
     * (`Account::isPostable`) فسقطت كلُّ بيعةِ صندوقٍ بعد الترقية.
     */
    public function test_the_website_leaf_sits_beside_sales_not_under_it(): void
    {
        $sales = $this->leaf('sales');
        $web = $this->leaf('sales_website');

        $this->assertSame($sales->parent_id, $web->parent_id, 'الورقتان تحت أبٍ واحد');
        $this->assertNotSame($sales->id, $web->parent_id, 'ورقةُ الموقع ليست ابنةً لـ«إيراد المبيعات»');
        $this->assertTrue($sales->isPostable(), '«إيراد المبيعات» ما زال يقبل الترحيل');
        $this->assertTrue($web->isPostable());
        $this->assertSame('إيراد', $web->type);
        $this->assertSame('credit', $web->normal_side);
    }

    public function test_a_website_sale_credits_its_own_leaf(): void
    {
        $this->sell(SalesChannel::WEBSITE, 30);

        $this->assertSame(30.0, $this->credited('sales_website'));
        $this->assertSame(0.0, $this->credited('sales'));
    }

    /** وبيعةُ الصندوق — وما لا قناةَ له — تبقيان حيث كانتا */
    public function test_the_till_and_the_channelless_stay_where_they_were(): void
    {
        $this->sell(SalesChannel::POS, 20);
        $this->sell(null, 7);

        $this->assertSame(27.0, $this->credited('sales'));
        $this->assertSame(0.0, $this->credited('sales_website'));
    }

    /**
     * ومجموعُ الإيراد لا يتبدّل بحرف — وهو أثقلُ ما هنا.
     *
     * فالتقسيمُ إن مسّ المجموع صار تزويرًا لا تفصيلًا: من يقرأ قائمةَ الدخل
     * بعد الترقية يجب أن يجد الرقمَ نفسَه الذي كان يقرؤه قبلها.
     */
    public function test_the_revenue_total_does_not_move(): void
    {
        $this->sell(SalesChannel::WEBSITE, 30);
        $this->sell(SalesChannel::POS, 20);

        $revenue = Account::where('business_id', $this->shop->id)->where('code', '4')->firstOrFail();
        $leaves = Account::where('business_id', $this->shop->id)->where('parent_id', $revenue->id)->pluck('id');

        $this->assertSame(50.0, (float) JournalLine::whereIn('account_id', $leaves)->sum('credit'));
        $this->assertTrue(Ledger::trialBalance($this->shop->id)['balanced']);
    }

    /**
     * ومتجرٌ بُنيت شجرتُه قبل هذه النسخة يجد الورقةَ حين يحتاجها.
     *
     * `seedChart` لا تلمس شجرةً قائمة — وهو الصواب. ولولا استدراكُ
     * `ensureSystemAccounts` لَسقطت أوّلُ بيعةِ موقعٍ عند كلّ متجرٍ قديم
     * برسالة «حسابٌ غير موجود» لا يفهمها أحد.
     */
    public function test_an_older_chart_gains_the_leaf_when_it_is_needed(): void
    {
        $this->leaf('sales_website')->delete();

        $this->sell(SalesChannel::WEBSITE, 15);

        $this->assertSame(15.0, $this->credited('sales_website'));
    }

    /**
     * ونقضُ القيد يردّ المبلغ إلى الورقة التي خرج منها.
     *
     * `Ledger::reverse` تبني سطورَها من القيد المُرحَّل نفسِه. ولو حُسبت
     * الورقةُ من جديد لَنقض تصحيحُ طلبٍ قديم في ورقةٍ لم يُقيَّد فيها —
     * فبقي رصيدٌ في واحدة وسالبٌ في أختها.
     */
    public function test_unposting_a_website_sale_returns_it_to_its_own_leaf(): void
    {
        $order = $this->sell(SalesChannel::WEBSITE, 30);

        Books::unpostSale($order->refresh(), $this->owner->id, 'إلغاء');

        $web = $this->leaf('sales_website');

        $this->assertSame(30.0, (float) JournalLine::where('account_id', $web->id)->sum('credit'));
        $this->assertSame(30.0, (float) JournalLine::where('account_id', $web->id)->sum('debit'));
        $this->assertSame(0.0, round($web->balance(), 3));
        $this->assertTrue(Ledger::trialBalance($this->shop->id)['balanced']);
    }

    /* ═══════════ التقرير ═══════════ */

    private function report(?string $channel): array
    {
        $this->actingAs($this->owner);

        return Reports::salesReport('month', $channel);
    }

    public function test_the_report_reads_one_channel_alone(): void
    {
        $this->sell(SalesChannel::WEBSITE, 30, 3);
        $this->sell(SalesChannel::POS, 20, 2);

        $this->assertSame(50.0, $this->report(null)['summary']['sales']);
        $this->assertSame(30.0, $this->report(SalesChannel::WEBSITE)['summary']['sales']);
        $this->assertSame(20.0, $this->report(SalesChannel::POS)['summary']['sales']);
    }

    /** والتكلفةُ تتبع القناة كما تتبعها المبيعات — وإلّا قُرئ ربحٌ لم يُكسب */
    public function test_the_cost_follows_the_channel_too(): void
    {
        $this->sell(SalesChannel::WEBSITE, 30, 3);
        $this->sell(SalesChannel::POS, 20, 2);

        $this->assertSame(12.0, $this->report(SalesChannel::WEBSITE)['summary']['cogs']);
        $this->assertSame(8.0, $this->report(SalesChannel::POS)['summary']['cogs']);
        $this->assertSame(20.0, $this->report(null)['summary']['cogs']);
    }

    /**
     * ═══ والمصروفاتُ لا تُقسَم على القنوات ═══
     *
     * وهذا أخطرُ ما في هذا الملفّ. إيجارُ المحلّ وراتبُ الموظّف على المتجر
     * كلِّه، ولا في النظام ما يقول كم منها على المتجر الإلكترونيّ. فلو طُرحت
     * كاملةً من مبيعات قناةٍ واحدة لَقرأ صاحبُها أنّ موقعه خاسرٌ وهو رابح —
     * وعلى هذا الرقم يُتّخذ قرارُ إغلاق.
     *
     * فالربحُ لقناةٍ **مُجمل** لا صافٍ، والاسمُ يُرسَل مع الرقم.
     */
    public function test_expenses_are_not_split_across_channels(): void
    {
        \App\Models\Expense::create([
            'business_id' => $this->shop->id, 'type' => 'إيجار', 'description' => 'إيجار المحلّ',
            'amount' => 100, 'spent_at' => now(), 'status' => 'مدفوع',
        ]);

        $this->sell(SalesChannel::WEBSITE, 30, 3);

        $all = $this->report(null)['summary'];
        $web = $this->report(SalesChannel::WEBSITE)['summary'];

        // المتجرُ كلُّه: ٣٠ − ٠ ضريبة − ١٢ تكلفة − ١٠٠ مصروف = −٨٢، وهو صافٍ
        $this->assertSame('net', $all['profit_kind']);
        $this->assertSame(100.0, $all['expenses']);
        $this->assertSame(-82.0, $all['profit']);

        // والموقعُ وحده: ٣٠ − ١٢ = ١٨، وهو مُجمل — ولا مصروفَ يُنسب إليه
        $this->assertSame('gross', $web['profit_kind']);
        $this->assertSame(0.0, $web['expenses']);
        $this->assertSame(18.0, $web['profit']);
    }

    /** وما لا يُحسب لقناةٍ لا يُرشَّح بها: عددُ الأصناف والزبائن حالُ الآن لا حصيلةُ باب */
    public function test_what_has_no_channel_is_not_filtered_by_it(): void
    {
        $this->sell(SalesChannel::POS, 20);

        $all = $this->report(null)['summary'];
        $web = $this->report(SalesChannel::WEBSITE)['summary'];

        foreach (['products', 'customers', 'employees', 'inventory_alerts'] as $key) {
            $this->assertSame($all[$key], $web[$key], $key.' رُشّح بقناةٍ لا شأنَ له بها');
        }
    }

    /** والمنحنى وتوزيعُ الدفع والأكثرُ مبيعًا يتبعون القناة كذلك */
    public function test_the_chart_and_the_lists_follow_the_channel(): void
    {
        $this->sell(SalesChannel::WEBSITE, 30, 3);
        $this->sell(SalesChannel::POS, 20, 2);

        $web = $this->report(SalesChannel::WEBSITE);

        $this->assertSame(30.0, round(array_sum(array_filter($web['salesSeries']['data'])), 3));
        $this->assertSame(30.0, round(array_sum($web['paymentDistribution']['series']), 3));
        $this->assertSame(3, (int) $web['topSellingProducts'][0]['sold']);
    }

    /** وقناةٌ لا تصحّ تُقرأ «المتجر كلُّه» — تقريرٌ يُقرأ لا يُردّ بشاشةٍ حمراء */
    public function test_a_channel_that_is_no_channel_reads_the_whole_store(): void
    {
        $this->sell(SalesChannel::POS, 20);

        $this->assertNull($this->report('بابٌ لا وجود له')['channel']);
        $this->assertSame(20.0, $this->report('بابٌ لا وجود له')['summary']['sales']);
    }

    /** والقنواتُ تُرسَل إلى الشاشة من مصدرها — لا تُكتب فيها */
    public function test_the_screen_is_sent_the_channels_from_their_one_source(): void
    {
        $props = $this->actingAs($this->owner)
            ->get(route('admin.reports.sales', ['channel' => SalesChannel::WEBSITE]))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(SalesChannel::WEBSITE, $props['channel']);
        $this->assertSame(SalesChannel::options(), $props['channels']);
    }

    /**
     * والملفّاتُ الثلاثةُ ولقمةُ التحديث تقرأ القناةَ كما تقرؤها الشاشة.
     *
     * ولولا ذلك لَخرج ملفٌّ بغير ما على الشاشة — وهو يُرسَل إلى محاسب.
     */
    public function test_the_feed_carries_the_channel_too(): void
    {
        $this->sell(SalesChannel::WEBSITE, 30);
        $this->sell(SalesChannel::POS, 20);

        $feed = $this->actingAs($this->owner)
            ->getJson(route('admin.reports.feed', ['range' => 'month', 'channel' => SalesChannel::WEBSITE]))
            ->assertOk()->json();

        $this->assertSame(30.0, (float) data_get($feed, 'summary.sales'));
    }
}
