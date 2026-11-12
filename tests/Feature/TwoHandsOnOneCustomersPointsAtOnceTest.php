<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\JobTitle;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * يدان على نقاط عميلٍ واحد في اللحظة نفسها — لا خصمَ مرّتين ولا إعادةَ مرّتين،
 * ولا يتجاوز ما رُدّ من الفاتورة قيمتَها، ولا يقف طلبٌ على طلب.
 *
 * عمليّاتٌ مستقلّة باتّصالاتٍ مستقلّة تبدأ عند لحظةٍ واحدة. وحيث يلزم تشابكٌ
 * بعينه يُمسك الطرفُ الأوّل قفلَه برهةً (`usleep` في حدث نموذج) فيصل الثاني
 * وهو ممسوك — فيُختبر ترتيبُ الأقفال لا الحظّ.
 *
 * ═══ ترتيبُ الأقفال ═══
 *
 * المتجرُ قبل العميل. بيعةُ الصندوق تُمسك صفَّ المتجر من أوّل صفٍّ تكتبه
 * (مفتاحُه الأجنبيّ يأخذ FOR KEY SHARE)، ثمّ صفَّ العميل حين تكتب طلبَه.
 * والإشعارُ الدائنُ والإلغاءُ يأخذان المتجرَ (الترقيمُ/عكسُ القيد) ثمّ الورقةَ
 * ثمّ العميل. والإصدارُ كان يُمسك العميلَ (`spendPoints`) ثمّ يطلب المتجر —
 * عكسَ الصندوق، فتُسقط PostgreSQL أحدَهما بـ deadlock. وصار يأخذ المتجرَ أوّلًا.
 *
 * على PostgreSQL وحدها: SQLite لا تُبقي معاملتين مفتوحتين معًا. ولا
 * `RefreshDatabase`: معاملتُها تُخفي الصفوفَ عن العمليّات، فتُودَع وتُكنَس بعده.
 */
class TwoHandsOnOneCustomersPointsAtOnceTest extends TestCase
{
    use DatabaseTruncation;

    private Business $shop;

    private User $owner;

    private Branch $branch;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('يُثبَت على PostgreSQL وحدها — SQLite لا تُبقي معاملتين مفتوحتين معًا');
        }

        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود التزامن', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        // الاكتسابُ صفر: النقاطُ هنا استبدالٌ وإعادةٌ لا غير، فيُقرأ الرصيدُ بلا ضجيج
        foreach (['loyalty_enabled' => '1', 'loyalty_earn_rate' => '0', 'vat_enabled' => '0'] as $key => $value) {
            Setting::create(['business_id' => $this->shop->id, 'key' => $key, 'value' => $value]);
        }
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'admin']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'race@loyalty.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'مريم', 'phone' => '91111111',
            'points' => 500, 'language' => 'ar', 'allow_credit_sales' => true,
        ]);
        $this->product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة', 'price' => 100, 'cost' => 40,
            'quantity' => 1000, 'alert_qty' => 1, 'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    /** مسودّةٌ بمئة تطلب `$points` نقطة — خمسمئةٍ بخمسة، فإجماليُّها ٩٥ */
    private function draft(int $points = 500): CustomerInvoice
    {
        return CustomerInvoices::create($this->shop->id, $this->customer, [
            'issued_at' => now()->toDateString(), 'redeem_points' => $points,
        ], [['description' => 'باقة ورد', 'quantity' => 1, 'unit_price' => 100]], $this->owner->id);
    }

    private function issued(int $points = 500): CustomerInvoice
    {
        return CustomerInvoices::issue($this->draft($points), $this->owner->id)->fresh();
    }

    /** عمليّةٌ مستقلّة: تُقلع التطبيق، وتنتظر اللحظة، ثمّ تُنفّذ `$body` — و`$ids` بين يديها */
    private function child(string $body): string
    {
        $script = tempnam(sys_get_temp_dir(), 'points');
        file_put_contents($script, <<<PHP
<?php
[\$self, \$root, \$at, \$ids] = \$argv;
\$ids = json_decode(\$ids, true);
require \$root.'/vendor/autoload.php';
\$app = require \$root.'/bootstrap/app.php';
\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
app()->setLocale('ar');
while (microtime(true) < (float) \$at) { usleep(200); }
{$body}
PHP);

        return $script;
    }

    /**
     * يُشغّل العمليّاتِ معًا — وما في `$meanwhile` يجري هنا بعد اللحظة بنصف ثانية.
     *
     * عمليّةٌ تسقط بخطأٍ غير متوقَّع (ومنه deadlock) تُسقط الاختبار بنصّها.
     *
     * @return array{0: array<int, string>, 1: mixed}
     */
    private function race(array $bodies, array $ids, ?\Closure $meanwhile = null): array
    {
        $c = DB::connection()->getConfig();
        $env = [
            'PATH' => getenv('PATH'), 'HOME' => sys_get_temp_dir(),
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $c['host'], 'DB_PORT' => (string) ($c['port'] ?? 5432),
            'DB_DATABASE' => (string) $c['database'], 'DB_USERNAME' => (string) $c['username'], 'DB_PASSWORD' => (string) ($c['password'] ?? ''),
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
            'BROADCAST_CONNECTION' => 'null', 'LOG_CHANNEL' => 'null',
        ];

        $at = microtime(true) + 2.0;
        $runs = [];
        foreach ($bodies as $body) {
            $script = $this->child($body);
            $proc = proc_open([PHP_BINARY, $script, base_path(), (string) $at, json_encode($ids)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($proc);
            $runs[] = [$proc, $pipes, $script];
        }

        $result = null;
        if ($meanwhile) {
            while (microtime(true) < $at + 0.5) {
                usleep(1000);
            }
            $result = $meanwhile();
        }

        $outs = [];
        foreach ($runs as [$proc, $pipes, $script]) {
            $out = trim(stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            @unlink($script);
            $this->assertSame(0, $code, 'سقطت عمليّة: '.$out);
            $outs[] = $out;
        }

        return [$outs, $result];
    }

    /** بيعةُ صندوقٍ بما عرضته الشاشة: مئةُ نقطةٍ بريال — و`$replay` بيعةٌ اكتملت ووصلت من الطابور */
    private function sell(int $redeem, bool $replay = false): TestResponse
    {
        return $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), [
                'items' => [['id' => $this->product->id, 'name' => 'باقة', 'qty' => 1]],
                'payment_method' => 'نقدي',
                'customer_id' => $this->customer->id,
                'client_uuid' => uniqid('race', true),
                'redeem_points' => $redeem,
                'redeem_discount' => round($redeem / 100, 3),
            ] + ($replay ? ['replay' => true] : []));
    }

    private function points(): int
    {
        return (int) DB::table('customers')->where('id', $this->customer->id)->value('points');
    }

    private function spends(): int
    {
        return PointTransaction::where('customer_id', $this->customer->id)->where('type', 'redeem')->count();
    }

    private const ISSUE = <<<'PHP'
// يُمسك صفَّ العميل برهةً بعد الخصم — فيصل الآخرُ وهو ممسوك
App\Models\PointTransaction::created(function () { usleep(800000); });
try {
    App\Support\CustomerInvoices::issue(App\Models\CustomerInvoice::findOrFail(%d));
    echo 'issued';
} catch (RuntimeException $e) {
    echo 'refused';
}
PHP;

    /** مسودّتان تَعِدان بالنقاط نفسِها تُصدَران معًا: تُصرف مرّةً، والأخرى تُرفض وتبقى كما هي */
    public function test_two_drafts_issued_at_once_spend_the_points_once(): void
    {
        $a = $this->draft();
        $b = $this->draft();
        [$outs] = $this->race([sprintf(self::ISSUE, $a->id), sprintf(self::ISSUE, $b->id)], []);

        sort($outs);
        $this->assertSame(['issued', 'refused'], $outs);
        $this->assertSame(0, $this->points());
        $this->assertSame(1, $this->spends(), 'خصمان لرصيدٍ واحد');
        $statuses = CustomerInvoice::whereIn('id', [$a->id, $b->id])->pluck('status')->all();
        $this->assertEqualsCanonicalizing([CustomerInvoice::DRAFT, CustomerInvoice::ISSUED], $statuses);
        // والمرفوضةُ بمبلغها كما كُتبت — لا يُنزع خصمُها في صمت
        $this->assertEqualsWithDelta(95.0, (float) CustomerInvoice::where('status', CustomerInvoice::DRAFT)->value('total'), 0.0001);
    }

    /** المسودّةُ نفسُها بضغطتين معًا: خصمٌ واحد وقيدٌ واحد */
    public function test_the_same_draft_issued_twice_at_once_spends_and_posts_once(): void
    {
        $draft = $this->draft();
        $body = sprintf(self::ISSUE, $draft->id);

        [$outs] = $this->race([$body, $body], []);

        $this->assertSame(['issued', 'issued'], $outs, 'الثانيةُ تجدها صادرةً فتخرج بها');
        $this->assertSame(0, $this->points());
        $this->assertSame(1, $this->spends());
        $this->assertSame(1, JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $draft->id)->count());
    }

    /**
     * إشعاران معًا بأكثرَ من نصف الورقة: يمرّ واحد ويُردّ الآخر.
     *
     * كان الحدُّ يُقرأ قبل المعاملة: يقرأ الاثنان «الباقي ٩٥» فيمرّان، ومجموعُهما
     * ١٢٠ على ورقةٍ بـ٩٥.
     */
    public function test_two_credit_notes_at_once_never_exceed_the_invoice(): void
    {
        $invoice = $this->issued();
        $credit = <<<'PHP'
// يُمسك ما يمسكه برهةً قبل أن يكتب الإشعار — فيصل الآخرُ وقد قرأ حدَّه
App\Models\CustomerCreditNote::creating(function () { usleep(800000); });
try {
    Tests\Support\ReturnNote::issue(App\Models\CustomerInvoice::findOrFail($ids['invoice']), 60);
    echo 'credited';
} catch (RuntimeException|Illuminate\Validation\ValidationException $e) {
    echo 'refused';
}
PHP;

        [$outs] = $this->race([$credit, $credit], ['invoice' => $invoice->id]);

        sort($outs);
        $this->assertSame(['credited', 'refused'], $outs, 'مرّ الإشعاران فوق قيمة الفاتورة');
        $this->assertEqualsWithDelta(60.0, (float) CustomerCreditNote::where('customer_invoice_id', $invoice->id)->sum('amount'), 0.0001);
        // ٥٠٠ × ٦٠ ÷ ٩٥ = ٣١٥٫٧ ← ٣١٥ تعود، ولا أكثر
        $this->assertSame(315, CustomerInvoices::pointsReturned($invoice));
        $this->assertSame(315, $this->points());
    }

    /**
     * إشعارٌ دائن وبيعةُ صندوقٍ للعميل نفسِه معًا — تنتهيان كلتاهما.
     *
     * الإشعارُ يُمسك المتجرَ برهةً قبل أن يعيد النقاط، والصندوقُ يصل في
     * الأثناء فيصرف نقاطًا. وكلاهما يأخذ المتجرَ قبل العميل، فينتظر الثاني
     * الأوّلَ ولا ينتظره الأوّل.
     */
    public function test_a_credit_note_and_a_till_sale_for_one_customer_at_once_both_finish(): void
    {
        $this->customer->update(['points' => 1000]);
        $invoice = $this->issued(); // ٥٠٠ صُرفت، والرصيدُ ٥٠٠
        $credit = <<<'PHP'
App\Models\CustomerCreditNote::created(function () { usleep(1500000); });
Tests\Support\ReturnNote::issue(App\Models\CustomerInvoice::findOrFail($ids['invoice']), 47.5);
echo 'credited';
PHP;

        [$outs, $sale] = $this->race([$credit], ['invoice' => $invoice->id], fn () => $this->sell(300));

        $this->assertSame(['credited'], $outs);
        $sale->assertOk();
        // ٥٠٠ − ٣٠٠ بالصندوق + ٢٥٠ بالإشعار
        $this->assertSame(450, $this->points());
        $this->assertSame(300, (int) Order::where('business_id', $this->shop->id)->value('redeemed_points'));
        $this->assertSame(250, CustomerInvoices::pointsReturned($invoice));
    }

    /** إصدارٌ يصرف وبيعةُ صندوقٍ تصرف الرصيدَ نفسَه معًا — يسبق أحدُهما، ويُردّ الآخرُ بسببه */
    public function test_issuing_and_a_till_sale_at_once_spend_the_balance_once(): void
    {
        $draft = $this->draft();
        [$outs, $sale] = $this->race([sprintf(self::ISSUE, $draft->id)], [], fn () => $this->sell(300));

        $this->assertSame(['issued'], $outs, 'الإصدارُ أمسك الرصيدَ أوّلًا');
        // ويُردّ الصندوقُ ومعه الرصيدُ الصحيح — فيسأل الكاشيرُ من جديد
        $sale->assertStatus(422)->assertJsonValidationErrors('redeem_points')->assertJsonPath('loyalty.balance', 0);
        $this->assertSame(0, $this->points());
        $this->assertSame(1, $this->spends());
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count(), 'ولا بيعةَ بخصمٍ لا رصيدَ له');
    }

    /**
     * وبطريقة النسبة كذلك: فاتورةٌ تصرف خمسَمئة (٥٠٪ من مئة) وصندوقٌ يطلب
     * ثلاثَمئة (٣٠٪ = ثلاثون) في اللحظة نفسها — يسبق أحدُهما، ويُردّ الآخر
     * بالرصيد الصحيح، ولا ينزل الرصيدُ تحت الصفر.
     */
    public function test_in_percent_mode_issuing_and_a_till_sale_at_once_spend_the_balance_once(): void
    {
        foreach (['loyalty_redeem_mode' => 'percent', 'loyalty_redeem_percent' => '10', 'loyalty_redeem_points' => '100'] as $key => $value) {
            Setting::create(['business_id' => $this->shop->id, 'key' => $key, 'value' => $value]);
        }
        $draft = $this->draft();
        $this->assertEqualsWithDelta(50.0, (float) $draft->total, 0.0001, '٥٠٠ نقطة = نصفُ المئة');

        [$outs, $sale] = $this->race([sprintf(self::ISSUE, $draft->id)], [], fn () => $this->actingAs($this->owner)
            ->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), [
                'items' => [['id' => $this->product->id, 'name' => 'باقة', 'qty' => 1]],
                'payment_method' => 'نقدي', 'customer_id' => $this->customer->id, 'client_uuid' => uniqid('race', true),
                'redeem_points' => 300, 'redeem_discount' => 30,
            ]));

        $this->assertSame(['issued'], $outs);
        $sale->assertStatus(422)->assertJsonValidationErrors('redeem_points')->assertJsonPath('loyalty.balance', 0);
        $this->assertSame(0, $this->points());
        $this->assertSame(1, $this->spends());
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
    }

    /** إلغاءٌ وإشعارٌ على الورقة نفسِها معًا: يعود ما صُرف كلُّه، مرّةً — ولا إشعارَ على ملغاة */
    public function test_a_cancel_and_a_credit_note_at_once_return_the_points_once(): void
    {
        $invoice = $this->issued();
        $cancel = <<<'PHP'
App\Models\PointTransaction::created(function () { usleep(800000); });
App\Support\CustomerInvoices::cancel(App\Models\CustomerInvoice::findOrFail($ids['invoice']), 'أُلغيت');
echo 'cancelled';
PHP;
        $credit = <<<'PHP'
try {
    Tests\Support\ReturnNote::issue(App\Models\CustomerInvoice::findOrFail($ids['invoice']), 47.5);
    echo 'credited';
} catch (RuntimeException|Illuminate\Validation\ValidationException $e) {
    echo 'refused';
}
PHP;

        [$outs] = $this->race([$cancel, $credit], ['invoice' => $invoice->id]);

        $this->assertSame('cancelled', $outs[0]);
        $this->assertSame(500, CustomerInvoices::pointsReturned($invoice));
        $this->assertSame(500, $this->points());
        if ($outs[1] === 'credited') {
            // سبق الإشعارُ: صدر على ورقةٍ قائمة، ثمّ أعاد الإلغاءُ الباقي
            $this->assertSame(2, PointTransaction::where('customer_invoice_id', $invoice->id)->where('type', 'earn')->count());
        } else {
            $this->assertSame('refused', $outs[1]);
            $this->assertSame(0, CustomerCreditNote::where('customer_invoice_id', $invoice->id)->count(), 'إشعارٌ على فاتورةٍ ملغاة');
        }
    }

    /**
     * إلغاءٌ وإشعارٌ تتقاطع أقفالُهما — فلا يُسقط أحدُهما الآخر.
     *
     * الإشعارُ يقفل المتجرَ ثمّ الورقة (كتابتُه تطلب صفَّها بمفتاحها الأجنبيّ، ثمّ
     * `returnPointsForCredit`). فإلغاءٌ يقفل الورقةَ ثمّ المتجر (`Ledger::reverse`) ينتظر كلٌّ منهما ما في
     * يد الآخر، فتُسقط القاعدةُ أحدَهما (40P01) ويرى الموظّفُ خطأً لا سببَ له.
     * والترتيبُ الواحد — المتجرُ ثمّ الورقةُ ثمّ العميل — يجعلهما ينتظران ولا
     * يتعارضان: الإشعارُ يصدر، ثمّ يلغي الإلغاءُ ويعيد الباقي.
     */
    public function test_a_cancel_and_a_credit_note_crossing_their_locks_both_finish(): void
    {
        $invoice = $this->issued();
        $cancel = <<<'PHP'
// يدُ الإلغاء تبدأ بعد أن يمسك الإشعارُ المتجرَ — فتمسك الورقةَ وهو ممسكٌ به
usleep(250000);
try {
    App\Support\CustomerInvoices::cancel(App\Models\CustomerInvoice::findOrFail($ids['invoice']), 'أُلغيت');
    echo 'cancelled';
} catch (Illuminate\Database\QueryException $e) {
    echo 'lock-error:'.$e->getCode();
}
PHP;
        $credit = <<<'PHP'
// يدُ الإشعار تمسك المتجرَ ثمّ تتمهّل قبل أن تكتب الإشعار (والكتابةُ تطلب صفَّ الورقة بمفتاحها الأجنبيّ)
App\Models\Business::retrieved(function () { usleep(1000000); });
try {
    Tests\Support\ReturnNote::issue(App\Models\CustomerInvoice::findOrFail($ids['invoice']), 47.5);
    echo 'credited';
} catch (Illuminate\Database\QueryException $e) {
    echo 'lock-error:'.$e->getCode();
} catch (RuntimeException|Illuminate\Validation\ValidationException $e) {
    echo 'refused';
}
PHP;

        [$outs] = $this->race([$cancel, $credit], ['invoice' => $invoice->id]);

        $this->assertSame(['cancelled', 'credited'], $outs, 'تقاطعُ الأقفال أسقط إحدى اليدين');
        $this->assertSame(CustomerInvoice::CANCELLED, $invoice->fresh()->status);
        $this->assertSame(1, CustomerCreditNote::where('customer_invoice_id', $invoice->id)->count());
        $this->assertSame(500, CustomerInvoices::pointsReturned($invoice));
        $this->assertSame(500, $this->points());
    }

    /** إلغاءان معًا: النقاطُ تعود مرّةً والقيدُ يُعكس مرّة */
    public function test_two_cancels_at_once_return_the_points_once(): void
    {
        $invoice = $this->issued();
        $cancel = <<<'PHP'
App\Models\PointTransaction::created(function () { usleep(800000); });
App\Support\CustomerInvoices::cancel(App\Models\CustomerInvoice::findOrFail($ids['invoice']), 'أُلغيت');
echo 'cancelled';
PHP;

        $this->race([$cancel, $cancel], ['invoice' => $invoice->id]);

        $this->assertSame(500, $this->points());
        $this->assertSame(1, PointTransaction::where('customer_invoice_id', $invoice->id)->where('type', 'earn')->count());
        $this->assertSame(1, JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $invoice->id)->whereNotNull('reversed_at')->count());
    }

    /**
     * يدٌ تفتح المسودّة لتلغيها، ويدٌ تُصدرها في اللحظة نفسها.
     *
     * الإلغاءُ قرأ «مسودّة» قبل أن يكتب الإصدارُ قيدَه. فإن بنى على تلك القراءة
     * كتب «ملغاة» فوق «صادرة» وترك القيدَ قائمًا (الإصلاح: يقفل الصفّ ويقرأ من
     * جديد). فالنتيجةُ: ملغاةٌ، وقيدُها معكوسٌ مرّة، ونقاطُها عادت مرّة.
     */
    public function test_issuing_and_cancelling_one_draft_at_once_leave_no_live_entry_behind(): void
    {
        $draft = $this->draft();
        $cancel = <<<'PHP'
$stale = App\Models\CustomerInvoice::findOrFail($ids['invoice']);
usleep(1500000); // يدُ الإلغاء تقرأ المسودّة ثمّ تتأخّر — والإصدارُ يمضي
App\Support\CustomerInvoices::cancel($stale, 'أُلغيت');
echo 'cancelled';
PHP;

        [$outs] = $this->race([sprintf(self::ISSUE, $draft->id), $cancel], ['invoice' => $draft->id]);

        $this->assertSame(['issued', 'cancelled'], $outs);
        $this->assertSame(CustomerInvoice::CANCELLED, $draft->fresh()->status);
        $entries = JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $draft->id)->get();
        $this->assertSame(1, $entries->whereNull('reverses_id')->count(), 'قيدُ الإصدار مرّة');
        $this->assertSame(1, $entries->whereNull('reverses_id')->whereNotNull('reversed_at')->count(), 'قيدٌ قائمٌ على فاتورةٍ ملغاة');
        $this->assertSame(500, $this->points());
        $this->assertSame(1, $this->spends());
        $this->assertSame(1, PointTransaction::where('customer_invoice_id', $draft->id)->where('type', 'earn')->count());
    }

    /*
     * ═══ وفاتورةٌ تغطّيها النقاطُ كلُّها — إجماليٌّ صفر ═══
     *
     * سقوفٌ تركها صاحبُ النشاط فارغة: خمسُمئة نقطةٍ تغطّي ورقةً بخمسة. ولا قيدَ
     * لها، فالقفلُ والمفتاحُ الفريد وحدهما يمنعان الصرفَ والإعادةَ مرّتين.
     */
    private function wholeDraft(): CustomerInvoice
    {
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'loyalty_redeem_max_pct'], ['value' => '']);

        return CustomerInvoices::create($this->shop->id, $this->customer, [
            'issued_at' => now()->toDateString(), 'redeem_points' => 500,
        ], [['description' => 'وردة', 'quantity' => 1, 'unit_price' => 5]], $this->owner->id);
    }

    public function test_two_whole_points_drafts_issued_at_once_spend_the_balance_once(): void
    {
        $a = $this->wholeDraft();
        $b = $this->wholeDraft();
        $this->assertEqualsWithDelta(0.0, (float) $a->total, 0.0001);

        [$outs] = $this->race([sprintf(self::ISSUE, $a->id), sprintf(self::ISSUE, $b->id)], []);

        sort($outs);
        $this->assertSame(['issued', 'refused'], $outs);
        $this->assertSame(0, $this->points(), 'ولا رصيدَ سالب');
        $this->assertSame(1, $this->spends());
        $this->assertSame(0, JournalEntry::where('sourceable_type', CustomerInvoice::class)->whereIn('sourceable_id', [$a->id, $b->id])->count());
        $this->assertEqualsCanonicalizing([CustomerInvoice::DRAFT, CustomerInvoice::ISSUED], CustomerInvoice::whereIn('id', [$a->id, $b->id])->pluck('status')->all());
    }

    public function test_the_same_whole_points_draft_issued_twice_at_once_spends_and_numbers_once(): void
    {
        $draft = $this->wholeDraft();
        $body = sprintf(self::ISSUE, $draft->id);

        [$outs] = $this->race([$body, $body], []);

        $this->assertSame(['issued', 'issued'], $outs);
        $this->assertSame(0, $this->points());
        $this->assertSame(1, $this->spends());
        $this->assertNotNull($draft->fresh()->number);
        $this->assertSame(1, CustomerInvoice::where('business_id', $this->shop->id)->where('number', $draft->fresh()->number)->count());
        $this->assertSame(0, JournalEntry::where('sourceable_type', CustomerInvoice::class)->where('sourceable_id', $draft->id)->count());
    }

    public function test_two_cancels_of_a_whole_points_invoice_at_once_return_the_points_once(): void
    {
        $invoice = CustomerInvoices::issue($this->wholeDraft(), $this->owner->id)->fresh();
        $this->assertSame(0, $this->points());
        $cancel = <<<'PHP'
App\Models\PointTransaction::created(function () { usleep(800000); });
App\Support\CustomerInvoices::cancel(App\Models\CustomerInvoice::findOrFail($ids['invoice']), 'أُلغيت');
echo 'cancelled';
PHP;

        $this->race([$cancel, $cancel], ['invoice' => $invoice->id]);

        $this->assertSame(500, $this->points());
        $this->assertSame(1, PointTransaction::where('customer_invoice_id', $invoice->id)->where('type', 'earn')->count());
        $this->assertSame(CustomerInvoice::CANCELLED, $invoice->fresh()->status);
    }

    /** إصدارُ ورقةٍ صفريّة وبيعةُ صندوقٍ على الرصيد نفسِه معًا: يسبق أحدُهما، ويُردّ الآخر */
    public function test_a_whole_points_issue_and_a_till_sale_at_once_spend_the_balance_once(): void
    {
        $draft = $this->wholeDraft();

        [$outs, $sale] = $this->race([sprintf(self::ISSUE, $draft->id)], [], fn () => $this->sell(300));

        $this->assertSame(['issued'], $outs);
        $sale->assertStatus(422)->assertJsonValidationErrors('redeem_points')->assertJsonPath('loyalty.balance', 0);
        $this->assertSame(0, $this->points());
        $this->assertSame(1, $this->spends());
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count());
    }

    private const OTHER_TILL = <<<'PHP'
// صندوقٌ آخر يصرف أربعمئة من الخمسمئة ويُمسك الصفَّ برهةً قبل أن يُودع
Illuminate\Support\Facades\DB::transaction(function () use ($ids) {
    App\Support\Loyalty::spend(App\Models\Customer::findOrFail($ids['customer']), 400, 'صندوقٌ آخر');
    usleep(1500000);
});
echo 'spent';
PHP;

    /**
     * صندوقان يصرفان الرصيدَ نفسَه معًا — والثاني بيعةٌ تُرسل الآن.
     *
     * الأوّلُ يأخذ أربعمئة. والثاني رأى خمسمئةً وعرض ثلاثمئة: يُردّ بلا كتابة،
     * ومعه الرصيدُ الصحيح (مئة) والخصمُ المتاح الآن — ولا رصيدَ سالب.
     */
    public function test_two_tills_spending_one_balance_at_once_refuse_the_live_one_with_the_right_offer(): void
    {
        [$outs, $sale] = $this->race([self::OTHER_TILL], ['customer' => $this->customer->id], fn () => $this->sell(300));

        $this->assertSame(['spent'], $outs);
        $sale->assertStatus(422)->assertJsonValidationErrors('redeem_points')
            ->assertJsonPath('loyalty.balance', 100)
            ->assertJsonPath('loyalty.points', 100)
            ->assertJsonPath('loyalty.discount', 1);
        $this->assertSame(100, $this->points());
        $this->assertSame(1, $this->spends());
        $this->assertSame(0, Order::where('business_id', $this->shop->id)->count(), 'لا بيعةَ بخصمٍ لم يُؤكَّد');
    }

    /**
     * والثاني بيعةٌ اكتملت بلا اتصال ووصلت في اللحظة نفسها.
     *
     * لا تُردّ ولا يتغيّر مبلغُها: تُكتب بخصمها (ثلاثة)، ويُصرف ما بقي (مئة)
     * لا أكثر، والفرقُ للمراجعة. والرصيدُ صفرٌ لا سالب.
     */
    public function test_a_replayed_sale_racing_another_till_spends_only_what_is_left(): void
    {
        [$outs, $sale] = $this->race([self::OTHER_TILL], ['customer' => $this->customer->id], fn () => $this->sell(300, replay: true));

        $this->assertSame(['spent'], $outs);
        $sale->assertOk();
        $order = Order::where('business_id', $this->shop->id)->sole();
        $this->assertEqualsWithDelta(3.0, (float) $order->discount, 0.0001, 'الخصمُ كما قُبض');
        $this->assertEqualsWithDelta(97.0, (float) $order->total, 0.0001, 'والمبلغُ المدفوع لم يتغيّر');
        $this->assertSame(100, (int) $order->redeemed_points);
        $this->assertSame(0, $this->points(), 'ولا رصيدَ سالب');
        $this->assertSame(2, $this->spends());
        $this->assertEqualsWithDelta(2.0, $order->loyalty_review['gap'], 0.0001);
    }
}
