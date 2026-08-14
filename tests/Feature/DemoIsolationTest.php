<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\DemoGuard;
use App\Support\DemoStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * البيانات الوهميّة لا تدخل حساب تاجر — وهذه هي القاعدة التي طُلبت.
 *
 * العزل بين التجّار قائمٌ بـ`business_id` ويحرسه `TenantIsolationTest`: يمنع
 * تاجرًا أن يقرأ بيانات آخر. وهذا الملفّ يحرس اتّجاهًا آخر لم يكن محروسًا:
 * أن يُكتب الوهميّ في الحقيقيّ.
 *
 * والفرق بينهما ليس نظريًّا: أمرٌ يُشغَّل بمعرّفٍ خاطئ، أو زرٌّ في اللوحة
 * يُضغط والمتجر المفتوح غير المقصود — فتدخل مئةُ فاتورةٍ ملفَّقة دفترَ تاجرٍ
 * يحاسب بها ضريبته.
 */
class DemoIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::create([
            'name' => 'مدير المنصة', 'email' => 'root@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function merchant(string $name = 'متجر تاجر'): Business
    {
        return Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
    }

    /* ------------------------------ القاعدة ------------------------------ */

    public function test_a_merchant_business_is_never_a_demo_by_default(): void
    {
        $this->assertFalse($this->merchant()->is_demo);
    }

    public function test_seeding_refuses_a_business_that_is_not_marked_demo(): void
    {
        $merchant = $this->merchant();

        $this->expectException(RuntimeException::class);
        DemoStore::reseed($merchant, 'صغير');
    }

    public function test_destroying_refuses_a_business_that_is_not_marked_demo(): void
    {
        $merchant = $this->merchant();
        Product::create(['business_id' => $merchant->id, 'name' => 'صنف', 'price' => 1, 'cost' => 1, 'quantity' => 1]);

        try {
            DemoStore::destroy($merchant);
            $this->fail('حُذف متجر تاجر');
        } catch (RuntimeException) {
            // المطلوب
        }

        $this->assertDatabaseHas('businesses', ['id' => $merchant->id]);
        $this->assertSame(1, Product::where('business_id', $merchant->id)->count());
    }

    public function test_the_guard_names_the_business_it_refuses(): void
    {
        $merchant = $this->merchant('مخبز الأصيل');

        try {
            DemoGuard::assertDemo($merchant);
            $this->fail('لم يرفض');
        } catch (RuntimeException $e) {
            // الرسالة تُعرض للمشغّل — فتقول أي متجرٍ رُفض لا «رُفض» وحدها
            $this->assertStringContainsString('مخبز الأصيل', $e->getMessage());
        }
    }

    /** متجرٌ فيه بيعٌ فعليّ لا يُوسَم تجريبيًّا — الوسم يُبيح محوَه بزرّ */
    public function test_a_business_with_real_sales_is_not_markable_as_demo(): void
    {
        $merchant = $this->merchant();
        $this->assertTrue(DemoGuard::markable($merchant));

        Order::create([
            'business_id' => $merchant->id, 'number' => 'A-1', 'customer_name' => 'عميل',
            'status' => 'مكتمل', 'subtotal' => 10, 'total' => 10, 'ordered_at' => now(),
        ]);

        $this->assertFalse(DemoGuard::markable($merchant->fresh()));
    }

    /* ------------------------------ البناء ------------------------------ */

    public function test_a_demo_store_is_built_marked_and_filled(): void
    {
        $demo = DemoStore::create('متجر العرض', 'صغير');

        $this->assertTrue($demo->is_demo);
        $this->assertGreaterThan(0, Product::where('business_id', $demo->id)->count());
        $this->assertGreaterThan(0, Order::where('business_id', $demo->id)->count());
        // الدفتر يُملأ أيضًا — عرضٌ بلا قيودٍ يُظهر «المالية» فارغة
        $this->assertGreaterThan(0, \App\Models\JournalEntry::where('business_id', $demo->id)->count());
    }

    /** كل قيدٍ في الديمو متوازن — العرض على تاجرٍ محاسبٍ يُقرأ سطرًا سطرًا */
    public function test_every_demo_journal_entry_balances(): void
    {
        $demo = DemoStore::create('متجر العرض', 'صغير');

        $unbalanced = \App\Models\JournalEntry::where('business_id', $demo->id)
            ->withSum('lines', 'debit')->withSum('lines', 'credit')->get()
            ->filter(fn ($e) => round((float) $e->lines_sum_debit, 3) !== round((float) $e->lines_sum_credit, 3));

        $this->assertCount(0, $unbalanced, 'قيودٌ غير متوازنة في بيانات الديمو');
    }

    /** بناء متجرٍ تجريبيّ ثانٍ لا يصطدم ببريدٍ مكرّر */
    public function test_two_demo_stores_can_exist_side_by_side(): void
    {
        $first = DemoStore::create('عرض أوّل', 'صغير');
        $second = DemoStore::create('عرض ثانٍ', 'صغير');

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame(
            User::where('business_id', $first->id)->where('role', 'admin')->value('email'),
            User::where('business_id', $second->id)->where('role', 'admin')->value('email'),
        );
    }

    /* ------------------------------ المحو ------------------------------ */

    public function test_destroying_a_demo_leaves_no_row_behind(): void
    {
        $merchant = $this->merchant();
        Product::create(['business_id' => $merchant->id, 'name' => 'صنف تاجر', 'price' => 1, 'cost' => 1, 'quantity' => 1]);

        $demo = DemoStore::create('متجر العرض', 'صغير');
        $demoId = $demo->id;

        DemoStore::destroy($demo);

        $this->assertDatabaseMissing('businesses', ['id' => $demoId]);

        // لا صفٌّ معلَّق في أي جدولٍ يحمل business_id
        foreach (['products', 'customers', 'orders', 'journal_entries', 'users', 'suppliers'] as $table) {
            $this->assertSame(0, \DB::table($table)->where('business_id', $demoId)->count(), "بقيت صفوف في {$table}");
        }

        // وبيانات التاجر لم تُمسّ
        $this->assertSame(1, Product::where('business_id', $merchant->id)->count());
    }

    /* --------------------------- فصلُ ما يُعرَض --------------------------- */

    public function test_platform_lists_and_counters_exclude_demo_stores(): void
    {
        $this->merchant('تاجر أوّل');
        $this->merchant('تاجر ثانٍ');
        $demo = DemoStore::create('متجر العرض', 'صغير');

        $this->assertSame(2, Business::real()->count());
        $this->assertSame(1, Business::demo()->count());

        $this->actingAs($this->superAdmin);

        $businesses = $this->get(route('super-admin.businesses.index'))
            ->assertOk()->viewData('page')['props']['businesses'];

        $this->assertNotContains($demo->id, array_column($businesses, 'id'), 'المتجر التجريبيّ في قائمة التجّار');

        // وموظّفوه ليسوا في قائمة المستخدمين
        $users = $this->get(route('super-admin.users.index'))
            ->assertOk()->viewData('page')['props']['users'];

        $demoEmails = User::where('business_id', $demo->id)->pluck('email')->all();
        $this->assertEmpty(array_intersect($demoEmails, array_column($users, 'email')));
    }

    /* ------------------------------ الشاشة ------------------------------ */

    public function test_the_demo_section_only_accepts_a_demo_id(): void
    {
        $merchant = $this->merchant();
        $this->actingAs($this->superAdmin);

        $this->delete(route('super-admin.demo.destroy', $merchant->id))
            ->assertSessionHasErrors('store');

        $this->assertDatabaseHas('businesses', ['id' => $merchant->id]);

        $this->post(route('super-admin.demo.reseed', $merchant->id), ['size' => 'صغير'])
            ->assertSessionHasErrors('size');
    }

    public function test_the_demo_section_builds_and_removes_from_the_panel(): void
    {
        $this->actingAs($this->superAdmin);

        $this->post(route('super-admin.demo.store'), ['name' => 'عرض المعرض', 'size' => 'صغير'])
            ->assertSessionHasNoErrors();

        $demo = Business::demo()->firstOrFail();
        $this->assertSame('عرض المعرض', $demo->name);

        $this->delete(route('super-admin.demo.destroy', $demo->id))->assertSessionHasNoErrors();
        $this->assertSame(0, Business::demo()->count());
    }

    /**
     * ويُدخَل المتجر التجريبيّ بزرّ، لا بنسخ بريدٍ وكلمة مرور.
     *
     * وهو الفرق الذي يجعل الشاشة تُستعمل: العرض يقع أمام عميل، وتسجيلُ خروجٍ
     * ودخولٍ من جديد أمامه دقيقةٌ ضائعة وخطرُ أن تُكتب الكلمة خطأً مرّتين.
     */
    public function test_the_platform_admin_enters_a_demo_store_by_button(): void
    {
        $demo = DemoStore::create('متجر العرض', 'صغير');
        $owner = User::where('business_id', $demo->id)->where('role', 'admin')->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->from(route('super-admin.demo.index'))
            ->post(route('super-admin.businesses.impersonate', $demo->id))
            ->assertRedirect();

        $this->assertAuthenticatedAs($owner);

        // والعودةُ إلى الديمو: قائمة الشركات لا تعرض هذا المتجر أصلًا
        $this->post(route('impersonate.stop'))->assertRedirect(route('super-admin.demo.index'));
        $this->assertAuthenticatedAs($this->superAdmin);
    }

    /** كل جدولٍ يحمل business_id يُمحى — بما يُستحدَث بعد كتابة هذا الاختبار */
    public function test_the_wipe_covers_every_scoped_table(): void
    {
        $demo = DemoStore::create('متجر العرض', 'صغير');
        $demoId = $demo->id;

        DemoStore::destroy($demo);

        $leftovers = [];
        foreach (Schema::getTableListing() as $raw) {
            $table = str_contains($raw, '.') ? substr(strrchr($raw, '.'), 1) : $raw;
            if ($table === 'businesses' || ! Schema::hasColumn($table, 'business_id')) {
                continue;
            }
            if (\DB::table($table)->where('business_id', $demoId)->exists()) {
                $leftovers[] = $table;
            }
        }

        $this->assertSame([], $leftovers, 'جداولٌ بقيت فيها بيانات ديمو بعد المحو');
    }
}
