<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * تعديل الموظف، وتعديل الوظائف، وقوالب الإيصال.
 *
 * وأخطر ما هنا أن الموظفين مرتبطون بالوظيفة **بالاسم لا بالمعرّف**: تغيير
 * اسم وظيفة يترك حامليها معلَّقين على وظيفة لا وجود لها، وتغيير صلاحيتها
 * لا يصلهم فيبقى «كاشير» بصلاحيات مدير.
 */
class EmployeesAndTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_rate', 'value' => '5']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function title(string $name = 'كاشير', string $role = 'cashier'): JobTitle
    {
        return JobTitle::create(['business_id' => $this->business->id, 'name' => $name, 'role' => $role]);
    }

    private function employee(string $jobTitle = 'كاشير', string $role = 'cashier'): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'a@abaad.om',
            'password' => bcrypt('password'), 'role' => $role, 'job_title' => $jobTitle,
            'branch' => 'الفرع الرئيسي', 'status' => 'نشط',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function updatePayload(User $e, array $overrides = []): array
    {
        return array_merge([
            'name' => $e->name,
            'email' => $e->email,
            'job_title' => $e->job_title,
            'branch' => $e->branch,
        ], $overrides);
    }

    /* ======================= تعديل الموظف ======================= */

    public function test_the_edit_form_carries_the_fields_it_shows(): void
    {
        $e = $this->employee();
        $e->update(['monthly_target' => 500, 'commission_rate' => 2.5]);

        $props = $this->actingAs($this->owner)->get(route('admin.employees.edit', $e->id))
            ->assertOk()->viewData('page')['props']['employee'];

        $this->assertSame('نشط', $props['status']);
        $this->assertSame('500', (string) (int) $props['monthly_target']);
        $this->assertArrayHasKey('avatar', $props);
        // الرمز رُفع من النظام كلّه، فلا يصل حقلُه أصلًا
        $this->assertArrayNotHasKey('pin', $props);
    }

    public function test_a_new_password_typed_on_the_edit_form_actually_changes_it(): void
    {
        $this->title();
        $e = $this->employee();

        $this->actingAs($this->owner)->put(
            route('admin.employees.update', $e->id),
            $this->updatePayload($e, ['password' => 'newpass123']),
        )->assertRedirect();

        $this->assertTrue(
            Hash::check('newpass123', $e->fresh()->password),
            'حقلٌ معروض والتحقق لا يقبله — تُبتلع كلمة المرور بصمت',
        );
    }

    public function test_leaving_the_password_blank_keeps_the_old_one(): void
    {
        $this->title();
        $e = $this->employee();

        $this->actingAs($this->owner)->put(
            route('admin.employees.update', $e->id),
            $this->updatePayload($e, ['password' => '', 'name' => 'أحمد المعدَّل']),
        );

        $this->assertTrue(Hash::check('password', $e->fresh()->password), 'مُسحت كلمة المرور بحفظٍ عاديّ');
        $this->assertSame('أحمد المعدَّل', $e->fresh()->name);
    }

    public function test_the_status_toggle_disables_and_enables(): void
    {
        $this->title();
        $e = $this->employee();

        $this->actingAs($this->owner)->put(route('admin.employees.update', $e->id), $this->updatePayload($e, ['status' => false]));
        $this->assertSame('معطل', $e->fresh()->status);

        $this->actingAs($this->owner)->put(route('admin.employees.update', $e->id), $this->updatePayload($e, ['status' => true]));
        $this->assertSame('نشط', $e->fresh()->status);
    }

    public function test_an_admin_cannot_lock_themselves_out(): void
    {
        $this->title('مدير', 'manager');
        $this->owner->update(['job_title' => 'مدير']);

        $this->actingAs($this->owner)->put(
            route('admin.employees.update', $this->owner->id),
            $this->updatePayload($this->owner, ['status' => false]),
        )->assertSessionHasErrors('status');

        $this->assertSame('نشط', $this->owner->fresh()->status);
    }

    public function test_target_and_commission_are_saved(): void
    {
        $this->title();
        $e = $this->employee();

        $this->actingAs($this->owner)->put(route('admin.employees.update', $e->id), $this->updatePayload($e, [
            'monthly_target' => '1200', 'commission_rate' => '3.5',
        ]));

        $this->assertSame(1200.0, (float) $e->fresh()->monthly_target);
        $this->assertSame(3.5, (float) $e->fresh()->commission_rate);
    }

    public function test_a_commission_above_a_hundred_percent_is_refused(): void
    {
        $this->title();
        $e = $this->employee();

        $this->actingAs($this->owner)->put(route('admin.employees.update', $e->id), $this->updatePayload($e, [
            'commission_rate' => '150',
        ]))->assertSessionHasErrors('commission_rate');
    }

    /* ======================= تعديل الوظيفة ======================= */

    public function test_renaming_a_job_title_carries_its_holders_with_it(): void
    {
        $title = $this->title('كاشير');
        $e = $this->employee('كاشير');

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $title->id), [
            'name' => 'أمين صندوق', 'role' => 'cashier',
        ])->assertRedirect();

        $this->assertSame('أمين صندوق', $e->fresh()->job_title, 'بقي الموظف على وظيفة لا وجود لها');
    }

    /**
     * الوظيفة مسمًّى لا صلاحية.
     *
     * كانت تحمل «صلاحية مكافئة» تُفرَض على كل حاملٍ لها، فتعديل اسمها يقلب
     * صلاحيات موظفين لم يُقصدوا. وصار لكل موظف صلاحياته في شاشته، فلا يمسّ
     * تعديلُ الوظيفة أحدًا سوى اسمها.
     */
    public function test_renaming_a_title_does_not_touch_its_holders_permissions(): void
    {
        $title = $this->title('كاشير', 'cashier');
        $e = $this->employee('كاشير', 'cashier');
        $e->update(['permissions' => ['inventory']]);

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $title->id), [
            'name' => 'أمين صندوق',
        ])->assertRedirect();

        $e = $e->fresh();
        $this->assertSame('أمين صندوق', $e->job_title);
        $this->assertSame('cashier', $e->role, 'تبدّل الدور بلا طلب');
        $this->assertTrue($e->allows('inventory'), 'ضاعت صلاحية مُنحت يدويًا');
    }

    /** وتُضاف الوظيفة باسمها وحده — لا حقل ثانٍ يسأل عن صلاحية تُحدَّد لاحقًا */
    public function test_a_title_can_be_created_with_just_a_name(): void
    {
        $this->actingAs($this->owner)->post(route('admin.jobTitles.store'), [
            'name' => 'مشرف الصالة',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('job_titles', ['name' => 'مشرف الصالة']);
    }

    public function test_a_renamed_title_is_still_accepted_when_saving_the_employee(): void
    {
        $title = $this->title('كاشير');
        $e = $this->employee('كاشير');

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $title->id), [
            'name' => 'أمين صندوق', 'role' => 'cashier',
        ]);

        // كان هذا يُرفض بـ«الوظيفة المحددة غير موجودة»
        $this->actingAs($this->owner)->put(route('admin.employees.update', $e->id), [
            'name' => 'أحمد', 'email' => 'a@abaad.om', 'job_title' => 'أمين صندوق', 'branch' => 'الفرع الرئيسي',
        ])->assertSessionHasNoErrors();
    }

    public function test_two_job_titles_cannot_share_a_name(): void
    {
        $this->title('كاشير');
        $other = $this->title('مندوب', 'delivery');

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $other->id), [
            'name' => 'كاشير', 'role' => 'delivery',
        ])->assertSessionHasErrors('name');
    }

    public function test_a_title_keeps_its_own_name_on_save(): void
    {
        $title = $this->title('كاشير');

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $title->id), [
            'name' => 'كاشير', 'role' => 'manager',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_unknown_permission_is_refused(): void
    {
        $title = $this->title();

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $title->id), [
            'name' => 'كاشير', 'role' => 'god',
        ])->assertSessionHasErrors('role');
    }

    public function test_a_title_of_another_business_is_out_of_reach(): void
    {
        $other = Business::create(['name' => 'جارنا', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = JobTitle::create(['business_id' => $other->id, 'name' => 'وظيفة الجار', 'role' => 'cashier']);

        $this->actingAs($this->owner)->put(route('admin.jobTitles.update', $theirs->id), [
            'name' => 'مسروقة', 'role' => 'manager',
        ])->assertNotFound();

        $this->assertSame('وظيفة الجار', $theirs->fresh()->name);
    }

    /* ======================= قوالب الإيصال ======================= */

    private function order(): Order
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-1', 'status' => 'مكتمل',
            'payment_method' => 'نقدي', 'subtotal' => 10, 'tax' => 0.5, 'total' => 10.5,
            'employee_name' => 'أحمد', 'branch' => 'الفرع الرئيسي', 'ordered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'name' => 'باقة ورد',
            'price' => 10, 'quantity' => 1, 'total' => 10,
        ]);

        return $order;
    }

    private function tpl(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => $k], ['value' => $v]);
        }
    }

    public function test_the_templates_settings_are_saved(): void
    {
        $this->actingAs($this->owner)->post(route('admin.settings.update'), [
            'tpl_header' => 'أجمل الورود',
            'tpl_footer' => "شكرًا\nنراكم قريبًا",
            'tpl_font' => 'كبير',
            'tpl_show_employee' => '0',
        ])->assertRedirect();

        $saved = Setting::where('business_id', $this->business->id)->pluck('value', 'key');
        $this->assertSame('أجمل الورود', $saved['tpl_header']);
        $this->assertSame("شكرًا\nنراكم قريبًا", $saved['tpl_footer']);
        $this->assertSame('كبير', $saved['tpl_font']);
    }

    public function test_a_receipt_with_no_template_settings_prints_as_before(): void
    {
        $order = $this->order();

        $res = $this->actingAs($this->owner)->get(route('pos.receipt.pdf', $order->number));

        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertNotEmpty($res->getContent());
    }

    public function test_hiding_a_field_removes_it_from_the_rendered_receipt(): void
    {
        $order = $this->order();

        $shown = $this->renderReceipt($order);
        $this->assertStringContainsString('أحمد', $shown, 'اسم الموظف غائب أصلًا — راجع الاختبار');

        $this->tpl(['tpl_show_employee' => '0']);
        $this->assertStringNotContainsString('أحمد', $this->renderReceipt($order), 'بقي اسم الموظف رغم إخفائه');
    }

    public function test_the_footer_text_reaches_the_receipt(): void
    {
        $order = $this->order();
        $this->tpl(['tpl_footer' => "وداعًا\nنراكم قريبًا"]);

        $html = $this->renderReceipt($order);

        $this->assertStringContainsString('وداعًا', $html);
        $this->assertStringContainsString('نراكم قريبًا', $html, 'ضاع السطر الثاني من التذييل');
    }

    public function test_the_header_line_appears_only_when_written(): void
    {
        $order = $this->order();
        $this->assertStringNotContainsString('أجمل الورود', $this->renderReceipt($order));

        $this->tpl(['tpl_header' => 'أجمل الورود']);
        $this->assertStringContainsString('أجمل الورود', $this->renderReceipt($order));
    }

    public function test_the_font_size_setting_changes_the_rendered_size(): void
    {
        $order = $this->order();
        $normal = $this->renderReceipt($order);

        $this->tpl(['tpl_font' => 'كبير']);
        $this->assertNotSame($normal, $this->renderReceipt($order), 'حجم الخط لا أثر له');
    }

    /** يبني الإيصال كـHTML (لا PDF) حتى يُفحص محتواه لا حجمه */
    private function renderReceipt(Order $order): string
    {
        return view('pdf.receipt', [
            'order' => $order->fresh(['items']),
            'qr' => null,
            'tpl' => \App\Support\ReceiptTemplate::forBusiness($this->business->id),
        ])->render();
    }
}
