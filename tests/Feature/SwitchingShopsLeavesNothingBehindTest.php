<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Customer;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Demo;
use App\Support\MerchantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * تبدُّلُ المتجر لا يترك وراءه شيئًا — لا في الجلسة ولا في سجلّ المتصفّح.
 *
 * ═══ ما قِيس في متصفّحٍ حقيقيّ قبل الإصلاح ═══
 *
 * مديرُ المنصّة دخل متجر A وأضاف مسمًّى وظيفيًّا، ثمّ عاد ودخل B — كلُّه
 * بزياراتِ Inertia في المستند نفسِه. ثمّ ضغط «رجوع»: ظهرت «إضافة موظّف»
 * لمتجر A بمسمّاه، **بصفر طلباتٍ إلى الخادم**. Inertia تحفظ خصائصَ الصفحة
 * في `history.state` وتعرضها من هناك.
 *
 * وفي الجلسة مثلُه: ملفُّ استيرادٍ رُفع في A يُؤكَّد في B فيُكتب مخزونُ B
 * على فرعٍ يملكه A.
 *
 * ═══ وما يُحرس هنا ═══
 *
 *   ١) كلُّ صفحةٍ تطلب تشفيرَ سجلّها، وكلُّ تبدّلٍ للهويّة يمسحه.
 *   ٢) الانتحالُ دخولًا وخروجًا يفرّغ الجلسةَ إلّا اللغة.
 *   ٣) ملفُّ الاستيراد مختومٌ بمتجره: لا يُقرأ لغيره ولو بقي في الجلسة.
 *   ٤) المنصّةُ والمتجرُ بابان: لا يعبر أحدُهما إلى الآخر.
 */
class SwitchingShopsLeavesNothingBehindTest extends TestCase
{
    use RefreshDatabase;

    private Business $a;

    private Business $b;

    private User $ownerA;

    private User $ownerB;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->a, $this->ownerA] = $this->shop('متجر A', 'owner-a@abaad.om');
        [$this->b, $this->ownerB] = $this->shop('متجر B', 'owner-b@abaad.om');
        $this->super = User::create([
            'business_id' => null, 'name' => 'المنصّة', 'email' => 'super@abaad.om',
            'password' => bcrypt('password12345'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function shop(string $name, string $email): array
    {
        $shop = Business::create(['name' => $name, 'status' => 'نشط', 'tier' => 'gold']);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي '.$name]);

        return [$shop, MerchantAccount::provision($shop, $email, bcrypt('password12345'))];
    }

    private function branchOf(Business $shop): Branch
    {
        return Branch::where('business_id', $shop->id)->firstOrFail();
    }

    /** بقايا متجر A في جلسةٍ ستتبدّل — كلُّ مفتاحٍ يحمل شيئًا من متجر */
    private function leftovers(): array
    {
        return [
            'product_import' => ['business_id' => $this->a->id, 'user_id' => $this->super->id, 'branch_id' => $this->branchOf($this->a)->id, 'rows' => []],
            'customer_import' => ['business_id' => $this->a->id, 'rows' => []],
            'supplier_import' => ['business_id' => $this->a->id, 'rows' => []],
            'current_branch' => $this->branchOf($this->a)->id,
            'display_currency' => 'OMR',
            'resume_cart' => ['items' => [1, 2]],
            'pos_cashier_id' => $this->ownerA->id,
            'locale' => 'en',
        ];
    }

    private function assertNothingLeft(): void
    {
        foreach (['product_import', 'customer_import', 'supplier_import', 'current_branch', 'display_currency', 'resume_cart', 'pos_cashier_id'] as $key) {
            $this->assertFalse(session()->has($key), "«{$key}» عبر من متجرٍ إلى متجر");
        }

        $this->assertSame('en', session('locale'), 'لغةُ الجالس ضاعت مع التبدّل');
    }

    private function page(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page');
    }

    /* ══════════════ ١ · سجلُّ المتصفّح ══════════════ */

    public function test_every_merchant_page_asks_the_browser_to_encrypt_its_history(): void
    {
        $this->actingAs($this->ownerA);

        foreach (['admin.employees.create', 'admin.employees.index', 'admin.dashboard'] as $name) {
            $this->assertTrue($this->page(route($name))['encryptHistory'] ?? false, "«{$name}» تُحفظ في سجلّ المتصفّح مكشوفة");
        }
    }

    public function test_entering_a_shop_clears_the_browser_history_once(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.businesses.impersonate', $this->b->id))->assertRedirect();

        $this->assertTrue($this->page(route('admin.dashboard'))['clearHistory'] ?? false, 'دخولُ متجرٍ لم يمسح سجلَّ المتصفّح');
        $this->assertArrayNotHasKey('clearHistory', $this->page(route('admin.dashboard')), 'السجلُّ يُمسح في كلّ صفحة لا مرّةً');
    }

    public function test_leaving_a_shop_clears_the_browser_history(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.businesses.impersonate', $this->a->id));
        $this->page(route('admin.dashboard'));

        $this->post(route('impersonate.stop'))->assertRedirect();

        $this->assertTrue($this->page(route('super-admin.businesses.index'))['clearHistory'] ?? false);
    }

    public function test_logging_in_and_out_clears_the_browser_history(): void
    {
        $this->post(route('login.attempt'), ['email' => 'owner-a@abaad.om', 'password' => 'password12345'])->assertRedirect();
        $this->assertTrue($this->page(route('admin.dashboard'))['clearHistory'] ?? false, 'الدخولُ لم يمسح السجلّ');

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertTrue($this->page(route('login'))['clearHistory'] ?? false, 'الخروجُ لم يمسح السجلّ');
    }

    /* ══════════════ ٢ · الجلسة ══════════════ */

    public function test_entering_a_shop_forgets_what_the_last_one_left_in_the_session(): void
    {
        $this->actingAs($this->super)->withSession($this->leftovers())
            ->post(route('super-admin.businesses.impersonate', $this->b->id))->assertRedirect();

        $this->assertNothingLeft();
        $this->assertSame($this->super->id, session('impersonator_id'));
        $this->assertSame($this->b->id, (int) auth()->user()->business_id, 'الانتحالُ لم يدخل المتجرَ المختار');
    }

    public function test_leaving_a_shop_forgets_it_too(): void
    {
        $this->actingAs($this->super)->post(route('super-admin.businesses.impersonate', $this->a->id));

        $this->withSession($this->leftovers() + ['impersonator_id' => $this->super->id])
            ->post(route('impersonate.stop'))->assertRedirect();

        $this->assertNothingLeft();
        $this->assertSame($this->super->id, auth()->id());
        $this->assertSame(0, Demo::bid(), 'مديرُ المنصّة بعد العودة يقع في متجر');
    }

    /** ما وقع فعلًا: استيرادٌ رُفع في A يُؤكَّد في B */
    public function test_a_product_import_started_in_one_shop_cannot_land_in_another(): void
    {
        $branchA = $this->branchOf($this->a);

        $this->actingAs($this->super)->post(route('super-admin.businesses.impersonate', $this->a->id));
        $this->post(route('admin.products.import.upload'), [
            'file' => UploadedFile::fake()->createWithContent('p.csv', "الاسم,السعر,الكمية\nTENANT-A-IMPORT,4.500,10\n"),
            'branch_id' => $branchA->id,
        ])->assertRedirect(route('admin.products.import.preview'));

        $this->post(route('impersonate.stop'));
        $this->post(route('super-admin.businesses.impersonate', $this->b->id));
        $this->post(route('admin.products.import.confirm'));

        $this->assertSame(0, Product::where('name', 'TENANT-A-IMPORT')->count(), 'استيرادُ A كُتب في B');
        $this->assertSame(0, BranchStock::where('business_id', $this->b->id)->where('branch_id', $branchA->id)->count(), 'مخزونُ B على فرعِ A');
    }

    /** والختمُ حارسٌ ثانٍ: ملفُّ A في جلسة B لا يُقرأ ولو لم يُفرَّغ شيء */
    public function test_a_stamped_import_is_not_read_by_another_shop(): void
    {
        $uploads = [
            'product_import' => ['admin.products.import.upload', "الاسم,السعر,الكمية\nTENANT-A-P,4.500,10\n", 'admin.products.import.confirm', fn () => Product::where('name', 'TENANT-A-P')->count()],
            'customer_import' => ['admin.customers.import.upload', "الاسم,الهاتف\nTENANT-A-C,91234567\n", 'admin.customers.import.confirm', fn () => Customer::where('name', 'TENANT-A-C')->count()],
            'supplier_import' => ['admin.suppliers.import.upload', "الاسم,الهاتف\nTENANT-A-S,92345678\n", 'admin.suppliers.import.confirm', fn () => Supplier::where('name', 'TENANT-A-S')->count()],
        ];

        foreach ($uploads as $key => [$upload, $csv, $confirm, $count]) {
            $this->actingAs($this->ownerA)->post(route($upload), [
                'file' => UploadedFile::fake()->createWithContent('f.csv', $csv),
            ])->assertRedirect();
            $payload = session($key);
            $this->assertIsArray($payload, "لم يُحفظ «{$key}»");
            $this->assertSame($this->a->id, $payload['business_id'], "«{$key}» لم يُختم بمتجره");

            $this->actingAs($this->ownerB)->withSession([$key => $payload])->post(route($confirm));

            $this->assertSame(0, $count(), "ملفُّ «{$key}» من A أُكِّد في B");
            $this->assertFalse(session()->has($key), "ملفُّ «{$key}» الغريب بقي في الجلسة");
        }
    }

    /* ══════════════ ٣ · المنصّة والمتجر ══════════════ */

    public function test_a_merchant_cannot_open_the_platform(): void
    {
        $status = $this->actingAs($this->ownerA)->get(route('super-admin.businesses.index'))->status();

        $this->assertContains($status, [302, 403], 'تاجرٌ فتح قائمةَ متاجر المنصّة');
    }

    public function test_the_platform_admin_does_not_fall_into_a_merchant_shop(): void
    {
        $this->actingAs($this->super);

        $this->assertSame(0, Demo::bid());
        $this->get(route('admin.employees.index'))->assertRedirect();
    }

    public function test_impersonation_enters_exactly_the_chosen_shop(): void
    {
        JobTitle::create(['business_id' => $this->a->id, 'name' => 'TENANT-A-SENTINEL', 'role' => 'cashier']);
        JobTitle::create(['business_id' => $this->b->id, 'name' => 'TENANT-B-SENTINEL', 'role' => 'cashier']);

        $this->actingAs($this->super)->post(route('super-admin.businesses.impersonate', $this->b->id));
        $body = json_encode($this->page(route('admin.employees.create')), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('TENANT-B-SENTINEL', $body);
        $this->assertStringNotContainsString('TENANT-A-SENTINEL', $body);
    }
}
