<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المحاسبُ يقرأ نصفَي الدفتر — المدينَ والدائن.
 *
 * ═══ ما كان ═══
 *
 * يُصدر فواتير العملاء ويحصّلها ويكتب الإشعارَ الدائن ويقرأ الرواتب وشجرةَ
 * الحسابات وميزانَ المراجعة — ولا يرى فواتيرَ الموردين ولا سنداتِ استلامهم.
 * فيقفل نصفَ الدفتر ويُسأل عن النصف الآخر شفاهةً.
 *
 * وأمينُ المخزن يراها، والمحاسبُ لا. وهو مقلوب.
 *
 * ═══ والدليلُ أنّه سهوٌ لا تصميم ═══
 *
 * `ATTACHMENT_VIEW` كانت تشمله من أوّل يوم: مأذونٌ أن يفتح **مرفقَ** سندٍ
 * لا يرى السندَ نفسَه. فالقصدُ كان أن يقرأها، ونُسيت أفعالُ القراءة.
 *
 * ═══ وما لم يُمنح — وهو نصفُ ما تحرسه هذه الحالات ═══
 *
 * لا كتابةَ ولا اعتمادَ ولا رفضَ ولا تجاوزَ ولا سداد. الاستلامُ عملُ من
 * يستلم البضاعة بيده، والاعتمادُ والصرفُ لمن يملك المال. **ومن يُقيّد لا
 * يعتمد ما قيّده** — وتوسيعُ دورٍ لا يعني فتحَه.
 */
class TheAccountantSeesBothSidesOfTheLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private User $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);

        $this->accountant = User::create([
            'business_id' => $business->id, 'name' => 'المحاسب', 'email' => 'acc@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'accountant', 'status' => 'نشط',
        ]);

        $this->inventory = User::create([
            'business_id' => $business->id, 'name' => 'أمين المخزن', 'email' => 'inv@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'inventory', 'status' => 'نشط',
        ]);
    }

    /* --------------------------- ما يقرؤه --------------------------- */

    /** يقرأ سنداتِ الاستلام — وفيها تكلفةُ ما اشترى المتجر */
    public function test_the_accountant_may_read_goods_receipts(): void
    {
        $this->assertTrue($this->accountant->may(Permissions::RECEIPT_VIEW));
    }

    /** ويقرأ فواتيرَ الموردين — وهي الذمّةُ الدائنة نفسُها */
    public function test_the_accountant_may_read_supplier_invoices(): void
    {
        $this->assertTrue($this->accountant->may(Permissions::INVOICE_VIEW));
    }

    /** وشاشتا «الموردون» و«المشتريات» تُفتحان له */
    public function test_the_payables_screens_open_for_the_accountant(): void
    {
        $this->actingAs($this->accountant)->get(route('admin.suppliers.index'))->assertOk();
        $this->actingAs($this->accountant)->get(route('admin.purchases.index'))->assertOk();
    }

    /**
     * ولا تناقضَ بين مرفقٍ يُفتح ومستندٍ لا يُرى.
     *
     * وهذا الحارسُ يقيس **الاتّساق** لا القيمة: من أُذن له بالمرفق أُذن له
     * بالمستند. فلو ضُيّق أحدهما يومًا وبقي الآخر عاد العطبُ بشكلٍ آخر.
     */
    public function test_reading_an_attachment_and_reading_its_document_go_together(): void
    {
        $this->assertSame(
            $this->accountant->may(Permissions::ATTACHMENT_VIEW),
            $this->accountant->may(Permissions::INVOICE_VIEW),
            'يُفتح له المرفقُ ولا يُفتح المستند — أو العكس',
        );
    }

    /* ------------------------- وما لا يفعله ------------------------- */

    /** لا يُنشئ سندَ استلام: الاستلامُ عملُ من يستلم البضاعة بيده */
    public function test_the_accountant_does_not_create_goods_receipts(): void
    {
        $this->assertFalse($this->accountant->may(Permissions::RECEIPT_CREATE));
    }

    /** ولا يكتب فاتورةَ مورّد */
    public function test_the_accountant_does_not_create_supplier_invoices(): void
    {
        $this->assertFalse($this->accountant->may(Permissions::INVOICE_CREATE));
    }

    /**
     * ولا يعتمد ولا يرفض ولا يتجاوز ولا يسدّد.
     *
     * ومن يُقيّد لا يعتمد ما قيّده: أن يقرأ الفاتورة ثمّ يُقرّ بها ثمّ
     * يُخرج ثمنَها ثلاثةُ قراراتٍ في يدٍ واحدة.
     */
    public function test_the_accountant_neither_approves_nor_pays(): void
    {
        foreach ([
            Permissions::RECEIPT_APPROVE, Permissions::RECEIPT_REJECT,
            Permissions::INVOICE_APPROVE, Permissions::INVOICE_REJECT,
            Permissions::INVOICE_OVERRIDE, Permissions::INVOICE_PAY,
        ] as $action) {
            $this->assertFalse(
                $this->accountant->may($action),
                'المحاسب يملك فعلًا ليس له: '.$action,
            );
        }
    }

    /** ولا يعتمد الرواتب ولا يصرفها — وهو يقرؤها */
    public function test_payroll_stays_read_only_for_the_accountant(): void
    {
        $this->assertTrue($this->accountant->may(Permissions::PAYROLL_VIEW));
        $this->assertFalse($this->accountant->may(Permissions::PAYROLL_APPROVE));
        $this->assertFalse($this->accountant->may(Permissions::PAYROLL_PAY));
    }

    /** والإعداداتُ ليست له: تكوينُ متجرٍ لا محاسبة */
    public function test_settings_stay_closed_to_the_accountant(): void
    {
        $this->actingAs($this->accountant)->get(route('admin.settings.index'))->assertForbidden();
    }

    /* --------------------- ولا يُوسَّع غيرُه معه --------------------- */

    /** وأمينُ المخزن لا يرث المالية بهذا التوسيع */
    public function test_the_storekeeper_gains_nothing_from_this(): void
    {
        $this->assertFalse($this->inventory->allows('finance'));
        $this->assertFalse($this->inventory->may(Permissions::PAYROLL_VIEW));
        $this->actingAs($this->inventory)->get(route('admin.finance.index'))->assertForbidden();
    }

    /** ويبقى هو وحدَه من ينشئ سنداتِ الاستلام */
    public function test_the_storekeeper_still_creates_receipts(): void
    {
        $this->assertTrue($this->inventory->may(Permissions::RECEIPT_CREATE));
    }
}
