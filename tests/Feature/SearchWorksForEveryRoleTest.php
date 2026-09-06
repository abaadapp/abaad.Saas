<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * زرُّ البحث كما يراه كلُّ من يفتحه: مدير المنصّة، وصاحب النشاط، والموظّف.
 *
 * الصندوق واحدٌ في الترويسة، ومساره ليس واحدًا: مدير المنصّة لا يملك
 * `business_id`، فبحثُه على `super-admin.search` — شركاتٌ ومستخدمون. وصاحبُ
 * النشاط وموظّفوه على `admin.search` — منتجاتٌ وطلباتٌ وعملاءُ وموردون.
 *
 * وحدودُ الموظّف حدودُ صلاحيته: من لا يملك «العملاء» لا يجدهم في الصندوق.
 * وبابٌ يعرض ما لا يُفتح أسوأ من بابٍ لا يُعرض — والأسوأ منهما أن يعرض
 * البحثُ اسمَ عميلٍ لمن مُنع من شاشة العملاء.
 */
class SearchWorksForEveryRoleTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $cashier;

    private User $onFloor;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        /*
         * كاشيرٌ بدوره لا بصلاحياتٍ مكتوبةٍ باليد.
         *
         * `Permissions::MAP['cashier']` هو ما يملكه كاشيرُ الإنتاج فعلًا —
         * لوحةٌ ونقطةُ بيع، لا أكثر. ومُعطًى يمنحه `orders` يدويًّا يختبر
         * موظّفًا لا وجود له، ويمرّ بينما ينكسر الحقيقيّ.
         */
        $this->cashier = User::create([
            'business_id' => $this->shop->id, 'name' => 'كاشير', 'email' => 'cashier@abaadapp.om',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        /*
         * وموظّفٌ يدخل اللوحة بصلاحياتٍ ممنوحةٍ يدويًّا: نقطةُ البيع والمنتجات.
         *
         * وهو الحال الذي يقع فعلًا حين يُمنح كاشيرٌ شاشةَ المنتجات: يدخل
         * اللوحة فيرى ترويستها وصندوقَها — ولا يملك «الطلبات».
         */
        $this->onFloor = User::create([
            'business_id' => $this->shop->id, 'name' => 'بائع', 'email' => 'floor@abaadapp.om',
            'password' => bcrypt('x'), 'role' => 'cashier', 'status' => 'نشط',
            'permissions' => ['pos', 'products'],
        ]);

        $this->platform = User::create([
            'business_id' => null, 'name' => 'مدير المنصّة', 'email' => 'super@abaadapp.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        Product::create([
            'business_id' => $this->shop->id, 'name' => 'وردة حمراء',
            'price' => 5, 'cost' => 2, 'quantity' => 9, 'alert_qty' => 1, 'active' => true,
        ]);
        Customer::create(['business_id' => $this->shop->id, 'name' => 'زبونة الورد', 'phone' => '99001122']);
        Supplier::create(['business_id' => $this->shop->id, 'name' => 'مشتل الورد']);
    }

    /** مجموعاتُ النتائج كما يردّها المسار لهذا المستخدم */
    private function titles(User $as, string $q, string $route): array
    {
        $res = $this->actingAs($as)->getJson(route($route, ['q' => $q]));
        $res->assertOk();

        return array_column($res->json('groups') ?? [], 'title');
    }

    private function labels(User $as, string $q, string $route): array
    {
        $res = $this->actingAs($as)->getJson(route($route, ['q' => $q]));
        $res->assertOk();

        $out = [];
        foreach ($res->json('groups') ?? [] as $g) {
            foreach ($g['items'] as $i) {
                $out[] = $i['label'];
            }
        }

        return $out;
    }

    /* --------------------------- صاحب النشاط --------------------------- */

    public function test_the_owner_finds_everything_in_his_shop(): void
    {
        $found = $this->labels($this->owner, 'ورد', 'admin.search');

        $this->assertContains('وردة حمراء', $found, 'المنتج لا يُوجد');
        $this->assertContains('زبونة الورد', $found, 'العميل لا يُوجد');
        $this->assertContains('مشتل الورد', $found, 'المورّد لا يُوجد');
    }

    public function test_the_owner_finds_a_customer_by_phone(): void
    {
        $this->assertContains('زبونة الورد', $this->labels($this->owner, '990011', 'admin.search'));
    }

    /* ----------------------------- الموظّف ----------------------------- */

    /**
     * الكاشير يبحث — لكن في حدود ما يملك.
     *
     * له «نقطة البيع» و«الطلبات» ولا شيء غيرهما، فاسمُ العميل واسمُ المورّد
     * لا يصلانه من الصندوق: لو وصلاه لكان الصندوق بابًا خلفيًّا إلى شاشةٍ
     * مُنع منها بالباب الأمامي.
     */
    public function test_an_employee_sees_only_what_his_permissions_open(): void
    {
        $found = $this->labels($this->onFloor, 'ورد', 'admin.search');

        $this->assertNotContains('زبونة الورد', $found, 'يرى عميلًا لا يملك شاشته');
        $this->assertNotContains('مشتل الورد', $found, 'يرى مورّدًا لا يملك شاشته');
        $this->assertContains('وردة حمراء', $found, 'مُنح المنتجات ولا يجدها');
    }

    private function sale(string $number): Order
    {
        return Order::create([
            'business_id' => $this->shop->id, 'number' => $number, 'status' => 'مكتمل',
            'payment_status' => 'مدفوع', 'payment_method' => 'نقدي', 'is_held' => false,
            'subtotal' => 5, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 5,
        ]);
    }

    /**
     * والكاشير يجد فاتورته برقمها.
     *
     * صلاحية `orders` تفتح شاشة الطلبات في اللوحة، وهو لا يملكها — فكان
     * البحث يحجب الفواتير عنه كلَّها. وهو أكثرُ من يكتب رقم فاتورةٍ في
     * يومه: عند الإرجاع، وإعادة الطباعة، وحين يسأل زبونٌ عن بيعةٍ سابقة.
     */
    public function test_a_cashier_finds_an_invoice_by_its_number(): void
    {
        $this->sale('INV-777777');

        $this->assertContains(
            'INV-777777',
            $this->labels($this->onFloor, '777777', 'admin.search'),
            'الكاشير لا يجد فاتورةً يفتحها بنفسه من الإيصالات',
        );
    }

    /** وتقوده إلى بابه هو لا إلى شاشةٍ مُنع منها */
    public function test_the_invoice_leads_the_cashier_to_his_own_screen(): void
    {
        $this->sale('INV-888888');

        $res = $this->actingAs($this->onFloor)->getJson(route('admin.search', ['q' => '888888']));
        $url = $res->json('groups.0.items.0.url');

        $this->assertStringContainsString('/pos/', (string) $url, 'الوجهة شاشةٌ لا يملكها الكاشير');
        $this->assertStringNotContainsString('/admin/orders', (string) $url);
    }

    /** ومن يملك شاشة الطلبات يُقاد إليها لا إلى نقطة البيع */
    public function test_the_owner_still_lands_on_the_orders_screen(): void
    {
        $this->sale('INV-999999');

        $res = $this->actingAs($this->owner)->getJson(route('admin.search', ['q' => '999999']));
        $url = $res->json('groups.0.items.0.url');

        $this->assertStringContainsString('/admin/orders', (string) $url);
    }

    /**
     * والكاشير الخالص لا يدخل اللوحة أصلًا — فلا ترويسةَ له ولا صندوق.
     *
     * `Permissions::MAP['cashier']` لوحةٌ ونقطةُ بيع، و`EntersPanel` يردّه.
     * وبابُه إلى الفواتير شاشةُ «الإيصالات» في نقطة البيع، وفيها بحثُها
     * الخاصّ يسأل الطلبات كلَّها لا الثلاثين المعروضة.
     */
    public function test_a_pure_cashier_never_reaches_the_panel(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('admin.search', ['q' => 'ورد']))
            ->assertForbidden();

        $this->actingAs($this->cashier)
            ->getJson(route('pos.receipts.search', ['q' => 'ورد']))
            ->assertOk();
    }

    /* -------------------------- مدير المنصّة -------------------------- */

    public function test_the_platform_admin_searches_companies_and_users(): void
    {
        $groups = $this->titles($this->platform, 'متجر', 'super-admin.search');

        $this->assertContains(__('الشركات'), $groups, 'بحث المنصّة لا يجد الشركات');
    }

    /**
     * ولا يدخل بحثَ التجّار: ليس له متجرٌ يبحث فيه.
     *
     * والردّ تحويلٌ لا منع: الحارس يردّه إلى لوحته بدل أن يقول «ممنوع» —
     * فمن قصد بابًا ليس بابَه يجد نفسه عند بابه لا أمام جدار.
     */
    public function test_the_platform_admin_is_not_a_merchant(): void
    {
        $res = $this->actingAs($this->platform)->get(route('admin.search', ['q' => 'ورد']));

        $this->assertTrue(
            in_array($res->status(), [302, 403], true),
            'بحثُ التجّار مفتوحٌ لمن لا متجرَ له — الردّ: '.$res->status(),
        );

        if ($res->status() === 302) {
            $this->assertStringNotContainsString('/admin/', (string) $res->headers->get('Location'));
        }
    }

    /** والتاجر لا يدخل بحث المنصّة */
    public function test_a_merchant_cannot_search_the_platform(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('super-admin.search', ['q' => 'متجر']))
            ->assertForbidden();
    }

    /* ---------------------------- والحدود ---------------------------- */

    /** ولا يعبر البحث بين متجرين */
    public function test_the_box_stops_at_the_shop_wall(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Product::create([
            'business_id' => $other->id, 'name' => 'وردة الجار',
            'price' => 9, 'cost' => 3, 'quantity' => 9, 'alert_qty' => 1, 'active' => true,
        ]);

        $this->assertNotContains('وردة الجار', $this->labels($this->owner, 'ورد', 'admin.search'));
    }

    /** والزائر لا يبحث */
    public function test_a_guest_gets_no_search(): void
    {
        $this->getJson(route('admin.search', ['q' => 'ورد']))->assertUnauthorized();
    }

    /** وحرفٌ واحد لا يُتعب الخادم */
    public function test_one_letter_asks_the_server_for_nothing(): void
    {
        $res = $this->actingAs($this->owner)->getJson(route('admin.search', ['q' => 'و']));

        $res->assertOk();
        $this->assertSame([], $res->json('groups'));
    }
}
