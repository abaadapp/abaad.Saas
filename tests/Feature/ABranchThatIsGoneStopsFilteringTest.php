<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فرعٌ لم يعد موجودًا لا يبقى يُرشِّح الشاشات.
 *
 * ═══ العطب ═══
 *
 * اختيارُ الفرع يُحفظ في الجلسة رقمًا. ثمّ يُحذف الفرع — من هذه الشاشة نفسِها
 * أو من تبويبٍ آخر — ويبقى الرقمُ في الجلسة.
 *
 * فيفترق قارئان لسؤالٍ واحد: `currentBranchName` تفحص أنّ الفرع قائمٌ في هذا
 * المتجر فتقول «كل الفروع»، و`currentBranchId` تُعيد الرقمَ كما هو فتُرشَّح به
 * كلُّ استعلام.
 *
 * فيقرأ التاجرُ ترويسةً تقول **«كل الفروع»** فوق لوحةٍ فارغة: لا مبيعات، ولا
 * مخزون، ولا طلبات — لأنّ كلَّ استعلامٍ يسأل عن فرعٍ لا وجود له. ولا شيءَ في
 * الشاشة يقول ما جرى، ولا زرَّ يُعيده إلى حاله.
 *
 * ═══ والعلاج في موضعٍ واحد ═══
 *
 * `currentBranchId` هي المصدر: منها تُشتقّ `activeBranchId` و`currentBranchName`
 * معًا. فتفحص هي، ويتّفق القارئان — «فحصان لسؤالٍ واحد يفترقان يوم يُبدَّل
 * أحدهما».
 */
class ABranchThatIsGoneStopsFilteringTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Branch $main;

    private Branch $qurum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->main = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->qurum = Branch::create(['business_id' => $this->shop->id, 'name' => 'القرم']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => 'password12345', 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /** ما دام الفرعُ قائمًا فالاختيارُ قائم — العلاجُ لا يُلغي الاختيار */
    public function test_a_living_choice_is_kept(): void
    {
        session(['current_branch' => $this->qurum->id]);

        $this->assertSame($this->qurum->id, Demo::currentBranchId());
        $this->assertSame($this->qurum->id, Demo::activeBranchId());
        $this->assertSame('القرم', Demo::currentBranchName());
    }

    /** ويسقط الاختيارُ حين يُحذف الفرع — فلا يُرشَّح بما لا وجود له */
    public function test_a_deleted_branch_stops_being_the_filter(): void
    {
        session(['current_branch' => $this->qurum->id]);
        $this->delete(route('admin.branches.destroy', $this->qurum->id));

        $this->assertNull(Demo::currentBranchId(), 'الجلسةُ ما زالت تُرشِّح بفرعٍ محذوف');
        $this->assertSame(__('كل الفروع'), Demo::currentBranchName());
    }

    /**
     * والاسمُ والمُرشِّحُ يقولان الشيءَ نفسَه دائمًا.
     *
     * هذا هو الحارسُ الذي يمسك العطب في أصله: لا يسأل عن قيمةٍ بعينها، يسأل
     * أن يتّفق القارئان. فترويسةٌ تقول «كل الفروع» فوق بياناتٍ مُرشَّحة كذبةٌ
     * لا يكشفها إلّا من يعدّ الصفوف بيده.
     */
    public function test_the_header_and_the_filter_never_disagree(): void
    {
        foreach ([$this->qurum->id, 9999, 0] as $stale) {
            session(['current_branch' => $stale]);
            $this->delete(route('admin.branches.destroy', $this->qurum->id));

            $named = Demo::currentBranchName() !== __('كل الفروع');
            $filtered = Demo::currentBranchId() !== null;

            $this->assertSame($named, $filtered, "الترويسةُ والمُرشِّحُ افترقا عند: {$stale}");
        }
    }

    /** وفرعُ جارٍ عالقٌ في جلسةٍ قديمة لا يُرشِّح شيئًا */
    public function test_a_neighbours_branch_in_the_session_filters_nothing(): void
    {
        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = Branch::create(['business_id' => $neighbour->id, 'name' => 'فرعهم']);

        session(['current_branch' => $theirs->id]);

        $this->assertNull(Demo::currentBranchId(), 'فرعُ متجرٍ آخر صار مُرشِّحًا');
        $this->assertSame($this->main->id, Demo::activeBranchId(), 'البيعُ يقع على فرعٍ لا يملكه');
    }

    /** و«موضعُ البيع» يعود إلى أوّل فرعٍ حيّ لا إلى الرقم الميّت */
    public function test_the_place_of_sale_falls_back_to_a_living_branch(): void
    {
        session(['current_branch' => $this->qurum->id]);
        $this->delete(route('admin.branches.destroy', $this->qurum->id));

        $this->assertSame($this->main->id, Demo::activeBranchId());
    }
}
