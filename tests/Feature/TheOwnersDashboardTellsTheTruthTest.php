<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحةُ صاحب النشاط تقول ما وقع — لا ما يسهل جمعه.
 *
 * ═══ صافي ربحٍ بلا تكلفة بضاعة ═══
 *
 * البطاقة الثامنة كانت `المبيعات − المصروفات`: بلا تكلفةِ بضاعةٍ أصلًا،
 * وبالضريبة داخلَ الإيراد. فمحلٌّ باع بألفٍ اشتراه بستّمئة وأنفق مئتين يقرأ
 * «صافي ربح ٨٠٠» وربحُه مئتان.
 *
 * والتعريفُ الصحيح مكتوبٌ في هذا الملفّ نفسه منذ أُصلحت شاشةُ التقارير
 * (`Demo::profitStats`): «(المبيعات − الضريبة) − تكلفة البضاعة المباعة −
 * المصروفات». فأُصلحت الشاشة وبقيت اللوحة — وهي أوّل ما يفتحه صاحب المحلّ
 * كلَّ صباح.
 *
 * ═══ وبطاقةُ أداءٍ تقول صفرًا دائمًا ═══
 *
 * «أداء الموظفين» كانت تقرأ `users.sales_total` — عمودًا **لا يكتبه شيءٌ في
 * النظام**: لا بيعةٌ تزيده ولا وردية. فتُعرض أصفارٌ لكلّ موظّفٍ منذ فتح
 * المحلّ، ومبيعاتُهم الحقيقية محسوبةٌ في الحمولة نفسها ولا تُرسم.
 *
 * وترتيبُها كان بالمعرّف: أقدمُ خمسةِ موظّفين لا أعلاهم بيعًا. فمحلٌّ بثمانية
 * لا يرى ثلاثةً منهم أبدًا، ولو كانوا أعلى الجميع.
 *
 * ═══ و«أفضل المنتجات» أقدمُها ═══
 *
 * كانت `Demo::products()` مرتّبةً بالمعرّف ثمّ تُقتطع خمسةً — أقدمُ خمسةِ
 * أصناف، بأسعارها لا بمبيعاتها. صنفٌ لم يُبَع مرّةً يتصدّر «الأفضل»، وصنفٌ
 * يحمل المحلّ لا يُذكر.
 *
 * ═══ وثلاثةُ جداولَ تُحمَّل لستّة عشر سطرًا ═══
 */
class TheOwnersDashboardTellsTheTruthTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Branch $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        $this->main = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function product(string $name, float $price, float $cost): Product
    {
        return Product::create([
            'business_id' => $this->business->id, 'name' => $name,
            'price' => $price, 'cost' => $cost, 'quantity' => 100, 'alert_qty' => 2,
        ]);
    }

    /** بيعةٌ حقيقية: طلبٌ مباعٌ وبندٌ فيه بتكلفته */
    private function sell(Product $p, int $qty, float $tax = 0, array $over = []): Order
    {
        $total = $p->price * $qty + $tax;

        $order = Order::create(array_merge([
            'business_id' => $this->business->id,
            'branch_id' => $this->main->id,
            'number' => 'INV-'.str_pad((string) (Order::count() + 1), 5, '0', STR_PAD_LEFT),
            'status' => 'مكتمل', 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => $p->price * $qty, 'tax' => $tax, 'total' => $total,
            'ordered_at' => now(),
        ], $over));

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'name' => $p->name,
            'price' => $p->price, 'cost' => $p->cost, 'quantity' => $qty,
            'total' => $p->price * $qty,
        ]);

        return $order;
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        return $this->get(route('admin.dashboard'))->viewData('page')['props'];
    }

    private function card(string $label): array
    {
        return collect($this->props()['stats'])->firstWhere('label', __($label)) ?? [];
    }

    /**
     * الرقمُ وحده من نصّ البطاقة.
     *
     * والمقارنة عليه لا على النصّ كاملًا: رمزُ العملة يتبع لغةَ الطلب
     * وعملةَ العرض، فاختبارٌ يقارن «ر.ع» يحمرّ يوم تُبدَّل اللغة لا يوم
     * يعطب الحساب — وحارسٌ يكذب أسوأ من غيابه.
     */
    private function amount(string $value): float
    {
        return (float) preg_replace('/[^0-9.\-]/u', '', str_replace(',', '', $value));
    }

    /* ============================ صافي الأرباح ============================ */

    public function test_the_net_profit_subtracts_the_cost_of_what_was_sold(): void
    {
        $rose = $this->product('باقة', 100, 60);
        $this->sell($rose, 10);                                  // بيع 1000، تكلفة 600
        Expense::create([
            'business_id' => $this->business->id, 'type' => 'إيجار',
            'amount' => 200, 'status' => 'مدفوع', 'spent_at' => now(),
        ]);

        // 1000 − 0 ضريبة − 600 تكلفة − 200 مصروف = 200
        $this->assertSame(200.0, $this->amount($this->card('صافي الأرباح')['value']));
    }

    /** والضريبةُ التزامٌ يُورَّد لا إيرادٌ يُملك */
    public function test_the_tax_is_not_counted_as_profit(): void
    {
        $rose = $this->product('باقة', 100, 0);
        $this->sell($rose, 1, tax: 5);                           // الإجمالي 105 منها 5 ضريبة

        $this->assertSame(100.0, $this->amount($this->card('صافي الأرباح')['value']));
    }

    /** ومحلٌّ لم يبع شيئًا لا ربح له ولا خسارة مخترعة */
    public function test_an_empty_month_reads_zero(): void
    {
        $this->assertSame(0.0, $this->amount($this->card('صافي الأرباح')['value']));
    }

    /** ومصروفٌ يفوق الربح يُقرأ سالبًا لا يُخبَّأ */
    public function test_a_loss_is_shown_as_a_loss(): void
    {
        $rose = $this->product('باقة', 100, 60);
        $this->sell($rose, 1);
        Expense::create([
            'business_id' => $this->business->id, 'type' => 'إيجار',
            'amount' => 500, 'status' => 'مدفوع', 'spent_at' => now(),
        ]);

        $this->assertSame(-460.0, $this->amount($this->card('صافي الأرباح')['value']));
    }

    /**
     * والتكلفة تتبع الفرع كما تتبعه المبيعات.
     *
     * بطاقاتُ اللوحة كلُّها مقيَّدةٌ بالفرع المختار. فلو بقيت التكلفة على
     * المتجر كلِّه لَقرأ صاحبُ فرعٍ صغيرٍ خسارةً من تكلفة بضاعةٍ باعها غيرُه.
     */
    public function test_the_cost_follows_the_chosen_branch(): void
    {
        $other = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        $rose = $this->product('باقة', 100, 60);

        $this->sell($rose, 1);                                    // الرئيسي
        $this->sell($rose, 5, over: ['branch_id' => $other->id]); // الخوير

        $this->get(route('admin.branch.switch', $this->main->id));

        // الرئيسي وحده: 100 − 60 = 40
        $this->assertSame(40.0, $this->amount($this->card('صافي الأرباح')['value']));
    }

    /* ============================ أداء الموظفين ============================ */

    private function cashier(string $name, string $email): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => $name, 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
    }

    public function test_the_staff_card_shows_what_they_actually_sold(): void
    {
        $sara = $this->cashier('سارة', 's@abaad.om');
        $rose = $this->product('باقة', 100, 60);
        $this->sell($rose, 3, over: ['user_id' => $sara->id]);

        $row = collect($this->props()['topEmployees'])->firstWhere('name', 'سارة');

        $this->assertNotNull($row);
        $this->assertSame(300.0, (float) $row['achieved'], 'بطاقة الأداء تقرأ عمودًا لا يكتبه شيء');
    }

    /** والترتيب بالبيع لا بالأقدميّة */
    public function test_the_staff_card_is_ranked_by_sales(): void
    {
        $old = $this->cashier('قديم', 'old@abaad.om');
        $new = $this->cashier('جديد', 'new@abaad.om');
        $rose = $this->product('باقة', 100, 60);

        $this->sell($rose, 1, over: ['user_id' => $old->id]);
        $this->sell($rose, 9, over: ['user_id' => $new->id]);

        $names = collect($this->props()['topEmployees'])->pluck('name')->all();

        $this->assertSame('جديد', $names[0], 'أوّل الأداء أقدمُ الموظّفين لا أعلاهم بيعًا');
    }

    /** ولا يسقط الأعلى بيعًا لأنّه أحدثُ من خمسةٍ قبله */
    public function test_the_top_seller_is_never_cut_off_by_seniority(): void
    {
        foreach (range(1, 6) as $i) {
            $this->cashier('موظف '.$i, "e{$i}@abaad.om");
        }
        $star = $this->cashier('النجم', 'star@abaad.om');
        $rose = $this->product('باقة', 100, 60);
        $this->sell($rose, 4, over: ['user_id' => $star->id]);

        $names = collect($this->props()['topEmployees'])->pluck('name')->all();

        $this->assertContains('النجم', $names);
    }

    /**
     * والعمودُ الميّت يُرفع من مصدره لا من البطاقة وحدها.
     *
     * `Demo::employees()` يقرؤها معها عمودُ «المبيعات» في الإعدادات و«إجمالي
     * المبيعات» على ملفّ الموظّف ومتوسّطُ الطلب المشتقّ منه. فلو صُحّحت
     * البطاقةُ وحدها لَبقيت ثلاثُ شاشاتٍ تعرض أصفارًا.
     */
    public function test_an_employees_lifetime_sales_are_real(): void
    {
        $sara = $this->cashier('سارة', 's2@abaad.om');
        $rose = $this->product('باقة', 100, 60);

        $this->sell($rose, 2, over: ['user_id' => $sara->id, 'ordered_at' => now()->subMonths(4)]);
        $this->sell($rose, 1, over: ['user_id' => $sara->id]);

        $row = collect(Demo::employees())->firstWhere('name', 'سارة');

        $this->assertSame(300.0, (float) $row['sales'], 'إجمالي مبيعات الموظّف يُقرأ من عمودٍ لا يكتبه شيء');
    }

    /** و«ما باعه منذ التحق» غيرُ «ما حقّقه هذا الشهر» — رقمان لا رقمٌ مكرّر */
    public function test_lifetime_and_this_month_are_two_numbers(): void
    {
        $sara = $this->cashier('سارة', 's3@abaad.om');
        $rose = $this->product('باقة', 100, 60);

        $this->sell($rose, 5, over: ['user_id' => $sara->id, 'ordered_at' => now()->subMonths(3)]);
        $this->sell($rose, 1, over: ['user_id' => $sara->id]);

        $row = collect(Demo::employees())->firstWhere('name', 'سارة');

        $this->assertSame(600.0, (float) $row['sales']);
        $this->assertSame(100.0, (float) $row['achieved']);
    }

    /**
     * ولكلٍّ ما باع هو — لا مجموعُ المحلّ.
     *
     * والفحص على اثنين ببيعتين مختلفتين ومن لم يبع: الأوّلان يمسكان خلطَ
     * الصفوف، والثالث يمسك من يُحسب له ما لم يبعه.
     */
    public function test_each_one_carries_only_what_they_sold(): void
    {
        $sara = $this->cashier('سارة', 's4@abaad.om');
        $omar = $this->cashier('عمر', 'o2@abaad.om');
        $this->cashier('هدوء', 'q@abaad.om');
        $rose = $this->product('باقة', 100, 60);

        $this->sell($rose, 3, over: ['user_id' => $omar->id]);
        $this->sell($rose, 1, over: ['user_id' => $sara->id]);

        $rows = collect(Demo::employees())->keyBy('name');

        $this->assertSame(300.0, (float) $rows['عمر']['sales']);
        $this->assertSame(100.0, (float) $rows['سارة']['sales']);
        $this->assertSame(0.0, (float) $rows['هدوء']['sales']);
    }

    /* ============================ أفضل المنتجات ============================ */

    public function test_the_best_products_are_the_ones_that_sold(): void
    {
        $slow = $this->product('صنف قديم', 10, 5);
        $star = $this->product('باقة الورد', 100, 60);

        $this->sell($star, 5);

        $rows = $this->props()['topProducts'];

        $this->assertSame('باقة الورد', $rows[0]['name']);
        $this->assertSame(5, (int) $rows[0]['sold']);
    }

    /** وصنفٌ لم يُبَع لا يتصدّر «الأفضل» */
    public function test_a_product_that_never_sold_is_not_best(): void
    {
        $this->product('صنف قديم', 10, 5);
        $star = $this->product('باقة', 100, 60);
        $this->sell($star, 1);

        $names = collect($this->props()['topProducts'])->pluck('name')->all();

        $this->assertNotContains('صنف قديم', $names);
    }

    /** والملغى لا يُصعّد صنفًا */
    public function test_a_cancelled_sale_does_not_crown_a_product(): void
    {
        $a = $this->product('أ', 10, 5);
        $b = $this->product('ب', 10, 5);

        $this->sell($a, 100, over: ['status' => 'ملغي']);
        $this->sell($b, 2);

        $this->assertSame('ب', $this->props()['topProducts'][0]['name']);
    }

    /* ============================ الثمن ============================ */

    /**
     * واللوحةُ لا تُحمّل جداولها لتعرض ستّة عشر سطرًا.
     *
     * كانت تقرأ كلَّ طلبٍ وكلَّ منتجٍ في المتجر ثمّ تقتطع في PHP: ستّةً من
     * ستّمئة، وخمسةً من خمسمئة.
     */
    public function test_the_dashboard_does_not_load_the_whole_tables(): void
    {
        $rose = $this->product('باقة', 100, 60);
        foreach (range(1, 40) as $i) {
            $this->sell($rose, 1);
        }
        foreach (range(1, 40) as $i) {
            $this->product('صنف '.$i, 10, 5);
        }

        $orders = 0;
        $products = 0;
        Order::retrieved(function () use (&$orders) {
            $orders++;
        });
        Product::retrieved(function () use (&$products) {
            $products++;
        });

        $this->get(route('admin.dashboard'))->assertSuccessful();

        $this->assertLessThanOrEqual(12, $orders, "اللوحة بنت {$orders} طلبًا لتعرض ستّة");
        $this->assertLessThanOrEqual(15, $products, "اللوحة بنت {$products} منتجًا لتعرض خمسة");
    }

    /** وأحدثُ الطلبات ستّةٌ، أحدثُها أوّلًا */
    public function test_the_recent_orders_are_six_newest_first(): void
    {
        $rose = $this->product('باقة', 100, 60);
        foreach (range(1, 9) as $i) {
            $this->sell($rose, 1, over: ['ordered_at' => now()->subDays(10 - $i)]);
        }

        $rows = $this->props()['recentOrders'];

        $this->assertCount(6, $rows);
        $this->assertSame(
            collect($rows)->pluck('date')->sortDesc()->values()->all(),
            collect($rows)->pluck('date')->all(),
            'أحدث الطلبات ليست مرتّبةً بالأحدث',
        );
    }
}
