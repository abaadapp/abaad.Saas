<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحة النشاط: تنبيهٌ يُطلق حين يجب، وقالبٌ يحكم الأوراق الثلاث كما يَعِد.
 */
class AdminPanelAuditTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // الأوراق تُختبر بالعربية: التسميات هي ما يُبحث عنه في الناتج
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function order(Customer $c, string $status, string $when, int $n): Order
    {
        return Order::create([
            'business_id' => $this->business->id, 'customer_id' => $c->id, 'number' => $n,
            'customer_name' => $c->name, 'subtotal' => 10, 'tax' => 0, 'discount' => 0, 'total' => 10,
            'payment_method' => 'نقدي', 'status' => $status, 'is_held' => false, 'ordered_at' => $when,
        ]);
    }

    /* --------------------- تنبيه العميل المتعثّر --------------------- */

    public function test_a_cancelled_order_does_not_make_a_lapsed_customer_look_active(): void
    {
        /*
         * التنبيه الذي لا يُطلق أسوأ من غيابه: صاحبه يقرأ سكوت الشاشة
         * «لا متعثّر عندي».
         */
        $c = Customer::create(['business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '90000001']);
        $this->order($c, 'مكتمل', now()->subDays(120)->toDateTimeString(), 1);
        $this->order($c, Order::CANCELLED, now()->toDateTimeString(), 2);

        $alerts = collect(Demo::smartAlertsFor($this->business->id));

        $this->assertTrue($alerts->contains(fn ($a) => str_contains($a['text'], 'سالم')));
    }

    public function test_a_customer_who_really_bought_recently_is_not_flagged(): void
    {
        $c = Customer::create(['business_id' => $this->business->id, 'name' => 'ناصر', 'phone' => '90000002']);
        $this->order($c, 'مكتمل', now()->subDays(120)->toDateTimeString(), 1);
        $this->order($c, 'مكتمل', now()->subDay()->toDateTimeString(), 2);

        $alerts = collect(Demo::smartAlertsFor($this->business->id));

        $this->assertFalse($alerts->contains(fn ($a) => str_contains($a['text'], 'ناصر')));
    }

    public function test_a_customer_who_never_bought_is_not_called_lapsed(): void
    {
        // «متعثّر» وصفٌ لمن كان يشتري فتوقّف — لا لمن لم يبدأ
        Customer::create(['business_id' => $this->business->id, 'name' => 'جديد', 'phone' => '90000003']);

        $alerts = collect(Demo::smartAlertsFor($this->business->id));

        $this->assertFalse($alerts->contains(fn ($a) => str_contains($a['text'], 'جديد')));
    }

    public function test_the_lapsed_scan_is_one_query_not_one_per_customer(): void
    {
        foreach (range(1, 15) as $i) {
            $c = Customer::create([
                'business_id' => $this->business->id, 'name' => "عميل {$i}", 'phone' => '9000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]);
            $this->order($c, 'مكتمل', now()->subDays(120)->toDateTimeString(), $i);
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        Demo::smartAlertsFor($this->business->id);
        $reads = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "orders"'))->count();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $reads, 'صفُّ كل عميلٍ يُقرأ على حدة');
    }

    /* ------------------ القالب يحكم الأوراق الثلاث ------------------ */

    /* ------------------ القالب يحكم الأوراق الثلاث ------------------ */

    /** فاتورة A4 كما تُرسَم فعلًا — القالب هو ما يُختبر لا ملفّ الـPDF */
    private int $paper = 100;

    private function a4(array $tpl = []): string
    {
        $n = ++$this->paper;
        $c = Customer::create(['business_id' => $this->business->id, 'name' => 'شركة النور', 'phone' => '9000'.$n]);
        $order = $this->order($c, 'مكتمل', now()->toDateTimeString(), $n);
        $order->items()->create(['name' => 'صنف', 'quantity' => 1, 'price' => 10, 'total' => 10]);

        return view('pdf.invoice', [
            'order' => $order->fresh('items'),
            'qr' => '',
            'tpl' => $tpl,
            'customerTax' => null,
        ])->render();
    }

    public function test_the_a4_invoice_prints_the_header_line_the_card_promises_it_obeys(): void
    {
        // البطاقة تقول: «يحكم الإيصال المطبوع وفاتورة A4 والفاتورة الضريبية معًا»
        $this->assertStringContainsString('سطرٌ تحت الاسم', $this->a4(['tpl_header' => 'سطرٌ تحت الاسم']));
    }

    public function test_hiding_the_customer_reaches_the_a4_invoice(): void
    {
        $this->assertStringContainsString('شركة النور', $this->a4());
        $this->assertStringNotContainsString('شركة النور', $this->a4(['tpl_show_customer' => '0']));
    }

    public function test_the_items_count_toggle_reaches_the_a4_invoice(): void
    {
        $this->assertStringContainsString('عدد الأصناف', $this->a4(['tpl_show_items_count' => '1']));
        $this->assertStringNotContainsString('عدد الأصناف', $this->a4(['tpl_show_items_count' => '0']));
    }

    public function test_the_seller_tax_number_is_hideable_on_a_plain_invoice(): void
    {
        $with = $this->a4(['tpl_show_vat_no' => '1', 'vat_number' => 'OM1234567']);
        $without = $this->a4(['tpl_show_vat_no' => '0', 'vat_number' => 'OM1234567']);

        $this->assertStringContainsString('OM1234567', $with);
        $this->assertStringNotContainsString('OM1234567', $without);
    }

    public function test_the_font_size_setting_reaches_the_a4_invoice(): void
    {
        $this->assertStringContainsString('font-size: 14px', $this->a4(['tpl_font' => 'كبير']));
        $this->assertStringContainsString('font-size: 11px', $this->a4(['tpl_font' => 'صغير']));
    }
    /* ------------------ مفاتيح الإعدادات مغلقة ------------------ */

    public function test_a_key_the_screen_does_not_own_is_not_stored(): void
    {
        /*
         * كان الحفظ حرَّ المفاتيح: مقبضٌ يُسمّى بحرفٍ زائد يُكتب في مفتاحٍ
         * لا يقرؤه أحد، ويقول التنبيه «تم الحفظ» ولا يتغيّر شيء.
         */
        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            'vat_rate' => '5', 'tpl_show_qrr' => '1', 'مفتاح_مخترع' => 'قيمة',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', ['business_id' => $this->business->id, 'key' => 'vat_rate']);
        $this->assertDatabaseMissing('settings', ['key' => 'tpl_show_qrr']);
        $this->assertDatabaseMissing('settings', ['key' => 'مفتاح_مخترع']);
    }

    public function test_every_key_the_form_sends_is_accepted(): void
    {
        // القائمة المغلقة لا تُسقط مقبضًا قائمًا: هذا ما يحرس الإغلاق نفسه
        $sent = [
            'shop_name' => 'متجري', 'email' => 'a@b.om', 'phone' => '90000000', 'address' => 'مسقط',
            'vat_enabled' => true, 'vat_rate' => '5', 'vat_number' => 'OM1', 'tax_mode' => 'exclusive',
            'currency' => 'OMR', 'decimals' => '3', 'symbol_pos' => 'after',
            'pay_cash' => true, 'pay_card' => false, 'pay_transfer' => true,
            'inv_prefix' => 'INV-', 'inv_start' => '1', 'paper' => '80mm',
            'notify_new_order' => true, 'notify_smart_alerts' => true, 'notify_daily_summary' => false,
            'loyalty_enabled' => true, 'loyalty_earn_rate' => '5',
            'loyalty_redeem_max_pct' => '50', 'loyalty_redeem_min' => '100',
            'require_open_shift' => false, 'shift_max_hours' => '18',
            'tpl_header' => 'سطر', 'tpl_footer' => "شكرًا\nمرحبًا", 'tpl_font' => 'عادي',
            'tpl_show_logo' => false, 'tpl_show_branch' => true, 'tpl_show_employee' => true,
            'tpl_show_customer' => true, 'tpl_show_datetime' => true, 'tpl_show_items_count' => true,
            'tpl_show_vat_no' => false, 'tpl_show_qr' => true,
        ];

        $this->actingAs($this->owner)->post(route('admin.settings.update'), $sent)
            ->assertSessionHasNoErrors();

        foreach (array_keys($sent) as $key) {
            if (in_array($key, ['shop_name', 'email', 'phone', 'address'], true)) {
                continue; // هذه تسكن جدول النشاط
            }
            $this->assertDatabaseHas('settings', ['business_id' => $this->business->id, 'key' => $key]);
        }
    }

    public function test_a_toggle_turned_off_is_stored_as_zero_not_as_emptiness(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.update'), ['vat_enabled' => false]);

        $this->assertDatabaseHas('settings', [
            'business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0',
        ]);
        $this->assertFalse(\App\Support\Vat::enabled($this->business->id));
    }

    public function test_a_nonsense_value_is_refused_rather_than_saved(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            'shift_max_hours' => '900', 'vat_rate' => 'خمسة', 'paper' => 'A3',
        ])->assertSessionHasErrors(['shift_max_hours', 'vat_rate', 'paper']);

        $this->assertDatabaseMissing('settings', ['business_id' => $this->business->id, 'key' => 'paper']);
    }

    public function test_the_business_profile_still_lands_in_its_own_table(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            'shop_name' => 'اسمٌ جديد', 'phone' => '99887766',
        ])->assertSessionHasNoErrors();

        $this->assertSame('اسمٌ جديد', $this->business->fresh()->name);
        $this->assertDatabaseMissing('settings', ['key' => 'shop_name']);
    }
    public function test_the_screen_and_the_closed_list_do_not_drift_apart(): void
    {
        /*
         * الحارس الحقيقيّ للإغلاق.
         *
         * قائمةٌ مغلقة تُكتب مرّةً ثم يُضاف مقبضٌ إلى الشاشة ولا يُضاف إليها:
         * يتحرّك في الواجهة ويقول التنبيه «تم الحفظ» ولا يُكتب شيء — وهو
         * العطب نفسه الذي جاء الإغلاق ليمنعه، بوجهٍ مقلوب. فيُقارَن المصدران.
         */
        $tsx = file_get_contents(resource_path('js/Pages/Admin/Settings/Index.tsx'));
        $start = strpos($tsx, 'const form = useForm({');
        $this->assertNotFalse($start, 'لم يُعثر على نموذج الإعدادات في الشاشة');

        $depth = 0;
        $open = strpos($tsx, '{', $start);
        for ($i = $open; $i < strlen($tsx); $i++) {
            if ($tsx[$i] === '{') { $depth++; }
            elseif ($tsx[$i] === '}') { $depth--; if ($depth === 0) { break; } }
        }
        preg_match_all('/^\s{8}([a-z_0-9]+):/m', substr($tsx, $open, $i - $open), $m);

        $onScreen = array_unique($m[1]);
        $reflection = new \ReflectionClass(\App\Http\Controllers\Admin\SettingController::class);
        $allowed = array_keys($reflection->getConstant('KEYS'));

        $this->assertSame([], array_values(array_diff($onScreen, $allowed)), 'مقبضٌ في الشاشة لا يقبله الحفظ');
        $this->assertSame([], array_values(array_diff($allowed, $onScreen)), 'مفتاحٌ مسموح لا مقبض له في الشاشة');
    }
}
