<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger;
use App\Support\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فتحُ الصندوق لا يغيّر فرعَ لوحة الإدارة — والصندوقُ يبيع على فرع جهازه (#165).
 *
 * كان `BindPosBranch` يكتب فرعَ الجهاز في `current_branch`، وهي الجلسةُ نفسُها
 * التي تقرأ منها لوحةُ الإدارة الفرعَ المختار. فيفتح المالكُ الصندوقَ على جهاز
 * مسقط، ثمّ يعود إلى «المبيعات» فيجدها على مسقط وحده: بيعاتُ موظّفي صحار
 * «اختفت» ولم يلمسها أحد. وهكذا وصلت الشكوى: «مبيعات موظّفيّ لا تظهر».
 *
 * والإصلاحُ يضع فرعَ الجهاز على الطلب وحده. وهذه الاختبارات تقيس الوجهين:
 * اللوحةُ تبقى على ما اختاره المالك، والصندوقُ — رصيدُه وبيعتُه وخصمُ مخزونه
 * ودرجُه وسلالُه المعلّقة — يبقى على فرع جهازه مهما اختار المالك في لوحته.
 */
class TheTillLeavesTheOwnersBranchAloneTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $muscat;

    private Branch $sohar;

    private User $owner;

    private Product $rose;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود الفرعين', 'type' => 'عام', 'status' => 'نشط']);
        $this->muscat = Branch::create(['business_id' => $this->shop->id, 'name' => 'مسقط']);
        $this->sohar = Branch::create(['business_id' => $this->shop->id, 'name' => 'صحار']);
        Ledger::seedChart($this->shop->id);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'صاحب الورود', 'email' => 'owner@two-branches.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->rose = Product::create(['business_id' => $this->shop->id, 'name' => 'وردة', 'price' => 10, 'cost' => 4, 'quantity' => 340, 'active' => true]);
        // رصيدان مختلفان: فيُعرف من الرقم وحده أيُّ فرعٍ قرأه الصندوق
        BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $this->muscat->id, 'product_id' => $this->rose->id, 'quantity' => 250]);
        BranchStock::create(['business_id' => $this->shop->id, 'branch_id' => $this->sohar->id, 'product_id' => $this->rose->id, 'quantity' => 90]);
    }

    /* ============================== أدوات ============================== */

    private function sale(string $number, Branch $branch, bool $held = false): Order
    {
        return Order::create([
            'business_id' => $this->shop->id, 'number' => $number, 'customer_name' => 'زبون',
            'employee_name' => 'موظّف '.$branch->name, 'branch_id' => $branch->id, 'branch' => $branch->name,
            'channel' => SalesChannel::POS, 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
            'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'total' => 10, 'is_held' => $held,
            'ordered_at' => now(), 'status' => 'مكتمل',
        ]);
    }

    /** أرقامُ «المبيعات» في لوحة الإدارة كما تعرضها */
    private function adminSales(): array
    {
        $page = $this->get(route('admin.orders.index'))->assertOk()->viewData('page');

        return collect($page['props']['orders'])->pluck('id')->sort()->values()->all();
    }

    /** الفرعُ الذي ترسمه الترويسة لهذا الطلب — `null` = «كل الفروع» */
    private function branchOf($response): ?int
    {
        return $response->assertOk()->viewData('page')['props']['context']['branchId'] ?? null;
    }

    private function stock(Branch $branch): int
    {
        return (int) BranchStock::where('branch_id', $branch->id)->where('product_id', $this->rose->id)->value('quantity');
    }

    /** بيعةٌ من الصندوق الحقيقيّ — وكوكي الجهاز ترافقها كما يرسلها المتصفّح */
    private function checkout(): Order
    {
        $number = $this->withCredentials()->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->rose->id, 'name' => $this->rose->name, 'qty' => 1]],
            'payment_method' => 'نقدي',
        ])->assertOk()->json('invoice');

        return Order::where('business_id', $this->shop->id)->where('number', $number)->firstOrFail();
    }

    /* ============================ الاختبارات ============================ */

    /**
     * الخللُ كما وصل: يفتح المالكُ الصندوقَ ثمّ يعود إلى لوحته.
     *
     * وكوكي الجهاز ترافق طلبات اللوحة كذلك — هو المتصفّحُ نفسُه.
     */
    public function test_opening_the_till_leaves_the_owners_admin_on_every_branch(): void
    {
        $this->sale('M-1', $this->muscat);
        $this->sale('S-1', $this->sohar);
        $this->activatePosDevice($this->shop->id, $this->muscat->id);
        $this->actingAs($this->owner);

        $this->assertSame(['M-1', 'S-1'], $this->adminSales());

        $this->assertSame($this->muscat->id, $this->branchOf($this->get(route('pos.index'))), 'الصندوق لم يقرأ فرعَ جهازه');

        $this->assertSame(['M-1', 'S-1'], $this->adminSales(), 'اختفت بيعاتُ صحار بعد فتح صندوق مسقط');
        $this->assertNull($this->branchOf($this->get(route('admin.orders.index'))), 'صارت الترويسةُ على فرع الجهاز');
        $this->assertNull(session('current_branch'), 'كتب الصندوقُ فرعَه في جلسة الإدارة');
    }

    /** وما اختاره المالك فرعًا بعينه يبقى له كذلك — لا «كل الفروع» وحدها */
    public function test_the_owners_chosen_branch_survives_the_till(): void
    {
        $this->sale('M-1', $this->muscat);
        $this->sale('S-1', $this->sohar);
        $this->activatePosDevice($this->shop->id, $this->muscat->id);
        $this->actingAs($this->owner);

        $this->get(route('admin.branch.switch', $this->sohar->id));
        $this->assertSame(['S-1'], $this->adminSales());

        $this->get(route('pos.index'))->assertOk();
        $this->checkout();

        $this->assertSame($this->sohar->id, session('current_branch'));
        $this->assertSame(['S-1'], $this->adminSales(), 'نقل الصندوقُ لوحةَ المالك إلى فرعه');
    }

    /**
     * والصندوقُ على فرع جهازه مهما اختار المالك: الرصيدُ المعروض، والبيعة،
     * وخصمُ المخزون — من مسقط، وصحار لا تُمسّ.
     */
    public function test_the_till_sells_and_deducts_on_its_own_branch_whatever_the_admin_chose(): void
    {
        $this->activatePosDevice($this->shop->id, $this->muscat->id);
        $this->actingAs($this->owner);
        $this->get(route('admin.branch.switch', $this->sohar->id));

        $till = $this->get(route('pos.index'));
        $this->assertSame($this->muscat->id, $this->branchOf($till));
        $shelf = collect($till->viewData('page')['props']['products'])->firstWhere('id', $this->rose->id);
        $this->assertSame(250, (int) $shelf['qty'], 'عرض الصندوقُ رصيدَ فرعٍ غير فرعه');

        $order = $this->checkout();

        $this->assertSame($this->muscat->id, (int) $order->branch_id, 'وقعت البيعةُ على فرع اللوحة لا فرع الجهاز');
        $this->assertSame(249, $this->stock($this->muscat));
        $this->assertSame(90, $this->stock($this->sohar), 'خُصم المخزونُ من فرعٍ لم يبع');
    }

    /** وتبديلُ الفرع في تبويب الإدارة أثناء الوردية لا ينقل ما يكتبه الصندوق بعده */
    public function test_switching_the_admin_branch_mid_shift_does_not_move_the_till(): void
    {
        $this->activatePosDevice($this->shop->id, $this->muscat->id);
        $this->actingAs($this->owner);

        $this->get(route('pos.index'))->assertOk();
        $this->get(route('admin.branch.switch', $this->sohar->id));
        $first = $this->checkout();

        $this->get(route('admin.branch.switch', 'all'));
        $second = $this->checkout();

        $this->withCredentials()->post(route('pos.movements.store'), [
            'kind' => 'expense', 'amount' => 3, 'description' => 'ماء للمحلّ',
        ])->assertSessionHasNoErrors();
        $movement = Transaction::where('business_id', $this->shop->id)->latest('id')->firstOrFail();

        $this->assertSame([$this->muscat->id, $this->muscat->id], [(int) $first->branch_id, (int) $second->branch_id]);
        $this->assertSame($this->muscat->id, (int) $movement->branch_id, 'سُجّل قيدُ الدرج على فرعٍ غير فرع الجهاز');
        $this->assertSame(248, $this->stock($this->muscat));
        $this->assertSame(90, $this->stock($this->sohar));
    }

    /**
     * والسلّةُ المعلّقة في صحار لا يستأنفها صندوقُ مسقط — ولو كانت اللوحةُ
     * على صحار. اختيارُ اللوحة لا يفتح للصندوق فرعًا غيرَ فرعه.
     */
    public function test_the_admin_branch_does_not_open_another_branchs_held_cart_to_the_till(): void
    {
        $held = $this->sale('S-HOLD', $this->sohar, held: true);
        $this->activatePosDevice($this->shop->id, $this->muscat->id);
        $this->actingAs($this->owner);
        $this->get(route('admin.branch.switch', $this->sohar->id));

        $this->get(route('pos.orders.resume', $held->id))->assertNotFound();
    }

    /**
     * ولا كاتبَ لـ`current_branch` إلّا مبدّلُ الفروع.
     *
     * هو اختيارُ المالك في لوحته — ومن يكتبه من بابٍ آخر يعيد #165 بلا أن
     * يسقط اختبارٌ يقيس ذلك البابَ بعينه.
     */
    public function test_only_the_branch_switcher_writes_the_admin_branch(): void
    {
        $writers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            if (preg_match("/session\(\s*\[\s*'current_branch'|->put\(\s*'current_branch'|session\(\)->put\(\s*'current_branch'/", $code)) {
                $writers[] = str_replace(app_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame(['Http/Controllers/Admin/BranchController.php'], $writers);
    }
}
