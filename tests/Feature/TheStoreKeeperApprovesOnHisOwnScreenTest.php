<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\GoodsReceiptNote;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\GoodsReceipts;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اعتمادُ الاستلام — بابُه بابُ الشاشة التي يُعرض فيها.
 *
 * ═══ العطب ═══
 *
 * سندُ الاستلام ورقةُ المخزن: قائمتُه `admin.inventory.receipts`، وصفحتُه
 * وورقتُه ومرفقُه كلُّها `inventory.receipts.*`، ومتحكّمُه
 * `Admin\Inventory\GoodsReceiptNoteController`. وزرّا «اعتماد» و«رفض»
 * وحدَهما كانا `purchases.receipts.*` — والقسمُ يُشتقّ من اسم المسار.
 *
 * فأمينُ مخزنٍ مُنح «المخزون» وفعلَ «اعتماد الاستلام» — وهو منحٌ مقصود:
 * يؤكّد ما وصل الرفَّ ولا يفتح أوامرَ الشراء فيقرأ ما دفعه صاحبُه للمورّد —
 * يفتح قائمتَه ٢٠٠، ويرى الزرَّ معروضًا (`canApprove` تُحسب من الفعل وحده)،
 * فيضغطه فيُردّ ٤٠٣ عن قسمٍ لا تُعرض له شاشةٌ منه أصلًا.
 *
 * وهي عينُ العلّة التي صُحّحت في سند التسليم ومرفق كرت الهدية: زرٌّ يُعرض
 * على شاشةٍ ويتبع بابُه قسمًا آخر. و«بابٌ يُعرض ولا يُفتح أسوأ من غيابه» —
 * مكتوبةٌ فوق `canApprove` نفسِها.
 *
 * ═══ ولمَ نُقل الاسمُ ولم يُزدَد بابٌ ثانٍ ═══
 *
 * لا شاشةَ في «المشتريات» تعرض هذين الزرّين — هما في `Admin/Inventory`
 * وحدهما. فبابان لفعلٍ واحد يفترقان يوم يُشدَّد أحدُهما، والاسمُ الصحيح
 * اسمُ الشاشة التي تملكه.
 */
class TheStoreKeeperApprovesOnHisOwnScreenTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'price' => 10,
            'cost' => 4, 'quantity' => 100,
        ]);
    }

    /** موظّفٌ بأقسامٍ وأفعالٍ بأعيانها — لا بدورٍ يحمل معه غيرَها */
    private function staff(array $grants, string $email = 's@abaad.om'): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'أمين المخزن', 'email' => $email,
            'password' => bcrypt('password1'), 'role' => 'employee', 'status' => 'نشط',
            'permissions' => array_merge(['dashboard'], $grants),
        ]);
    }

    private function pendingNote(): GoodsReceiptNote
    {
        $po = PurchaseOrder::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'number' => 'PO-000125', 'status' => 'مُرسل',
            'total' => 900, 'ordered_at' => now(),
        ]);
        $po->items()->create([
            'product_id' => $this->product->id, 'name' => $this->product->name,
            'cost' => 9, 'quantity' => 100,
        ]);

        $owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password1'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($owner)->post(route('admin.purchases.receive', $po->id))
            ->assertSessionHasNoErrors();

        return GoodsReceiptNote::firstOrFail();
    }

    /* ─────────────── الشاشةُ تَعِد ─────────────── */

    /**
     * أمينُ المخزن يفتح قائمتَه، والزرُّ معروضٌ له.
     *
     * وهذا نصفُ القياس: الوعدُ يُقاس أوّلًا، فبلا وعدٍ لا يكون الردُّ خُلفًا.
     */
    public function test_the_screen_opens_and_offers_him_the_button(): void
    {
        $keeper = $this->staff(['inventory', Permissions::RECEIPT_VIEW, Permissions::RECEIPT_APPROVE]);
        $this->pendingNote();

        $props = $this->actingAs($keeper)->get(route('admin.inventory.receipts'))
            ->assertOk()->viewData('page')['props'];

        $this->assertTrue($props['canApprove'], 'الزرُّ لا يُعرض له أصلًا — فالقياسُ لا موضعَه');
    }

    /**
     * ومن لا يملك الفعل لا يُعرض له الزرّ.
     *
     * «بابٌ يُعرض ولا يُفتح أسوأ من غيابه» — مكتوبةٌ فوق `canApprove` نفسِها،
     * وهي نصفُ العقد: الشاشةُ تَعِد بما يقع، ولا تَعِد بما لا يقع.
     */
    public function test_the_button_is_hidden_from_who_cannot_press_it(): void
    {
        $watcher = $this->staff(['inventory', Permissions::RECEIPT_VIEW]);
        $this->pendingNote();

        $props = $this->actingAs($watcher)->get(route('admin.inventory.receipts'))
            ->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['canApprove'], 'عُرض زرُّ اعتمادٍ لمن لا يملك الفعل');
        $this->assertFalse($props['canReject'], 'عُرض زرُّ رفضٍ لمن لا يملك الفعل');
    }

    /* ─────────────── والبابُ يفي ─────────────── */

    /** وما وعدت به الشاشةُ يقع: الورقةُ تُعتمد والبضاعةُ تدخل الرفّ */
    public function test_the_keeper_approves_what_his_screen_offered(): void
    {
        $keeper = $this->staff(['inventory', Permissions::RECEIPT_VIEW, Permissions::RECEIPT_APPROVE]);
        $note = $this->pendingNote();

        $this->actingAs($keeper)
            ->post(route('admin.inventory.receipts.approve', $note->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(GoodsReceipts::APPROVED, $note->fresh()->status);
        $this->assertSame(200, (int) $this->product->fresh()->quantity, 'لم تدخل البضاعةُ الرفَّ');
    }

    /** والرفضُ مثلُه — نصفُ المراجعة الآمن يُمنح وحده */
    public function test_the_keeper_rejects_on_his_own_screen_too(): void
    {
        $keeper = $this->staff(['inventory', Permissions::RECEIPT_VIEW, Permissions::RECEIPT_REJECT]);
        $note = $this->pendingNote();

        $this->actingAs($keeper)
            ->post(route('admin.inventory.receipts.reject', $note->id), ['reason' => 'وصلت ناقصة'])
            ->assertSessionHasNoErrors();

        $this->assertSame(GoodsReceipts::REJECTED, $note->fresh()->status);
        $this->assertSame(100, (int) $this->product->fresh()->quantity, 'دخلت بضاعةُ ورقةٍ مرفوضة');
    }

    /* ─────────────── والحارسان يبقيان ─────────────── */

    /**
     * ومن لا يملك الفعل يُردّ — القسمُ وحده لا يعتمد شحنة.
     *
     * وهذا ما يمنع «الإصلاح» الأسهل: فتحُ الباب للقسم يُلغي حارسَ الفعل.
     */
    public function test_the_section_alone_does_not_approve(): void
    {
        $keeper = $this->staff(['inventory', Permissions::RECEIPT_VIEW]);
        $note = $this->pendingNote();

        $this->actingAs($keeper)
            ->post(route('admin.inventory.receipts.approve', $note->id))
            ->assertForbidden();

        $this->assertSame(GoodsReceipts::PENDING, $note->fresh()->status);
    }

    /** والرفضُ فعلٌ باسمه أيضًا: القسمُ وحده لا يرفض ورقة */
    public function test_the_section_alone_does_not_reject(): void
    {
        $keeper = $this->staff(['inventory', Permissions::RECEIPT_VIEW]);
        $note = $this->pendingNote();

        $this->actingAs($keeper)
            ->post(route('admin.inventory.receipts.reject', $note->id), ['reason' => 'وصلت ناقصة'])
            ->assertForbidden();

        $this->assertSame(GoodsReceipts::PENDING, $note->fresh()->status);
    }

    /** ومن لا يملك القسمَ يُردّ ولو مُنح الفعل — الورقةُ لا تُرى له أصلًا */
    public function test_the_action_alone_does_not_approve(): void
    {
        $stranger = $this->staff([Permissions::RECEIPT_VIEW, Permissions::RECEIPT_APPROVE]);
        $note = $this->pendingNote();

        $this->actingAs($stranger)
            ->post(route('admin.inventory.receipts.approve', $note->id))
            ->assertForbidden();

        $this->assertSame(GoodsReceipts::PENDING, $note->fresh()->status);
    }

    /** وورقةُ الجار لا تُعتمد — الحصرُ بالمتجر قائمٌ قبل كلّ ذلك */
    public function test_a_neighbours_note_is_not_approved(): void
    {
        $note = $this->pendingNote();

        $neighbour = Business::create(['name' => 'جار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = User::create([
            'business_id' => $neighbour->id, 'name' => 'جار', 'email' => 'jar@abaad.om',
            'password' => bcrypt('password1'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($theirs)
            ->post(route('admin.inventory.receipts.approve', $note->id))
            ->assertNotFound();

        $this->assertSame(GoodsReceipts::PENDING, $note->fresh()->status);
    }

    /* ─────────────── ولا اسمَ ثانٍ للفعل نفسِه ─────────────── */

    /**
     * وبابٌ واحدٌ لا بابان.
     *
     * لو أُبقي الاسمُ القديم إلى جانب الجديد لَافترقا: يُشدَّد أحدهما يومًا
     * ويبقى الآخر، فيُعتمد من بابٍ ما يُمنع من الآخر.
     */
    public function test_the_old_name_is_gone(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertNull($routes->getByName('admin.purchases.receipts.approve'), 'بقي بابٌ ثانٍ للاعتماد');
        $this->assertNull($routes->getByName('admin.purchases.receipts.reject'), 'بقي بابٌ ثانٍ للرفض');
        $this->assertNotNull($routes->getByName('admin.inventory.receipts.approve'));
        $this->assertNotNull($routes->getByName('admin.inventory.receipts.reject'));
    }

    /** ولا تبقى في الشاشات إشارةٌ إلى الاسم القديم */
    public function test_no_screen_still_points_at_the_old_name(): void
    {
        $dir = base_path('resources/js/Pages/Admin/Inventory');
        $found = [];

        foreach (glob($dir.'/*.tsx') as $file) {
            if (str_contains((string) file_get_contents($file), 'purchases.receipts.')) {
                $found[] = basename($file);
            }
        }

        $this->assertNotSame([], glob($dir.'/*.tsx'), 'لم تُقرأ شاشاتُ المخزون أصلًا — فالقياسُ معطوب');
        $this->assertSame([], $found, 'شاشةٌ ما زالت تنادي بابًا لا وجودَ له: '.implode(', ', $found));
    }
}
