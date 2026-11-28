<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Permissions;
use App\Support\PurchaseOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ثماني عمليّاتٍ ماليّة — من يبلغها اليوم، وبأيّ حارس.
 *
 * ═══ لماذا هذا الملفّ ═══
 *
 * اقتُرح أن تصير ثمانٍ منها أفعالًا تُمنح بأسمائها: عكسُ القيد اليدويّ،
 * وإقفالُ الدفاتر، ومطابقةُ البنك، وتسوياتُ Paymob، وحذفُ المصروف، وحذفُ أمر
 * الشراء، وتسويةُ المخزون، واعتمادُ الجرد. وشرطُ صاحب النظام ألّا تتّسع
 * صلاحيةُ موظّفٍ ولا تضيق.
 *
 * وكلُّ واحدةٍ منها يحرسها اليومَ **قسمُها وحده** — `CheckAbility` من اسم
 * المسار، ولا سؤالَ بعده في المتحكّم. فمن يملك القسمَ يفعلها: بدوره، أو
 * بقائمةٍ يدويّةٍ فيها القسم. و`User::may` يقرأ القائمةَ اليدويّة حرفًا
 * بحرف — فلو صار لها فعلٌ باسمه لما وجده في قائمةٍ كُتبت قبله، فتُردّ.
 * وهذا عينُ ما كتبته هجرةُ `2026_09_08_120000_the_money_actions_are_named_one_by_one`
 * عن صباح النشر بلا هجرة.
 *
 * فلا تُوصَل بعدُ (القرارُ لصاحب النظام)، وهذا الملفّ يحفظ ما هو قائم:
 *
 *   ١ — كلُّ بابٍ من أبوابها يُفتح لأدوار قسمه، ويُردّ عن غيرها (٤٠٣).
 *   ٢ — والقائمةُ اليدويّةُ يحكمها ما فيها من أقسام، لا الدور.
 *   ٣ — وقائمةٌ فيها القسمُ وحده تُتمّ العمليّة فعلًا — لا تمرّ بالحارس فحسب.
 *   ٤ — ولا اسمَ من الثمانية في `Permissions` ولا في شاشة الصلاحيات ولا على مسار.
 *
 * ومن يوصلها بعد قرار صاحب النظام يُبقي (١) و(٢) و(٣) كما هي — فهي مقياسُ
 * «لم يتغيّر أحد» — ويُحدّث (٤) وحدها.
 */
class EachFinancialOperationAnswersToItsSectionTodayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * القسمُ ← الأدوارُ التي تفتحه بلا قائمة يدويّة.
     *
     * مكتوبةٌ باليد لا مقروءةٌ من `Permissions::MAP`: لو قُرئت منها لتبعتها
     * كلُّ طفرةٍ فيها ومرّ الاختبار.
     */
    private const SECTION_ROLES = [
        'finance' => ['admin', 'manager', 'accountant'],
        'settings' => ['admin', 'manager'],
        'expenses' => ['admin', 'manager', 'accountant'],
        'purchases' => ['admin', 'manager', 'accountant', 'inventory'],
        'inventory' => ['admin', 'manager', 'inventory'],
        'products' => ['admin', 'manager', 'inventory', 'sales'],
    ];

    /** الأدوارُ التي يحملها موظّفٌ في متجر — ومديرُ المنصّة لا يدخل لوحةَ متجر */
    private const ROLES = ['admin', 'manager', 'accountant', 'inventory', 'sales', 'delivery', 'cashier'];

    /** الأسماءُ الثمانية المقترحة — لا يُوصَل منها شيءٌ قبل قرار صاحب النظام */
    private const PROPOSED = [
        'journal.reverse', 'books.close', 'bank.reconcile', 'paymob.settle',
        'expense.delete', 'purchase.delete', 'stock.adjust', 'stock.count',
    ];

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورد المرسى', 'type' => 'محل ورود', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        $this->owner = $this->user('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?array $permissions = null): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'business_id' => $this->shop->id, 'name' => 'موظّف '.$n, 'email' => 'f'.$n.'@sections.om',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    /**
     * أبوابُ كلّ عمليّة — [الوصف، الطريقة، المسار، معاملاته، الجسد، الأقسامُ التي يسألها].
     *
     * والمعرِّفُ صفرٌ عمدًا: لا صفَّ به في أيّ قاعدة، فمن يمرّ بالحارس يبلغ
     * `findOrFail` فيُردّ ٤٠٤ — ولا يُكتب شيء. والجسدُ ناقصٌ عمدًا حيث يُتحقَّق
     * منه: من يمرّ يُردّ بخطأ تحقّقٍ (٣٠٢)، ومن لا يمرّ ٤٠٣. فالفرقُ بين
     * الاثنين هو الحارسُ وحده.
     *
     * @return array<string, list<array{0: string, 1: string, 2: string, 3: array, 4: array, 5: list<string>}>>
     */
    private static function doors(): array
    {
        $none = ['id' => 0];
        $notADate = 'ليس تاريخًا';

        return [
            'journal.reverse' => [
                ['عكس قيد يدوي', 'post', 'admin.finance.journal.reverse', $none, [], ['finance']],
            ],
            'books.close' => [
                ['تاريخ «الدفاتر مقفلة حتى»', 'post', 'admin.settings.update', [], ['books_closed_through' => $notADate], ['settings']],
                ['تاريخ «آخر إقرار قُدِّم»', 'post', 'admin.settings.update', [], ['vat_filed_through' => $notADate], ['settings']],
            ],
            'bank.reconcile' => [
                ['استيراد كشف البنك', 'post', 'admin.bank.import', [], [], ['finance']],
                ['إعادة المطابقة', 'post', 'admin.bank.rematch', [], [], ['finance']],
                ['حذف الكشف المستورد', 'delete', 'admin.bank.clear', [], [], ['finance']],
            ],
            'paymob.settle' => [
                ['تسجيل تسوية', 'post', 'admin.finance.paymobSettlements.store', [], [], ['finance']],
                ['فتح مسوّدة تسوية', 'post', 'admin.finance.paymobSettlements.drafts.store', [], [], ['finance']],
                ['إضافة مدفوعات للمسوّدة', 'post', 'admin.finance.paymobSettlements.items.store', $none, [], ['finance']],
                ['إضافة المنتظرة', 'post', 'admin.finance.paymobSettlements.waiting', $none, [], ['finance']],
                ['رفع مدفوعات من المسوّدة', 'post', 'admin.finance.paymobSettlements.items.remove', $none, [], ['finance']],
                ['رفع غير المؤكَّدة', 'post', 'admin.finance.paymobSettlements.dropUnsure', $none, [], ['finance']],
                ['ترحيل المسوّدة', 'post', 'admin.finance.paymobSettlements.post', $none, [], ['finance']],
                ['إعادة محاولة تسوية', 'post', 'admin.finance.paymobSettlements.retry', $none, [], ['finance']],
                ['إلغاء تسوية معلّقة', 'post', 'admin.finance.paymobSettlements.cancel', $none, [], ['finance']],
                ['تحديد ما حمله تحويلٌ قديم', 'post', 'admin.finance.paymobSettlements.attribute', $none, [], ['finance']],
                ['ترك تحديدٍ خاطئ', 'post', 'admin.finance.paymobSettlementItems.remove', $none, [], ['finance']],
            ],
            'expense.delete' => [
                ['حذف مصروف', 'delete', 'admin.expenses.destroy', $none, [], ['expenses']],
                ['استعادة مصروف محذوف', 'post', 'admin.expenses.restore', $none, [], ['expenses']],
                // والمحوُ النهائيّ يسأل «الإعدادات» فوق قسمه — `TrashController::gate`
                ['محو مصروف نهائيًا', 'delete', 'admin.expenses.purge', $none, [], ['expenses', 'settings']],
            ],
            'purchase.delete' => [
                ['حذف أمر شراء', 'delete', 'admin.purchases.destroy', $none, [], ['purchases']],
            ],
            'stock.adjust' => [
                ['تسوية مخزون بسبب', 'post', 'admin.inventory.adjustments.store', [], [], ['inventory']],
                ['حركة مخزون يدوية', 'post', 'admin.inventory.store', [], [], ['inventory']],
                // والكميّةُ تُكتب كذلك من نموذج المنتج وتعديله السريع واستيراده — بقسم «المنتجات»
                ['كمية المنتج من نموذجه', 'put', 'admin.products.update', $none, [], ['products']],
                ['كمية المنتج من التعديل السريع', 'patch', 'admin.products.quick', $none, [], ['products']],
                ['كميات المنتجات من الاستيراد', 'post', 'admin.products.import.upload', [], [], ['products']],
            ],
            'stock.count' => [
                ['تطبيق نتيجة الجرد', 'post', 'admin.inventory.stocktake.apply', [], [], ['inventory']],
            ],
        ];
    }

    public static function operations(): array
    {
        return array_combine(
            array_keys(self::doors()),
            array_map(fn ($op) => [$op], array_keys(self::doors())),
        );
    }

    /** يطرق البابَ ويقول: أمرّ بالحارس؟ — و٥٠٠ ليس جوابًا */
    private function passes(User $as, array $door): bool
    {
        [, $method, $route, $params, $body] = $door;

        $status = $this->actingAs($as)->{$method}(route($route, $params), $body)->getStatusCode();

        $this->assertLessThan(500, $status, "{$route} انفجر بـ{$status}");

        return $status !== 403;
    }

    /* ═══════════════ ١ — الأدوار ═══════════════ */

    #[DataProvider('operations')]
    public function test_each_role_reaches_every_door_exactly_as_its_section_says(string $op): void
    {
        $wrong = [];

        foreach (self::ROLES as $role) {
            $user = $this->user($role);

            foreach (self::doors()[$op] as $door) {
                $expected = collect($door[5])->every(fn ($s) => in_array($role, self::SECTION_ROLES[$s], true));

                if ($this->passes($user, $door) !== $expected) {
                    $wrong[] = "{$role} ← {$door[0]}: ".($expected ? 'رُدّ وكان يمرّ' : 'مرّ وكان يُردّ');
                }
            }
        }

        $this->assertSame([], $wrong);
    }

    /* ═══════════════ ٢ — القائمة اليدويّة ═══════════════ */

    /**
     * قوائمُ يدويّة — والدورُ تحتها لا يقول شيئًا.
     *
     * الكاشيرُ بدوره لا يدخل اللوحة، ومع قسمٍ واحدٍ في قائمته يدخلها ويبلغ
     * ذلك القسم. والمديرُ والمحاسبُ و«مدير النشاط» المضيَّقون يُردّون عمّا
     * لم يُكتب في قوائمهم وإن حمله دورُهم.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private static function handPicked(): array
    {
        return [
            ...array_map(fn ($s) => ['cashier', ['dashboard', $s]], array_keys(self::SECTION_ROLES)),
            ['cashier', ['dashboard', 'expenses', 'settings']],
            ['manager', ['dashboard', 'orders', 'customers']],
            ['accountant', ['dashboard', 'reports']],
            ['admin', ['dashboard', 'finance']],
        ];
    }

    #[DataProvider('operations')]
    public function test_a_hand_picked_list_reaches_every_door_exactly_as_its_sections_say(string $op): void
    {
        $wrong = [];

        foreach (self::handPicked() as [$role, $list]) {
            $user = $this->user($role, $list);

            foreach (self::doors()[$op] as $door) {
                $expected = array_diff($door[5], $list) === [];

                if ($this->passes($user, $door) !== $expected) {
                    $wrong[] = "{$role} [".implode(', ', $list)."] ← {$door[0]}: ".($expected ? 'رُدّ وكان يمرّ' : 'مرّ وكان يُردّ');
                }
            }
        }

        $this->assertSame([], $wrong);
    }

    /* ═══════════════ ٣ — والقسمُ وحده يُتمّ العمليّة ═══════════════ */

    /**
     * ليس المرورُ بالحارس وحده: قائمةٌ فيها القسمُ وحده تفعلها كاملة.
     *
     * وهذا ما تفقده لو صار للعمليّة فعلٌ باسمه بلا هجرة — فهو الدليلُ على
     * أنّها لا تُوصَل بلا قرار.
     */
    public function test_a_list_holding_only_finance_reverses_a_manual_entry(): void
    {
        $account = fn (string $code) => Account::where('business_id', $this->shop->id)->where('code', $code)->firstOrFail();
        $entry = Ledger::post($this->shop->id, 'قيدٌ كُتب خطأً', [
            ['account' => $account('5900'), 'debit' => 30.0, 'credit' => 0.0],
            ['account' => $account('1200'), 'debit' => 0.0, 'credit' => 30.0],
        ], Carbon::parse('2026-11-19'), 'يدوي', null, $this->owner->id);

        $this->actingAs($this->user('cashier', ['dashboard', 'finance']))
            ->post(route('admin.finance.journal.reverse', $entry->id), ['reason' => 'اتجاه معكوس'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue(JournalEntry::where('reverses_id', $entry->id)->exists(), 'كُتب قيدُ العكس');
    }

    public function test_a_list_holding_only_settings_closes_the_books(): void
    {
        $this->actingAs($this->user('cashier', ['dashboard', 'settings']))
            ->post(route('admin.settings.update'), ['books_closed_through' => '2026-10-31', 'vat_filed_through' => '2026-09-30'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $saved = fn (string $key) => Setting::where('business_id', $this->shop->id)->where('key', $key)->value('value');
        $this->assertSame('2026-10-31', $saved('books_closed_through'));
        $this->assertSame('2026-09-30', $saved('vat_filed_through'));
    }

    public function test_a_list_holding_only_purchases_deletes_a_purchase_order(): void
    {
        $supplier = Supplier::create(['business_id' => $this->shop->id, 'name' => 'ورد الخليج']);
        $po = PurchaseOrder::create([
            'business_id' => $this->shop->id,
            'number' => PurchaseOrders::nextNumber($this->shop->id),
            'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
            'status' => PurchaseOrders::SENT, 'total' => 10, 'ordered_at' => now(),
        ]);

        $this->actingAs($this->user('cashier', ['dashboard', 'purchases']))
            ->delete(route('admin.purchases.destroy', $po->id))
            ->assertRedirect(route('admin.purchases.orders'));

        $this->assertNull(PurchaseOrder::find($po->id));
    }

    public function test_a_list_holding_only_inventory_adjusts_stock(): void
    {
        $branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        $product = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة جوري', 'sku' => 'RS-1',
            'price' => 12, 'cost' => 6, 'quantity' => 0,
        ]);

        $this->actingAs($this->user('cashier', ['dashboard', 'inventory']))
            ->post(route('admin.inventory.adjustments.store'), [
                'branch_id' => $branch->id, 'product_id' => $product->id,
                'quantity_delta' => 5, 'reason' => 'تصحيح', 'adjusted_at' => '2026-11-20',
            ])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(5, (int) $product->fresh()->quantity);
    }

    /* ═══════════════ ٤ — ولا يُوصَل شيءٌ قبل القرار ═══════════════ */

    /**
     * لا اسمَ من الثمانية في `Permissions` ولا في الشاشة ولا على مسار.
     *
     * مقبضٌ في «صلاحيات الموظفين» لا يحرس شيئًا أسوأ من غيابه: يُنزع فلا
     * يتغيّر شيء. وفعلٌ يُضاف إلى `ACTION_ROLES` يغيّر وحده من يمسّ حسابَ
     * من (`Permissions::beyond`) ومن يُسند أيَّ وظيفة (`mayAssignRole`) —
     * لمن قائمتُه يدويّة. فمن يوصلها بعد قرار صاحب النظام يحدّث هذا وحده.
     */
    public function test_none_of_the_eight_proposed_actions_is_wired_yet(): void
    {
        $board = file_get_contents(resource_path('js/Pages/Admin/Employees/partials/permissionGroups.ts'));

        foreach (self::PROPOSED as $key) {
            $this->assertNotContains($key, Permissions::actions(), "{$key} في ACTIONS");
            $this->assertArrayNotHasKey($key, Permissions::ACTION_ROLES, "{$key} في ACTION_ROLES");
            $this->assertStringNotContainsString("'{$key}'", $board, "{$key} في شاشة الصلاحيات");
        }

        $guarded = collect(Route::getRoutes())->flatMap(fn ($r) => $r->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'may:'))
            ->map(fn ($m) => substr($m, 4))->unique()->values()->all();

        $this->assertSame([], array_values(array_intersect(self::PROPOSED, $guarded)), 'مسارٌ يحرسه اسمٌ مقترح');
    }
}
