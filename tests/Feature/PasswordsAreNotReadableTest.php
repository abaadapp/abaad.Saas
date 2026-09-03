<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\MerchantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * كلمة المرور القائمة لا تُقرأ — لا في شاشة، ولا في سجلّ، ولا في القاعدة.
 *
 * وطُلب أن تُحفظ لتُعرض عند فتح ملفّ الموظف. ولا تُحفظ: من يفتح ملفّ موظّفٍ
 * يقرأ كلمته يفتح كلمةَ صاحبها في **كلّ موقعٍ آخر يستعملها فيه** — والناس
 * يعيدون كلماتهم. ويكفي أن يُسرَّب الجدولُ مرّةً ليخرج كلُّ ذلك معه، ولا
 * يعرف أحدٌ متى خرج.
 *
 * والحاجةُ التي وراء الطلب حقيقية: المدير يريد أن يُملي على موظّفه كلمةً
 * يعرفها. فتُقضى بأن تُعرض **لحظةَ كتابتها** — تُولَّد، وتُرى بالعين،
 * وتُنسخ — لا بأن تبقى مقروءةً إلى الأبد.
 */
class PasswordsAreNotReadableTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط', 'job_title' => 'مدير',
        ]);

        $this->employee = User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'ahmad@abaadapp.om',
            'password' => bcrypt('sirr-al-muwazzaf'), 'role' => 'cashier', 'status' => 'نشط', 'job_title' => 'كاشير',
        ]);
    }

    /* ---------------------------- لا تُحفظ نصًّا ---------------------------- */

    public function test_the_stored_password_is_a_hash_not_the_word_itself(): void
    {
        $stored = $this->employee->fresh()->password;

        $this->assertNotSame('sirr-al-muwazzaf', $stored);
        $this->assertStringNotContainsString('sirr-al-muwazzaf', $stored);
        $this->assertTrue(Hash::check('sirr-al-muwazzaf', $stored));
    }

    /** ولا عمودَ ثانٍ يحملها إلى جانب تجزئتها */
    public function test_no_second_column_carries_it(): void
    {
        $row = (array) \DB::table('users')->where('id', $this->employee->id)->first();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString(
                'sirr-al-muwazzaf', (string) $value, "العمود «{$column}» يحمل كلمة المرور نصًّا"
            );
        }
    }

    /* --------------------------- ولا تصل الشاشة --------------------------- */

    public function test_the_edit_screen_never_carries_the_password(): void
    {
        $res = $this->actingAs($this->owner)->get(route('admin.employees.edit', $this->employee->id));

        $res->assertOk();
        $this->assertStringNotContainsString('sirr-al-muwazzaf', $res->getContent());
        $res->assertInertia(fn ($page) => $page->missing('employee.password'));
    }

    public function test_the_file_screen_never_carries_it_either(): void
    {
        $res = $this->actingAs($this->owner)->get(route('admin.employees.show', $this->employee->id));

        $res->assertOk();
        $this->assertStringNotContainsString('sirr-al-muwazzaf', $res->getContent());
    }

    /* ------------------------- الكلمة المولَّدة تُرى ------------------------- */

    /**
     * وتُعاد مرّةً واحدة لتُنسخ.
     *
     * كانت تُرسل داخل نصّ التوست فتختفي بعد ثوانٍ ولا تُسترجَع — فيعيد المدير
     * التوليد مرّةً بعد مرّة، وفي كلّ مرّة يُخرج الموظفَ من حسابه.
     */
    public function test_a_generated_password_comes_back_once_so_it_can_be_copied(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.employees.resetPassword', $this->employee->id))
            ->assertSessionHas('issued_password');

        $issued = session('issued_password');

        $this->assertIsString($issued);
        $this->assertTrue(Hash::check($issued, $this->employee->fresh()->password));
    }

    /** ولا تُكتب في سجلّ النشاط: السجلّ يُقرأ لاحقًا ويُصدَّر */
    public function test_the_generated_password_is_not_written_into_the_activity_log(): void
    {
        $this->actingAs($this->owner)->post(route('admin.employees.resetPassword', $this->employee->id));

        $issued = session('issued_password');

        $this->assertDatabaseMissing('activity_logs', ['description' => 'أعاد تعيين كلمة مرور الموظف: أحمد '.$issued]);

        foreach (\DB::table('activity_logs')->get() as $row) {
            $this->assertStringNotContainsString($issued, json_encode($row, JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * والمولَّدة ليست ستّة محارف من قاعدةٍ معروفة.
     *
     * كانت `'Ab'.random_int(1000, 9999)` — تسعةُ آلاف احتمال، وشكلٌ يبدأ
     * بـ«Ab». من عرف القاعدة جرّبها كلَّها في دقائق، وهي مفتاحُ صندوقٍ
     * ومخزونٍ وأرقام هواتف زبائن.
     */
    public function test_the_generated_password_is_long_and_unpredictable(): void
    {
        $seen = [];

        for ($i = 0; $i < 50; $i++) {
            $seen[] = MerchantAccount::temporaryPassword();
        }

        foreach ($seen as $one) {
            $this->assertSame(10, strlen($one));
            $this->assertStringStartsNotWith('Ab', $one);
            // بلا حروفٍ تلتبس عند الإملاء
            $this->assertDoesNotMatchRegularExpression('/[lO01]/', $one);
        }

        $this->assertCount(50, array_unique($seen));
    }

    /** ولا يعيد أحدٌ تعيين كلمة موظّف جارٍ */
    public function test_a_neighbours_employee_keeps_their_password(): void
    {
        $other = Business::create(['name' => 'الجار', 'status' => 'نشط']);
        $theirs = User::create([
            'business_id' => $other->id, 'name' => 'موظفهم', 'email' => 'x@abaadapp.om',
            'password' => bcrypt('kalimat-al-jaar'), 'role' => 'cashier', 'status' => 'نشط', 'job_title' => 'كاشير',
        ]);

        $this->actingAs($this->owner)
            ->post(route('admin.employees.resetPassword', $theirs->id))
            ->assertNotFound();

        $this->assertTrue(Hash::check('kalimat-al-jaar', $theirs->fresh()->password));
    }
}
