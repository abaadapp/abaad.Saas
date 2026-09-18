<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الحسابُ الذي يرحّل إليه النظام لا يصير أبًا.
 *
 * ═══ العطبُ كما وقع على متجرٍ حقيقيّ ═══
 *
 * قائمةُ «الحساب الأب» كانت تعرض الحسابات كلَّها. فأضاف تاجرٌ «بترول (تنقل)»
 * تحت «٥٩٠٠ مصروفات أخرى» — تبويبٌ معقول تمامًا — فصار ٥٩٠٠ أبًا.
 *
 * و`Account::isPostable` تشترط ألّا فروعَ للحساب: مجموعُ الأب هو مجموعُ
 * فروعه، وقيدٌ عليه مباشرةً يجعل الشجرة تقول رقمين. فتوقّف في تلك اللحظة
 * كلُّ ما يُرحَّل إلى ٥٩٠٠ — تسويةُ المخزون وفاقدُ الجرد — ولا رسالةَ تقول
 * لماذا: يضغط التاجر «حفظ التسوية» فيُردّ بخطأ خادم.
 *
 * والإغلاقُ كان محروسًا منذ زمن («إغلاقه يوقف البيع والشراء»)، وأثرُ الأبوّة
 * هو أثرُ الإغلاق بحرفه — فحارسٌ في بابٍ وبابان بلا حارس.
 *
 * ═══ وثلاثةُ أشياء تُختبر هنا ═══
 *
 * أنّ البابين أُغلقا، وأنّ الشاشة لا تعرض ما يُردّ فاتحُه، وأنّ ما وقع قبل
 * الإغلاق يُصلَح بالهجرة — فيعود الحسابُ ورقةً ويبقى الفرعُ في الشجرة.
 */
class ASystemAccountIsNeverAParentTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_09_000000_a_system_account_is_never_a_parent.php';

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function leaf(string $key): Account
    {
        return Ledger::account($this->business->id, $key);
    }

    /** ما وقع فعلًا: فرعٌ تحت حسابٍ نظاميّ، كُتب في القاعدة قبل أن يُغلق الباب */
    private function breakIt(): Account
    {
        return Account::create([
            'business_id' => $this->business->id,
            'parent_id' => $this->leaf('other_expenses')->id,
            'code' => '87284', 'name' => 'بترول (تنقل)',
            'type' => 'مصروف', 'normal_side' => 'debit',
        ]);
    }

    /* ==================== البابان ==================== */

    public function test_a_new_account_may_not_hide_under_a_system_account(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.finance.chart.store'), [
                'parent_id' => $this->leaf('other_expenses')->id,
                'code' => '87284', 'name' => 'بترول (تنقل)', 'type' => 'مصروف',
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertTrue($this->leaf('other_expenses')->isPostable());
    }

    public function test_nor_may_an_existing_one_be_moved_under_it(): void
    {
        $free = Account::create([
            'business_id' => $this->business->id, 'code' => '5950', 'name' => 'تنقّلات',
            'type' => 'مصروف', 'normal_side' => 'debit',
        ]);

        $this->actingAs($this->owner)
            ->put(route('admin.finance.chart.update', $free->id), [
                'parent_id' => $this->leaf('cash')->id,
                'code' => '5950', 'name' => 'تنقّلات', 'type' => 'مصروف', 'normal_side' => 'debit',
            ])
            ->assertSessionHasErrors('parent_id');

        $this->assertTrue($this->leaf('cash')->isPostable());
    }

    public function test_but_a_group_that_is_not_the_machines_own_still_takes_children(): void
    {
        // ‏«٥ المصروفات» تبويبٌ لا يرحّل إليه أحد — وهو مكان «بترول (تنقل)» الصحيح
        $group = Account::where('business_id', $this->business->id)->where('code', '5')->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('admin.finance.chart.store'), [
                'parent_id' => $group->id,
                'code' => '87284', 'name' => 'بترول (تنقل)', 'type' => 'مصروف',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Account::where('parent_id', $group->id)->where('code', '87284')->count());
    }

    public function test_the_screen_does_not_offer_what_the_server_refuses(): void
    {
        // ‏بابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض
        $panel = file_get_contents(resource_path('js/Pages/Admin/Settings/panels/ChartPanel.tsx'));

        $this->assertStringContainsString('.filter((a) => !a.system)', $panel, 'قائمةُ الآباء ما زالت تعرض الحسابات النظاميّة');
    }

    /* ==================== وما وقع قبل الإغلاق ==================== */

    public function test_the_break_is_real_before_it_is_repaired(): void
    {
        $this->breakIt();

        $this->assertFalse($this->leaf('other_expenses')->fresh()->isPostable());
    }

    public function test_the_migration_gives_the_account_its_leaf_back(): void
    {
        $this->breakIt();

        (require base_path(self::MIGRATION))->up();

        $this->assertTrue($this->leaf('other_expenses')->fresh()->isPostable());
    }

    public function test_and_keeps_the_branch_where_it_belongs(): void
    {
        $child = $this->breakIt();
        $group = Account::where('business_id', $this->business->id)->where('code', '5')->firstOrFail();

        (require base_path(self::MIGRATION))->up();

        // ‏أختًا لا ابنًا: يبقى تحت «المصروفات» باسمه ورمزه
        $this->assertSame($group->id, $child->fresh()->parent_id);
        $this->assertSame('بترول (تنقل)', $child->fresh()->name);
    }

    public function test_the_migration_touches_nothing_that_is_already_right(): void
    {
        $group = Account::where('business_id', $this->business->id)->where('code', '5')->firstOrFail();
        $child = Account::create([
            'business_id' => $this->business->id, 'parent_id' => $group->id,
            'code' => '87284', 'name' => 'بترول (تنقل)', 'type' => 'مصروف', 'normal_side' => 'debit',
        ]);

        (require base_path(self::MIGRATION))->up();

        $this->assertSame($group->id, $child->fresh()->parent_id);
    }

    public function test_after_the_repair_a_stock_loss_reaches_the_ledger_again(): void
    {
        $this->breakIt();
        (require base_path(self::MIGRATION))->up();

        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'وردة',
            'price' => 10, 'cost' => 4, 'quantity' => 10, 'active' => true,
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.inventory.adjustments.store'), [
                'branch_id' => Branch::where('business_id', $this->business->id)->firstOrFail()->id,
                'product_id' => $product->id,
                'quantity_delta' => 2,
                'reason' => 'تلف',
                'adjusted_at' => now()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        // ‏٢ × ٤ خرجت من المخزون إلى «مصروفات أخرى»
        $this->assertSame(8.0, Ledger::balance($this->business->id, 'other_expenses'));
    }

    /* ==================== والاستدراكُ يُنادى ==================== */

    public function test_the_catch_up_command_is_actually_scheduled(): void
    {
        /*
         * أمرُ استدراكٍ لا يُنادى ليس استدراكًا: كان بيد من يفتح سطر أوامر
         * الخادم وحدَه، ولا يفتحه صاحبُ المتجر — فتبقى بيعةٌ أخفق ترحيلُها
         * خارج الدفتر إلى الأبد.
         */
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($e) => (string) $e->command)
            ->filter(fn ($c) => str_contains($c, 'finance:post-missing-sales'));

        $this->assertCount(1, $commands, 'أمرُ استدراك المبيعات غيرُ مجدول');
    }

    /* ══════════ وحسابُ التاجر عليه قيودٌ — البابُ نفسُه ══════════ */

    /**
     * ═══ العلّةُ ليست في «النظاميّ» بل في الأبوّة ═══
     *
     * الحارسُ فوق يسأل عن `system_key`. و`isPostable` لا تسأل عنه: تشترط
     * ألّا فروعَ للحساب، كائنًا من كان. فمن رحّل إلى حسابٍ أنشأه بيده شهرًا
     * كاملًا، ثمّ بوّبه فأضاف فرعًا تحته — تبويبٌ معقولٌ تمامًا — أقفل بابَه
     * صامتًا: يختفي من قائمة القيد اليدويّ بلا كلمة، وقيودُه الماضية معلّقةٌ
     * على عقدةٍ لا تقبل شيئًا ولا يُعكس منها قيد.
     *
     * والإغلاقُ يُقال لصاحبه («أغلقه إن لم تعد تستعمله») وأثرُه هو الأثرُ
     * نفسُه — فبابٌ يُقال فيه وبابٌ يفعله صامتًا.
     */
    public function test_an_account_that_carries_entries_never_becomes_a_parent(): void
    {
        $mine = Account::create([
            'business_id' => $this->business->id, 'code' => '5950',
            'name' => 'بترول', 'type' => 'مصروف', 'normal_side' => 'debit',
        ]);

        Ledger::post($this->business->id, 'قيد', [
            ['account' => $mine, 'debit' => 10],
            ['account' => 'cash', 'credit' => 10],
        ]);

        $this->actingAs($this->owner)->post(route('admin.finance.chart.store'), [
            'code' => '5951', 'name' => 'بنزين', 'type' => 'مصروف', 'normal_side' => 'debit',
            'parent_id' => $mine->id,
        ])->assertSessionHasErrors('parent_id');

        $this->assertFalse($mine->fresh()->children()->exists(), 'صار أبًا وعليه قيود');
        $this->assertTrue($mine->fresh()->isPostable(), 'أُقفل بابُ الترحيل إليه');
    }

    /** ونقلُ حسابٍ قائمٍ تحته مردودٌ كذلك — البابان لا بابٌ واحد */
    public function test_nor_is_one_moved_under_an_account_that_carries_entries(): void
    {
        $mine = Account::create([
            'business_id' => $this->business->id, 'code' => '5950',
            'name' => 'بترول', 'type' => 'مصروف', 'normal_side' => 'debit',
        ]);
        $other = Account::create([
            'business_id' => $this->business->id, 'code' => '5951',
            'name' => 'بنزين', 'type' => 'مصروف', 'normal_side' => 'debit',
        ]);

        Ledger::post($this->business->id, 'قيد', [
            ['account' => $mine, 'debit' => 10],
            ['account' => 'cash', 'credit' => 10],
        ]);

        $this->actingAs($this->owner)->put(route('admin.finance.chart.update', $other->id), [
            'code' => '5951', 'name' => 'بنزين', 'type' => 'مصروف', 'normal_side' => 'debit',
            'parent_id' => $mine->id,
        ])->assertSessionHasErrors('parent_id');

        $this->assertNull($other->fresh()->parent_id);
    }

    /** وحسابٌ فارغٌ يبقى يقبل فروعًا — القيدُ على من عليه قيود وحده */
    public function test_an_empty_account_still_takes_children(): void
    {
        $mine = Account::create([
            'business_id' => $this->business->id, 'code' => '5950',
            'name' => 'تنقلات', 'type' => 'مصروف', 'normal_side' => 'debit',
        ]);

        $this->actingAs($this->owner)->post(route('admin.finance.chart.store'), [
            'code' => '5951', 'name' => 'بنزين', 'type' => 'مصروف', 'normal_side' => 'debit',
            'parent_id' => $mine->id,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($mine->fresh()->children()->exists());
    }

    /**
     * وشجرةٌ بُنيت قبل هذا الحارس لا تُقفل على صاحبها.
     *
     * أبٌ عليه قيودٌ وتحته فرع — حالٌ قائمةٌ في قواعد اليوم. ولو سُئل
     * الحارسُ عند كلّ حفظ لَرُدّ التاجرُ عن إعادة تسمية فرعٍ لم يمسّ أباه.
     * فالسؤالُ عند تبديل الأب وحده.
     */
    public function test_an_old_tree_is_not_locked_against_its_owner(): void
    {
        $parent = Account::create([
            'business_id' => $this->business->id, 'code' => '5950',
            'name' => 'تنقلات', 'type' => 'مصروف', 'normal_side' => 'debit',
        ]);
        $child = Account::create([
            'business_id' => $this->business->id, 'parent_id' => $parent->id, 'code' => '5951',
            'name' => 'بنزين', 'type' => 'مصروف', 'normal_side' => 'debit',
        ]);

        // قيدٌ على الأب كما في شجرةٍ بُنيت قبل الحارس
        Ledger::post($this->business->id, 'قيد', [
            ['account' => $child, 'debit' => 10],
            ['account' => 'cash', 'credit' => 10],
        ]);
        JournalLine::where('account_id', $child->id)->update(['account_id' => $parent->id]);

        $this->actingAs($this->owner)->put(route('admin.finance.chart.update', $child->id), [
            'code' => '5951', 'name' => 'بنزين وزيوت', 'parent_id' => $parent->id,
            'type' => 'مصروف', 'normal_side' => 'debit',
        ])->assertSessionHasNoErrors();

        $this->assertSame('بنزين وزيوت', $child->fresh()->name);
    }
}
