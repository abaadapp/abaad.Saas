<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * إهلاكُ الشهر يُرحَّل مرّةً — ولو ضُغط الزرُّ مرّتين معًا.
 *
 * ═══ ما كان محروسًا وما لم يكن ═══
 *
 * الضغطتان **المتتاليتان** محروستان: الأولى تكتب `depreciated_through`،
 * فتجد الثانيةُ المستحقَّ صفرًا وتنصرف.
 *
 * والمتزامنتان لا. `depreciate` تقرأ الأصولَ ومستحقَّها **خارج المعاملة
 * وبلا قفل**، ثمّ ترحّل القيدَ وتكتب الأصولَ داخلها. فنداءان يقعان قبل أن
 * يكتب أحدُهما — وهو ما يقع حين يبطؤ الردُّ فيُضغط الزرُّ ثانيةً، أو حين
 * يرحّله محاسبان في آخر الشهر — يقرآن المستحقَّ نفسَه فيرحّلانه مرّتين.
 *
 * ═══ وما يبقى بعدها ═══
 *
 * الدفترُ يحمل قيدين، والأصلُ يحمل شهرًا واحدًا: الكتابةُ الثانية تُحسب من
 * نسخةٍ قُرئت قبل الأولى (`accumulated + due`) فتكتب القيمةَ نفسَها فوقها.
 *
 * فمصروفُ الإهلاك في الدفتر ضِعفُ ما يقوله سجلُّ الأصول، ومجمَّعُ الإهلاك
 * مثلُه — وهما رقمان يدخلان الميزانيّةَ وقائمةَ الدخل. ولا شيءَ في الشاشة
 * يقول ذلك: الأصلُ يبدو سليمًا، والخللُ في الطرف الآخر من الدفتر.
 *
 * ولا يُكتشف إلّا حين يُقارَن سجلُّ الأصول بالدفتر — وهو ما لا يقع إلّا عند
 * مراجعةٍ سنويّة، إن وقع.
 */
class TheMonthIsDepreciatedOnceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password1'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    private function bid(): int
    {
        return (int) $this->business->id;
    }

    /** أصلٌ يُسجَّل من بابه — فالشجرةُ تُبنى والدفترُ يبدأ متوازنًا */
    private function asset(): FixedAsset
    {
        $this->get(route('admin.finance.assets'));

        $this->post(route('admin.finance.assets.store'), [
            'name' => 'ثلاجة عرض',
            // أوّلُ الشهر ثمّ الطرح: `subMonths` يفيض في اليوم ٣١
            'purchased_at' => now()->startOfMonth()->subMonths(2)->toDateString(),
            'cost' => 1200,
            'salvage_value' => 0,
            'life_months' => 12,
            'paid_from' => 'cash',
        ])->assertSessionHasNoErrors();

        return FixedAsset::where('business_id', $this->bid())->latest('id')->firstOrFail();
    }

    private function depreciate()
    {
        return $this->post(route('admin.finance.assets.depreciate'), ['month' => now()->format('Y-m')]);
    }

    /**
     * محاسبٌ ثانٍ يرحّله بين قراءتنا وكتابتنا.
     *
     * ويُقاس محسومًا بلا خيطين: يُنصَت لاستعلام الأصول — وهو يقع **خارج**
     * المعاملة — فيُرحَّل الإهلاكُ كاملًا من تحته. فتمضي نسختُنا بما قرأت.
     */
    private function someoneElsePostsItFirst(): void
    {
        $fired = false;

        DB::listen(function ($q) use (&$fired) {
            if ($fired || ! str_contains($q->sql, 'fixed_assets') || ! str_contains($q->sql, 'status')) {
                return;
            }
            $fired = true;

            $this->depreciate();
        });
    }

    /** ما يُستحقّ: شهرُ الشراء وشهران بعده × ١٠٠ */
    private const DUE = 300.0;

    /* ─────────────── ما يجب أن يقع ─────────────── */

    public function test_two_at_once_do_not_double_the_month(): void
    {
        $asset = $this->asset();
        $this->someoneElsePostsItFirst();

        $this->depreciate();

        $this->assertSame(self::DUE, (float) $asset->fresh()->accumulated, 'سجلُّ الأصل تحرّك مرّتين');
        $this->assertSame(
            self::DUE,
            Ledger::account($this->bid(), 'depreciation')->balance(),
            'مصروفُ الإهلاك في الدفتر ضِعفُ ما يقوله سجلُّ الأصول',
        );
        $this->assertSame(
            self::DUE,
            Ledger::account($this->bid(), 'accumulated_depreciation')->balance(),
            'مجمَّعُ الإهلاك في الدفتر ضِعفُ ما يقوله سجلُّ الأصول',
        );
    }

    /** ولا يُكتب قيدُ إهلاكٍ ثانٍ لشهرٍ واحد */
    public function test_only_one_entry_carries_the_month(): void
    {
        $this->asset();
        $this->someoneElsePostsItFirst();

        $this->depreciate();

        $this->assertSame(
            1,
            JournalEntry::where('business_id', $this->bid())->where('source', 'إهلاك')->count(),
            'الدفترُ يحمل قيدَي إهلاكٍ لشهرٍ واحد',
        );
    }

    /**
     * والدفترُ وسجلُّ الأصول يقولان الرقمَ نفسَه.
     *
     * وهو المقياسُ الذي يُقرأ وحده لو نُسي ما قبله: ما في الدفتر هو ما في
     * السجلّ، مهما كان عددُ من ضغط الزرّ.
     */
    public function test_the_ledger_and_the_register_say_the_same_number(): void
    {
        $asset = $this->asset();
        $this->someoneElsePostsItFirst();

        $this->depreciate();

        $this->assertSame(
            (float) $asset->fresh()->accumulated,
            Ledger::account($this->bid(), 'accumulated_depreciation')->balance(),
            'سجلُّ الأصول والدفترُ افترقا',
        );
    }

    /* ─────────────── والرسالةُ تقول ما وقع ─────────────── */

    /**
     * ومن سبقه غيرُه يُقال له — لا رسالةٌ خضراء بترحيلٍ لم يقع.
     *
     * وهذا هو الدرسُ نفسُه الذي أخرج عطبَ صرف الرواتب: الحارسُ يصمد
     * والدفترُ سليم، ثمّ تُكتب الرسالةُ من نيّةِ ما قبل المعاملة.
     */
    public function test_a_raced_posting_says_nothing_was_due(): void
    {
        $this->asset();
        $this->someoneElsePostsItFirst();

        $this->depreciate()->assertSessionHas('toast');
        $toast = session('toast');

        $this->assertSame('info', $toast['type'], 'رسالةٌ خضراء لترحيلٍ لم يقع');
        $this->assertStringContainsString('لا إهلاك مستحقّ', (string) $toast['msg']);
    }

    /** والترحيلُ الواقع يقول عددَه ومبلغَه — من المعاملة لا من قراءةٍ قبلها */
    public function test_the_message_carries_what_was_actually_posted(): void
    {
        $this->asset();

        $this->depreciate()->assertSessionHas('toast');
        $toast = session('toast');

        $this->assertSame('success', $toast['type']);
        $this->assertStringContainsString('300.000', (string) $toast['msg'], 'المبلغُ في الرسالة ليس ما رُحّل');
        $this->assertStringContainsString('1', (string) $toast['msg'], 'العددُ في الرسالة ليس ما رُحّل');
    }

    /* ─────────────── وما كان يعمل يبقى ─────────────── */

    /** والترحيلُ الهادئ يقع كما كان — شهرُ الشراء وما بعده */
    public function test_a_quiet_posting_still_carries_the_month(): void
    {
        $asset = $this->asset();

        $this->depreciate()->assertSessionHasNoErrors();

        $this->assertSame(self::DUE, (float) $asset->fresh()->accumulated);
        $this->assertSame(self::DUE, Ledger::account($this->bid(), 'depreciation')->balance());
    }

    /** والضغطتان المتتاليتان تبقيان محروستين — حارسٌ قائمٌ لا يُكسر بالإصلاح */
    public function test_pressing_twice_in_a_row_still_does_not_double(): void
    {
        $asset = $this->asset();

        $this->depreciate();
        $this->depreciate();

        $this->assertSame(self::DUE, (float) $asset->fresh()->accumulated);
        $this->assertSame(self::DUE, Ledger::account($this->bid(), 'depreciation')->balance());
    }

    /** ومن لا مستحقَّ عنده يُقال له — لا يُكتب قيدٌ فارغ */
    public function test_nothing_due_writes_nothing(): void
    {
        $this->asset();
        $this->depreciate();

        $before = JournalEntry::where('business_id', $this->bid())->count();
        $this->depreciate()->assertSessionHasNoErrors();

        $this->assertSame($before, JournalEntry::where('business_id', $this->bid())->count());
    }
}
