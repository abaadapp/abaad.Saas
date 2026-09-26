<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CustomAlert;
use App\Models\DismissedNotification;
use App\Models\JobTitle;
use App\Models\NotificationState;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Demo;
use App\Support\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «تم» لا تُغلق مشكلةً قائمة.
 *
 * ═══ العطبُ الذي يُحرَس منه ═══
 *
 * زرُّ إنجازٍ يُصدّق الضغطةَ يجعل الجرسَ يكذب: يضغط التاجرُ «تم» على «نفد
 * المخزون» وهو لم يُعد شيئًا إلى الرفّ، فيصمت التنبيهُ والرفُّ فارغ. وصمتٌ
 * عن مشكلةٍ قائمةٍ أسوأ من ضجيجٍ عنها — الأوّلُ يُطمئن كذبًا، والثاني يُزعج
 * صادقًا.
 *
 * ═══ ولمَ لا يُقاس بحقلٍ مخزَّن ═══
 *
 * تنبيهاتُ هذا النظام مشتقّةٌ من البيانات الحيّة. فالحارسُ هنا **يغيّر
 * البياناتِ نفسَها** — يُعيد الكمّيةَ إلى الرفّ، ويُبدّل حالةَ الطلب — ثمّ
 * يسأل البابَ. فلو نُسخت قاعدةُ المخزون يومًا إلى نظام الإشعارات لَسقط.
 */
class ANoticeIsNotDoneUntilTheProblemIsTest extends TestCase
{
    use RefreshDatabase;

    private Business $biz;

    private User $owner;

    private Product $rare;

    protected function setUp(): void
    {
        parent::setUp();

        $this->biz = Business::create([
            'name' => 'متجري', 'type' => 'عام', 'status' => 'نشط', 'email' => 'shop@abaad.om',
        ]);
        Branch::create(['business_id' => $this->biz->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->biz->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->owner = User::create([
            'business_id' => $this->biz->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->rare = Product::create([
            'business_id' => $this->biz->id, 'name' => 'صنف نادر',
            'price' => 5, 'cost' => 2, 'quantity' => 1, 'alert_qty' => 10,
        ]);

        $this->actingAs($this->owner);
    }

    private function key(): string
    {
        return 'low-'.$this->rare->id;
    }

    /** يُعيد الصنفَ إلى الرفّ — بالبيانات لا بحقلٍ في نظام الإشعارات */
    private function restock(): void
    {
        $this->rare->forceFill(['quantity' => 50])->save();
    }

    /** @return list<string> */
    private function activeKeys(): array
    {
        return array_column(Demo::notificationFeed()['items'], 'key');
    }

    /* ─────────── التحقّقُ التلقائيّ ─────────── */

    public function test_a_shelf_that_is_still_short_refuses_to_be_called_done(): void
    {
        $this->assertContains($this->key(), $this->activeKeys(), 'التهيئةُ لم تُنتج تنبيهَ النقص');

        $this->postJson(route('admin.notifications.done'), ['key' => $this->key()])
            ->assertStatus(409)
            ->assertJsonPath('outcome', 'unresolved')
            /* والسببُ نصُّ التنبيه نفسِه — يقول كم بقي لا «تعذّر» */
            ->assertJsonFragment(['url' => route('admin.inventory.index')]);

        $this->assertSame(0, NotificationState::count(), 'رُفض الإنجازُ وكُتب صفُّه');
        $this->assertContains($this->key(), $this->activeKeys(), 'اختفى التنبيهُ رغم رفض الإنجاز');
    }

    public function test_and_once_the_shelf_is_filled_the_same_press_closes_it(): void
    {
        /* فتحه ليعالجه، فصار له صفٌّ يحمل نصَّه */
        $this->postJson(route('admin.notifications.open'), ['key' => $this->key()])->assertOk();

        $this->restock();

        $this->postJson(route('admin.notifications.done'), ['key' => $this->key()])
            ->assertOk()->assertJsonPath('outcome', 'done');

        $row = NotificationState::where('notif_key', $this->key())->sole();
        $this->assertNotNull($row->done_at, 'لم يُسجَّل وقتُ الإنجاز');
        $this->assertNotNull($row->resolved_at, 'أُنجز ولم تُغلق دورتُه');
        $this->assertSame($this->owner->id, (int) $row->user_id, 'لم يُسجَّل مَن أنجزه');
    }

    public function test_a_problem_that_goes_away_closes_itself_with_no_press(): void
    {
        $this->postJson(route('admin.notifications.open'), ['key' => $this->key()])->assertOk();

        $this->restock();

        /* لا ضغطةَ «تم» — مجرّدُ استطلاعٍ للجرس */
        Demo::notificationFeed();

        $row = NotificationState::where('notif_key', $this->key())->sole();
        $this->assertNotNull($row->resolved_at, 'زال السببُ وبقيت الدورةُ مفتوحة');
        $this->assertNull($row->done_at, 'أُغلق وحدَه فنُسب إلى أحد');
    }

    public function test_a_problem_that_comes_back_starts_a_new_round(): void
    {
        $this->postJson(route('admin.notifications.open'), ['key' => $this->key()])->assertOk();
        $this->restock();
        $this->postJson(route('admin.notifications.done'), ['key' => $this->key()])->assertOk();

        $this->assertNotContains($this->key(), $this->activeKeys(), 'أُنجز وبقي في «تحتاج إجراء»');

        /* ونفد ثانيةً — وهو المفتاحُ نفسُه */
        $this->rare->forceFill(['quantity' => 0])->save();

        $this->assertContains(
            $this->key(),
            $this->activeKeys(),
            'إنجازُ الأمس يكتم نفادَ اليوم — وهذا ما تعالجه الدورات',
        );

        $this->postJson(route('admin.notifications.open'), ['key' => $this->key()])->assertOk();

        $cycles = NotificationState::where('notif_key', $this->key())->orderBy('cycle')->pluck('cycle')->all();
        $this->assertSame([1, 2], $cycles, 'العودةُ لم تفتح دورةً ثانية');
    }

    /* ─────────── التأكيدُ اليدويّ ─────────── */

    public function test_a_reminder_the_system_cannot_check_is_taken_at_its_word(): void
    {
        $alert = CustomAlert::create([
            'business_id' => $this->biz->id, 'created_by' => $this->owner->id, 'active' => true,
            'type' => 'reminder', 'message' => 'اتّصل بالمورّد', 'section' => 'inventory',
            'due_at' => now()->subDay(), 'color' => 'warning',
        ]);

        $key = 'custom-'.$alert->id;

        $this->postJson(route('admin.notifications.done'), ['key' => $key])
            ->assertOk()->assertJsonPath('outcome', 'done');

        $row = NotificationState::where('notif_key', $key)->sole();
        $this->assertNotNull($row->done_at);
        /* وسببُه ما زال قائمًا — الموعدُ حان ولم يتبدّل — فالدورةُ مفتوحة */
        $this->assertNull($row->resolved_at);
        $this->assertNotContains($key, $this->activeKeys(), 'أُنجز يدويًّا وبقي نشطًا');
    }

    public function test_a_reminder_turned_into_a_rule_no_longer_rests_on_yesterdays_word(): void
    {
        /*
         * ═══ وتصنيفُ التنبيه يتبدّل بيد صاحبه ═══
         *
         * «اتّصل بالمورّد» تذكيرٌ يُصدَّق صاحبُه فيه. ثمّ يُبدّله إلى قاعدةٍ
         * تُقاس — «نبّهني ما دام في المتجر صنفٌ ناقص» — فصار للنظام جوابٌ
         * عن حاله. وإنجازُ الأمس كان تصديقًا لكلامٍ لا قياسًا، فلا يصحّ أن
         * يكتم قاعدةً تقول الآن إنّ الصنفَ ناقص.
         */
        $alert = CustomAlert::create([
            'business_id' => $this->biz->id, 'created_by' => $this->owner->id, 'active' => true,
            'type' => 'reminder', 'message' => 'اتّصل بالمورّد', 'section' => 'inventory',
            'due_at' => now()->subDay(), 'color' => 'warning',
        ]);
        $key = 'custom-'.$alert->id;

        $this->postJson(route('admin.notifications.done'), ['key' => $key])->assertOk();
        $this->assertNotContains($key, $this->activeKeys());

        $alert->forceFill([
            'type' => 'rule', 'metric' => 'low_stock', 'operator' => '>=',
            'threshold' => 1, 'due_at' => null,
        ])->save();

        $this->assertContains(
            $key,
            $this->activeKeys(),
            'صار يُقاس فقال «ناقص» — وإنجازُ الأمس يكتمه',
        );

        $this->assertNotNull(
            NotificationState::where('notif_key', $key)->sole()->resolved_at,
            'عاد نشطًا وبقيت دورتُه القديمةُ مفتوحة',
        );
    }

    public function test_a_rule_that_can_be_checked_is_not_taken_at_its_word(): void
    {
        /* القاعدةُ تُقاس فتُجيب — فهي كالمخزون لا كالتذكير */
        $alert = CustomAlert::create([
            'business_id' => $this->biz->id, 'created_by' => $this->owner->id, 'active' => true,
            'type' => 'rule', 'metric' => 'low_stock', 'operator' => '>=', 'threshold' => 1,
            'message' => 'أصنافٌ ناقصة', 'section' => 'inventory', 'color' => 'warning',
        ]);

        $key = 'custom-'.$alert->id;

        $this->assertContains($key, $this->activeKeys(), 'التهيئةُ لم تُشعل القاعدة');

        $this->postJson(route('admin.notifications.done'), ['key' => $key])
            ->assertStatus(409)->assertJsonPath('outcome', 'unresolved');
    }

    public function test_a_piece_of_news_carries_no_done_button(): void
    {
        /* «ملخّصُ اليوم» لا يُبنى إلّا بنشاطٍ اليومَ */
        Order::create([
            'business_id' => $this->biz->id, 'number' => 'T-1', 'status' => 'مكتمل',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);

        $key = 'daily-'.today()->toDateString();
        $this->assertContains($key, $this->activeKeys(), 'التهيئةُ لم تُنتج ملخّصَ اليوم');

        $this->postJson(route('admin.notifications.done'), ['key' => $key])
            ->assertStatus(422)->assertJsonPath('outcome', 'info');

        $this->postJson(route('admin.notifications.snooze'), ['key' => $key, 'minutes' => 1440])
            ->assertStatus(422)->assertJsonPath('outcome', 'info');

        /*
         * وفتحُه لا يُتتبَّع أيضًا: ملخّصٌ يمرّ كلَّ يومٍ ويزول كلَّ ليلة،
         * فصفٌّ لكلّ يومٍ فُتح فيه يملأ الجدولَ وسجلَّ «المكتملة» بما لم
         * يكن مشكلةً تُحلّ.
         */
        $this->postJson(route('admin.notifications.open'), ['key' => $key])
            ->assertOk()->assertJsonPath('outcome', 'gone');

        $this->assertSame(0, NotificationState::count(), 'كُتب صفُّ متابعةٍ لخبرٍ لا يُتابَع');

        /* ويبقى له الإخفاءُ كما كان */
        $this->postJson(route('admin.notifications.dismiss'), ['key' => $key])->assertOk();
        $this->assertNotContains($key, $this->activeKeys(), 'أُخفي الخبرُ وبقي');
    }

    /* ─────────── التأجيل ─────────── */

    public function test_a_snoozed_notice_leaves_the_count_and_comes_back_after_its_time(): void
    {
        $before = Demo::notificationFeed();

        $this->postJson(route('admin.notifications.snooze'), ['key' => $this->key(), 'minutes' => 1440])
            ->assertOk()->assertJsonPath('outcome', 'snoozed');

        $after = Demo::notificationFeed();

        $this->assertNotContains($this->key(), array_column($after['items'], 'key'));
        $this->assertSame($before['count'] - 1, $after['count'], 'المؤجَّلُ ما زال يُعدّ نشطًا');
        $this->assertContains($this->key(), array_column($after['snoozed'], 'key'), 'اختفى ولم يظهر في «المؤجَّلة»');

        $this->travel(25)->hours();

        $this->assertContains($this->key(), $this->activeKeys(), 'انقضت المدّةُ ولم يعد التنبيه');
    }

    public function test_a_snoozed_problem_that_solves_itself_does_not_come_back_as_a_problem(): void
    {
        $this->postJson(route('admin.notifications.snooze'), ['key' => $this->key(), 'minutes' => 1440])->assertOk();

        $this->restock();
        $this->travel(25)->hours();

        $this->assertNotContains($this->key(), $this->activeKeys(), 'زال سببُه وعاد يُطالب بإجراء');

        $row = NotificationState::where('notif_key', $this->key())->sole();
        $this->assertNotNull($row->resolved_at);
        $this->assertNull($row->done_at, 'حُلّ من نفسه ونُسب إلى أحد');
    }

    public function test_a_wild_snooze_length_is_refused(): void
    {
        $this->postJson(route('admin.notifications.snooze'), ['key' => $this->key(), 'minutes' => 525600])
            ->assertStatus(422);

        $this->assertSame(0, NotificationState::count());
    }

    /* ─────────── القراءةُ ليست إنجازًا ─────────── */

    public function test_opening_a_notice_does_not_finish_it(): void
    {
        $this->postJson(route('admin.notifications.open'), ['key' => $this->key()])->assertOk();

        $row = NotificationState::where('notif_key', $this->key())->sole();
        $this->assertNotNull($row->seen_at);
        $this->assertNull($row->done_at, 'الفتحُ صار إنجازًا');

        $this->assertContains($this->key(), $this->activeKeys(), 'قُرئ فخرج من «تحتاج إجراء»');
    }

    /* ─────────── العزلُ والصلاحيات ─────────── */

    public function test_a_neighbours_shelf_cannot_be_finished_from_here(): void
    {
        $other = Business::create([
            'name' => 'محلُّ الجار', 'type' => 'عام', 'status' => 'نشط', 'email' => 'jar@abaad.om',
        ]);
        Branch::create(['business_id' => $other->id, 'name' => 'الرئيسي']);
        $theirs = Product::create([
            'business_id' => $other->id, 'name' => 'صنفُ الجار',
            'price' => 5, 'cost' => 2, 'quantity' => 0, 'alert_qty' => 10,
        ]);

        $this->postJson(route('admin.notifications.done'), ['key' => 'low-'.$theirs->id])
            ->assertOk()->assertJsonPath('outcome', 'gone');

        $this->postJson(route('admin.notifications.snooze'), ['key' => 'low-'.$theirs->id, 'minutes' => 1440])
            ->assertOk()->assertJsonPath('outcome', 'gone');

        $this->assertSame(0, NotificationState::count(), 'كُتب صفٌّ عن تنبيهٍ ليس لهذا النشاط');
    }

    public function test_a_clerk_cannot_finish_what_his_bell_never_rang_for(): void
    {
        $clerk = User::create([
            'business_id' => $this->biz->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'job_title' => 'كاشير',
            'status' => 'نشط', 'permissions' => ['orders'],
        ]);

        /* تنبيهُ المخزون ليس في جرسه — `Demo::buildNotifications` ترشّحه بالقسم */
        $this->actingAs($clerk)
            ->postJson(route('admin.notifications.done'), ['key' => $this->key()])
            ->assertOk()->assertJsonPath('outcome', 'gone');

        $this->assertSame(0, NotificationState::count());
    }

    public function test_one_persons_done_does_not_silence_anothers_bell(): void
    {
        $manager = User::create([
            'business_id' => $this->biz->id, 'name' => 'مدير', 'email' => 'm@abaad.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط',
        ]);

        $this->postJson(route('admin.notifications.snooze'), ['key' => $this->key(), 'minutes' => 1440])->assertOk();

        $this->actingAs($manager);

        $this->assertContains(
            $this->key(),
            $this->activeKeys(),
            'أجّله زميلُه فصمت جرسُه — والمشكلةُ على الرفّ كما هي',
        );
    }

    /* ─────────── ما يقصّه الجرسُ ليس ما زال ─────────── */

    public function test_a_row_pushed_past_the_bells_limit_is_not_read_as_solved(): void
    {
        /*
         * ═══ أخطرُ ما في الإغلاق التلقائيّ ═══
         *
         * الجرسُ يبني عشرةً من كلّ مصدر. فلو قُرئ غيابُ الحادي عشرَ عن هذا
         * البناء حلًّا لَأُغلق تنبيهُ صنفٍ ما زال ناقصًا على الرفّ — والصنفُ
         * لا يصرخ، فلا شيءَ يقول إنّ شيئًا انكسر.
         */
        $this->postJson(route('admin.notifications.open'), ['key' => $this->key()])->assertOk();

        /* عشرون صنفًا أنقصُ منه — فيُدفع خارج العشرة التي يبنيها الجرس */
        for ($i = 0; $i < 20; $i++) {
            Product::create([
                'business_id' => $this->biz->id, 'name' => 'ناقص '.$i,
                'price' => 5, 'cost' => 2, 'quantity' => 0, 'alert_qty' => 10,
            ]);
        }

        Demo::notificationFeed();

        $row = NotificationState::where('notif_key', $this->key())->sole();
        $this->assertNull(
            $row->resolved_at,
            'قُرئ القصُّ حلًّا — وأُغلق تنبيهُ صنفٍ ما زال ناقصًا',
        );
    }

    /* ─────────── السجلُّ والتراجع ─────────── */

    public function test_the_log_says_what_it_was_and_who_ended_it(): void
    {
        $this->postJson(route('admin.notifications.open'), ['key' => $this->key()])->assertOk();
        $this->restock();
        $this->postJson(route('admin.notifications.done'), ['key' => $this->key()])->assertOk();

        $this->getJson(route('admin.notifications.history'))
            ->assertOk()
            ->assertJsonPath('items.0.key', $this->key())
            ->assertJsonPath('items.0.state', 'done')
            ->assertJsonPath('items.0.url', route('admin.inventory.index'));

        /* والنصُّ لقطةٌ — المصدرُ لم يعد يُنتجه */
        $this->assertStringContainsString(
            'صنف نادر',
            $this->getJson(route('admin.notifications.history'))->json('items.0.text'),
        );
    }

    public function test_a_press_in_the_wrong_place_can_be_taken_back(): void
    {
        $alert = CustomAlert::create([
            'business_id' => $this->biz->id, 'created_by' => $this->owner->id, 'active' => true,
            'type' => 'reminder', 'message' => 'اتّصل بالمورّد', 'section' => 'inventory',
            'due_at' => now()->subDay(), 'color' => 'warning',
        ]);
        $key = 'custom-'.$alert->id;

        $this->postJson(route('admin.notifications.done'), ['key' => $key])->assertOk();
        $this->assertNotContains($key, $this->activeKeys());

        $this->postJson(route('admin.notifications.reopen'), ['key' => $key])
            ->assertOk()->assertJsonPath('outcome', 'active');

        $this->assertContains($key, $this->activeKeys(), 'التراجعُ لم يُعده');
    }

    /* ─────────── البابُ القديم لا يبتلع إجراءً ─────────── */

    public function test_the_old_hiding_door_does_not_swallow_a_live_problem(): void
    {
        /*
         * ═══ ولولا هذا لَما عنى التحقّقُ شيئًا ═══
         *
         * `dismiss` أقدمُ من حالات الإنجاز وكان يقبل أيَّ مفتاح. فمن رُدّت
         * «تم»ه يفتح الطرفيّةَ — أو تفتحها شاشةُ الإعدادات نيابةً عنه —
         * فيُسكت «نفد المخزون» ثلاثين يومًا والرفُّ فارغ. وحارسٌ يُلتفّ
         * حوله بابٌ مفتوحٌ لا حارس.
         */
        $this->postJson(route('admin.notifications.dismiss'), ['key' => $this->key()])
            ->assertStatus(422)
            ->assertJsonPath('outcome', 'task');

        $this->assertContains($this->key(), $this->activeKeys(), 'أُخفي إجراءٌ لم يُنجَز');
        $this->assertSame(0, DismissedNotification::count(), 'كُتب صفُّ إخفاءٍ لإجراء');
    }

    public function test_but_a_reminder_no_one_can_check_is_still_hideable(): void
    {
        /*
         * ═══ ولا يُردّ إلّا ما يملك النظامُ أن يكذّبه ═══
         *
         * تذكيرٌ كتبه صاحبُه لا دليلَ عليه: «تم» فيه تصديقٌ لكلامه لا قياس،
         * وهي و«أخفِه» سواءٌ في الحجّة. فمنعُ إحداهما تضييقٌ بلا فائدةٍ
         * يحرم التاجرَ من إخفاء تذكيرٍ وضعه بيده — ويكسر ما كان يعمل.
         */
        $alert = CustomAlert::create([
            'business_id' => $this->biz->id, 'created_by' => $this->owner->id, 'active' => true,
            'type' => 'reminder', 'message' => 'اتّصل بالمورّد', 'section' => 'inventory',
            'due_at' => now()->subDay(), 'color' => 'warning',
        ]);
        $key = 'custom-'.$alert->id;

        $this->postJson(route('admin.notifications.dismiss'), ['key' => $key])->assertOk();

        $this->assertNotContains($key, $this->activeKeys(), 'رُدّ إخفاءُ تذكيرٍ لا دليلَ عليه');
    }

    public function test_and_a_rule_that_can_be_checked_is_not_hideable(): void
    {
        /* والقاعدةُ تُقاس فتُجيب — فهي كالمخزون لا كالتذكير، ولو حملت المفتاحَ نفسَه */
        $alert = CustomAlert::create([
            'business_id' => $this->biz->id, 'created_by' => $this->owner->id, 'active' => true,
            'type' => 'rule', 'metric' => 'low_stock', 'operator' => '>=', 'threshold' => 1,
            'message' => 'أصنافٌ ناقصة', 'section' => 'inventory', 'color' => 'warning',
        ]);
        $key = 'custom-'.$alert->id;

        $this->postJson(route('admin.notifications.dismiss'), ['key' => $key])
            ->assertStatus(422)->assertJsonPath('outcome', 'task');

        $this->assertContains($key, $this->activeKeys());
    }

    public function test_clearing_them_all_hides_the_news_and_keeps_the_work(): void
    {
        Order::create([
            'business_id' => $this->biz->id, 'number' => 'T-2', 'status' => 'مكتمل',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);

        $news = 'daily-'.today()->toDateString();
        $this->assertContains($news, $this->activeKeys(), 'التهيئةُ لم تُنتج خبرًا');

        /* والتذكيرُ يُبقيه الجماعيُّ أيضًا — أداةٌ غاشمةٌ لا تمسّ إلّا الخبر */
        CustomAlert::create([
            'business_id' => $this->biz->id, 'created_by' => $this->owner->id, 'active' => true,
            'type' => 'reminder', 'message' => 'اتّصل بالمورّد', 'section' => 'inventory',
            'due_at' => now()->subDay(), 'color' => 'warning',
        ]);

        $this->postJson(route('admin.notifications.clear'))
            ->assertOk()
            ->assertJsonPath('hidden', 1)
            ->assertJsonPath('kept', 2);

        $left = $this->activeKeys();
        $this->assertNotContains($news, $left, 'بقي الخبرُ بعد «إخفاء الأخبار»');
        $this->assertContains($this->key(), $left, 'ضغطةٌ واحدةٌ أسكتت رفًّا ناقصًا');
    }

    public function test_a_hiding_written_before_the_upgrade_is_still_honoured(): void
    {
        /*
         * التوافقُ لا يُكسر: صفوفُ الإخفاء القديمة تبقى وتُحترم، ومهلتُها
         * ثلاثون يومًا تنقضي من نفسها. المسدودُ بابُ **الكتابة** الجديدة
         * لا قراءةُ ما كُتب.
         */
        DismissedNotification::create([
            'user_id' => $this->owner->id, 'notif_key' => $this->key(),
        ]);

        $this->assertNotContains($this->key(), $this->activeKeys(), 'كُسر توافقُ الإخفاء القديم');
    }

    /* ─────────── الغيابُ أربعةُ أسبابٍ لا سببٌ واحد ─────────── */

    public function test_an_old_hiding_is_not_a_solved_problem(): void
    {
        $this->postJson(route('admin.notifications.open'), ['key' => $this->key()])->assertOk();

        /* أُخفي بالباب القديم — والصنفُ على الرفّ ناقصٌ كما كان */
        DismissedNotification::create([
            'user_id' => $this->owner->id, 'notif_key' => $this->key(),
        ]);

        Demo::notificationFeed();

        $this->assertNull(
            NotificationState::where('notif_key', $this->key())->sole()->resolved_at,
            'قُرئ الإخفاءُ حلًّا — وكُتب في السجلّ أنّ مشكلةً انتهت ولم تنتهِ',
        );
    }

    public function test_losing_a_permission_is_not_a_solved_problem(): void
    {
        $clerk = User::create([
            'business_id' => $this->biz->id, 'name' => 'موظّف', 'email' => 'w@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'job_title' => 'كاشير',
            'status' => 'نشط', 'permissions' => ['inventory', 'orders'],
        ]);

        $this->actingAs($clerk);
        $this->postJson(route('admin.notifications.open'), ['key' => $this->key()])->assertOk();

        /* نُزعت منه صلاحيةُ المخزون — والصنفُ ناقصٌ كما كان */
        $clerk->forceFill(['permissions' => ['orders']])->save();
        $this->actingAs($clerk->fresh());

        $this->assertNotContains($this->key(), $this->activeKeys(), 'يرى ما لا يفتحه');

        Demo::notificationFeed();

        $this->assertNull(
            NotificationState::where('notif_key', $this->key())->sole()->resolved_at,
            'حُجب عنه فقُرئ حلًّا — وهو على الرفّ ناقص',
        );
    }

    /* ─────────── وثمنُ الفرز مقيسٌ لا مقدَّر ─────────── */

    public function test_the_pulse_pays_one_query_and_the_closing_pays_once(): void
    {
        /*
         * ═══ ثمنٌ يُقاس لأنّه يقع كلَّ ثلاثين ثانيةً لكلّ من يفتح اللوحة ═══
         *
         * والثمنُ ثلاثةُ أحوال:
         *
         *   • **المستقرّ**: صفٌّ مفتوحٌ ومفتاحُه حاضر — بناءٌ واحدٌ وقراءةُ
         *     حالات. وهذا حالُ كلّ نبضةٍ تقريبًا.
         *   • **الانتقال**: زال السببُ فغاب المفتاح — يُسأل بناءٌ ثانٍ غيرُ
         *     مرشَّح، لأنّ الغيابَ عن بناء المستخدم أربعةُ أسبابٍ لا سببٌ
         *     واحد. ويُكتب الإغلاق.
         *   • **بعده**: لا صفَّ مفتوحًا، فتعود إلى بناءٍ وقراءة.
         *
         * والمقيسُ أنّ البناءَ الثاني **لا يتكرّر**: لو بقي في كلّ نبضةٍ
         * لَتضاعف ثمنُ الجرس على كلّ تاجرٍ أجّل تنبيهًا.
         */
        $this->postJson(route('admin.notifications.open'), ['key' => $this->key()])->assertOk();

        Demo::notifications();                       // تسخين
        $build = $this->queries(fn () => Demo::notifications());

        $steady = $this->queries(fn () => Demo::notificationFeed());
        $this->assertSame(
            $build + Notifications::STATE_QUERIES,
            $steady,
            'النبضةُ المستقرّة تكلّف أكثرَ من بناءٍ وقراءةِ حالات',
        );

        $this->restock();

        $closing = $this->queries(fn () => Demo::notificationFeed());
        $this->assertGreaterThan($steady, $closing, 'أُغلقت بلا شاهدٍ — والغيابُ وحدَه ليس دليلًا');
        $this->assertLessThanOrEqual(
            2 * $build + Notifications::STATE_QUERIES + 1,
            $closing,
            'الإغلاقُ يكلّف أكثرَ من بناءٍ ثانٍ وكتبةٍ واحدة',
        );

        $this->assertNotNull(NotificationState::where('notif_key', $this->key())->sole()->resolved_at);

        $after = $this->queries(fn () => Demo::notificationFeed());
        $this->assertSame(
            $build + Notifications::STATE_QUERIES,
            $after,
            'البناءُ الثاني صار في كلّ نبضة — وهو ضعفُ ثمن الجرس بلا سبب',
        );
    }

    private function queries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $work();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    /* ─────────── ولا ينمو الجدولُ بلا سقف ─────────── */

    public function test_the_sweep_clears_finished_rounds_and_spares_the_open_ones(): void
    {
        $old = NotificationState::create([
            'business_id' => $this->biz->id, 'user_id' => $this->owner->id,
            'notif_key' => 'low-900', 'cycle' => 1, 'label' => 'قديمٌ منتهٍ',
            'done_at' => now(), 'resolved_at' => now()->subDays(Notifications::HISTORY_DAYS + 1),
        ]);

        /* ومؤجَّلٌ قديمٌ ما زال يحجب تنبيهًا قائمًا — فلا يُمسّ */
        $sleeping = NotificationState::create([
            'business_id' => $this->biz->id, 'user_id' => $this->owner->id,
            'notif_key' => $this->key(), 'cycle' => 1, 'label' => 'مؤجَّلٌ قديم',
            'snooze_until' => now()->addWeek(),
        ]);
        $sleeping->forceFill(['created_at' => now()->subDays(90)])->save();

        $this->artisan('trash:purge')->assertExitCode(0);

        $this->assertNull(NotificationState::find($old->id), 'صفٌّ خرج من السجلّ وبقي في القاعدة');
        $this->assertModelExists($sleeping);
        $this->assertNotContains($this->key(), $this->activeKeys(), 'مُحي تأجيلٌ حيٌّ فعاد التنبيه فجأة');
    }

    /* ─────────── كلُّ مصدرٍ مصنَّف ─────────── */

    public function test_every_source_the_bell_knows_has_been_classified(): void
    {
        /*
         * حارسٌ يشيخ لا يحرس: من أضاف مصدرًا ثالثَ عشرَ ولم يصنّفه يسقط هنا
         * لا في الإنتاج. وبلا هذا يصير المصدرُ الجديدُ «خبرًا» بالصمت — فلا
         * زرَّ إنجازٍ عليه وإن كان إجراءً يُتابَع.
         */
        Order::create([
            'business_id' => $this->biz->id, 'number' => 'NEW-1', 'status' => 'جديد',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);
        CustomAlert::create([
            'business_id' => $this->biz->id, 'created_by' => $this->owner->id, 'active' => true,
            'type' => 'reminder', 'message' => 'راجع', 'section' => 'inventory',
            'due_at' => now()->subDay(), 'color' => 'warning',
        ]);

        $built = Demo::allNotifications();
        $this->assertGreaterThan(2, count($built), 'التهيئةُ لم تُنتج ما يكفي للقياس');

        foreach ($built as $item) {
            $this->assertNotNull(
                Notifications::kindOf($item['key']),
                'مصدرٌ بلا تصنيف: '.$item['key'],
            );
        }
    }
}
