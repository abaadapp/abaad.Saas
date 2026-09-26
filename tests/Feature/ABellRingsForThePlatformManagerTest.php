<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\DismissedNotification;
use App\Models\NotificationState;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportRead;
use App\Models\User;
use App\Support\Demo;
use App\Support\Support;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * جرسُ مدير المنصّة — كان الخادمُ يبنيه ولا بابَ يُسلّمه.
 *
 * ═══ العطبُ كما قِيس ═══
 *
 * `Demo::buildNotifications` فيها فرعٌ كاملٌ لمن لا متجرَ له: محادثاتُ الدعم
 * التي تنتظره، والاشتراكاتُ التي تنتهي — ثمّ تردّ قبل أن تقرأ صفَّ تاجرٍ
 * واحد. وقِيس بمتصفّحٍ حقيقيّ أنّ الصفّين يُبنيان فعلًا، ثمّ لا يبلغه منهما
 * شيء:
 *
 *   · `HandleInertiaRequests` تشارك التغذية بشرط `business_id`، والشريطُ
 *     يرسم الجرسَ بـ`{feed && …}` — فلا زرَّ أصلًا.
 *   · وأبوابُ اللوحة الثمانيةُ خلف `RequiresBusiness`، فكلُّها تردّه
 *     بتحويلةٍ إلى `/super-admin/dashboard`: لا JSON ولا جرس.
 *
 * ═══ والإصلاحُ إضافةٌ لا نزع ═══
 *
 * لم يُنزع `RequiresBusiness` عن أبواب التاجر — يحرس اثنتين وستّين شاشة،
 * ولا يُضعَّف لأجل ثمانية أبواب. بل نسخةُ الأبواب في مجموعة `super-admin`
 * بحرسها (`role:super_admin`)، وقاعدتُها تُسلَّم من الخادم في `at`.
 *
 * وصفوفُه أخبارٌ لا مهامّ: `Notifications::MAP` تصنّف `support-` و`sub-`
 * إخبارًا، و`NotificationController::mine` لا يكتب له صفَّ حالة. فلا «تم»
 * ولا «تأجيل» — قراءةٌ وإخفاءٌ كما هو سلوكُ الأخبار عند التاجر نفسِه.
 */
class ABellRingsForThePlatformManagerTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'محلُّ الورد', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->boss = User::create([
            'business_id' => null, 'name' => 'مديرُ المنصّة', 'email' => 'boss@abaad.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        /* ما يُنتج صفَّيه: محادثةٌ كتبها تاجرٌ ولم يقرأها، واشتراكٌ ينتهي */
        Support::open($this->owner, 'الفاتورة لا تُطبع', 'مشكلة تقنية', 'أضغط طباعة فلا يحدث شيء.');

        Subscription::create([
            'business_id' => $this->shop->id, 'starts_at' => now()->subMonths(11)->toDateString(),
            'ends_at' => now()->addDays(10)->toDateString(), 'amount' => 25, 'status' => 'نشط',
        ]);

        /* وصنفٌ ناقصٌ عند التاجر — ليُقاس أنّه لا يبلغ جرسَ المنصّة */
        Product::create([
            'business_id' => $this->shop->id, 'name' => 'وردٌ أحمر', 'sku' => 'FLW-1',
            'price' => 5, 'cost' => 2, 'quantity' => 1, 'alert_qty' => 10, 'active' => true,
        ]);
    }

    /** الأبوابُ الثمانيةُ بأسمائها — المنصّةُ والتاجرُ سواءٌ في العدد */
    private const DOORS = [
        ['get', 'feed'],
        ['get', 'history'],
        ['post', 'open'],
        ['post', 'done'],
        ['post', 'snooze'],
        ['post', 'reopen'],
        ['post', 'dismiss'],
        ['post', 'clear'],
    ];

    /* ═══════════════ الجرسُ يُرسم ═══════════════ */

    public function test_the_shared_prop_reaches_the_platform_manager(): void
    {
        /*
         * وهي التي كانت `null`: الشريطُ يرسم الجرسَ بـ`{feed && …}`، فخاصّيّةٌ
         * فارغةٌ تعني ألّا أيقونةَ في الشاشة مهما بنى الخادم.
         */
        $props = $this->actingAs($this->boss)
            ->get(route('super-admin.dashboard'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNotNull($props['notifications'], 'لا جرسَ لمدير المنصّة');
        $this->assertSame(2, $props['notifications']['count']);
    }

    public function test_and_it_carries_the_doors_of_its_own_panel(): void
    {
        $this->actingAs($this->boss);

        $this->assertSame('/super-admin/notifications', Demo::notificationFeed()['at']);
    }

    public function test_while_the_merchant_keeps_his_own(): void
    {
        // ولا يتبدّل سلوكُ التاجر بحرف
        $this->actingAs($this->owner);

        $this->assertSame('/admin/notifications', Demo::notificationFeed()['at']);
    }

    /* ═══════════════ الأبوابُ الثمانية ═══════════════ */

    public function test_all_eight_doors_answer_the_platform_manager(): void
    {
        foreach (self::DOORS as [$verb, $door]) {
            $res = $this->actingAs($this->boss)->{$verb}(
                route("super-admin.notifications.{$door}"),
                $verb === 'post' ? ['key' => 'sub-1', 'minutes' => 60] : [],
            );

            $this->assertTrue(
                $res->isOk() || $res->isRedirect(),
                "البابُ {$door} ردّ {$res->getStatusCode()}",
            );
            $this->assertStringNotContainsString(
                '/super-admin/dashboard',
                (string) $res->headers->get('Location'),
                "البابُ {$door} ما زال يردّ صاحبَه بتحويلة",
            );
        }
    }

    public function test_the_feed_door_answers_with_his_two_rows(): void
    {
        $body = $this->actingAs($this->boss)
            ->getJson(route('super-admin.notifications.feed'))
            ->assertOk()
            ->json();

        $keys = array_column($body['items'], 'key');

        $this->assertCount(2, $keys);
        $this->assertTrue((bool) preg_grep('/^support-/', $keys), 'محادثةُ الدعم غائبة');
        $this->assertTrue((bool) preg_grep('/^sub-/', $keys), 'الاشتراكُ المنتهي غائب');
    }

    public function test_the_merchants_own_rows_never_reach_him(): void
    {
        /*
         * وهو ما يحرسه الفرعُ بردّه المبكر: لا نقصَ مخزونٍ ولا طلبَ تاجر.
         */
        $keys = array_column(
            $this->actingAs($this->boss)->getJson(route('super-admin.notifications.feed'))->json('items'),
            'key',
        );

        $this->assertEmpty(preg_grep('/^(low-|order-|grn-|custom-|daily-)/', $keys), 'صفُّ تاجرٍ في جرس المنصّة');
    }

    /* ═══════════════ وأبوابُ التاجر تبقى بحرسها ═══════════════ */

    public function test_the_merchants_doors_still_turn_him_away(): void
    {
        /*
         * `RequiresBusiness` لم يُنزع: من لا متجرَ له لا يقرأ لوحةَ نشاطٍ ولا
         * أبوابَها. وهذا هو الحارسُ الذي لم أُضعّفه لأجل هذه الميزة.
         */
        $this->actingAs($this->boss)
            ->get(route('admin.notifications.feed'))
            ->assertRedirect(route('super-admin.dashboard'));
    }

    public function test_and_a_merchant_cannot_reach_the_platforms_doors(): void
    {
        foreach (self::DOORS as [$verb, $door]) {
            $this->actingAs($this->owner)->{$verb}(
                route("super-admin.notifications.{$door}"),
                $verb === 'post' ? ['key' => 'sub-1'] : [],
            )->assertForbidden();
        }
    }

    public function test_a_cashier_cannot_either(): void
    {
        $clerk = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'clerk@abaad.om',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط', 'permissions' => ['orders'],
        ]);

        $this->actingAs($clerk)
            ->getJson(route('super-admin.notifications.feed'))
            ->assertForbidden();
    }

    /* ═══════════ الجرسُ والشارةُ يقولان الشيءَ نفسَه ═══════════ */

    public function test_a_conversation_he_has_read_leaves_his_bell(): void
    {
        /*
         * وقاعدةُ «غيرِ المقروء» أُعيدت صياغتُها في الجرس لتُسأل مرّةً لا
         * مرّةً لكلّ محادثة (كانت تُسقط حارسَ الكلفة). فهذا يربطها بالشارة:
         * ما يخرج من الجرس يخرج من الشارة، ولا تفترق الصياغتان صامتتَين.
         */
        $c = SupportConversation::firstOrFail();

        SupportRead::create([
            'conversation_id' => $c->id, 'user_id' => $this->boss->id,
            'last_read_message_id' => SupportMessage::where('conversation_id', $c->id)->max('id'),
        ]);

        $keys = array_column(
            $this->actingAs($this->boss)->getJson(route('super-admin.notifications.feed'))->json('items'),
            'key',
        );

        $this->assertEmpty(preg_grep('/^support-/', $keys), 'محادثةٌ قُرئت ما زالت في الجرس');
        $this->assertSame(0, Support::platformBadge($this->boss->fresh()), 'والشارةُ تقول غيرَ ما يقول الجرس');
    }

    public function test_and_a_new_word_from_the_shop_brings_it_back(): void
    {
        $c = SupportConversation::firstOrFail();

        SupportRead::create([
            'conversation_id' => $c->id, 'user_id' => $this->boss->id,
            'last_read_message_id' => SupportMessage::where('conversation_id', $c->id)->max('id'),
        ]);

        Support::say($c, $this->owner, 'business', 'ما زال لا يطبع.');

        $keys = array_column(
            $this->actingAs($this->boss)->getJson(route('super-admin.notifications.feed'))->json('items'),
            'key',
        );

        $this->assertNotEmpty(preg_grep('/^support-/', $keys), 'كلامٌ جديدٌ لم يُعِد المحادثة');
        $this->assertSame(1, Support::platformBadge($this->boss->fresh()));
    }

    /* ═══════════════ أخبارٌ تُقرأ وتُخفى — لا مهامُّ تُنجَز ═══════════════ */

    public function test_his_rows_are_news_not_tasks(): void
    {
        $items = $this->actingAs($this->boss)
            ->getJson(route('super-admin.notifications.feed'))->json('items');

        foreach ($items as $item) {
            $this->assertSame('info', $item['kind'], "صفُّ {$item['key']} صُنّف مهمّةً");
        }
    }

    public function test_he_can_hide_a_piece_of_news(): void
    {
        $key = $this->actingAs($this->boss)
            ->getJson(route('super-admin.notifications.feed'))->json('items.0.key');

        $this->actingAs($this->boss)
            ->postJson(route('super-admin.notifications.dismiss'), ['key' => $key])
            ->assertOk();

        $this->assertDatabaseHas('dismissed_notifications', [
            'user_id' => $this->boss->id, 'notif_key' => $key,
        ]);

        $keys = array_column(
            $this->actingAs($this->boss)->getJson(route('super-admin.notifications.feed'))->json('items'),
            'key',
        );

        $this->assertNotContains($key, $keys, 'الخبرُ باقٍ بعد إخفائه');
    }

    public function test_hiding_the_news_empties_his_bell(): void
    {
        // «إخفاء الأخبار» — وكلُّ صفوفه أخبار، فلا يبقى شيء
        $this->actingAs($this->boss)->postJson(route('super-admin.notifications.clear'))->assertOk();

        $this->assertSame(
            0,
            $this->actingAs($this->boss)->getJson(route('super-admin.notifications.feed'))->json('count'),
        );

        $this->assertSame(2, DismissedNotification::where('user_id', $this->boss->id)->count());
    }

    public function test_no_state_row_is_ever_written_for_him(): void
    {
        /*
         * وهو مقصودٌ ومكتوبٌ في `mine()`: صفٌّ بـ`business_id = 0` لا تقرؤه
         * شاشةٌ ولا يمحوه كنس. فالأبوابُ الثلاثةُ تُنادى ولا تكتب.
         */
        $key = $this->actingAs($this->boss)
            ->getJson(route('super-admin.notifications.feed'))->json('items.0.key');

        foreach (['open', 'done', 'snooze'] as $door) {
            $this->actingAs($this->boss)
                ->postJson(route("super-admin.notifications.{$door}"), ['key' => $key, 'minutes' => 60])
                ->assertOk();
        }

        $this->assertSame(0, NotificationState::count(), 'كُتب صفُّ حالةٍ لمن لا متجرَ له');
    }

    public function test_his_reading_does_not_touch_the_merchants_bell(): void
    {
        /*
         * وإخفاءُ مديرِ المنصّة خبرَه لا يمسّ جرسَ التاجر — الإخفاءُ صفٌّ
         * لصاحبه وحدَه.
         */
        $this->actingAs($this->boss)->postJson(route('super-admin.notifications.clear'))->assertOk();

        $this->actingAs($this->owner);
        $keys = array_column(Demo::notificationFeed()['items'], 'key');

        $this->assertTrue((bool) preg_grep('/^low-/', $keys), 'ذهب تنبيهُ التاجر مع أخبار المنصّة');
    }
}
