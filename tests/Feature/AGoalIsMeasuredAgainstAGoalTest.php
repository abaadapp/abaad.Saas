<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Support\Demo;
use App\Support\Website\Builder;
use App\Support\Website\Preview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رقمان صحيحا الشكل كاذبا المعنى — وحدةٌ ليست وحدته، وبيعةٌ لم تقع.
 *
 * ═══ «٢١٪» لواحدٍ وعشرين ريالًا ═══
 *
 * عمود «تحقيق الهدف» في قائمة الموظفين كان يرسم مبيعات الشهر بالريال ويُلحق
 * بها علامة `%`. فمن باع واحدًا وعشرين ريالًا يُقرأ «٢١٪»، ومن باع ألفًا
 * «١٬٠٠٠٪». ولا شيء في الشاشة يقول إنّ الوحدة ليست ما يظنّ القارئ.
 *
 * و`users.monthly_target` — الحقل الذي يملؤه التاجر في ملفّ الموظّف تحت
 * «الهدف والعمولة»، وتلميحُه يَعِد صراحةً بأنّه «يُحتسب عليه تحقيقُ الهدف في
 * قائمة الموظفين» — لم يكن يُقرأ في النظام كلِّه: يُكتب، ويُعرض في نموذجه،
 * ثمّ لا يدخل حسابًا واحدًا. مقبضٌ لا يُدير شيئًا.
 *
 * ═══ و«الأكثر مبيعًا» يعدّ ما لم يُبَع ═══
 *
 * قسمُ «الأكثر مبيعًا» في الموقع المنشور كان يعدّ كلّ سطرٍ في كلّ طلب: الملغى
 * منها والمعلَّق. فطلبٌ كبيرٌ أُلغي يرفع صنفه إلى صدر الموقع، وسلّةٌ نسيها
 * موظّفٌ مفتوحةً على شاشة الكاشير تفعل مثلها.
 *
 * وأسوأُ ما فيه أنّه يُعرض على الزبائن لا على التاجر: هو لا يفتح موقعه
 * المنشور كلّ يوم، فيبقى صنفٌ لم يُبَع في صدر الصفحة شهورًا.
 */
class AGoalIsMeasuredAgainstAGoalTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '96890000000', 'email' => 'shop@abaad.om',
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function bid(): int
    {
        return $this->business->id;
    }

    private function staff(string $name, float $target = 0): User
    {
        return User::create([
            'business_id' => $this->bid(), 'name' => $name,
            'email' => $name.'@abaad.om', 'password' => bcrypt('password'),
            'role' => 'cashier', 'status' => 'نشط', 'monthly_target' => $target,
        ]);
    }

    private function product(string $name = 'باقة', float $price = 10): Product
    {
        return Product::create([
            'business_id' => $this->bid(), 'name' => $name,
            'price' => $price, 'cost' => 4, 'quantity' => 500, 'alert_qty' => 2, 'active' => true,
        ]);
    }

    /** بيعةٌ لموظّف — بمبلغها وتاريخها */
    private function sell(?User $by, float $total, array $over = []): Order
    {
        return Order::create(array_merge([
            'business_id' => $this->bid(),
            'user_id' => $by?->id,
            'number' => 'INV-'.str_pad((string) (Order::count() + 1), 5, '0', STR_PAD_LEFT),
            'status' => 'مكتمل', 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => $total, 'tax' => 0, 'total' => $total,
            'ordered_at' => now(),
        ], $over));
    }

    /** @return array<string, mixed> صفُّ موظّفٍ بعينه كما تُرسله `Demo::employees` */
    private function row(User $u): array
    {
        return collect(Demo::employees())->firstWhere('id', $u->id) ?? [];
    }

    /* ═══════════════════ نسبةٌ تُقاس على هدف ═══════════════════ */

    public function test_the_target_column_is_a_percentage_not_an_amount(): void
    {
        $salem = $this->staff('سالم', target: 100);
        $this->sell($salem, 21);

        $row = $this->row($salem);

        // ٢١ من ١٠٠ = ٢١٪ — وهي هنا مصادفةٌ مقصودة: الرقمان يتطابقان
        $this->assertSame(21.0, $row['achieved'], 'مبيعات الشهر مبلغٌ لا يتغيّر');
        $this->assertSame(21.0, $row['target_pct']);
    }

    public function test_the_target_the_merchant_typed_is_actually_read(): void
    {
        $salem = $this->staff('سالم', target: 100);
        $this->sell($salem, 21);

        $this->assertSame(21.0, $this->row($salem)['target_pct']);

        // الهدفُ نصفُه: النسبةُ تتضاعف. ولو لم يُقرأ الحقل لبقيت ٢١
        $salem->update(['monthly_target' => 50]);

        $this->assertSame(42.0, $this->row($salem)['target_pct']);
        $this->assertSame(21.0, $this->row($salem)['achieved'], 'المبلغ لا يتبدّل بتبدّل الهدف');
    }

    public function test_whoever_has_no_target_has_no_percentage(): void
    {
        /*
         * ونموذجُ الموظّف يقول «اتركه فارغًا لبلا هدف» — فالفراغ يُحترم:
         * صفرٌ يُقرأ تقصيرًا، ومئةٌ تُقرأ إنجازًا، وكلاهما اختراع.
         */
        $nasser = $this->staff('ناصر');
        $this->sell($nasser, 300);

        $row = $this->row($nasser);

        $this->assertNull($row['target_pct']);
        $this->assertSame(300.0, $row['achieved'], 'ومبيعاتُه تُقال كاملةً');
    }

    public function test_beating_the_target_is_not_capped_at_a_hundred(): void
    {
        // القصُّ عند مئة يُخفي أحسنَ بائعٍ في المحلّ خلف من بلغ هدفه بالضبط
        $salem = $this->staff('سالم', target: 100);
        $this->sell($salem, 150);

        $this->assertSame(150.0, $this->row($salem)['target_pct']);
    }

    public function test_a_cancelled_sale_does_not_reach_the_target(): void
    {
        $salem = $this->staff('سالم', target: 100);
        $this->sell($salem, 40);
        $this->sell($salem, 60, ['status' => Order::CANCELLED]);
        $this->sell($salem, 60, ['is_held' => true]);

        $this->assertSame(40.0, $this->row($salem)['target_pct'], 'الملغى والمعلَّق ليسا بيعًا');
    }

    public function test_last_months_sales_do_not_count_toward_this_months_target(): void
    {
        $salem = $this->staff('سالم', target: 100);
        $this->sell($salem, 30);
        $this->sell($salem, 500, ['ordered_at' => now()->subMonthNoOverflow()->startOfMonth()]);

        $this->assertSame(30.0, $this->row($salem)['target_pct']);
    }

    public function test_each_employee_is_measured_against_his_own_target(): void
    {
        $salem = $this->staff('سالم', target: 100);
        $nasser = $this->staff('ناصر', target: 400);

        $this->sell($salem, 50);
        $this->sell($nasser, 50);

        $this->assertSame(50.0, $this->row($salem)['target_pct']);
        $this->assertSame(12.5, $this->row($nasser)['target_pct'], 'المبلغُ نفسه وهدفان مختلفان');
    }

    public function test_the_dashboard_still_reads_the_amount_not_the_percentage(): void
    {
        /*
         * بطاقةُ «أداء الموظفين» على لوحة التاجر ترسم `achieved` مبلغًا
         * وترتّب عليه. فتغييرُ معنى الحقل كان سيقلب البطاقةَ إلى نِسَبٍ
         * برمز عملة — ولذلك أُضيف حقلٌ ولم يُبدَّل حقل.
         */
        $salem = $this->staff('سالم', target: 1000);
        $this->sell($salem, 75);

        $props = $this->get(route('admin.dashboard'))->viewData('page')['props'];
        $top = collect($props['topEmployees'])->firstWhere('id', $salem->id);

        $this->assertSame(75.0, $top['achieved']);
        $this->assertSame(7.5, $top['target_pct']);
    }

    public function test_the_screen_draws_the_percentage_and_not_the_amount(): void
    {
        /*
         * وعلى شكل الشفرة لا على نصٍّ يمرّ به تعليقٌ لي: حارسٌ يقرأ كلمةً
         * كتبتُها في شرحي حارسٌ يحرس نفسه.
         */
        $panel = file_get_contents(resource_path('js/Pages/Admin/Settings/panels/EmployeesPanel.tsx'));

        $this->assertStringContainsString('{number(e.target_pct)}%', $panel);
        $this->assertStringNotContainsString('{number(e.achieved)}%', $panel);
        // ومن لا هدف له تُرسم له شرطة لا صفر
        $this->assertStringContainsString('e.target_pct === null ?', $panel);
    }

    public function test_the_employees_screen_ships_the_percentage(): void
    {
        $salem = $this->staff('سالم', target: 200);
        $this->sell($salem, 50);

        $props = $this->get(route('admin.employees.index'))->viewData('page')['props'];
        $row = collect($props['employees'])->firstWhere('id', $salem->id);

        $this->assertSame(25.0, $row['target_pct']);
        $this->assertSame(200.0, $row['target']);
    }

    /* ═══════════════════ والأكثرُ مبيعًا يُقاس على بيع ═══════════════════ */

    private function site(): Website
    {
        Category::create(['business_id' => $this->bid(), 'name' => 'باقات']);

        return Builder::create($this->business, 'store', 'modern', $this->owner->id);
    }

    /** قسمُ «الأكثر مبيعًا» على الصفحة الأولى — يُنشأ إن لم يكن */
    private function bestSection(Website $site, int $limit = 5, int $days = 90): void
    {
        /** @var WebsitePage $home */
        $home = $site->homePage();

        $section = WebsiteSection::where('website_id', $site->id)
            ->where('type', 'best_sellers')->first();

        if ($section) {
            $section->update(['visible' => true, 'data' => ['limit' => $limit, 'days' => $days]]);

            return;
        }

        WebsiteSection::create([
            'website_id' => $site->id, 'business_id' => $this->bid(),
            'page_id' => $home->id, 'slot' => null, 'type' => 'best_sellers',
            'position' => 99, 'visible' => true,
            'data' => ['limit' => $limit, 'days' => $days],
        ]);
    }

    /** بندٌ في طلب — بكمّيته */
    private function line(Order $order, Product $p, int $qty): void
    {
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name,
            'price' => $p->price, 'cost' => $p->cost, 'quantity' => $qty,
            'total' => $p->price * $qty,
        ]);
    }

    /** @return array<int, string> أسماءُ «الأكثر مبيعًا» بترتيبها */
    private function best(Website $site): array
    {
        return array_column(Preview::document($site->fresh())['data']['best'], 'name');
    }

    public function test_a_cancelled_order_does_not_make_a_best_seller(): void
    {
        $site = $this->site();
        $this->bestSection($site);

        $sold = $this->product('باقة صغيرة');
        $ghost = $this->product('باقة الشبح');

        $this->line($this->sell($this->owner, 10), $sold, 1);
        // طلبٌ كبيرٌ أُلغي — عشرون قطعةً لم تخرج من المحلّ
        $this->line($this->sell($this->owner, 200, ['status' => Order::CANCELLED]), $ghost, 20);

        $this->assertSame(['باقة صغيرة'], $this->best($site));
    }

    public function test_a_held_basket_does_not_make_a_best_seller(): void
    {
        $site = $this->site();
        $this->bestSection($site);

        $sold = $this->product('باقة صغيرة');
        $ghost = $this->product('سلّةٌ منسيّة');

        $this->line($this->sell($this->owner, 10), $sold, 1);
        // سلّةٌ مفتوحةٌ على شاشة الكاشير لم تُدفع بعد
        $this->line($this->sell($this->owner, 200, ['is_held' => true]), $ghost, 20);

        $this->assertSame(['باقة صغيرة'], $this->best($site));
    }

    public function test_what_was_actually_sold_ranks_by_how_much_sold(): void
    {
        $site = $this->site();
        $this->bestSection($site);

        $many = $this->product('الأكثر');
        $few = $this->product('الأقلّ');

        $this->line($this->sell($this->owner, 100), $many, 9);
        $this->line($this->sell($this->owner, 100), $few, 2);

        $this->assertSame(['الأكثر', 'الأقلّ'], $this->best($site));
    }

    public function test_a_cancellation_does_not_reorder_the_shelf(): void
    {
        $site = $this->site();
        $this->bestSection($site);

        $first = $this->product('الأوّل');
        $second = $this->product('الثاني');

        $this->line($this->sell($this->owner, 100), $first, 5);
        $this->line($this->sell($this->owner, 100), $second, 3);
        // وإلغاءٌ ضخمٌ على الثاني كان يقلب الصدارة
        $this->line($this->sell($this->owner, 900, ['status' => Order::CANCELLED]), $second, 90);

        $this->assertSame(['الأوّل', 'الثاني'], $this->best($site));
    }

    public function test_a_sale_outside_the_window_is_not_counted(): void
    {
        $site = $this->site();
        $this->bestSection($site, days: 30);

        $recent = $this->product('حديث');
        $old = $this->product('قديم');

        $this->line($this->sell($this->owner, 10), $recent, 1);
        $this->line($this->sell($this->owner, 900, ['ordered_at' => now()->subDays(200)]), $old, 90);

        $this->assertSame(['حديث'], $this->best($site));
    }

    public function test_a_tie_does_not_shuffle_the_page_on_every_order(): void
    {
        // صفحةٌ تتبدّل ترتيبًا بلا سببٍ عند كلّ طلبٍ جديد ليست صفحةً ثابتة
        $site = $this->site();
        $this->bestSection($site);

        $a = $this->product('أ');
        $b = $this->product('ب');

        $this->line($this->sell($this->owner, 100), $a, 4);
        $this->line($this->sell($this->owner, 100), $b, 4);

        $this->assertSame($this->best($site), $this->best($site));
        $this->assertSame(['أ', 'ب'], $this->best($site));
    }
}
