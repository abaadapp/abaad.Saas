<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Support\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * من حُذف لا يبقى في الداخل.
 *
 * الحذف في «الموظفون» ناعم: يبقى الصفّ ويُرفع عنه العلم. ومزوّد المصادقة
 * يقرأ المستخدم من الجلسة بلا نطاقات — فالمحذوف كان يبقى مسجَّلًا كما كان.
 *
 * فصاحب النشاط يطرد موظفًا ويحذفه، والموظف واقفٌ خارج المحل ولوحتُه مفتوحة
 * على هاتفه: يقرأ الزبائن، ويعدّل المنتجات، **ويكتب** — وقد أثبتناه بصفٍّ
 * كُتب بعد الحذف. والنافذة ١٢٠ دقيقة تتجدّد مع كلّ نقرة، أي ما دام يضغط.
 *
 * والدخول من جديد كان ممنوعًا أصلًا — البحث بالبريد وبالرمز يمرّ بنطاق
 * الحذف الناعم فلا يجده. فالثغرة في الجلسة القائمة وحدها، وهي التي تُغلق
 * هنا: عند `Tenancy::blockReason`، النقطة التي يقرؤها كلّ باب.
 */
class FiredEmployeeStopsWorkingTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->employee = User::create([
            'business_id' => $this->business->id, 'name' => 'موظف', 'email' => 'e@abaad.om',
            'password' => bcrypt('password'), 'role' => 'manager', 'status' => 'نشط', 'pin' => '7361',
        ]);
    }

    public function test_before_the_dismissal_he_works_normally(): void
    {
        $this->actingAs($this->employee)->get(route('admin.dashboard'))->assertSuccessful();
    }

    public function test_a_deleted_employee_cannot_read(): void
    {
        $this->actingAs($this->employee)->get(route('admin.dashboard'))->assertSuccessful();

        $this->employee->delete();

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** والقراءة أهون: الكتابة هي ما يُفسد دفاتر التاجر */
    public function test_a_deleted_employee_cannot_write(): void
    {
        $this->actingAs($this->employee)->get(route('admin.dashboard'))->assertSuccessful();

        $this->employee->delete();

        $this->post(route('admin.customers.store'), ['name' => 'زبونٌ بعد الطرد', 'phone' => '90000009']);

        $this->assertFalse(Customer::where('name', 'زبونٌ بعد الطرد')->exists(),
            'الموظف المحذوف كتب صفًّا في متجرٍ لم يعد يعمل فيه');
    }

    /** والموقوف كما كان — لم يُكسَر بإصلاح المحذوف */
    public function test_a_suspended_employee_is_still_stopped(): void
    {
        $this->actingAs($this->employee)->get(route('admin.dashboard'))->assertSuccessful();

        $this->employee->update(['status' => 'موقوف']);

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** ولا يعود من الباب — وهو بريده وكلمة مروره */
    public function test_a_deleted_employee_cannot_log_back_in(): void
    {
        $this->employee->delete();

        $this->post(route('login.attempt'), ['email' => 'e@abaad.om', 'password' => 'password'])
            ->assertSessionHasErrors();
        $this->assertGuest();
    }

    /** ولا يبيع على صندوقٍ مفعَّل: الجلسة تُقطع عند حارس الطلب */
    public function test_a_deleted_employee_cannot_sell_on_an_active_register(): void
    {
        $branch = Branch::where('business_id', $this->business->id)->first();
        $owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        PosTerminal::activate($branch, 'صندوق', $owner->id);

        $this->employee->delete();

        $this->post(route('login.attempt'), ['email' => 'e@abaad.om', 'password' => 'password'])
            ->assertSessionHasErrors();
        $this->assertGuest();
    }

    /** والحيّ يبقى حيًّا — لئلا يُقرأ الإغلاق إغلاقًا على الجميع */
    public function test_an_employee_who_was_never_deleted_still_works(): void
    {
        $this->actingAs($this->employee)->get(route('admin.dashboard'))->assertSuccessful();
        $this->post(route('admin.customers.store'), ['name' => 'زبونٌ عاديّ', 'phone' => '90000008']);

        $this->assertTrue(Customer::where('name', 'زبونٌ عاديّ')->exists());
    }
}
