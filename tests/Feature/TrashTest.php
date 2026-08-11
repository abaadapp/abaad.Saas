<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\TrashController;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * سلّة المحذوفات: ما تَعِد به يقع، وما لا تَعِد به لا يقع خلسةً.
 *
 * كانت الشاشة تقول «يمكن استعادة ما حُذف خلال ٩٠ يومًا» ولا شيء ينفّذ ذلك:
 * الرقم مرشِّح عرضٍ فقط. فالصفوف تبقى في القاعدة أبدًا، ويظنّ التاجر ما
 * حذفه ذهب — ثم لا يستطيع بعد اليوم ٩١ استعادته ولا محوَه.
 */
class TrashTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function product(string $name = 'باقة ورد', ?Business $of = null): Product
    {
        return Product::create([
            'business_id' => ($of ?? $this->business)->id, 'name' => $name,
            'price' => 10, 'cost' => 4, 'quantity' => 5, 'active' => true,
        ]);
    }

    private function expense(string $ref = 'EXP-1001'): Expense
    {
        return Expense::create([
            'business_id' => $this->business->id, 'reference' => $ref,
            'type' => 'إيجار', 'amount' => 250, 'spent_at' => now()->subDay(),
        ]);
    }

    // ————— المحو النهائي بالزرّ —————

    public function test_the_button_erases_the_row_for_good(): void
    {
        $p = $this->product();
        $p->delete();

        $this->actingAs($this->owner)
            ->delete(route('admin.products.purge', $p->id))
            ->assertRedirect();

        $this->assertNull(Product::withTrashed()->find($p->id), 'الصفّ ما زال في القاعدة بعد المحو');
    }

    public function test_the_file_on_disk_goes_with_the_row(): void
    {
        /*
         * الصورة والمرفق كانا يبقيان بعد ذهاب ما يشير إليهما: ملفٌّ لا أحد
         * يعرف أنه هناك ولا كيف يصل إليه، ويكبر القرص بما لا يُقرأ. وفي
         * المصروف الملفّ فاتورةٌ ممسوحة — بيانات طرفٍ ثالث لا يجوز بقاؤها.
         */
        Storage::fake('public');
        Storage::disk('public')->put('products/rose.jpg', 'x');

        $p = $this->product();
        $p->update(['image' => 'products/rose.jpg']);
        $p->delete();

        $this->actingAs($this->owner)->delete(route('admin.products.purge', $p->id));

        Storage::disk('public')->assertMissing('products/rose.jpg');
    }

    public function test_the_stock_rows_do_not_outlive_the_product(): void
    {
        /*
         * `branch_stocks.product_id` عمودٌ عاديّ بلا قيد مفتاح، فلا شيء
         * يتكفّل بها. وصفٌّ باقٍ يحمل كمّيةً لمنتجٍ لا وجود له يدخل مجموع
         * «قيمة المخزون» — رقمٌ خاطئ في تقريرٍ لا يظهر فيه سببه.
         */
        $p = $this->product();
        BranchStock::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::first()->id,
            'product_id' => $p->id,
            'quantity' => 7,
        ]);
        $p->delete();

        $this->actingAs($this->owner)->delete(route('admin.products.purge', $p->id));

        $this->assertSame(0, BranchStock::where('product_id', $p->id)->count());
    }

    public function test_a_row_from_another_store_is_not_found(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = $this->product('منتجهم', $other);
        $theirs->delete();

        $this->actingAs($this->owner)
            ->delete(route('admin.products.purge', $theirs->id))
            ->assertNotFound();

        $this->assertNotNull(Product::withTrashed()->find($theirs->id));
    }

    public function test_only_what_is_already_in_the_trash_can_be_purged(): void
    {
        // منتجٌ حيّ لا يُمحى بمسار السلّة: المحو خطوةٌ ثانية بعد الحذف
        $p = $this->product();

        $this->actingAs($this->owner)
            ->delete(route('admin.products.purge', $p->id))
            ->assertNotFound();
    }

    // ————— الفرع لا يُمحى —————

    public function test_there_is_no_way_to_erase_a_branch(): void
    {
        /*
         * محو الفرع يمحو بالتسلسل تسجيلَ كل صندوقٍ فيه وأذونَ موظفيه،
         * ويُفرّغ فرعَ العميل وحركةِ المخزون، وتبقى مبيعاته تشير إلى رقمٍ
         * لا وجود له. فلا مسار له أصلًا — وهذا الاختبار يمنع فتحه سهوًا.
         */
        $this->assertFalse(Route::has('admin.branches.purge'));
        $this->assertNotContains('branch', TrashController::PURGEABLE);
    }

    public function test_a_deleted_branch_stays_visible_however_old_it_is(): void
    {
        // المهلة لا تسري على ما لا يُمحى: إخفاؤه من الشاشة يجعله غير قابل
        // للاستعادة بلا أن يكون قد ذهب
        $b = Branch::create(['business_id' => $this->business->id, 'name' => 'الخوير']);
        $b->delete();
        $b->forceFill(['deleted_at' => now()->subYears(2)])->saveQuietly();

        $this->actingAs($this->owner);
        $data = TrashController::panelData();

        $this->assertCount(1, $data['trashedBranches']);
    }

    // ————— «من حذفه» —————

    public function test_the_screen_says_who_pressed_delete(): void
    {
        /*
         * الاستعادة تُصلح الضرر مرّة، ومعرفة الفاعل تمنع تكراره. والاسم
         * يُقرأ من سجلّ النشاط لأن الصفّ يحمل «متى» ولا يحمل «من».
         */
        $staff = User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 'salem@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $p = $this->product();

        $this->actingAs($staff)->delete(route('admin.products.destroy', $p->id));

        $this->actingAs($this->owner);
        $data = TrashController::panelData();

        $this->assertSame('سالم', $data['products'][0]['deletedBy']);
    }

    public function test_an_id_from_one_kind_does_not_borrow_another_kinds_deleter(): void
    {
        /*
         * أهمّ ما في الملفّ بعد المحو: المطابقة بالمعرّف وحده تخلط منتجًا
         * رقمه ٥ بمصروفٍ رقمه ٥، فيُنسب الحذف إلى من لم يفعله — وهي تهمةٌ
         * تصل موظفًا. ولذلك صار كل حذفٍ يكتب `subject_type`.
         */
        $p = $this->product();
        $p->delete();

        ActivityLog::create([
            'business_id' => $this->business->id, 'user_id' => 99, 'user_name' => 'شخصٌ آخر',
            'action' => 'deleted', 'subject_type' => 'expense', 'subject_id' => $p->id,
            'description' => 'حذف المصروف', 'icon' => 'trash-2', 'color' => 'danger',
        ]);

        $this->actingAs($this->owner);
        $data = TrashController::panelData();

        $this->assertNull($data['products'][0]['deletedBy'], 'نُسب حذف المنتج إلى قيدٍ يخصّ مصروفًا');
    }

    // ————— الأمر المجدول: الوعد يقع —————

    public function test_the_scheduled_command_erases_what_outlived_the_window(): void
    {
        $old = $this->product('قديم');
        $old->delete();
        $old->forceFill(['deleted_at' => now()->subDays(TrashController::WINDOW_DAYS + 1)])->saveQuietly();

        $recent = $this->product('حديث');
        $recent->delete();

        $this->artisan('trash:purge')->assertSuccessful();

        $this->assertNull(Product::withTrashed()->find($old->id), 'لم يُنفَّذ الوعد المكتوب على الشاشة');
        $this->assertNotNull(Product::withTrashed()->find($recent->id), 'مُحي ما لم تنقضِ مهلته');
    }

    public function test_a_live_row_is_never_touched(): void
    {
        // الحارس الذي يمنع الكارثة: `onlyTrashed` لو سقطت يومًا لمُحي المتجر
        $alive = $this->product('حيّ');
        $this->expense();

        $this->artisan('trash:purge', ['--days' => 0])->assertSuccessful();

        $this->assertNotNull(Product::find($alive->id));
        $this->assertSame(1, Expense::count());
    }

    public function test_the_command_never_reaches_a_branch(): void
    {
        $b = Branch::create(['business_id' => $this->business->id, 'name' => 'القديم']);
        $b->delete();
        $b->forceFill(['deleted_at' => now()->subYears(3)])->saveQuietly();

        $this->artisan('trash:purge')->assertSuccessful();

        $this->assertNotNull(Branch::withTrashed()->find($b->id));
    }

    public function test_dry_run_counts_and_does_not_erase(): void
    {
        $p = $this->product();
        $p->delete();
        $p->forceFill(['deleted_at' => now()->subDays(200)])->saveQuietly();

        $this->artisan('trash:purge', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(Product::withTrashed()->find($p->id));
    }

    // ————— المهلة على الشاشة —————

    public function test_what_passed_the_window_leaves_the_screen(): void
    {
        $p = $this->product();
        $p->delete();
        $p->forceFill(['deleted_at' => now()->subDays(TrashController::WINDOW_DAYS + 5)])->saveQuietly();

        $this->actingAs($this->owner);

        $this->assertCount(0, TrashController::panelData()['products']);
    }

    public function test_the_days_left_are_counted_down_not_up(): void
    {
        $p = $this->product();
        $p->delete();
        $p->forceFill(['deleted_at' => now()->subDays(80)])->saveQuietly();

        $this->actingAs($this->owner);

        $this->assertSame(10, TrashController::panelData()['products'][0]['daysLeft']);
    }
}
