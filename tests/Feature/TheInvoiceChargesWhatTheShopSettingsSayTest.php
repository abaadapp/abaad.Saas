<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فاتورةُ العميل تحتسب بإعدادات المتجر — لا بإعداداتٍ لها وحدها.
 *
 * ثلاثةُ قرّاءٍ كانوا يسألون السؤالَ نفسَه بثلاث صيغ: `compute` وشاشةُ
 * الإنشاء و`Vat`. فافترقوا في موضعين، وكلاهما مال:
 *
 * ١) **«مشمولة في السعر»** — مفتاحٌ يحكم المتجرَ كلَّه، تقرؤه نقطةُ البيع
 *    وتصحيحُ الطلب، وكانت هذه وحدَها تُضيف الضريبة فوق سعرٍ يشملها. فتاجرٌ
 *    يسعّر شاملًا يكتب ١٠٥ فتخرج ورقتُه بـ١١٠٫٢٥: يُطالَب عميلُه بضريبةٍ
 *    مرّتين، ويُقرّ هو بما لم يقبض.
 *
 * ٢) **نسبةُ المنصّة** — `Vat::rate` تهبط إليها قبل الخمسة، وهي التي تقرؤها
 *    نقطةُ البيع. وكانت `compute` تسقط إلى خمسة مباشرةً: الكاشيرُ يجبي عشرة
 *    وفاتورةُ العميل تطلب خمسة، في المتجر نفسه واليوم نفسه.
 */
class TheInvoiceChargesWhatTheShopSettingsSayTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة', 'phone' => '90000000',
        ]);

        $this->actingAs($this->owner);
    }

    private function set(?int $bid, string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => $bid, 'key' => $key], ['value' => $value]);
    }

    /* ═══════════ «مشمولة في السعر» ═══════════ */

    public function test_an_inclusive_price_does_not_grow_by_its_own_tax(): void
    {
        $this->set($this->business->id, 'tax_mode', 'inclusive');
        $this->set($this->business->id, 'vat_rate', '5');

        $t = CustomerInvoices::compute($this->business->id, [
            ['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 105],
        ]);

        // ما كُتب على الرفّ هو ما يدفعه العميل
        $this->assertSame(105.0, $t['total'], 'سعرٌ شاملٌ نما بضريبته');
        $this->assertSame(5.0, $t['tax']);
        $this->assertSame(100.0, $t['subtotal'], 'المجموعُ الفرعيُّ يجب أن يكون صافيًا حين تكون الضريبة مشمولة');
    }

    public function test_exclusive_still_adds_the_tax_on_top(): void
    {
        $this->set($this->business->id, 'tax_mode', 'exclusive');
        $this->set($this->business->id, 'vat_rate', '5');

        $t = CustomerInvoices::compute($this->business->id, [
            ['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100],
        ]);

        $this->assertSame(105.0, $t['total']);
        $this->assertSame(5.0, $t['tax']);
        $this->assertSame(100.0, $t['subtotal']);
    }

    public function test_the_lines_add_up_to_the_paper_in_both_modes(): void
    {
        $lines = [
            ['description' => 'أ', 'quantity' => 2, 'unit_price' => 30, 'discount' => 5],
            ['description' => 'ب', 'quantity' => 1, 'unit_price' => 105],
        ];

        foreach (['exclusive', 'inclusive'] as $mode) {
            $this->set($this->business->id, 'tax_mode', $mode);
            $this->set($this->business->id, 'vat_rate', '5');

            $t = CustomerInvoices::compute($this->business->id, $lines);
            $sum = round(array_sum(array_column($t['items'], 'line_total')), 3);

            // الخاصّيّةُ التي يُراجَع بها ورقٌ رسميّ: البنودُ تجمع إلى مجموعها
            $this->assertSame(
                $t['total'],
                $sum,
                "مجموعُ البنود لا يساوي إجماليَّ الورقة في «{$mode}»",
            );
            $this->assertSame(
                $t['total'],
                round($t['subtotal'] - $t['discount'] + $t['tax'], 3),
                "المجاميعُ لا تتّسق في «{$mode}»",
            );
        }
    }

    public function test_switching_the_mode_off_beats_both(): void
    {
        $this->set($this->business->id, 'tax_mode', 'inclusive');
        $this->set($this->business->id, 'vat_enabled', '0');

        $t = CustomerInvoices::compute($this->business->id, [
            ['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 105],
        ]);

        $this->assertSame(0.0, $t['tax'], 'متجرٌ أطفأ ضريبته لا تُستخرَج منه ضريبة');
        $this->assertSame(105.0, $t['total']);
    }

    /* ═══════════ نسبةُ المنصّة ═══════════ */

    public function test_the_platform_rate_reaches_the_invoice_like_it_reaches_the_till(): void
    {
        Setting::where('business_id', $this->business->id)->where('key', 'vat_rate')->delete();
        $this->set(null, 'vat_rate', '10');

        $t = CustomerInvoices::compute($this->business->id, [
            ['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 100],
        ]);

        $this->assertSame(Vat::rate($this->business->id), 10.0);
        $this->assertSame(10.0, $t['tax'], 'الكاشيرُ يجبي نسبةً والفاتورةُ تطلب أخرى');
    }

    public function test_the_screen_is_told_the_same_rate_the_server_will_charge(): void
    {
        Setting::where('business_id', $this->business->id)->where('key', 'vat_rate')->delete();
        $this->set(null, 'vat_rate', '10');
        $this->set($this->business->id, 'tax_mode', 'inclusive');

        $props = $this->get(route('admin.customerInvoices.create'))->viewData('page')['props'];

        $this->assertSame(10.0, $props['tax_rate'], 'الشاشةُ تُظهر نسبةً والخادمُ يحتسب غيرَها');
        $this->assertTrue($props['tax_inclusive'], 'الشاشةُ لا تعرف أنّ الأسعار شاملة فتحسب بقاعدةٍ أخرى');
    }

    /* ═══════════ والبابان يقبلان ما يقبله الآخر ═══════════ */

    public function test_the_preview_refuses_what_the_save_refuses(): void
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'payment_method' => 'آجل',
            'items' => [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 10, 'discount' => -50]],
        ];

        // ولا تُعايَن ورقةٌ لا تُحفظ
        $this->post(route('admin.customerInvoices.preview'), $payload)
            ->assertSessionHasErrors('items.0.discount');
        $this->post(route('admin.customerInvoices.store'), $payload)
            ->assertSessionHasErrors('items.0.discount');
    }

    public function test_a_discount_larger_than_its_line_is_capped_not_negated(): void
    {
        $t = CustomerInvoices::compute($this->business->id, [
            ['description' => 'باقة', 'quantity' => 1, 'unit_price' => 10, 'discount' => 25, 'tax_rate' => 5],
        ]);

        $this->assertSame(10.0, $t['discount'], 'الخصمُ المحتسَب هو المطبَّق لا المطلوب');
        $this->assertSame(0.0, $t['total']);
        $this->assertGreaterThanOrEqual(0.0, $t['total']);
    }

    /* ═══════════ والصيغةُ واحدةٌ في الخادم والشاشة ═══════════ */

    /**
     * `lib/invoice-totals.ts` نسخةٌ حرفيّة من `compute`.
     *
     * ولو افترقتا لرأى التاجرُ رقمًا وحُفظ غيرُه ولا يشتكي أحد — وهو ما وقع
     * فعلًا قبل هذا الإصلاح. فتُقرأ نسخةُ الشاشة هنا، ويقابلها الخادمُ
     * بالأرقام نفسِها التي يقابلها `tests/js/invoice-totals.test.ts`.
     */
    public function test_the_screen_formula_matches_the_server_formula(): void
    {
        $ts = file_get_contents(base_path('resources/js/lib/invoice-totals.ts'));

        $this->assertStringContainsString(
            'Math.min(Math.max(0, num(line.discount)), gross)',
            $ts,
            'قصُّ الخصم في الشاشة يخالف الخادم',
        );
        $this->assertStringContainsString(
            'inclusive ? (net * rate) / (100 + rate) : (net * rate) / 100',
            $ts,
            'استخراجُ الضريبة في الشاشة يخالف الخادم',
        );
        $this->assertStringContainsString('inclusive ? gross_sum - tax : gross_sum', $ts);

        // وشاشةُ الإنشاء تنادي الوحدةَ ولا تكتب صيغةً من عندها
        $screen = file_get_contents(resource_path('js/Pages/Admin/CustomerInvoices/Create.tsx'));
        $this->assertStringContainsString('invoiceTotals(lines, tax_inclusive)', $screen);

        // والخادمُ يقول الشيء نفسه بالأرقام — وهي أرقامُ اختبار الشاشة
        $this->set($this->business->id, 'vat_rate', '5');
        $this->set($this->business->id, 'tax_mode', 'exclusive');

        $capped = CustomerInvoices::compute($this->business->id, [
            ['description' => 'باقة', 'quantity' => 1, 'unit_price' => 10, 'discount' => 25],
        ]);
        $this->assertSame(10.0, $capped['discount']);
        $this->assertSame(0.0, $capped['total']);

        $this->set($this->business->id, 'tax_mode', 'inclusive');
        $incl = CustomerInvoices::compute($this->business->id, [
            ['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 105],
        ]);
        $this->assertSame(105.0, $incl['total']);
        $this->assertSame(100.0, $incl['subtotal']);
    }
}
