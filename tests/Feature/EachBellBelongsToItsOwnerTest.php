<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CustomAlert;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * جرسُ الموظّف جرسُه هو — ولا يُخلط بجرس صاحب النشاط.
 *
 * ═══ ما يحرسه هذا الملفّ ═══
 *
 * الإشعارُ مشتقٌّ لا مخزون: يُبنى لكلّ عينٍ على حدة من حال المتجر. فالصفُّ
 * الواحد — «مخزون منخفض» مثلًا — يظهر عند كلّ من يفتح قسمَه. وما يُخزَّن هو
 * **لمسةُ القارئ** وحدها: إخفاءٌ في `dismissed_notifications`، وإنجازٌ أو
 * تأجيلٌ في `notification_states`.
 *
 * وكلاهما شخصيٌّ بالتصميم: المفتاحُ الفريد `user_id + notif_key`. فلو صارا
 * يومًا على مستوى المتجر لانقلب المعنى — كاشيرٌ يضغط «تم» فيُطفئ جرسَ صاحب
 * المحلّ، وصاحبُ المحلّ يُخفي صفًّا فيختفي عن موظّفه قبل أن يقرأه.
 *
 * والفصلُ في الاتّجاهين يُقاس هنا، لأنّ عمودًا واحدًا يُنسى في استعلامٍ
 * يقلبه صامتًا — ولا تسقط به شاشةٌ ولا يظهر في سجلّ.
 *
 * وحجبُ الأقسام عن غير أهلها بابٌ آخر يحرسه
 * `TheBellNeverRingsForADoorYouCannotOpenTest`.
 */
class EachBellBelongsToItsOwnerTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'صاحب النشاط', 'email' => 'o@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        // موظّفٌ يفتح المخزون — فالصفُّ نفسُه يصل الاثنين، وهنا موضعُ الخلط
        $this->staff = User::create([
            'business_id' => $this->shop->id, 'name' => 'موظّف', 'email' => 's@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'job_title' => 'كاشير',
            'status' => 'نشط', 'permissions' => ['inventory', 'dashboard'],
        ]);

        Product::create([
            'business_id' => $this->shop->id, 'name' => 'صنف نادر',
            'price' => 5, 'cost' => 2, 'quantity' => 1, 'alert_qty' => 10,
        ]);

        CustomAlert::create([
            'business_id' => $this->shop->id, 'created_by' => $this->owner->id, 'active' => true,
            'type' => 'reminder', 'message' => 'راجع المخزون', 'section' => 'inventory',
            'due_at' => now()->subDay(), 'color' => 'warning',
        ]);

        // بيعةُ اليوم تُنطق «ملخّص اليوم» — وهو خبرٌ يُخفى، لا إجراءٌ يُنجَز
        Order::create([
            'business_id' => $this->shop->id, 'number' => 'T-1', 'status' => 'مكتمل',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);
    }

    /**
     * أوّلُ صفٍّ يراه الاثنان من نوعٍ بعينه.
     *
     * والنوعُ يُطلب لأنّ الأبواب تفترق عمدًا: «أخفِه» تردّ الإجراءَ المتحقَّق
     * منه (`low-`)، و«تم» تردّ الخبر. فقياسُ الفصل يحتاج صفًّا يقبله البابُ
     * المقيس — وإلّا قِيس ردُّ الباب لا فصلُ الجرسين.
     */
    private function sharedKeyLike(string $prefix): string
    {
        $mine = $this->keysFor($this->staff);
        $his = $this->keysFor($this->owner);

        $shared = array_values(array_filter(
            array_intersect($mine, $his),
            fn ($k) => str_starts_with($k, $prefix),
        ));

        $this->assertNotEmpty($shared, 'لا صفَّ «'.$prefix.'» مشتركًا — المقدّمة خاطئة فلا يُقاس الخلط');

        return $shared[0];
    }

    /** مفاتيحُ ما يراه هذا المستخدم الآن */
    private function keysFor(User $user): array
    {
        $this->actingAs($user);

        return collect(Demo::allNotifications())->pluck('key')->all();
    }

    /* ══════════ ١ · الإخفاء لا يعبر ══════════ */

    public function test_what_the_staff_hides_stays_on_his_owners_bell(): void
    {
        $key = $this->sharedKeyLike('custom-');

        $this->actingAs($this->staff)
            ->post(route('admin.notifications.dismiss'), ['key' => $key])
            ->assertOk();

        $this->assertNotContains($key, $this->keysFor($this->staff), 'أخفاه ولم يختفِ عنه');
        $this->assertContains($key, $this->keysFor($this->owner), 'إخفاءُ الموظّف أطفأ جرسَ صاحب النشاط');
    }

    public function test_and_what_the_owner_hides_stays_on_his_staffs_bell(): void
    {
        $key = $this->sharedKeyLike('custom-');

        $this->actingAs($this->owner)
            ->post(route('admin.notifications.dismiss'), ['key' => $key])
            ->assertOk();

        $this->assertNotContains($key, $this->keysFor($this->owner));
        $this->assertContains($key, $this->keysFor($this->staff), 'إخفاءُ صاحب النشاط حجب الصفَّ عن موظّفه');
    }

    /** و«امسح الأخبار» يمسح أخبارَ صاحبه وحدَه — والإجراءاتُ تبقى للاثنين */
    public function test_clearing_one_bell_leaves_the_other_full(): void
    {
        $news = $this->sharedKeyLike('daily-');
        $before = $this->keysFor($this->owner);

        $this->actingAs($this->staff)->post(route('admin.notifications.clear'))->assertOk();

        $this->assertNotContains($news, $this->keysFor($this->staff), 'مسح ولم يُمسح خبرُه');
        $this->assertSame($before, $this->keysFor($this->owner), 'مسحُ الموظّف مسَّ جرسَ صاحب النشاط');
    }

    /* ══════════ ٢ · و«تم» لا يُنجز أحدٌ بها عن زميله ══════════ */

    public function test_a_staff_done_does_not_finish_it_for_the_owner(): void
    {
        $key = $this->sharedKeyLike('custom-');

        $this->actingAs($this->staff)
            ->post(route('admin.notifications.done'), ['key' => $key])
            ->assertOk();

        $mine = DB::table('notification_states')
            ->where('user_id', $this->staff->id)->where('notif_key', $key)->first();

        $this->assertNotNull($mine?->done_at, 'لم يُكتب إنجازُ الموظّف');

        $this->assertSame(0, DB::table('notification_states')
            ->where('user_id', $this->owner->id)->whereNotNull('done_at')->count(),
            'كُتب إنجازٌ على صاحب النشاط لم يفعله');
    }

    /**
     * وتبويبُ «المكتملة» يقرأ ما أنجزه صاحبُه لا ما أنجزه زميلُه.
     *
     * ويُقرأ من الجرس نفسِه (`done`) لا من سجلّ `history`: الأخيرُ لا يكتب
     * الصفَّ حتّى يُغلَق سببُه، و«تمّ» على تذكيرٍ يدويّ تكتب `done_at` وحدَها.
     */
    public function test_the_finished_tab_of_one_is_not_the_other_s(): void
    {
        $key = $this->sharedKeyLike('custom-');

        $this->actingAs($this->staff)->post(route('admin.notifications.done'), ['key' => $key])->assertOk();

        $this->actingAs($this->staff);
        $mine = collect(Demo::notificationFeed()['done'])->pluck('key');

        $this->actingAs($this->owner);
        $his = collect(Demo::notificationFeed()['done'])->pluck('key');

        $this->assertTrue($mine->contains($key), 'أنجزه الموظّفُ ولا يجده في مكتملاته');
        $this->assertFalse($his->contains($key), 'ظهر في مكتملات صاحب النشاط ما أنجزه موظّفُه');

        // وصفُّه يبقى في جرس صاحب النشاط يطلب إجراءً
        $this->assertContains($key, $this->keysFor($this->owner), 'أُطفئ صفُّ صاحب النشاط بضغطة موظّفه');
    }

    /**
     * ولكلٍّ صفُّه هو — لا صفٌّ واحدٌ يتقاسمانه.
     *
     * لو قُرئ الصفُّ بالمتجر وحدَه لَوجد الثاني صفَّ الأوّل مفتوحًا فكتب فيه:
     * صفٌّ واحدٌ باسم أحدهما، وسجلٌّ يقول إنّ الآخر لم يُنجز شيئًا.
     */
    public function test_each_one_writes_his_own_row_not_a_shared_one(): void
    {
        $key = $this->sharedKeyLike('custom-');

        $this->actingAs($this->staff)->post(route('admin.notifications.done'), ['key' => $key])->assertOk();
        $this->actingAs($this->owner)->post(route('admin.notifications.done'), ['key' => $key])->assertOk();

        $rows = DB::table('notification_states')->where('notif_key', $key)->get();

        $this->assertCount(2, $rows, 'صفٌّ واحدٌ لاثنين — الحالةُ صارت على مستوى المتجر');
        $this->assertEqualsCanonicalizing(
            [$this->staff->id, $this->owner->id],
            $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
        );

        foreach ($rows as $row) {
            $this->assertNotNull($row->done_at, 'صفٌّ بلا إنجازٍ وقد ضغط صاحبُه «تم»');
        }
    }

    /* ══════════ ٣ · والتأجيل مثلُهما ══════════ */

    public function test_a_staff_snooze_does_not_silence_the_owners_row(): void
    {
        $key = $this->sharedKeyLike('low-');

        $this->actingAs($this->staff)
            ->post(route('admin.notifications.snooze'), ['key' => $key, 'minutes' => 60])
            ->assertOk();

        $this->assertSame(0, DB::table('notification_states')
            ->where('user_id', $this->owner->id)->whereNotNull('snooze_until')->count(),
            'أُجّل صفٌّ على صاحب النشاط بيد موظّفه');
    }

    /* ══════════ ٤ · ولا يمسّ أحدٌ صفَّ متجرٍ آخر ══════════ */

    public function test_and_no_one_touches_another_shops_rows(): void
    {
        $other = Business::create(['name' => 'متجرٌ آخر', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'فرعه']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'جار', 'email' => 'x@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $key = $this->sharedKeyLike('custom-');

        $this->actingAs($stranger)->post(route('admin.notifications.done'), ['key' => $key]);

        $this->assertSame(0, DB::table('notification_states')
            ->where('business_id', $this->shop->id)->count(),
            'كُتب صفٌّ في متجرٍ ليس متجرَه');
    }
}
