<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * جرسُ الإشعارات يُبنى مرّةً، ويقول ما عنده لا ما يعرضه.
 *
 * ═══ العطبُ الذي وُجد ═══
 *
 * كان كلُّ موضعٍ يسأل سؤالين: `notifications()` للصفوف
 * و`notificationsCount()` للعدّاد — وهذه تنادي تلك من جديد. فتُبنى
 * التغذيةُ **مرّتين في كلّ طلب**. وقياسٌ على الإنتاج: ٤٢ استعلامًا و١٥٠
 * جزءًا من الثانية حيث يكفي ٢١ و١٠٢ — في كلّ صفحةٍ يفتحها كلُّ مستخدم،
 * وفي كلّ نبضةٍ يستطلع بها الجرسُ نفسُه.
 *
 * ولم يكن للمسألة حارسٌ لأنّ المخرجات كانت صحيحة: العطبُ في الثمن لا في
 * الجواب، ولا يُرى إلّا بعدّ الاستعلامات.
 *
 * ═══ والصفوفُ ستٌّ لا عشرون ═══
 *
 * `buildNotifications(6)` تأخذ ستًّا **من كلّ مصدر**، والمصادرُ اثنا عشر.
 * فكان يُرسَل عشرون صفًّا في كلّ صفحة ويعرض الجرسُ ستًّا ويرمي الباقي.
 *
 * والعدّادُ يبقى على الجملة: شارةٌ تقول «٦» وتحتها مئتان تُطمئن كذبًا.
 */
class TheBellIsBuiltOnceAndSaysTheTruthTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);
    }

    /** أكثرُ من ستّة تنبيهاتٍ من مصدرين — فالحدُّ لكلّ مصدرٍ ستّة */
    private function manyNotifications(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            Order::create([
                'business_id' => $this->business->id, 'number' => 'INV-'.$i, 'customer_name' => 'نقدي',
                'status' => 'جديد', 'is_held' => false, 'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
            ]);
        }

        for ($i = 1; $i <= 5; $i++) {
            Product::create([
                'business_id' => $this->business->id, 'name' => 'صنف '.$i,
                'price' => 1, 'quantity' => 0, 'alert_qty' => 5,
            ]);
        }
    }

    /** عددُ الاستعلامات التي يُصدرها عملٌ ما */
    private function queries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $work();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_the_feed_is_built_once_not_twice(): void
    {
        $this->manyNotifications();

        /*
         * والقياسُ بعد تسخين.
         *
         * أوّلُ استدعاءٍ في العمليّة يحمل استعلامين زائدين لا علاقةَ لهما
         * بالتغذية (تهيئةٌ تقع مرّةً)، فلو قِيس عليه لَظهر البناءُ الواحد
         * أغلى من نفسه — وسقط حارسٌ سليم، أو مرّ معطوب.
         */
        Demo::notifications();

        $once = $this->queries(fn () => Demo::notifications());
        $feed = $this->queries(fn () => Demo::notificationFeed());

        $this->assertGreaterThan(1, $once, 'البناءُ بلا استعلامات — القياسُ لا يقيس شيئًا');
        $this->assertSame(
            $once,
            $feed,
            'التغذيةُ تبني مرّتين: الصفوفُ والعدّادُ من بناءٍ واحد لا من بناءين',
        );
    }

    public function test_the_bell_carries_six_rows_not_every_row(): void
    {
        $this->manyNotifications();

        $feed = Demo::notificationFeed();

        $this->assertGreaterThan(6, count(Demo::allNotifications()), 'التهيئةُ لم تُنتج أكثرَ من ستّة');
        $this->assertCount(6, $feed['items'], 'الجرسُ يحمل ما يرميه — صفوفٌ تعبر الشبكةَ ولا تُعرض');
    }

    public function test_the_badge_counts_more_than_the_bell_shows(): void
    {
        $this->manyNotifications();

        $feed = Demo::notificationFeed();

        /*
         * ولا يُقصّ العدّادُ مع الصفوف.
         *
         * شارةٌ تقول «٦» وتحتها عشرون طمأنينةٌ كاذبة — والتاجر يظنّ أنّه
         * قرأ ما عنده حين يُفرغ الستّ.
         */
        $this->assertGreaterThan(
            count($feed['items']),
            $feed['count'],
            'العدّادُ يعدّ المعروضَ لا الموجود',
        );
    }

    public function test_a_quiet_shop_counts_exactly_what_it_shows(): void
    {
        Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-1', 'customer_name' => 'نقدي',
            'status' => 'جديد', 'is_held' => false, 'subtotal' => 5, 'total' => 5, 'ordered_at' => now(),
        ]);

        $feed = Demo::notificationFeed();

        $this->assertSame(count($feed['items']), $feed['count']);
    }

    public function test_the_live_feed_route_builds_it_once_too(): void
    {
        $this->manyNotifications();

        Demo::notificationFeed();

        $one = $this->queries(fn () => Demo::notificationFeed());
        $http = $this->queries(fn () => $this->getJson(route('admin.notifications.feed'))->assertOk());

        // الطلبُ فيه استعلاماتُ الجلسة وآخرِ طلبٍ فوق البناء — لكنّه دون بناءين
        $this->assertLessThan(
            $one * 2,
            $http,
            'مسارُ التغذية الحيّة يبني مرّتين — وهو ما يُستطلع كلَّ بضع ثوانٍ',
        );
    }

    public function test_the_shared_shape_the_bell_reads_did_not_change(): void
    {
        $feed = Demo::notificationFeed();

        $this->assertSame(['items', 'count'], array_keys($feed));
        $this->assertIsArray($feed['items']);
        $this->assertIsInt($feed['count']);
    }
}
