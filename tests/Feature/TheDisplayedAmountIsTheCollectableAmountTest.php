<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Demo;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ما يُكتب على الورقة هو ما يُطالَب به — لا رقمٌ يُعرض وآخرُ يُحصَّل.
 *
 * ═══ العطبُ الذي وُلد منه هذا الملفّ ═══
 *
 * الحسابُ في النظام كلِّه يُقرَّب إلى ثلاثِ منازل — ثمانيةٌ وتسعون عمودًا
 * `decimal(…, 3)`، وعتبةُ تسويةٍ `0.0005` في عشرة ملفّات. والعرضُ يُكتب
 * بمنازل العملة: ثلاثٌ للريال، اثنتان للدرهم والدولار.
 *
 * فوقع لمتجرٍ بالدرهم:
 *
 *   بندٌ بـ‏12.28 وضريبةُ 5٪  →  0.614  →  إجماليٌّ مخزَّنٌ **12.894**
 *   والورقةُ تكتب              →  «‏12.89 د.إ»
 *
 * فيدفع الزبونُ ما قرأ، فيبقى **0.004**. فتقول الشاشةُ «مدفوعة جزئيًا» عن
 * ورقةٍ سُدّدت بالكامل بحسب ما كُتب عليها، وتقول في العمود نفسِه إنّ الباقيَ
 * «‏0.00 د.إ» — ورقةٌ تناقض نفسها، وتبقى في تقرير الأعمار إلى الأبد.
 *
 * والقاعدةُ التي أُغلق بها: **لا تُحذف منزلةٌ فيها رقم**. وهذا الملفّ يقيسها
 * على الريال والدرهم والدولار، وعلى الخصم والضريبة والسداد الجزئيّ وإشعار
 * الدائن — لأنّ كلَّ اختبارٍ ماليٍّ آخر في المستودع مكتوبٌ بالريال العمانيّ،
 * حيث يوافق المثبَّتُ الصوابَ مصادفةً.
 */
class TheDisplayedAmountIsTheCollectableAmountTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'متجر', 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط',
        ]);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'زبون']);

        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => 'vat_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => 'vat_rate'], ['value' => '5']);
    }

    /** @return array<string, mixed> */
    private function currency(string $code, string $symbol): array
    {
        Currency::where('business_id', $this->business->id)->delete();
        Currency::create([
            'business_id' => $this->business->id, 'code' => $code, 'name' => $code,
            'symbol' => $symbol, 'rate' => 1.0, 'is_base' => true, 'active' => true,
        ]);
        Demo::flushCurrency();

        return Money::of($this->business->id);
    }

    /** المكتوبُ مقروءًا عددًا — كما يقرؤه الزبون من الورقة */
    private function asRead(string $written): float
    {
        return (float) str_replace(',', '', $written);
    }

    /** فاتورةٌ صادرةٌ إجماليُّها 12.894 — ثالثتُها حقيقيّة لا صفر */
    private function issuedWithThirdDigit(): CustomerInvoice
    {
        return CustomerInvoices::issue(CustomerInvoices::create(
            $this->business->id,
            $this->customer,
            [],
            [['description' => 'باقة', 'quantity' => 1, 'unit_price' => 12.28]],
            $this->owner->id,
        ), $this->owner->id);
    }

    /* ═════════ القاعدة: لا تُحذف منزلةٌ فيها رقم ═════════ */

    /**
     * الكتابةُ لا تُخفي شيئًا من المخزَّن — في كلّ عملةٍ وكلّ مبلغ.
     *
     * وهذا هو الثابتُ كلُّه في سطرٍ واحد: ما يُقرأ من الورقة، إذا أُعيد عددًا،
     * يساوي ما في القاعدة.
     */
    public function test_what_is_written_reads_back_as_what_is_stored(): void
    {
        $cases = [['OMR', 'ر.ع'], ['AED', 'د.إ'], ['USD', '$'], ['KWD', 'د.ك']];
        $amounts = [0.0, 0.004, 0.005, 1.005, 12.894, 12.915, 5.25, 12.5, 1000.5, 99999.999];

        foreach ($cases as [$code, $symbol]) {
            $cur = $this->currency($code, $symbol);

            foreach ($amounts as $value) {
                $this->assertEqualsWithDelta(
                    round($value, 3),
                    $this->asRead(Money::amount($value, $cur)),
                    0.0004,
                    "{$code} تكتب {$value} على نحوٍ يُقرأ غيرَه",
                );
            }
        }
    }

    /** ولا تُكتب منزلةٌ لا خبرَ فيها — «5.25» درهمًا تبقى «5.25» لا «5.250» */
    public function test_a_digit_that_says_nothing_is_not_written(): void
    {
        $aed = $this->currency('AED', 'د.إ');

        $this->assertSame('5.25', Money::amount(5.25, $aed));
        $this->assertSame('12.00', Money::amount(12.0, $aed));
        $this->assertSame('1,000.50', Money::amount(1000.5, $aed));
        $this->assertSame('12.894', Money::amount(12.894, $aed));

        // والريالُ ثلاثٌ دائمًا — منازلُه هي دقّةُ التخزين نفسُها
        $omr = $this->currency('OMR', 'ر.ع');
        $this->assertSame('5.250', Money::amount(5.25, $omr));
    }

    /* ═════════ الثابتُ على فاتورةٍ حيّة ═════════ */

    /**
     * الدرهمُ: فاتورةٌ تُصدَر، ويدفع الزبون ما قرأ على ورقته — فتُغلَق.
     *
     * وهو الاختبارُ الذي لو كان قائمًا لما وقع العطب. وقبل الإصلاح كانت
     * الورقةُ تقول «12.89» والقاعدةُ 12.894، فيبقى 0.004 ولا تُغلَق أبدًا.
     */
    public function test_a_dirham_invoice_closes_when_the_customer_pays_what_the_paper_says(): void
    {
        $cur = $this->currency('AED', 'د.إ');
        $this->actingAs($this->owner);

        $invoice = $this->issuedWithThirdDigit();

        // ورقةٌ فيها ثالثةٌ حقيقيّة — وإلّا لم يكن الاختبار يقيس شيئًا
        $this->assertSame('12.894', number_format((float) $invoice->total, 3));

        $paid = $this->asRead(Money::amount((float) $invoice->total, $cur));
        CustomerPayments::record(
            $this->business->id, $this->customer, $paid,
            ['method' => 'نقدي'], [$invoice->id => $paid], $this->owner->id,
        );

        $invoice = $invoice->fresh();
        $this->assertSame(0.0, $invoice->outstanding());
        $this->assertSame('مدفوعة', $invoice->paymentState());
    }

    /** والورقةُ المطبوعةُ نفسُها تحمل الإجماليَّ كما يُطالَب به — لا كما يُقرَّب */
    public function test_the_printed_paper_carries_the_collectable_total(): void
    {
        $this->currency('AED', 'د.إ');
        $this->actingAs($this->owner);

        $invoice = $this->issuedWithThirdDigit()->fresh()->load('items');

        $html = CustomerInvoiceController::paper(
            $this->business->id, $invoice, $invoice->paidTotal(), $invoice->outstanding(), null,
        )->render();

        $this->assertStringContainsString('12.894', $html);
        $this->assertStringNotContainsString('>12.89 <', $html);
    }

    /** والسدادُ الجزئيّ: الباقي المكتوب هو الباقي المطلوب */
    public function test_a_partial_payment_leaves_a_written_balance_that_can_be_paid(): void
    {
        $cur = $this->currency('USD', '$');
        $this->actingAs($this->owner);

        $invoice = CustomerInvoices::issue(CustomerInvoices::create(
            $this->business->id, $this->customer, [],
            [['description' => 'خدمة', 'quantity' => 3, 'unit_price' => 4.09]],
            $this->owner->id,
        ), $this->owner->id);

        CustomerPayments::record(
            $this->business->id, $this->customer, 5.0,
            ['method' => 'نقدي'], [$invoice->id => 5.0], $this->owner->id,
        );

        $invoice = $invoice->fresh();
        $rest = $this->asRead(Money::amount($invoice->outstanding(), $cur));
        $this->assertGreaterThan(0.0, $rest);

        CustomerPayments::record(
            $this->business->id, $this->customer, $rest,
            ['method' => 'نقدي'], [$invoice->id => $rest], $this->owner->id,
        );

        $invoice = $invoice->fresh();
        $this->assertSame(0.0, $invoice->outstanding());
        $this->assertSame('مدفوعة', $invoice->paymentState());
    }

    /** وإشعارُ الدائن: ما يُردّ مكتوبًا هو ما يُطرح فعلًا */
    public function test_a_credit_note_written_amount_is_the_amount_removed(): void
    {
        $cur = $this->currency('AED', 'د.إ');
        $this->actingAs($this->owner);

        $invoice = $this->issuedWithThirdDigit();
        $note = CustomerInvoices::creditNote($invoice, 12.894, 0.614, 'إرجاع', $this->owner->id);

        $this->assertSame(
            $this->asRead(Money::amount((float) $note->amount, $cur)),
            round((float) $invoice->fresh()->total, 3),
        );
        $this->assertSame(0.0, $invoice->fresh()->outstanding());
    }

    /** والخصمُ: البنودُ تجمع إلى مجموعها، وكلُّ سطرٍ يُقرأ كما خُزّن */
    public function test_a_discounted_invoice_reads_back_line_by_line(): void
    {
        $cur = $this->currency('AED', 'د.إ');
        $this->actingAs($this->owner);

        $totals = CustomerInvoices::compute($this->business->id, [
            ['description' => 'أ', 'quantity' => 3, 'unit_price' => 4.09, 'discount' => 1.007],
            ['description' => 'ب', 'quantity' => 1, 'unit_price' => 0.005],
        ]);

        $sum = round(array_sum(array_column($totals['items'], 'line_total')), 3);
        $this->assertSame($sum, $totals['total']);

        foreach ($totals['items'] as $item) {
            $this->assertEqualsWithDelta(
                round((float) $item['line_total'], 3),
                $this->asRead(Money::amount((float) $item['line_total'], $cur)),
                0.0004,
            );
        }
        $this->assertEqualsWithDelta(
            $totals['total'],
            $this->asRead(Money::amount($totals['total'], $cur)),
            0.0004,
        );
    }

    /**
     * والدفترُ لا يتحرّك: التقريبُ الداخليُّ ثلاثُ منازل، وعتبةُ التسوية `0.0005`.
     *
     * وهذا الحارسُ يمنع أن يُغيَّر التقريبُ الداخليُّ في جولةٍ لاحقة بلا قراءةِ
     * ما بُني عليه — انظر خطّةَ الترحيل في تقرير الجولة.
     */
    public function test_the_internal_precision_is_three_and_stays_three(): void
    {
        $cur = $this->currency('AED', 'د.إ');
        $this->actingAs($this->owner);

        $invoice = $this->issuedWithThirdDigit();

        // الضريبةُ تُحسب بثلاث منازل — لا بمنازل العملة
        $this->assertSame('0.614', number_format((float) $invoice->tax_total, 3));
        $this->assertSame('12.894', number_format((float) $invoice->total, 3));
        $this->assertSame(CustomerInvoice::ISSUED, $invoice->status);
        $this->assertSame('12.894 د.إ', Money::format((float) $invoice->total, $cur));
    }
}
