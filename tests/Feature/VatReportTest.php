<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\ReportData;
use App\Support\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تقرير ضريبة القيمة المضافة — ما حصّلتَه وما دفعتَه، والفرقُ المستحقّ.
 *
 * وأخطرُ ما يحرسه هذا الملفّ أنّ **رقمًا خاطئًا هنا يُقدَّم إلى جهةٍ حكومية**:
 * لا يراجعه أحدٌ في الشاشة، ويُنقل كما هو إلى الإقرار. فالوعاء يُقرأ من حيث
 * حُسبت الضريبة نفسها لا من رقمٍ مجاور، والمدخلاتُ من سنداتٍ مكتوبة لا من
 * تقديرٍ بالنسبة، والصافي السالب يبقى سالبًا لأنّه رصيدٌ مستردّ لا مبلغٌ يُدفع.
 */
class VatReportTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function sale(float $subtotal, float $discount, float $tax, float $delivery = 0): Order
    {
        return Order::create([
            'business_id' => $this->business->id,
            'number' => 'S'.uniqid(),
            'status' => 'مكتمل',
            'payment_status' => 'مدفوع',
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'delivery_fee' => $delivery,
            'total' => $subtotal - $discount + $tax + $delivery,
            'ordered_at' => now(),
        ]);
    }

    private function purchase(float $subtotal, float $tax): SupplierInvoice
    {
        $supplier = Supplier::firstOrCreate(
            ['business_id' => $this->business->id, 'name' => 'مورّد'],
        );

        return SupplierInvoice::create([
            'business_id' => $this->business->id,
            'supplier_id' => $supplier->id,
            'supplier_ref' => 'R'.uniqid(),
            'issued_at' => now()->toDateString(),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal + $tax,
        ]);
    }

    private function vat(string $range = 'month'): array
    {
        return ReportData::vat($this->business->id, ['range' => $range]);
    }

    /* ============================== الوعاء ============================== */

    public function test_the_taxable_base_is_the_one_the_tax_was_taken_on(): void
    {
        /*
         * الوعاء `subtotal - discount` لا `total - tax`.
         *
         * ولو قيس بالثاني لَدخلت رسومُ التوصيل في الوعاء وهي لم تُضرَّب
         * أصلًا، فيخرج رقمٌ لا تُصدّقه الضريبةُ المحصّلة بجواره — ويُقدَّم.
         */
        $this->sale(subtotal: 100, discount: 20, tax: 4, delivery: 5);

        $summary = $this->vat()['summary'];

        $this->assertSame(80.0, $summary['taxable']);
        $this->assertSame(4.0, $summary['output']);
    }

    public function test_the_delivery_fee_is_shown_apart_not_melted_into_the_base(): void
    {
        // ما لا يفرض عليه النظامُ ضريبةً يُقال، لا يُترك ليُكتشف عند الجهة
        $this->sale(subtotal: 100, discount: 0, tax: 5, delivery: 7);

        $this->assertSame(7.0, $this->vat()['summary']['delivery']);
        $this->assertSame(100.0, $this->vat()['summary']['taxable']);
    }

    public function test_a_cancelled_sale_carries_no_tax_into_the_return(): void
    {
        // بيعةٌ أُلغيت لم تُحصَّل ضريبتُها — وإقرارُها دفعُ ما لم يُقبض
        $this->sale(subtotal: 100, discount: 0, tax: 5);
        $this->sale(subtotal: 200, discount: 0, tax: 10)->update(['status' => 'ملغي']);

        $this->assertSame(5.0, $this->vat()['summary']['output']);
    }

    /* ============================ المدخلات ============================ */

    public function test_input_tax_is_read_from_supplier_invoices_not_estimated(): void
    {
        /*
         * كانت تُقدَّر بضرب إجمالي أوامر الشراء في النسبة — رقمٌ مخترَع:
         * أمرُ شراءٍ ليس فاتورةً ضريبية، ولا كلُّ مورّدٍ مسجَّل.
         */
        $this->purchase(subtotal: 300, tax: 15);

        $summary = $this->vat()['summary'];

        $this->assertSame(300.0, $summary['purchases']);
        $this->assertSame(15.0, $summary['input']);
    }

    public function test_the_due_is_output_minus_input(): void
    {
        $this->sale(subtotal: 400, discount: 0, tax: 20);
        $this->purchase(subtotal: 100, tax: 5);

        $this->assertSame(15.0, $this->vat()['summary']['due']);
    }

    public function test_a_month_of_more_buying_than_selling_is_a_credit_not_a_debt(): void
    {
        /*
         * والصافي السالب ليس خطأً: موسمٌ اشترى فيه التاجر أكثر ممّا باع يترك
         * له رصيدًا مستردًّا. وعرضُه صفرًا أو بقيمةٍ مطلقة يجعله يدفع ما لا يجب.
         */
        $this->sale(subtotal: 100, discount: 0, tax: 5);
        $this->purchase(subtotal: 600, tax: 30);

        $this->assertSame(-25.0, $this->vat()['summary']['due']);
    }

    /* ============================= الصفوف ============================= */

    public function test_the_rows_are_monthly_because_the_return_is_quarterly(): void
    {
        // من اختار «السنة» يجمع ثلاثةَ أسطرٍ بعينها فيقرأ رُبعَه
        $this->sale(subtotal: 100, discount: 0, tax: 5);
        $this->sale(subtotal: 50, discount: 0, tax: 2.5)
            ->update(['ordered_at' => now()->subMonthNoOverflow()->startOfMonth()->addDay()]);

        $rows = $this->vat('year')['rows'];

        $this->assertCount(2, $rows);
        // الأحدث أوّلًا — والإقرار يُقدَّم عن آخر فترةٍ لا عن أوّل الشهور
        $this->assertSame(5.0, $rows[0]['output']);
    }

    public function test_a_month_with_purchases_only_still_gets_its_row(): void
    {
        /*
         * شهرٌ اشترى فيه ولم يبِعْ يحمل ضريبةَ مدخلاتٍ تُخصم. ولو بُنيت
         * الصفوف من المبيعات وحدها لَسقط، وسقطت معه مدخلاتُه من الإقرار.
         */
        $this->purchase(subtotal: 200, tax: 10);

        $rows = $this->vat()['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame(10.0, $rows[0]['input']);
        $this->assertSame(-10.0, $rows[0]['due']);
    }

    public function test_each_row_totals_to_the_summary(): void
    {
        // مجموعُ العمود يجب أن يساوي البطاقة فوقه — وإلّا فأيّهما يُقدَّم؟
        $this->sale(subtotal: 100, discount: 10, tax: 4.5);
        $this->purchase(subtotal: 60, tax: 3);

        $data = $this->vat();

        $this->assertSame(
            round(array_sum(array_column($data['rows'], 'output')), 3),
            $data['summary']['output'],
        );
        $this->assertSame(
            round(array_sum(array_column($data['rows'], 'due')), 3),
            $data['summary']['due'],
        );
    }

    /* ============================= الأبواب ============================= */

    public function test_one_shops_numbers_never_reach_another(): void
    {
        $this->sale(subtotal: 100, discount: 0, tax: 5);

        $other = Business::create(['name' => 'جارتي', 'type' => 'عام', 'status' => 'نشط']);

        $this->assertSame(0.0, ReportData::vat($other->id, ['range' => 'month'])['summary']['output']);
    }

    public function test_the_page_opens_and_carries_its_rows(): void
    {
        $this->sale(subtotal: 100, discount: 0, tax: 5);

        $this->actingAs($this->owner)
            ->get(route('admin.reports.vat'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reports/Vat')
                ->where('summary.output', fn ($v) => (float) $v === 5.0)
                ->has('rows', 1));
    }

    /* ================= رقم التسجيل: يُقرأ هنا ويُدخَل هناك ================= */

    public function test_the_registration_number_saved_in_settings_reaches_the_report(): void
    {
        /*
         * الطريقُ كاملًا: يُحفظ من شاشة الإعدادات، ويُقرأ في الإقرار.
         *
         * ولو افترق المفتاحُ بين الكاتب والقارئ لَحُفظ الرقم وبقيت الشاشة
         * تقول «غير مُدخَل» — ويظنّ صاحبُه أنّ الحفظ لا يعمل فيعيده.
         */
        $this->actingAs(User::find($this->owner->id))
            ->post(route('admin.settings.update'), [
                'vat_enabled' => true,
                'vat_rate' => '5',
                'vat_number' => 'OM1100123456',
            ])->assertSessionHasNoErrors();

        $summary = $this->vat()['summary'];

        $this->assertSame('OM1100123456', $summary['number']);
        $this->assertSame(5.0, $summary['rate']);
    }

    public function test_a_shop_that_does_not_charge_vat_shows_no_registration_number(): void
    {
        /*
         * ورقةٌ تحمل رقمًا ضريبيًّا لمتجرٍ لا يجبي الضريبة تدّعي تسجيلًا لا
         * يخصّه — والرقم يبقى محفوظًا، وإنّما لا يُعرض.
         */
        $this->actingAs(User::find($this->owner->id))
            ->post(route('admin.settings.update'), [
                'vat_enabled' => false,
                'vat_number' => 'OM1100123456',
            ])->assertSessionHasNoErrors();

        $this->assertSame('', $this->vat()['summary']['number']);
        $this->assertSame(0.0, $this->vat()['summary']['rate']);
    }

    public function test_the_settings_page_opens_on_the_finance_section(): void
    {
        // الرابط الذي تحمله الشاشة يجب أن يصل إلى الحقل لا إلى لوحة الأقسام
        $this->actingAs(User::find($this->owner->id))
            ->get(route('admin.settings.index', ['section' => 'finance']))
            ->assertOk();
    }

    public function test_it_has_a_card_in_the_index(): void
    {
        // بابٌ بلا بطاقةٍ تدلّ عليه بابٌ لا يجده أحد
        $keys = collect(Reports::forUser($this->owner))->pluck('key')->all();

        $this->assertContains('vat', $keys);
    }

    public function test_it_exports_in_the_three_formats(): void
    {
        $this->sale(subtotal: 100, discount: 0, tax: 5);

        foreach (['xlsx', 'csv', 'pdf'] as $format) {
            $this->actingAs(User::find($this->owner->id))
                ->get(route('admin.reports.export.'.$format, 'vat'))
                ->assertOk();
        }
    }

    public function test_the_file_comes_out_on_every_plan(): void
    {
        /*
         * ملفُّ الإقرار ليس تحليلًا يُشترى — هو الورقةُ التي يُقدَّم بها
         * إقرارٌ إلى جهةٍ حكومية. وتقريرُ ضريبةٍ لا يخرج ملفُّه لا يؤدّي
         * الغرضَ الوحيد الذي وُجد له: يُقرأ على الشاشة ثم يُنقل رقمًا رقمًا بيد.
         */
        $plan = Plan::create([
            'name' => 'الباقة الأساسية', 'monthly_price' => 9.9, 'yearly_price' => 99,
            'capabilities' => [],
        ]);
        $this->business->update(['plan_id' => $plan->id]);
        $this->business->refresh();
        $this->sale(subtotal: 100, discount: 0, tax: 5);

        $this->actingAs(User::find($this->owner->id))
            ->get(route('admin.reports.export.csv', 'vat'))->assertOk();

        // وبقيّةُ التقارير تبقى على ما هي — الاستثناء واحدٌ لا بابٌ عامّ
        $this->actingAs(User::find($this->owner->id))
            ->get(route('admin.reports.export.csv', 'orders'))->assertForbidden();
    }

    public function test_the_exported_file_carries_the_tax_column(): void
    {
        // ملفٌّ بلا عمود الضريبة لا يُقدَّم به إقرار
        $this->sale(subtotal: 100, discount: 0, tax: 5);

        $csv = $this->actingAs($this->owner)
            ->get(route('admin.reports.export.csv', 'vat'))->streamedContent();

        $this->assertStringContainsString('ضريبة المخرجات', $csv);
        $this->assertStringContainsString('ضريبة المدخلات', $csv);
    }
}
