<?php

namespace Tests\Feature;

use App\Mail\RecoveryOtpMail;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Support\WhatsAppMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * بابان في لوحة المنصّة كانا يعملان بلا حارس.
 *
 * `businesses.recovery.resend` و`whatsapp.shared.disconnect` — لا اختبارَ
 * واحدًا يذكرهما، وهما يعملان اليوم صحيحين. لكنّ العملَ اليوم بلا حارسٍ هو
 * الخطر نفسه: لا شيء يقول إنّ شيئًا انكسر حتى يكسره تعديلٌ بعيد.
 *
 * وأخطر ما يُحرَس فيهما اثنان: أنّ إعادة إرسال الرمز **لا تختم** العنوان —
 * ولو ختمته لَصار كلُّ حسابٍ مفتوحًا لمن يجلس على شاشة الدعم؛ وأنّ فصل
 * الرقم المشترك **تعطيلٌ لا حذف** — الحذف يقطع صلة تاريخ الرسائل بوصلتها.
 */
class PlatformWriteRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.gmail.com']);
        Mail::fake();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->super = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function shared(string $status = WhatsAppConnection::ACTIVE): WhatsAppConnection
    {
        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'phone_number_id' => 'ABAAD-PN',
            'display_phone_number' => '+96890000000',
            'access_token' => 'platform-token-value-0123456789',
            'status' => $status,
            'connected_at' => now(),
        ]);
    }

    /* ------------------------ إعادة إرسال رمز الاستعادة ------------------------ */

    public function test_the_saved_recovery_email_gets_its_code_again(): void
    {
        $this->owner->forceFill(['recovery_email' => 'sahib@gmail.com'])->save();

        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.recovery.resend', $this->business->id))
            ->assertRedirect();

        Mail::assertSent(RecoveryOtpMail::class, fn ($mail) => $mail->hasTo('sahib@gmail.com'));
    }

    /** الرمزُ يُرسل ولا يختم: الختم لا يضعه إلا رمزٌ عاد من الصندوق */
    public function test_resending_does_not_stamp_the_address_as_verified(): void
    {
        $this->owner->forceFill(['recovery_email' => 'sahib@gmail.com'])->save();

        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.recovery.resend', $this->business->id));

        $this->assertNull($this->owner->fresh()->recovery_email_verified_at);
    }

    public function test_a_business_with_no_saved_address_is_refused(): void
    {
        $this->actingAs($this->super)
            ->post(route('super-admin.businesses.recovery.resend', $this->business->id))
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_a_merchant_cannot_fire_the_recovery_mail(): void
    {
        $this->owner->forceFill(['recovery_email' => 'sahib@gmail.com'])->save();

        $this->actingAs($this->owner)
            ->post(route('super-admin.businesses.recovery.resend', $this->business->id))
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    /* --------------------------- فصل الرقم المشترك --------------------------- */

    public function test_disconnecting_stops_the_number_without_erasing_it(): void
    {
        $connection = $this->shared();

        $this->actingAs($this->super)
            ->delete(route('super-admin.whatsapp.shared.disconnect'))
            ->assertRedirect();

        $connection->refresh();

        $this->assertSame(WhatsAppConnection::INACTIVE, $connection->status);
        $this->assertNotNull($connection->disconnected_at);
        $this->assertDatabaseHas('whatsapp_connections', ['id' => $connection->id]);
    }

    /** لا وصلةَ أصلًا — والشاشة تردّ ولا تنكسر */
    public function test_disconnecting_nothing_is_not_an_error(): void
    {
        $this->actingAs($this->super)
            ->delete(route('super-admin.whatsapp.shared.disconnect'))
            ->assertRedirect();
    }

    public function test_a_merchant_cannot_cut_the_platform_number(): void
    {
        $connection = $this->shared();

        $this->actingAs($this->owner)
            ->delete(route('super-admin.whatsapp.shared.disconnect'))
            ->assertForbidden();

        $this->assertSame(WhatsAppConnection::ACTIVE, $connection->fresh()->status);
    }

    /* ------------------------- فاتورة الاشتراك على ورق ------------------------- */

    private function invoice(string $status = 'مدفوعة'): Invoice
    {
        $plan = Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => 25, 'yearly_price' => 250]);

        return Invoice::create([
            'number' => 'INV-2026-0001',
            'business_id' => $this->business->id,
            'plan_id' => $plan->id,
            'amount' => 25,
            'issued_at' => '2026-09-01',
            'status' => $status,
        ]);
    }

    /**
     * الورقة تخرج فعلًا.
     *
     * كانت ٥٠٠: القالب يقرأ `$order` والمتحكّم يمرّر `$invoice` — وزرّا «عرض»
     * و«تحميل» معروضان على كل صفٍّ في شاشة الفواتير.
     */
    public function test_a_subscription_invoice_actually_prints(): void
    {
        $invoice = $this->invoice();

        $res = $this->actingAs($this->super)
            ->get(route('super-admin.invoices.pdf', $invoice->number));

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    /** ورقةٌ بلا باقة لا تنكسر: `plan_id` يقبل الفراغ في الجدول */
    public function test_an_invoice_with_no_plan_still_prints(): void
    {
        $invoice = $this->invoice();
        $invoice->forceFill(['plan_id' => null])->save();

        $this->actingAs($this->super)
            ->get(route('super-admin.invoices.pdf', $invoice->number))
            ->assertOk();
    }

    /** اسم المنصّة على الورقة يأتي من إعداداتها لا من ثابتٍ في القالب */
    public function test_the_platform_identity_reaches_the_paper(): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => 'company'], ['value' => 'أبعاد للحلول']);
        app()->setLocale('ar');

        $html = view('pdf.platform-invoice', [
            'invoice' => $this->invoice()->load('business', 'plan'),
            'platform' => ['app_name' => 'أبعاد', 'company' => 'أبعاد للحلول', 'email' => '', 'phone' => '', 'website' => ''],
        ])->render();

        $this->assertStringContainsString('أبعاد للحلول', $html);
        $this->assertStringContainsString('محل ورد', $html);
        $this->assertStringContainsString('الباقة الاحترافية', $html);
    }

    public function test_a_merchant_cannot_pull_a_platform_invoice(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->owner)
            ->get(route('super-admin.invoices.pdf', $invoice->number))
            ->assertForbidden();
    }
}
