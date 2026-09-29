<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderStatus;
use App\Support\Permissions;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * جرسُ طلب الموقع — كما يصل من باب التغذية الذي يستطلعه الجرس.
 *
 * ═══ ولمَ هذا الملفّ وفي المستودع حارسٌ للقاعدة ═══
 *
 * `EverySaleSaysWhichDoorItCameThroughTest` يحرس القاعدة من `Demo::allNotifications`
 * للبائع والمحاسب ومن خُصِّص يدويًّا. وبقي بلا حارسٍ صريح: الأدوارُ التي
 * تستلمه بدورها (المالك، المدير، السائق) والتي لا تستلمه بدورها (الكاشير،
 * المخزن) — **ومن الباب الذي يطرقه الجرس فعلًا** كلَّ ثلاثين ثانية
 * (`admin.notifications.feed` — انظر `Topbar`).
 *
 * لا يُغيّر شيئًا في السلوك: يثبّت ما هو قائم ليسقط يومَ يتبدّل.
 */
class TheBellFeedWakesOnlyTheRolesThatPrepareWebsiteOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Order $web;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);

        $this->web = Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $branch->id,
            'number' => 'WEB-100', 'status' => OrderStatus::PENDING, 'is_held' => false,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 25, 'total' => 25, 'ordered_at' => now(),
            'channel' => SalesChannel::WEBSITE,
        ]);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function roles(): iterable
    {
        yield 'المالك' => ['admin', true];
        yield 'المدير' => ['manager', true];
        yield 'البائع' => ['sales', true];
        yield 'السائق' => ['delivery', true];
        yield 'الكاشير' => ['cashier', false];
        yield 'المخزن' => ['inventory', false];
        yield 'المحاسب' => ['accountant', false];
    }

    #[DataProvider('roles')]
    public function test_the_feed_wakes_a_role_by_its_default_grant(string $role, bool $woken): void
    {
        $user = User::create([
            'business_id' => $this->shop->id, 'name' => $role, 'email' => $role.'@abaad.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط',
        ]);

        // والقاعدةُ نفسُها كما في الصلاحيات — القسمُ والفعلُ معًا
        $this->assertSame($woken, $user->allows('orders') && $user->may(Permissions::ORDER_WEBSITE_NOTIFY));

        /*
         * والفعلُ نفسُه لا يُمنح بالدور لمن لا يستلمه — لا أنّ القسمَ وحده يحجبه.
         *
         * فلو مُنح الكاشيرُ الفعلَ بدوره غدًا ثمّ فُتح له قسمُ «المبيعات» لسببٍ
         * آخر، لاستلم الجرسَ بلا قرارٍ من أحد.
         */
        $this->assertSame($woken, $user->may(Permissions::ORDER_WEBSITE_NOTIFY), "منحُ {$role} الفعلَ بدوره تغيّر");

        $texts = $this->feedTexts($user);
        $line = 'طلبٌ جديد من الموقع الإلكتروني: WEB-100';

        $woken
            ? $this->assertContains($line, $texts, "{$role} لا يستلم طلبَ الموقع")
            : $this->assertNotContains($line, $texts, "{$role} يستلم طلبَ الموقع بدوره");
    }

    public function test_a_hand_picked_employee_needs_both_the_section_and_the_action(): void
    {
        $line = 'طلبٌ جديد من الموقع الإلكتروني: WEB-100';
        $make = fn (string $email, array $permissions) => User::create([
            'business_id' => $this->shop->id, 'name' => 'موظّف', 'email' => $email,
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'نشط', 'permissions' => $permissions,
        ]);

        $this->assertNotContains($line, $this->feedTexts($make('a@abaad.om', ['orders'])));
        $this->assertNotContains($line, $this->feedTexts($make('b@abaad.om', ['pos', Permissions::ORDER_WEBSITE_NOTIFY])));
        $this->assertContains($line, $this->feedTexts($make('c@abaad.om', ['orders', Permissions::ORDER_WEBSITE_NOTIFY])));
    }

    public function test_once_confirmed_the_website_order_leaves_the_bell(): void
    {
        // «بانتظار العمل» في الجرس: جديد وقيد التجهيز — والمؤكَّدُ ليس منهما
        $owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->web->update(['status' => OrderStatus::CONFIRMED]);

        $this->assertNotContains('طلبٌ جديد من الموقع الإلكتروني: WEB-100', $this->feedTexts($owner));
    }

    private function feedTexts(User $user): array
    {
        $response = $this->actingAs($user)->getJson(route('admin.notifications.feed'));

        // ومن لا يبلغ الباب أصلًا لا يستلم منه شيئًا
        if ($response->status() !== 200) {
            return [];
        }

        return array_column($response->json('items') ?? [], 'text');
    }
}
