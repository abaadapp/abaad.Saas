<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\Demo;
use App\Support\Pagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ثلاثةٌ أذن بها صاحب النظام: حدُّ الصفحة، وأعمدةٌ متقاعدة، وصفّان ميّتان.
 *
 * ═══ ١) `per_page` كان يُقرأ من شريط العنوان بلا فحص ═══
 *
 * في سبعة متحكّمات: `paginate((int) $request->query('per_page', 20))`.
 * وقِسنا الأعطاب الثلاثة على شاشة المصروفات قبل الإصلاح:
 *
 *   بلا مُعامل → ٢٠٠ (١٠ صفوف)   ·   `0` → ٢٠٠ لكن **١٥** صفًّا
 *   `-5` → **٥٠٠**                ·   `999999` → ٢٠٠ والجدولُ كلُّه
 *
 * فالسالبُ يكسر قسمة `LengthAwarePaginator`؛ والصفرُ زائفٌ في PHP فتسقط
 * القيمة إلى `$model->getPerPage()` لا إلى ما كتبه المتحكّم؛ والكبيرُ يحمّل
 * الجدول كلَّه في الذاكرة بطلبٍ واحد يُعاد كلَّما ضُغط.
 *
 * ولا شاشةَ ترسل المُعامل في هذه السبعة أصلًا — يُكتب باليد.
 *
 * ═══ ٢) وثلاثةُ أعمدةٍ تقاعدت ثمّ حُذفت ═══
 *
 * `sales_total` (لا بيعةٌ تزيده)، و`commission_rate` (نسبةٌ لا تُصرف)،
 * و`pin` (رُفع الدخولُ بالرمز). رُفعت مقابضُها أوّلًا، وحُذفت الأعمدةُ
 * بكلمةٍ صريحة.
 *
 * ═══ ٣) وصفّان في إعدادات المنصّة لا يقرؤهما سطر ═══
 *
 * `platform_name` (اسمٌ قديم — الشاشةُ تكتب `app_name`) و`currency_decimals`
 * (تُقرأ من عملة المتجر لا من صفّ المنصّة).
 */
class WhatNothingReadsIsRemovedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function expenses(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            Expense::create([
                'business_id' => $this->shop->id, 'type' => 'إيجار',
                'amount' => 10, 'spent_at' => now(),
            ]);
        }
    }

    /** عددُ صفوف شاشة المصروفات عند مُعاملٍ بعينه */
    private function rows(?string $per): int
    {
        $url = route('admin.expenses.index').($per === null ? '' : '?per_page='.$per);

        return count($this->actingAs($this->owner)->get($url)->assertOk()
            ->viewData('page')['props']['expenses']);
    }

    /* ═══════════ حدُّ الصفحة ═══════════ */

    public function test_a_negative_page_size_no_longer_breaks_the_screen(): void
    {
        // كان يردّ خمسمئة — صفحةُ عطبٍ يبلغها أيُّ موظّفٍ يملك القسم
        $this->expenses(30);

        $this->assertSame(10, $this->rows('-5'));
    }

    public function test_zero_falls_back_to_what_the_controller_meant(): void
    {
        // كان يسقط إلى ١٥ — حجمٌ لا يقوله المتحكّم ولا يطلبه أحد
        $this->expenses(30);

        $this->assertSame(10, $this->rows('0'));
    }

    public function test_a_huge_page_size_is_capped(): void
    {
        $this->expenses(30);

        $this->assertSame(Pagination::MAX, Pagination::perPage(
            Request::create('/', 'GET', ['per_page' => 999999]), 10
        ));
        // ٣٠ صفًّا كلُّها دون السقف، فتُعرض — والحدُّ يقع على ما فوقه
        $this->assertSame(30, $this->rows('999999'));
    }

    public function test_a_page_size_within_the_limit_is_obeyed(): void
    {
        // والحدُّ لا يُلغي الاختيار: ما دون السقف يُحترم كما طُلب
        $this->expenses(30);

        $this->assertSame(25, $this->rows('25'));
    }

    public function test_nonsense_falls_back_instead_of_becoming_one(): void
    {
        $r = fn ($v) => Pagination::perPage(
            Request::create('/', 'GET', ['per_page' => $v]), 20
        );

        // ونصٌّ ليس رقمًا لا يصير `(int) 'abc' === 0` ثمّ واحدًا
        $this->assertSame(20, $r('abc'));
        // ونصفُ رقمٍ ليس رقمًا: `(int) '5abc'` تساوي ٥ بلا فحص
        $this->assertSame(20, $r('5abc'), 'قيمةٌ مشوّهة تُقرأ رقمًا');
        $this->assertSame(20, $r(''));
        $this->assertSame(20, Pagination::perPage(Request::create('/'), 20));
        $this->assertSame(20, $r('-5'), 'ما لا معنى له يعود إلى الافتراض');
        $this->assertSame(Pagination::MAX, $r('1000000'));
        $this->assertSame(50, $r('50'));
    }

    public function test_no_controller_reads_the_page_size_unguarded(): void
    {
        /*
         * وحارسٌ على الشكل: من كتب `paginate((int) $request->…)` في شاشةٍ
         * جديدة أعاد الأعطاب الثلاثة. وشاشةُ فواتير العملاء تحرس نفسها
         * بقائمةٍ مغلقة — وهي أضيق، فتُستثنى بالاسم لا بالمصادفة.
         */
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Http/Controllers')));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if (str_contains($src, "query('per_page'") || str_contains($src, 'query("per_page"')) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'مُعامِلٌ يُقرأ بلا حدّ');
    }

    /* ═══════════ الأعمدة المتقاعدة ═══════════ */

    public function test_the_retired_columns_are_gone(): void
    {
        foreach (['sales_total', 'commission_rate', 'pin'] as $column) {
            $this->assertFalse(Schema::hasColumn('users', $column), $column);
        }
    }

    public function test_what_replaced_them_still_works(): void
    {
        /*
         * ولا يُحذف عمودٌ إلّا وقد قام غيرُه مقامه: مبيعاتُ الموظّف كانت
         * تُقرأ من `sales_total` وصارت تُحسب من الطلبات.
         */
        $clerk = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        Order::create([
            'business_id' => $this->shop->id, 'user_id' => $clerk->id, 'number' => 'INV-1',
            'status' => 'مكتمل', 'is_held' => false, 'payment_method' => 'نقدي',
            'payment_status' => 'مدفوع', 'subtotal' => 40, 'tax' => 0, 'total' => 40,
            'ordered_at' => now(),
        ]);

        $this->actingAs($this->owner);
        $row = collect(Demo::employees())->firstWhere('id', $clerk->id);

        $this->assertSame(40.0, $row['sales']);
    }

    public function test_an_account_is_still_created_and_saved(): void
    {
        // وحذفُ عمودٍ لا يكسر ما حوله: الحسابُ يُنشأ ويُعدَّل كما كان
        $clerk = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'c2@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $clerk->update(['phone' => '96890000000']);

        $this->assertSame('96890000000', $clerk->fresh()->phone);
    }

    /* ═══════════ صفّا الإعدادات ═══════════ */

    /**
     * تشغيلُ الكنس فعلًا — لا افتراضُ أنّه جرى.
     *
     * `RefreshDatabase` تُجري المهاجرات في التهيئة، فتصير الصفوف محذوفةً قبل
     * أن يكتبها الاختبار. و`artisan migrate` بعدها لا تُعيد تشغيل ما سُجِّل —
     * فاختبارٌ يستدعيها يمرّ مهما عبثتَ بجسم المهاجرة. أثبتناه: نجا متحوّلان
     * (حذفٌ بلا شرط الموضع، وصفٌّ حيٌّ يُكنس معهما). فتُستدعى `up()` بيدها.
     */
    private function sweep(): void
    {
        $migration = require database_path(
            'migrations/2026_09_09_140100_platform_rows_that_nothing_reads_are_removed.php'
        );

        $migration->up();
    }

    public function test_the_dead_platform_rows_are_removed(): void
    {
        Setting::create(['business_id' => null, 'key' => 'platform_name', 'value' => 'Abad POS']);
        Setting::create(['business_id' => null, 'key' => 'currency_decimals', 'value' => '3']);

        $this->sweep();

        foreach (['platform_name', 'currency_decimals'] as $key) {
            $this->assertFalse(
                Setting::whereNull('business_id')->where('key', $key)->exists(),
                $key
            );
        }
    }

    public function test_the_live_platform_row_is_untouched(): void
    {
        // و`vat_rate` يقرؤه `Vat` — فلا يُكنس معهما
        Setting::create(['business_id' => null, 'key' => 'vat_rate', 'value' => '5']);

        $this->sweep();

        $this->assertSame('5', Setting::whereNull('business_id')->where('key', 'vat_rate')->value('value'));
    }

    public function test_a_shops_own_decimals_setting_is_not_swept(): void
    {
        /*
         * وهذا موضعُ الخطر في الحذف: `currency_decimals` مفتاحٌ حيٌّ على
         * مستوى المتجر. وحذفٌ بالاسم وحده — بلا شرط `business_id IS NULL` —
         * كان يمحو إعداداتِ تجّارٍ لا علاقة لهم بصفّ المنصّة.
         */
        Setting::create([
            'business_id' => $this->shop->id, 'key' => 'currency_decimals', 'value' => '2',
        ]);

        $this->sweep();

        $this->assertSame('2', Setting::where('business_id', $this->shop->id)
            ->where('key', 'currency_decimals')->value('value'));
    }
}
