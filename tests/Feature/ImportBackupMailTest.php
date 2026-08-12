<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * استيراد العملاء، والنسخ الاحتياطي واستعادته، والبريد التجريبي.
 *
 * ثلاثتها لم تكن مفحوصة، وكلها تلمس بيانات حقيقية: الاستيراد يكتب دفعة
 * واحدة، والاستعادة تكتب فوق كل شيء، والبريد يخرج من النظام إلى العالم.
 */
class ImportBackupMailTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 's@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /* --------------------------- استيراد العملاء -------------------------- */

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
        file_put_contents($path, "\xEF\xBB\xBF" . $body);

        return new UploadedFile($path, 'customers.csv', 'text/csv', null, true);
    }

    public function test_it_imports_customers_from_a_csv(): void
    {
        $file = $this->csv("الاسم,الهاتف,البريد\nسالم البلوشي,+96891000001,salem@abaad.om\nمريم الحارثي,+96891000002,maryam@abaad.om\n");

        $this->actingAs($this->owner)
            ->post(route('admin.customers.import.upload'), ['file' => $file])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post(route('admin.customers.import.confirm'));

        $this->assertDatabaseHas('customers', [
            'business_id' => $this->business->id, 'name' => 'سالم البلوشي',
        ]);
        $this->assertDatabaseHas('customers', [
            'business_id' => $this->business->id, 'name' => 'مريم الحارثي',
        ]);
    }

    public function test_imported_customers_belong_to_the_importing_business_only(): void
    {
        $theirs = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);

        $file = $this->csv("الاسم,الهاتف\nعميل مستورد,+96891000003\n");

        $this->actingAs($this->owner)->post(route('admin.customers.import.upload'), ['file' => $file]);
        $this->actingAs($this->owner)->post(route('admin.customers.import.confirm'));

        $this->assertSame(
            0,
            Customer::where('business_id', $theirs->id)->count(),
            'الاستيراد سرّب عميلًا إلى متجر آخر'
        );
    }

    public function test_a_file_that_is_not_a_spreadsheet_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad') . '.txt';
        file_put_contents($path, 'ليس جدولًا');

        $response = $this->actingAs($this->owner)
            ->post(route('admin.customers.import.upload'), [
                'file' => new UploadedFile($path, 'notes.txt', 'text/plain', null, true),
            ]);

        // يُردّ بتنبيه خطأ لا بصفحة معاينة — والمهمّ ألّا يدخل شيء
        $response->assertSessionHas('toast.type', 'danger');
        $this->assertSame(0, Customer::where('business_id', $this->business->id)->count());
    }

    /* ------------------------- النسخ الاحتياطي ------------------------- */

    public function test_the_backup_carries_this_businesses_data(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'منتجي',
            'price' => 10, 'quantity' => 5, 'active' => true,
        ]);

        $response = $this->actingAs($this->owner)->get(route('admin.backup.download'));
        $response->assertOk();

        $body = $response->getContent();

        $this->assertNotEmpty($body, 'ملف نسخة فارغ');
        $this->assertStringContainsString('منتجي', $body);
    }

    public function test_the_backup_does_not_carry_another_businesses_data(): void
    {
        $theirs = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Product::create([
            'business_id' => $theirs->id, 'name' => 'سرّ الجار',
            'price' => 10, 'quantity' => 5, 'active' => true,
        ]);

        $body = $this->actingAs($this->owner)
            ->get(route('admin.backup.download'))->getContent();

        $this->assertStringNotContainsString('سرّ الجار', $body, 'النسخة تحمل بيانات متجر آخر');
    }

    public function test_a_backup_restores_what_it_carried(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'قبل النسخة',
            'price' => 10, 'quantity' => 5, 'active' => true,
        ]);

        $body = $this->actingAs($this->owner)
            ->get(route('admin.backup.download'))->getContent();

        // يحذف التاجر منتجه بالخطأ ثم يستعيد
        Product::where('business_id', $this->business->id)->delete();
        $this->assertSame(0, Product::where('business_id', $this->business->id)->count());

        $path = tempnam(sys_get_temp_dir(), 'bak') . '.json';
        file_put_contents($path, $body);

        $this->actingAs($this->owner)->post(route('admin.backup.restore'), [
            'backup' => new UploadedFile($path, 'backup.json', 'application/json', null, true),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', [
            'business_id' => $this->business->id, 'name' => 'قبل النسخة',
        ]);
    }

    public function test_restoring_does_not_lock_the_restoring_user_out(): void
    {
        $body = $this->actingAs($this->owner)
            ->get(route('admin.backup.download'))->getContent();

        $path = tempnam(sys_get_temp_dir(), 'bak') . '.json';
        file_put_contents($path, $body);

        $this->actingAs($this->owner)->post(route('admin.backup.restore'), [
            'backup' => new UploadedFile($path, 'backup.json', 'application/json', null, true),
        ]);

        // الحساب الذي أجرى الاستعادة يجب أن يبقى قائمًا وإلا أُقفلت اللوحة
        $this->assertNotNull(User::find($this->owner->id));
        $this->assertSame('نشط', $this->owner->fresh()->status);
    }

    /* ---------------------------- البريد ---------------------------- */

    /** ناقل «array» يحتفظ بالرسائل في الذاكرة — Mail::fake لا يلتقط Mail::raw */
    private function outbox()
    {
        return Mail::getSymfonyTransport()->messages();
    }

    public function test_the_test_email_actually_goes_out(): void
    {
        /*
         * ناقل الذاكرة تحت اسم smtp عمدًا.
         *
         * الزرّ صار يرفض المرسِلات التي لا تُخرج شيئًا (log و array و null)
         * بدل أن يقول «تم الإرسال» وهو يكتب في ملفّ. فلالتقاط الرسالة هنا
         * يُسمّى الناقلُ smtp ويبقى في الذاكرة: مرسِلٌ يسلّم في نظر الحارس،
         * وصندوقُ وارد نقرؤه في نظر الاختبار.
         */
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'array']]);
        Mail::purge('smtp');

        $this->actingAs($this->super)
            ->post(route('super-admin.settings.testEmail'), ['to' => 'check@abaad.om'])
            ->assertSessionHas('toast.type', 'success');

        $messages = $this->outbox();
        $this->assertCount(1, $messages, 'لم تخرج رسالة');
        $this->assertStringContainsString(
            'check@abaad.om',
            $messages[0]->getOriginalMessage()->getTo()[0]->getAddress(),
        );
    }

    public function test_the_button_refuses_when_the_server_sends_nothing(): void
    {
        /*
         * أخطر ما كان في الشاشة: المرسِل `log` يكتب الرسالة في ملفّ ولا
         * يخرجها، والزرّ يقول «تم الإرسال». فيطمئنّ المشغّل ولا يصل تنبيهُ
         * اشتراكٍ ولا رابطُ استعادة كلمة سرّ لأحد. الفشل الصريح هو الفائدة.
         */
        config(['mail.default' => 'log']);

        $this->actingAs($this->super)
            ->post(route('super-admin.settings.testEmail'), ['to' => 'check@abaad.om'])
            ->assertSessionHas('toast.type', 'error');
    }

    public function test_a_merchant_cannot_send_platform_test_email(): void
    {
        config(['mail.default' => 'array']);
        Mail::purge('array');

        $this->actingAs($this->owner)
            ->post(route('super-admin.settings.testEmail'), ['to' => 'check@abaad.om'])
            ->assertForbidden();

        $this->assertCount(0, $this->outbox());
    }
}
