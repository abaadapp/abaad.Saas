<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Setting;
use App\Models\User;
use App\Support\Permissions;
use App\Support\SectionActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * القسمُ يفتح الشاشة، وكلُّ ما يكتب فيها فعلٌ باسمه — ولا يخسر موظّفٌ قائمٌ شيئًا.
 *
 * ═══ ما يُحرس هنا ═══
 *
 *   - كلُّ مسارٍ في `SectionActions::ROUTES` موجود، ويقع في قسم فعله نفسِه.
 *   - أدوارُ كلّ فعلٍ هي أدوارُ قسمه في `Permissions::MAP` — فمن يتبع دورَه لا يخسر.
 *   - والهجرةُ تمنح القائمةَ اليدويّة أفعالَ أقسامها وحدها — ولا تمسّ من يتبع دورَه.
 *   - وكلُّ بابِ كتابةٍ في اللوحة مُصنَّف: فعلٌ باسمه، أو حارسٌ قائم، أو سببٌ مكتوب.
 *   - والقسمُ وحده يفتح الشاشة ويُردّ عن الكتابة بـ٤٠٣ — من الخادم لا من الزرّ.
 *   - ولا يمنح أحدٌ غيرَه ما لا يملك، ولا يرفع نفسه.
 *   - والنشاطُ الجديد يجد هذا كلَّه بلا إعداد، وصاحبُه يملك كلَّ فعل.
 */
class EveryOperationIsGrantedByItsOwnNameTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    /**
     * أبوابُ كتابةٍ لا تُفصل عن قسمها — وسببُ كلٍّ منها.
     *
     * بابٌ جديدٌ لا هنا ولا في `ROUTES` ولا بحارس `may:` يُسقط
     * `test_every_write_door_is_named_guarded_or_explained` — فيُسأل عنه قبل
     * أن يصير بابًا يفتحه القسمُ وحده.
     */
    private const KEPT = [
        // يحرسها فعلٌ قائمٌ أو «صاحب النشاط وحده» في المتحكّم — لا شرطَ ثانٍ فوقها
        'controller' => [
            'admin.customers.internal', 'admin.customers.paymentLink',
            'admin.payroll.approve', 'admin.payroll.pay',
            'admin.customerPayments.cancel', 'admin.customerPayments.store',
            'admin.finance.cheques.bounce', 'admin.finance.cheques.clear',
            'admin.finance.customerBill', 'admin.finance.customerCredit',
            'admin.finance.paymentLinks.cancel', 'admin.finance.paymentLinks.store',
            'admin.inventory.receipts.approve', 'admin.inventory.receipts.reject',
            'admin.marketing.store.save',
            'admin.customerInvoices.cancel', 'admin.customerInvoices.creditNote', 'admin.customerInvoices.issue',
            'admin.customerInvoices.store', 'admin.customerInvoices.update', 'admin.customerInvoices.editPreview',
            'admin.customerInvoices.paymentLink', 'admin.customerInvoices.branding',
            'admin.customerInvoices.preparation.activate', 'admin.customerInvoices.preparation.remove',
            'admin.customerInvoices.preparation.save',
            'admin.customerInvoices.corrections.approve', 'admin.customerInvoices.corrections.reject',
            'admin.customerInvoices.corrections.withdraw',
            'admin.orders.destroy', 'admin.orders.status', 'admin.orders.boutique-pieces', 'admin.orders.boutique-returns',
            'admin.products.addons.display',
            'admin.purchases.invoices.approve', 'admin.purchases.invoices.cancel', 'admin.purchases.invoices.destroy',
            'admin.purchases.invoices.pay', 'admin.purchases.invoices.reject', 'admin.purchases.invoices.store',
            'admin.purchases.receive',
            'admin.backup.restore', 'admin.backup.latest.restore',
            'admin.settings.recovery.start', 'admin.settings.recovery.confirm',
            'pos.movements.store', 'pos.setup.activate',
        ],
        // تصحيحُ البيع في يومه: `order.edit` يُسأل عنه في `OrderEditController::mayEdit`
        'order.edit' => [
            'admin.orders.items.store', 'admin.orders.items.update', 'admin.orders.items.replace',
            'admin.orders.items.note', 'admin.orders.items.addons.update', 'admin.orders.payment.update',
            'admin.orders.balance.collect',
            'pos.orders.items.store', 'pos.orders.items.update', 'pos.orders.items.replace',
            'pos.orders.items.note', 'pos.orders.items.addons.update', 'pos.orders.payment.update',
            'pos.orders.balance.collect',
        ],
        // النقلُ والتزويد: كلُّ خطوةٍ بفعلها في `BranchTransfers` و`BranchSupply`
        'transfers' => [
            'admin.inventory.transfers.store', 'admin.inventory.transfers.approve', 'admin.inventory.transfers.reject',
            'admin.inventory.transfers.prepare', 'admin.inventory.transfers.dispatch', 'admin.inventory.transfers.receive',
            'admin.inventory.transfers.resolve', 'admin.inventory.transfers.cancel',
            'admin.inventory.supply.store', 'admin.inventory.supply.approve', 'admin.inventory.supply.reject',
            'admin.inventory.supply.cancel',
        ],
        // عملُ القسم نفسُه — البيعُ في الصندوق ونقلُ الطلب في لوحة التجهيز
        'work' => [
            'pos.checkout', 'pos.hold', 'pos.orders.discard', 'pos.coupon.apply', 'pos.customers.store',
            'pos.occasions.store', 'pos.cashier.select', 'pos.cashier.leave', 'pos.lock', 'pos.language.update',
            'pos.printJobs.claim', 'pos.printJobs.ack', 'pos.printJobs.confirm',
            'admin.preparation.move', 'admin.preparation.check', 'admin.preparation.invoiceMove',
            'admin.preparation.invoiceRequeue', 'admin.preparation.invoicePrintConfirm',
        ],
        /*
         * معايناتٌ وتفضيلاتٌ لا تكتب مالًا ولا مستندًا: حسابُ الإجمالي قبل
         * الحفظ، والعميلُ والمورّدُ الافتراضيّ، وإخفاءُ وحدةٍ من القائمة. ومرفقُ
         * الفاتورة وعميلُها الجديد من شاشتها — يبقيان بالقسم عمدًا
         * (`SellingOpensTheScreenIssuingMakesTheDebtTest`).
         */
        'preview' => [
            'admin.customerInvoices.preview', 'admin.customerInvoices.defaultCustomer',
            'admin.customerInvoices.storeCustomer', 'admin.customerInvoices.attach', 'admin.customerInvoices.detach',
            'admin.purchases.preview', 'admin.purchases.defaultSupplier', 'admin.purchases.units.destroy',
            'admin.settings.templates.preview', 'admin.store.preview',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجر الصلاحيات', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $this->owner = $this->user('admin', 'owner@perm.om');
    }

    private function user(string $role, string $email, ?array $permissions = null, ?Business $shop = null): User
    {
        return User::create([
            'business_id' => ($shop ?? $this->shop)->id, 'name' => $role.' '.$email, 'email' => $email,
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /* ═══════════════ ١ · الخريطة نفسُها ═══════════════ */

    public function test_every_mapped_route_exists_and_sits_in_its_actions_own_section(): void
    {
        $wrong = [];

        foreach (SectionActions::ROUTES as $route => $action) {
            if (! Route::has($route)) {
                $wrong[] = "{$route}: لا مسار بهذا الاسم";

                continue;
            }

            $from = SectionActions::SPLIT[$action] ?? null;
            $section = Permissions::sectionFromRoute($route);

            // ولو وقع في قسمٍ غيرِ قسم فعله لخسر من يملك ذاك القسمَ بابًا كان يفتحه
            if ($from !== $section) {
                $wrong[] = "{$route}: في قسم «{$section}» وفعلُه «{$action}» من «{$from}»";
            }
        }

        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    public function test_every_split_action_is_held_by_exactly_the_roles_of_its_section(): void
    {
        foreach (SectionActions::SPLIT as $action => $section) {
            $holders = array_values(array_filter(array_keys(Permissions::MAP), fn ($r) => Permissions::allows($r, $section)));
            $roles = SectionActions::ROLES[$action] ?? [];
            sort($holders);
            sort($roles);

            $this->assertSame($holders, $roles, "«{$action}» لا يطابق أدوارَ قسم «{$section}»");
            $this->assertArrayHasKey($action, Permissions::ACTIONS, "«{$action}» بلا اسمٍ يُعرض");
        }

        // والأفعالُ الجديدة كلُّها في السجلّ الواحد: يقرؤها الحارسُ والشاشةُ و`beyond`
        $this->assertSame([], array_diff(array_keys(SectionActions::SPLIT), Permissions::actions()));
    }

    public function test_every_action_and_section_sits_in_exactly_one_group(): void
    {
        $actions = array_merge(...array_map(fn ($g) => $g['actions'], SectionActions::GROUPS));
        $views = array_values(array_filter(array_map(fn ($g) => $g['view'], SectionActions::GROUPS)));

        $this->assertSame([], array_diff_assoc($actions, array_unique($actions)), 'فعلٌ في مجموعتين');
        $this->assertEqualsCanonicalizing(Permissions::actions(), $actions, 'فعلٌ بلا مجموعة أو مجموعةٌ بفعلٍ مجهول');
        $this->assertEqualsCanonicalizing(Permissions::sections(), $views, 'قسمٌ بلا مجموعة');
    }

    public function test_every_write_door_is_named_guarded_or_explained(): void
    {
        $kept = array_merge(...array_values(self::KEPT));
        $open = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'admin.') && ! str_starts_with($name, 'pos.')) {
                continue;
            }

            if (array_diff($route->methods(), ['GET', 'HEAD']) === [] || Permissions::isShell($name)) {
                continue;
            }

            $guarded = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'may:'));

            if (! $guarded && ! isset(SectionActions::ROUTES[$name]) && ! in_array($name, $kept, true)
                && Permissions::routeAction($name) === null) {
                $open[] = $name;
            }
        }

        $this->assertSame([], $open, "أبوابُ كتابةٍ يفتحها القسمُ وحده — سمِّ فعلها في SectionActions::ROUTES أو اكتب سببها في KEPT:\n".implode("\n", $open));
    }

    /* ═══════════════ ٢ · الخادم يحرس ═══════════════ */

    public function test_a_section_alone_opens_the_screen_but_none_of_its_writes(): void
    {
        $clerk = $this->user('sales', 'clerk@perm.om', ['dashboard', 'customers']);
        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'عميل', 'phone' => '91110000']);

        $this->actingAs($clerk)->get(route('admin.customers.index'))->assertOk();

        $this->actingAs($clerk)->post(route('admin.customers.store'), ['name' => 'جديد', 'phone' => '92220000', 'language' => 'ar'])->assertForbidden();
        $this->actingAs($clerk)->put(route('admin.customers.update', $customer->id), ['name' => 'معدّل'])->assertForbidden();
        $this->actingAs($clerk)->delete(route('admin.customers.destroy', $customer->id))->assertForbidden();
        $this->actingAs($clerk)->get($this->exportRoute('admin.export.customers'))->assertForbidden();

        $this->assertSame('عميل', $customer->fresh()->name);
        $this->assertNull($customer->fresh()->deleted_at);
        $this->assertSame(1, Customer::where('business_id', $this->shop->id)->count());
    }

    public function test_the_named_action_opens_its_own_door_and_no_other(): void
    {
        $clerk = $this->user('sales', 'adder@perm.om', ['dashboard', 'customers', 'customer.create']);
        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'قائم', 'phone' => '91110001']);

        $this->actingAs($clerk)->post(route('admin.customers.store'), ['name' => 'جديد', 'phone' => '92220001', 'language' => 'ar'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Customer::where('business_id', $this->shop->id)->where('name', 'جديد')->exists());

        // والإضافةُ لا تبيح الحذف
        $this->actingAs($clerk)->delete(route('admin.customers.destroy', $customer->id))->assertForbidden();
        $this->assertNull($customer->fresh()->deleted_at);
    }

    public function test_finance_screens_open_but_a_journal_entry_and_its_reversal_are_each_named(): void
    {
        $viewer = $this->user('accountant', 'viewer@perm.om', ['dashboard', 'finance']);

        $this->actingAs($viewer)->get(route('admin.finance.journal'))->assertOk();
        $this->actingAs($viewer)->post(route('admin.finance.journal.store'), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.finance.journal.reverse', 1))->assertForbidden();
        $this->actingAs($viewer)->get($this->exportRoute('admin.export.transactions'))->assertForbidden();
    }

    public function test_staff_who_follow_their_role_keep_every_write_they_had(): void
    {
        // البائعُ بدوره كان يضيف عميلًا بقسم «العملاء» — ويبقى
        $seller = $this->user('sales', 'seller@perm.om');
        $this->actingAs($seller)->post(route('admin.customers.store'), ['name' => 'من البائع', 'phone' => '93330000', 'language' => 'ar'])
            ->assertRedirect()->assertSessionHasNoErrors();

        foreach (SectionActions::SPLIT as $action => $section) {
            foreach (array_keys(Permissions::MAP) as $role) {
                $u = new User(['role' => $role, 'permissions' => null]);
                $this->assertSame(Permissions::allows($role, $section), $u->may($action), "{$role} و«{$action}»");
            }
        }
    }

    public function test_the_closing_date_needs_its_own_action_and_an_unchanged_one_does_not(): void
    {
        $settings = $this->user('manager', 'set@perm.om', ['dashboard', 'settings', 'settings.edit']);

        $this->actingAs($settings)->post(route('admin.settings.update'), ['books_closed_through' => '2025-01-31'])
            ->assertSessionHasErrors('books_closed_through');
        $this->assertNull(Setting::where('business_id', $this->shop->id)->where('key', 'books_closed_through')->value('value'));

        // والشاشةُ ترسل التاريخ كما قرأته مع كلّ حفظ — فلا يُردّ ما لم يتغيّر
        $this->actingAs($settings)->post(route('admin.settings.update'), ['books_closed_through' => null, 'currency' => 'OMR'])
            ->assertSessionHasNoErrors();

        $closer = $this->user('manager', 'close@perm.om', ['dashboard', 'settings', 'settings.edit', 'books.close']);
        $this->actingAs($closer)->post(route('admin.settings.update'), ['books_closed_through' => '2025-01-31'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2025-01-31', Setting::where('business_id', $this->shop->id)->where('key', 'books_closed_through')->value('value'));

        // ومديرُ الفرع بدوره كان يُقفل — ويبقى
        $manager = $this->user('manager', 'mgr@perm.om');
        $this->actingAs($manager)->post(route('admin.settings.update'), ['books_closed_through' => '2025-02-28'])
            ->assertSessionHasNoErrors();
    }

    /* ═══════════════ ٣ · الهجرة ═══════════════ */

    public function test_the_migration_gives_a_manual_list_what_its_sections_allowed_and_nothing_more(): void
    {
        $manual = $this->user('sales', 'old@perm.om', ['dashboard', 'customers', 'orders', Permissions::CUSTOMER_INVOICE_ISSUE]);
        $byRole = $this->user('accountant', 'role@perm.om');
        $cashier = $this->user('cashier', 'till@perm.om', ['pos']);
        DB::table('users')->insert([
            'business_id' => $this->shop->id, 'name' => 'قديم', 'email' => 'odd@perm.om', 'password' => 'x',
            'role' => 'sales', 'status' => 'نشط', 'permissions' => '"customers"', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_04_090000_every_section_names_what_it_lets_you_do.php');
        $migration->up();

        $list = $manual->fresh()->permissions;
        foreach (['customer.create', 'customer.edit', 'customer.delete', 'customer.points', 'customer.export',
            'order.details', 'order.message', 'order.export', 'goal.manage'] as $action) {
            $this->assertContains($action, $list, "خسر «{$action}»");
        }

        // ولا شيءَ من قسمٍ لم يُمنح، ولا الفعلُ الجديدُ الذي لم يكن يُملك بقسم
        foreach (['product.create', 'journal.reverse', 'books.close', 'stock.adjust', Permissions::FINANCIAL_CORRECTION_APPROVE] as $action) {
            $this->assertNotContains($action, $list, "مُنح «{$action}» بلا قسمه");
        }

        $this->assertNull($byRole->fresh()->permissions, 'من يتبع دورَه لا تُكتب له قائمة');
        $this->assertSame(['pos'], $cashier->fresh()->permissions, 'نقطةُ البيع لا أفعالَ فُصلت عنها');
        $this->assertSame('"customers"', DB::table('users')->where('email', 'odd@perm.om')->value('permissions'), 'ما ليس قائمةً لا يُخمَّن');

        // وكلُّ بابٍ كان يفتحه أمسِ يفتحه اليوم
        $customer = Customer::create(['business_id' => $this->shop->id, 'name' => 'عميل', 'phone' => '91110002']);
        $this->actingAs($manual->fresh())->delete(route('admin.customers.destroy', $customer->id))->assertRedirect();

        $migration->down();
        $this->assertSame(['dashboard', 'customers', 'orders', Permissions::CUSTOMER_INVOICE_ISSUE], $manual->fresh()->permissions);
    }

    /* ═══════════════ ٤ · من يمنح ═══════════════ */

    public function test_no_one_grants_an_operation_he_does_not_hold(): void
    {
        $hr = $this->user('manager', 'hr@perm.om', ['dashboard', 'employees', 'employee.create', 'customers', 'customer.create']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'بائع', 'role' => 'sales']);

        // ٤٠٣ كاملة: `refuseGrantingMoreThanIHave` تقرأ `Permissions::actions()` وفيها الأفعالُ الجديدة
        $this->actingAs($hr)->post(route('admin.employees.store'), [
            'name' => 'موظف', 'login_username' => 'e'.uniqid(), 'job_title' => 'بائع', 'status' => 'نشط',
            'manual_permissions' => true, 'permissions' => ['customers', 'customer.delete'],
        ])->assertForbidden();

        $this->assertFalse(User::where('business_id', $this->shop->id)->where('name', 'موظف')->exists());
    }

    public function test_an_employee_cannot_widen_his_own_list(): void
    {
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير فرع', 'role' => 'manager']);
        $me = $this->user('manager', 'me@perm.om', SectionActions::withActionsOf(['dashboard', 'employees']));
        $me->forceFill(['job_title' => 'مدير فرع'])->save();

        // يُردّ بـ٤٠٣ كاملة قبل أن يُسأل عن نفسه: لا يمنح أحدٌ ما لا يملك (`refuseGrantingMoreThanIHave`)
        $this->actingAs($me)->put(route('admin.employees.update', $me->id), [
            'name' => $me->name, 'email' => $me->email, 'job_title' => 'مدير فرع', 'manual_permissions' => true,
            'permissions' => [...$me->permissions, 'customers', 'customer.delete'],
        ])->assertForbidden();

        $this->assertNotContains('customer.delete', $me->fresh()->permissions);
    }

    /* ═══════════════ ٥ · كلُّ نشاط ═══════════════ */

    public function test_a_new_business_finds_every_operation_and_its_owner_holds_them_all(): void
    {
        $fresh = Business::create(['name' => 'نشاطٌ جديد', 'type' => 'عام', 'status' => 'نشط']);
        $owner = $this->user('admin', 'new-owner@perm.om', null, $fresh);
        $seller = $this->user('sales', 'new-seller@perm.om', null, $fresh);

        foreach (Permissions::actions() as $action) {
            $this->assertTrue($owner->may($action), "صاحبُ النشاط الجديد لا يملك «{$action}»");
        }

        // والموظّفُ الجديدُ بدوره: عملُ قسمه، ولا شيءَ حسّاسٌ لم يُمنحه
        $this->assertTrue($seller->may('customer.create'));
        $this->assertFalse($seller->may('journal.reverse'));
        $this->assertFalse($seller->may(Permissions::FINANCIAL_CORRECTION_APPROVE));
        $this->assertFalse($seller->may('books.close'));

        // وشاشةُ الصلاحيات تعرض المجموعاتِ نفسَها لكلّ نشاط
        $this->actingAs($owner)->get(route('admin.employees.edit', $seller->id))
            ->assertInertia(fn (Assert $p) => $p->has('permissionGroups', count(SectionActions::GROUPS)));
    }

    public function test_one_shops_grants_never_reach_another(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = $this->user('sales', 'theirs@perm.om', null, $other);
        $mine = $this->user('sales', 'mine@perm.om', ['dashboard', 'customers']);

        $migration = require database_path('migrations/2026_11_04_090000_every_section_names_what_it_lets_you_do.php');
        $migration->up();

        $this->assertNull($theirs->fresh()->permissions);
        $this->assertContains('customer.create', $mine->fresh()->permissions);

        // ومالكُ نشاطٍ لا يبلغ عميلَ غيره ولو ملك كلّ فعل
        $foreign = Customer::create(['business_id' => $other->id, 'name' => 'عميلهم', 'phone' => '94440000']);
        $this->actingAs($this->owner)->delete(route('admin.customers.destroy', $foreign->id))->assertNotFound();
        $this->assertNull($foreign->fresh()->deleted_at);
    }
}
