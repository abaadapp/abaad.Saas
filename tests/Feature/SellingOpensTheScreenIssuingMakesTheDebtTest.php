<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * فتحُ «المبيعات» يفتح الشاشة — والإصدارُ يُنشئ الذمّة.
 *
 * ═══ العطب ═══
 *
 * أربعةُ أفعالٍ ماليّة كانت تُملَك بفتح القسم: الإصدارُ الذي يولد الذمّة
 * ويكتب القيد، والإلغاءُ الذي يعكسه، والإشعارُ الدائن الذي يُنقص الدَّين،
 * وتسجيلُ التحصيل. فمن يُؤتمن على قراءة الطلبات كان يُلغي فاتورةً صادرةً
 * على وزارة — ويعكس قيدَها في الدفتر.
 *
 * ═══ وما لم يتغيّر ═══
 *
 * المسودّةُ تبقى بالقسم: لا تُنشئ ذمّةً ولا تكتب قيدًا، وإلزامُها فعلًا
 * مُسمًّى يقطع البائعَ عن عمله بلا مقابل.
 *
 * والبيعُ الآجل في نقطة البيع كذلك: يمرّ على `CustomerInvoices::issue`
 * مباشرةً لا على هذا الباب، وحارسُه `CreditSales::assertAllowed`. وإلزامُ
 * الكاشير فعلَ الإصدار كان يوقف كلَّ بيعةٍ آجلة عند الصندوق.
 */
class SellingOpensTheScreenIssuingMakesTheDebtTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $seller;

    private Customer $ministry;

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

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        /** بائعٌ يفتح «المبيعات» ولا يملك فعلًا ماليًّا */
        $this->seller = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 's@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            /*
             * ومعه «المالية» عمدًا.
             *
             * بابُ التحصيل محسوبٌ عليها في `ALIASES`، فبلا منحِها يُردّ
             * البائعُ عند القسم — ويمرّ الاختبارُ خضراءَ ولو رُفع حارسُ
             * الفعل كلُّه. فيُمنح القسمان ليُسأل الحارسُ وحدَه.
             */
            'permissions' => ['orders', 'customers', 'finance'],
        ]);

        $this->ministry = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة الثقافة', 'customer_type' => 'جهة حكومية',
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);
    }

    private function draft(): CustomerInvoice
    {
        return CustomerInvoices::create($this->business->id, $this->ministry, [], [
            ['description' => 'توريد', 'quantity' => 1, 'unit_price' => 400],
        ], $this->owner->id);
    }

    /* ═══════════════ ما يبقى بالقسم ═══════════════ */

    /** البائعُ يفتح الشاشة ويكتب المسودّة — ولا تُنشئ ذمّةً ولا قيدًا */
    public function test_a_seller_still_opens_the_screen_and_writes_a_draft(): void
    {
        $this->actingAs($this->seller)->get(route('admin.customerInvoices.index'))->assertOk();
        $this->actingAs($this->seller)->get(route('admin.customerInvoices.create'))->assertOk();

        $this->actingAs($this->seller)->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->ministry->id,
            'issued_at' => now()->toDateString(),
            'items' => [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 400]],
        ])->assertSessionHasNoErrors();

        $invoice = CustomerInvoice::firstOrFail();
        $this->assertSame(CustomerInvoice::DRAFT, $invoice->status);
        $this->assertNull($invoice->number);
    }

    /* ═══════════════ ما صار يُمنح بالاسم ═══════════════ */

    public function test_a_seller_may_not_issue(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->seller)
            ->post(route('admin.customerInvoices.issue', $draft->id))->assertForbidden();

        $this->assertSame(CustomerInvoice::DRAFT, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->number);
    }

    /**
     * ولا يُصدر بمربّع اختيارٍ في نموذج الإنشاء.
     *
     * «احفظ وأصدر» تكتب الورقة وتُصدرها في طلبٍ واحد، فلا تمرّ على باب
     * الإصدار ولا على حارسه. وحارسٌ يُلتفّ حوله ليس حارسًا.
     */
    public function test_a_seller_may_not_issue_through_the_create_form(): void
    {
        $this->actingAs($this->seller)->post(route('admin.customerInvoices.store'), [
            'customer_id' => $this->ministry->id,
            'issued_at' => now()->toDateString(),
            'issue' => true,
            'items' => [['description' => 'توريد', 'quantity' => 1, 'unit_price' => 400]],
        ])->assertForbidden();

        // ولا ورقةً نصفَ مكتوبة: المعاملةُ تسقط كاملة
        $this->assertSame(0, CustomerInvoice::count());
    }

    public function test_a_seller_may_not_cancel_an_issued_invoice(): void
    {
        $invoice = CustomerInvoices::issue($this->draft(), $this->owner->id);

        $this->actingAs($this->seller)
            ->post(route('admin.customerInvoices.cancel', $invoice->id), ['reason' => 'خطأ'])
            ->assertForbidden();

        $this->assertSame(CustomerInvoice::ISSUED, $invoice->fresh()->status);
    }

    public function test_a_seller_may_not_write_a_credit_note(): void
    {
        $invoice = CustomerInvoices::issue($this->draft(), $this->owner->id);

        $this->actingAs($this->seller)
            ->post(route('admin.customerInvoices.creditNote', $invoice->id), [
                'amount' => 50, 'reason' => 'مرتجع',
            ])->assertForbidden();

        $this->assertSame(0.0, $invoice->fresh()->creditedTotal());
    }

    public function test_a_seller_may_not_record_a_collection(): void
    {
        $invoice = CustomerInvoices::issue($this->draft(), $this->owner->id);

        $this->actingAs($this->seller)->post(route('admin.customerPayments.store'), [
            'customer_id' => $this->ministry->id,
            'amount' => 100,
            'method' => 'نقدي',
            'occurred_at' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertSame(400.0, $invoice->fresh()->outstanding());
    }

    /**
     * والشاشةُ لا ترسم مقبضًا يردّه الخادم.
     *
     * «بابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض»: من يضغط «إلغاء» فيُردّ
     * بـ٤٠٣ يظنّ النظامَ معطوبًا لا نفسَه غيرَ مأذون. فما يملكه القارئ
     * يُرسَل مع الشاشة، وعليه تُرسم المقابض.
     */
    public function test_the_screens_are_told_what_the_reader_may_do(): void
    {
        $invoice = CustomerInvoices::issue($this->draft(), $this->owner->id);

        $this->actingAs($this->seller)->get(route('admin.customerInvoices.show', $invoice->id))
            ->assertInertia(fn ($p) => $p
                ->where('may.issue', false)
                ->where('may.cancel', false)
                ->where('may.credit_note', false)
                ->where('may.pay', false)
                ->etc());

        $this->actingAs($this->seller)->get(route('admin.customerInvoices.create'))
            ->assertInertia(fn ($p) => $p->where('may.issue', false)->etc());

        $this->actingAs($this->owner)->get(route('admin.customerInvoices.show', $invoice->id))
            ->assertInertia(fn ($p) => $p
                ->where('may.issue', true)
                ->where('may.cancel', true)
                ->where('may.credit_note', true)
                ->where('may.pay', true)
                ->etc());

        /*
         * وأنّ الشاشة تقرأ العلَم يُحرَس بقراءة المصدر — وهو حارسٌ ضعيف
         * يُقال ضعفُه: تركيبُ صفحةٍ كاملة في jsdom يحتاج `AdminLayout` كلَّه.
         */
        foreach (['Show', 'Create'] as $screen) {
            $source = file_get_contents(base_path("resources/js/Pages/Admin/CustomerInvoices/{$screen}.tsx"));
            $this->assertStringContainsString('may.issue', $source);
        }
    }

    /**
     * ═══ وبابُ الإصدار الثاني يحمل الحارسَ نفسَه ═══
     *
     * فوترةُ الطلبات من شاشة الذمم تُصدر ورقةً وتكتب قيدَها. وبابان لفعلٍ
     * واحد أحدُهما محروسٌ يعني أنّ الحارس زينة.
     */
    public function test_the_other_issuing_door_carries_the_same_guard(): void
    {
        $this->actingAs($this->seller)
            ->post(route('admin.finance.customerBill', $this->ministry->id), [
                'order_ids' => [1],
            ])->assertForbidden();
    }

    /* ═══════════════ ومن يملكها يفعلها ═══════════════ */

    public function test_who_is_granted_the_actions_does_them(): void
    {
        $clerk = User::create([
            'business_id' => $this->business->id, 'name' => 'محاسب', 'email' => 'a@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => [
                'orders', 'finance',
                Permissions::CUSTOMER_INVOICE_ISSUE,
                Permissions::CUSTOMER_PAYMENT_CREATE,
            ],
        ]);

        $draft = $this->draft();

        $this->actingAs($clerk)->post(route('admin.customerInvoices.issue', $draft->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(CustomerInvoice::ISSUED, $draft->fresh()->status);

        $this->actingAs($clerk)->post(route('admin.customerPayments.store'), [
            'customer_id' => $this->ministry->id,
            'amount' => 400,
            'method' => 'نقدي',
            'occurred_at' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $draft->fresh()->outstanding());

        // ولم يُمنح الإلغاء — فلا يُلغي
        $this->actingAs($clerk)
            ->post(route('admin.customerInvoices.cancel', $draft->id), ['reason' => 'خطأ'])
            ->assertForbidden();
    }

    /* ═══════════════ ومن كان يفعلها أمس لا يُقطع ═══════════════ */

    /**
     * الترقيةُ تمنح الأربعةَ لمن كانت له قائمةٌ يدويّة فيها «المبيعات».
     *
     * وهي تُشغَّل في هذه الاختبارات على قاعدةٍ جديدة، فيُحاكى هنا: تُكتب
     * قائمةٌ قديمة كما كانت، ويُقرأ ما تمنحه الترقية.
     */
    public function test_a_hand_written_list_is_not_cut_off_by_the_upgrade(): void
    {
        $legacy = ['orders', 'customers'];

        $upgraded = $legacy;
        foreach (Permissions::LEGACY_SECTION_ACTIONS as $section => $actions) {
            if (in_array($section, $legacy, true)) {
                $upgraded = array_merge($upgraded, $actions);
            }
        }

        $user = User::create([
            'business_id' => $this->business->id, 'name' => 'قديم', 'email' => 'l@abaad.om',
            'password' => bcrypt('password'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => $upgraded,
        ]);

        $draft = $this->draft();

        $this->actingAs($user)->post(route('admin.customerInvoices.issue', $draft->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(CustomerInvoice::ISSUED, $draft->fresh()->status);
    }

    /**
     * والقائمةُ التي تقرؤها الهجرةُ هي التي يقرؤها النظام.
     *
     * `LEGACY_SECTION_ACTIONS['orders']` تُبقي الأربعةَ لمن مُنح القسم —
     * وقائمتان تُكتبان باليد تفترقان، فتمرّ الترقيةُ وتُردّ الشاشة.
     */
    public function test_the_upgrade_list_and_the_system_read_the_same_four(): void
    {
        $this->assertSame([
            Permissions::CUSTOMER_INVOICE_ISSUE,
            Permissions::CUSTOMER_INVOICE_CANCEL,
            Permissions::CUSTOMER_CREDIT_NOTE,
        ], Permissions::LEGACY_SECTION_ACTIONS['orders']);

        // والتحصيلُ تحت «المالية» — بابُه هناك لا في المبيعات
        $this->assertSame(
            [Permissions::CUSTOMER_PAYMENT_CREATE],
            Permissions::LEGACY_SECTION_ACTIONS['finance'],
        );

        foreach (array_merge(...array_values(Permissions::LEGACY_SECTION_ACTIONS)) as $action) {
            $this->assertArrayHasKey($action, Permissions::ACTIONS, 'فعلٌ بلا اسمٍ يُعرض');
            $this->assertArrayHasKey($action, Permissions::ACTION_ROLES, 'فعلٌ بلا دورٍ يرثه');
        }
    }

    /**
     * ولا بابَ كتابةٍ في القسم بلا حارس.
     *
     * قائمةٌ تُكتب باليد تنسى التاليَ دائمًا — فتُقرأ من جدول المسارات:
     * كلُّ مسارٍ يكتب في فواتير العملاء يُسأل عنه، ويُردّ البائعُ عن كلّ
     * ما يمسّ ورقةً صادرة.
     */
    public function test_no_writing_door_in_the_section_is_left_unguarded(): void
    {
        $invoice = CustomerInvoices::issue($this->draft(), $this->owner->id);

        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName() ?? '';
            $writes = array_intersect(['POST', 'DELETE', 'PUT', 'PATCH'], $route->methods());

            if (! str_starts_with($name, 'admin.customerInvoices.') || $writes === []) {
                continue;
            }

            // والمسودّةُ وعميلُها ومرفقُها تبقى بالقسم عمدًا — انظر أعلاه
            if (in_array($name, [
                'admin.customerInvoices.store',
                'admin.customerInvoices.storeCustomer',
                'admin.customerInvoices.remind',
                'admin.customerInvoices.attach',
                'admin.customerInvoices.detach',
            ], true)) {
                continue;
            }

            $params = [];
            foreach ($route->parameterNames() as $p) {
                $params[$p] = $p === 'id' ? $invoice->id : 1;
            }

            $method = strtolower((string) array_values($writes)[0]);

            $this->actingAs($this->seller)->$method(route($name, $params))->assertForbidden();
            $checked++;
        }

        // الإصدار، والإلغاء، والإشعار الدائن
        $this->assertGreaterThanOrEqual(3, $checked);
    }
}
