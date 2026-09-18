<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * حذفُ الفرع لا يُطلق موظّفيه على المتجر كلّه.
 *
 * ═══ العطب ═══
 *
 * إسنادُ الفروع (`branch_user`) قيدٌ: من أُسند إلى «الخوير» لا يبيع في
 * «السيب». والفراغُ فيه يعني «كلّ الفروع» — وهو صحيحٌ لمن لم يُقيَّد أصلًا:
 * موظّفو كلّ متجرٍ قائم بلا صفوف، ولو كان الفارغُ منعًا لَأُقفل كلّ كاشيرٍ
 * صباح النشر.
 *
 * لكنّ `branches()` تقرأ عبر النموذج، والنموذجُ يُخفي المحذوفَ ليّنًا. فصفٌّ
 * قُيِّد بـ«الخوير» وحدها **يقرأ فارغًا** يوم يُحذف الخوير — وحالتان
 * متناقضتان تقعان على جوابٍ واحد:
 *
 *   «لم يُقيَّد»       → يعمل في كلّ فرع  ✓
 *   «قُيِّد بفرعٍ زال» → صار يعمل في كلّ فرع  ✗
 *
 * قِسناه من طرف الصندوق: كاشيرُ الخوير يُردّ عن صندوق السيب، فيُحذف الخوير،
 * فيفتح صندوق السيب ويبيع عليه — والفاتورة تُكتب على فرعٍ لم يُسنَد إليه
 * يومًا. ويشمل ذلك فرعًا يُفتح بعد الحذف بشهر.
 *
 * وحذفُ الفرع فعلٌ إداريّ عاديّ — يقع حين يُقفل محلّ — فلا يصحّ أن يكون
 * بابَ توسعةِ صلاحية.
 */
class AStaffMemberIsNotFreedByDeletingHisBranchTest extends TestCase
{
    use RefreshDatabase;

    private Business $biz;

    private User $owner;

    private User $cashier;

    private Branch $khuwair;

    private Branch $seeb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->biz = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $this->biz->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->khuwair = Branch::create(['business_id' => $this->biz->id, 'name' => 'الخوير']);
        $this->seeb = Branch::create(['business_id' => $this->biz->id, 'name' => 'السيب']);

        $this->owner = User::create([
            'business_id' => $this->biz->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->cashier = User::create([
            'business_id' => $this->biz->id, 'name' => 'كاشير الخوير', 'email' => 'k@abaad.om',
            'password' => 'password', 'role' => 'cashier', 'job_title' => 'كاشير', 'status' => 'نشط',
        ]);
        $this->cashier->branches()->sync([$this->khuwair->id]);
    }

    private function till(Branch $branch): array
    {
        $raw = Str::random(64);

        return [PosDevice::create([
            'business_id' => $this->biz->id, 'branch_id' => $branch->id, 'name' => 'صندوق '.$branch->name,
            'token_hash' => hash('sha256', $raw), 'status' => PosDevice::ACTIVE, 'activated_at' => now(),
        ]), $raw];
    }

    private function openTill(Branch $branch)
    {
        [$device, $raw] = $this->till($branch);

        return $this->withCookie(PosTerminal::COOKIE, $device->id.'|'.$raw)
            ->actingAs($this->cashier)->get(route('pos.index'));
    }

    private function deleteKhuwair(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('admin.branches.destroy', $this->khuwair->id));

        // وإن لم يُحذف الفرعُ لم يُقَس شيء — فالحذفُ نفسُه يُتحقَّق منه
        $this->assertSoftDeleted('branches', ['id' => $this->khuwair->id]);
    }

    /* ─────────── ما كان صحيحًا يبقى ─────────── */

    /** المقيَّدُ بفرعه يفتح صندوقَه */
    public function test_a_cashier_opens_the_till_of_his_own_branch(): void
    {
        $this->openTill($this->khuwair)->assertOk();
    }

    /** ولا يفتح صندوق غيره */
    public function test_a_cashier_does_not_open_another_branchs_till(): void
    {
        $this->openTill($this->seeb)->assertRedirect(route('login'));
    }

    /**
     * ومن لم يُقيَّد يفتح كلَّ صندوق — والفراغُ الحقيقيّ إطلاقٌ لا منع.
     *
     * وهو ما يجعل هذا الحارسَ لازمًا: لو أُغلق الفراغُ كلُّه لَوقف كلُّ
     * كاشيرٍ قائمٍ اليوم، إذ لا صفوفَ لأحدٍ منهم.
     */
    public function test_an_unrestricted_cashier_opens_every_till(): void
    {
        $this->cashier->branches()->sync([]);

        $this->openTill($this->khuwair)->assertOk();
        $this->openTill($this->seeb)->assertOk();
    }

    /* ─────────── وحذفُ الفرع لا يُطلق ─────────── */

    /**
     * فرعُه حُذف — فلا يفتح صندوق فرعٍ آخر.
     *
     * والبديلُ الذي كان: يفتحه ويبيع عليه. وهو أسوأُ الاثنين بفارق — الردُّ
     * يُقال عند الباب («هذا الجهاز يعمل على فرعٍ لست مُسنَدًا إليه») ويُراجَع
     * المدير، والبيعُ الخاطئ لا يُكتشف إلّا في جردٍ آخر الشهر.
     */
    public function test_deleting_his_branch_does_not_open_the_others(): void
    {
        $this->deleteKhuwair();

        $this->openTill($this->seeb)->assertRedirect(route('login'));
    }

    /** ولا فرعًا يُفتح بعد الحذف */
    public function test_nor_a_branch_opened_after_the_deletion(): void
    {
        $this->deleteKhuwair();

        $later = Branch::create(['business_id' => $this->biz->id, 'name' => 'فرع جديد']);

        $this->openTill($later)->assertRedirect(route('login'));
    }

    /** والقياسُ نفسُه على `worksAt` — هي مصدرُ الحكم لا الشاشة */
    public function test_works_at_fails_closed_for_a_stranded_assignment(): void
    {
        $this->assertTrue($this->cashier->fresh()->worksAt($this->khuwair->id));
        $this->assertFalse($this->cashier->fresh()->worksAt($this->seeb->id));

        $this->deleteKhuwair();

        $this->assertFalse($this->cashier->fresh()->worksAt($this->seeb->id),
            'فرعٌ حُذف فتح لصاحبه كلَّ الفروع');
    }

    /**
     * والصفُّ يبقى — فالاستعادةُ تُعيد الإسناد كما كان.
     *
     * ولو مُحي عند الحذف لَوجب على المدير أن يُعيد بناءه بيدٍ بعد كلّ تراجع،
     * ولا شيءَ يذكّره بمن كان مُسنَدًا.
     */
    public function test_restoring_the_branch_restores_the_assignment(): void
    {
        $this->deleteKhuwair();

        $this->assertSame(1, DB::table('branch_user')->where('user_id', $this->cashier->id)->count(),
            'مُحي صفُّ الإسناد فلا يعود بالاستعادة');

        $this->actingAs($this->owner)
            ->post(route('admin.branches.restore', $this->khuwair->id), ['type' => 'branch']);

        $this->assertNull($this->khuwair->fresh()->deleted_at, 'لم يُستعد الفرع');
        $this->assertTrue($this->cashier->fresh()->worksAt($this->khuwair->id),
            'عاد الفرعُ ولم يعد إسنادُه');
        $this->assertFalse($this->cashier->fresh()->worksAt($this->seeb->id));
    }

    /** ومن له فرعان يفقد أحدهما يبقى على الآخر — ولا يُطلق ولا يُقفل */
    public function test_losing_one_of_two_branches_leaves_the_other(): void
    {
        $this->cashier->branches()->sync([$this->khuwair->id, $this->seeb->id]);

        $this->deleteKhuwair();

        $this->assertTrue($this->cashier->fresh()->worksAt($this->seeb->id));

        $later = Branch::create(['business_id' => $this->biz->id, 'name' => 'فرع ثالث']);
        $this->assertFalse($this->cashier->fresh()->worksAt($later->id),
            'فقدُ فرعٍ من فرعين فتح فرعًا ثالثًا');
    }

    /* ─────────── ويُقال للمدير ساعةَ يحذف ─────────── */

    /**
     * الصمتُ هو ما يجعل المنعَ عطبًا.
     *
     * المديرُ واقفٌ على الزرّ — فهذه لحظةُ قولها. ولولاها لاكتشفها من كاشيرٍ
     * يُردّ صباحًا عن صندوقه ولا يعرف أحدٌ لماذا.
     */
    public function test_the_manager_is_told_who_is_left_without_a_branch(): void
    {
        $this->deleteKhuwair();

        $msg = (string) (session('toast')['msg'] ?? '');
        $this->assertStringContainsString('فرعهم الوحيد', $msg, 'حُذف الفرع ولم يُقل من بقي بلا فرع');
        $this->assertStringContainsString('1', $msg, 'الرسالة لا تقول كم موظّفًا');
    }

    /** ولا يُعدّ من له فرعٌ آخر */
    public function test_someone_with_another_branch_is_not_counted(): void
    {
        $this->cashier->branches()->sync([$this->khuwair->id, $this->seeb->id]);

        $this->deleteKhuwair();

        $this->assertStringNotContainsString('فرعهم الوحيد', (string) (session('toast')['msg'] ?? ''),
            'عُدّ موظّفٌ له فرعٌ آخر في من بقي بلا فرع');
    }

    /** ولا موظّفُ متجرٍ آخر */
    public function test_a_neighbours_staff_is_not_counted(): void
    {
        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        $his = User::create([
            'business_id' => $neighbour->id, 'name' => 'كاشير الجار', 'email' => 'n@abaad.om',
            'password' => 'password', 'role' => 'cashier', 'status' => 'نشط',
        ]);
        $his->branches()->sync([$this->khuwair->id]);

        $this->deleteKhuwair();

        $msg = (string) (session('toast')['msg'] ?? '');
        $this->assertStringContainsString('1', $msg, 'العدد تغيّر بموظّف متجرٍ آخر');
        $this->assertStringNotContainsString('2', $msg, 'عُدّ موظّفُ متجرٍ آخر');
    }
}
