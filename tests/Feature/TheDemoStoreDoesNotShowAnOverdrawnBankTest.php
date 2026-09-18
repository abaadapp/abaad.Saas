<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\Plan;
use App\Support\Bank;
use App\Support\DemoStore;
use App\Support\Ledger;
use App\Support\PaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * متجرُ العرض لا يُري حسابًا مكشوفًا بستّمئة ألف.
 *
 * ═══ العطب ═══
 *
 * بذرةُ العرض تُرحّل مجموعَ مبيعات الشهر مدينًا في **الصندوق** مهما قال
 * الطلب. والطلبُ يقول «بطاقة»، والمعاملةُ تقول «بطاقة»، وشاشةُ وسائل الدفع
 * تقول «بطاقة» — والدفترُ وحده يقول «نقد». أربعةُ قرّاءٍ لسؤالٍ واحد،
 * أحدُهم يخالف الثلاثة.
 *
 * وهي تدفع من **البنك**: المشتريات والرواتب والأصول والمصروفات. فيخرج منه
 * كلُّ شيء ولا يدخله ريال. وقيس على الإنتاج: بنكُ متجر العرض ‎−٦٢٣٬٢٤٨٫٥٦٥‎
 * والمشترياتُ وحدها ٥٥٨ ألفًا خرجت منه.
 *
 * وهذا أسوأ ما يقع في بذرةِ عرض: الشاشةُ تُفتح لتُقنع من يشتري، فيفتح
 * الميزانيةَ فيجد حسابًا بنكيًّا مكشوفًا بأكثر ممّا باع المتجر كلُّه.
 *
 * ═══ والوسيلةُ من مصدرها ═══
 *
 * وكانت البذرة تكتب «تحويل»، والنظامُ كلُّه يقول «تحويل بنكي»
 * (`PaymentMethods::TRANSFER`). فوسيلةٌ لا يعرفها أحد: `Bank::METHODS` لا
 * تعدّها بنكيّة فتسقط من كشف الحساب ومن المطابقة.
 */
class TheDemoStoreDoesNotShowAnOverdrawnBankTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::updateOrCreate(['name' => 'الباقة الاحترافية'], [
            'monthly_price' => 30, 'yearly_price' => 300,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 100000,
        ]);

        $this->business = DemoStore::create('متجر العرض', array_key_first(DemoStore::SIZES));
    }

    private function balance(string $key): float
    {
        return Ledger::account($this->business->id, $key)->balance();
    }

    /** لا وسيلةَ دفعٍ في البذرة خارج ما يعرفه النظام */
    public function test_every_seeded_payment_method_is_a_known_one(): void
    {
        $seeded = Order::where('business_id', $this->business->id)
            ->distinct()->pluck('payment_method')->filter()->all();

        $this->assertNotEmpty($seeded);
        $this->assertEmpty(
            array_diff($seeded, PaymentMethods::ALL),
            'البذرة تكتب وسيلةً لا يعرفها النظام: '.implode('، ', array_diff($seeded, PaymentMethods::ALL))
        );
    }

    /** والبطاقةُ تدخل البنك في الدفتر كما تقوله الفاتورة */
    public function test_card_sales_reach_the_bank_in_the_ledger(): void
    {
        $byCard = Order::where('business_id', $this->business->id)
            ->whereIn('payment_method', Bank::METHODS)->where('status', '!=', 'ملغي')->count();

        $this->assertGreaterThan(0, $byCard, 'البذرة لا تبيع بالبطاقة أصلًا فلا شيء يُقاس');

        $this->assertGreaterThan(
            0,
            $this->balance('bank'),
            'بيعُ البطاقة قُيّد في الصندوق — الفاتورةُ تقول بنكًا والدفترُ يقول نقدًا'
        );
    }

    /**
     * ولا يُري ميزانيّةً بحسابٍ بنكيٍّ مكشوف.
     *
     * ولا يكفي أن يدخله شيء: يجب أن يدخله ما يغطّي ما خرج منه. والبذرةُ تدفع
     * منه مشترياتِها ورواتبَها وأصولَها ومصروفاتِها.
     */
    public function test_the_bank_is_not_overdrawn(): void
    {
        $bank = Bank::total($this->business->id);

        $this->assertGreaterThanOrEqual(
            0.0,
            $bank,
            'متجر العرض يُري حسابًا بنكيًّا مكشوفًا بـ'.abs($bank).' — وهو أوّل ما يفتحه من يشتري'
        );
    }

    /** والصندوقُ لا ينتفخ بما لم يدخله */
    public function test_cash_does_not_swallow_the_card_sales(): void
    {
        $cash = $this->balance('cash');
        $bank = $this->balance('bank');

        $this->assertGreaterThan(0.0, $cash, 'لا نقدَ في الدرج أصلًا');
        $this->assertGreaterThan(
            0.0,
            $bank,
            'الصندوقُ ابتلع مبيعات البطاقة كلَّها: نقد='.$cash.' بنك='.$bank
        );
    }

    /** والدفترُ يبقى متوازنًا بعد الفرز */
    public function test_the_ledger_stays_balanced(): void
    {
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }
}
