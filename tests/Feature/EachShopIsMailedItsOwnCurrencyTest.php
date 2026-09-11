<?php

namespace Tests\Feature;

use App\Mail\DailySummaryMail;
use App\Mail\MonthlyReportMail;
use App\Mail\NewOrderMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\Demo;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * كلُّ متجرٍ يصله بريدُه بعملته — لا بعملة من سبقه في العامل.
 *
 * ═══ العطبُ الذي وُلد منه هذا الملفّ ═══
 *
 * `Demo::baseCurrency()` تقرأ **متجر الجلسة**. وهو الصواب في طلبٍ يفتحه
 * التاجر، وخطأٌ صامتٌ في موضعين:
 *
 *  • أمرٌ مجدول يمرّ على المتاجر واحدًا واحدًا (الملخّص اليوميّ، التقرير
 *    الشهريّ) — لا جلسةَ فيه أصلًا.
 *  • رسالةٌ `ShouldQueue` تُرسَم في عامل الطابور بعد أن يُغلق الطلب
 *    (`NewOrderMail`) — والعاملُ نفسُه قد يعالج متجرين بالتتابع.
 *
 * فتاجرُ دبي يصله «‏5.250 ر.ع» عن بيعةٍ بالدرهم، أو ما هو أسوأ: عملةُ
 * المتجر الذي عُولج قبله في الدورة نفسها. ولا يظهر في اختبارٍ بمتجرٍ واحد،
 * ولا في اختبارٍ كلُّ متاجره بالريال.
 *
 * فيُمرَّر وصفُ العملة إلى الرسالة عند بنائها، ويُقرأ من **متجر صاحبها**
 * لا من الجلسة. وهذا الملفّ يقيسه بمتجرين مختلفَي العملة في دورةٍ واحدة.
 */
class EachShopIsMailedItsOwnCurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Business, 1: Branch} */
    private function shop(string $name, string $email, string $code, string $symbol): array
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط', 'email' => $email,
        ]);
        $branch = Branch::create(['business_id' => $b->id, 'name' => 'الفرع الرئيسي']);
        User::create([
            'business_id' => $b->id, 'name' => 'المالك', 'email' => 'u'.$b->id.'@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Currency::create([
            'business_id' => $b->id, 'code' => $code, 'name' => $code,
            'symbol' => $symbol, 'rate' => 1.0, 'is_base' => true, 'active' => true,
        ]);

        return [$b, $branch];
    }

    private function sale(Business $b, Branch $branch, float $total, string $number): Order
    {
        $order = Order::create([
            'business_id' => $b->id, 'branch_id' => $branch->id, 'branch' => $branch->name,
            'number' => $number, 'status' => 'مكتمل', 'customer_name' => 'زبون',
            'employee_name' => 'كاشير', 'payment_method' => 'نقدي',
            'subtotal' => $total, 'tax' => 0, 'total' => $total, 'ordered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'name' => 'باقة', 'price' => $total, 'quantity' => 1, 'total' => $total,
        ]);

        return $order->load('items');
    }

    /** رسالةُ الطلب الجديد تُرسَم في الطابور — فتقرأ متجرَ الطلب لا الجلسة */
    public function test_the_new_order_mail_reads_the_order_shop(): void
    {
        app()->setLocale('ar');
        [$muscat, $mb] = $this->shop('متجر مسقط', 'muscat@x.om', 'OMR', 'ر.ع');
        [$dubai, $db] = $this->shop('متجر دبي', 'dubai@x.ae', 'AED', 'د.إ');

        $omani = (new NewOrderMail($this->sale($muscat, $mb, 5.25, 'INV-000001')))->render();
        $dirham = (new NewOrderMail($this->sale($dubai, $db, 5.25, 'INV-000002')))->render();

        $this->assertStringContainsString('5.250 ر.ع', $omani);
        $this->assertStringNotContainsString('د.إ', $omani);

        $this->assertStringContainsString('5.25 د.إ', $dirham);
        $this->assertStringNotContainsString('ر.ع', $dirham);
    }

    /**
     * والأمرُ المجدول يمرّ على الاثنين في دورةٍ واحدة — ولكلٍّ عملتُه.
     *
     * والترتيبُ مقصود: المتجرُ العمانيّ أوّلًا، فلو سُرّبت عملتُه إلى الثاني
     * — بذاكرةٍ ساكنة أو بقراءةٍ من الجلسة — ظهر في رسالة دبي.
     */
    public function test_a_scheduled_run_mails_two_shops_two_currencies(): void
    {
        app()->setLocale('ar');
        Mail::fake();

        [$muscat, $mb] = $this->shop('متجر مسقط', 'muscat@x.om', 'OMR', 'ر.ع');
        [$dubai, $db] = $this->shop('متجر دبي', 'dubai@x.ae', 'AED', 'د.إ');

        $this->sale($muscat, $mb, 120.5, 'INV-000001')->update(['ordered_at' => now()->subMonthNoOverflow()]);
        $this->sale($dubai, $db, 120.5, 'INV-000002')->update(['ordered_at' => now()->subMonthNoOverflow()]);

        $this->artisan('reports:email')->assertSuccessful();

        Mail::assertSent(MonthlyReportMail::class, function (MonthlyReportMail $mail) use ($muscat) {
            if (! $mail->hasTo($muscat->email)) {
                return false;
            }
            $body = $mail->render();

            return str_contains($body, 'ر.ع') && ! str_contains($body, 'د.إ');
        });

        Mail::assertSent(MonthlyReportMail::class, function (MonthlyReportMail $mail) use ($dubai) {
            if (! $mail->hasTo($dubai->email)) {
                return false;
            }
            $body = $mail->render();

            return str_contains($body, 'د.إ') && ! str_contains($body, 'ر.ع');
        });
    }

    /** والملخّصُ اليوميّ كذلك — عملةُ صاحبه تصل معه */
    public function test_the_daily_summary_carries_the_shops_currency(): void
    {
        app()->setLocale('ar');
        [$dubai, $db] = $this->shop('متجر دبي', 'dubai@x.ae', 'AED', 'د.إ');
        $this->sale($dubai, $db, 5.25, 'INV-000001');

        $mail = new DailySummaryMail(
            $dubai->name,
            Demo::dailySummaryFor((int) $dubai->id, now()),
            'اليوم',
            Money::of((int) $dubai->id),
        );

        $body = $mail->render();

        $this->assertStringContainsString('د.إ', $body);
        $this->assertStringNotContainsString('ر.ع', $body);
    }
}
