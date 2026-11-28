<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceRevision;
use App\Models\PointTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoiceEdits;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * مسودّةٌ اختير عليها استبدالُ نقاطٍ — تُعدَّل وخصمُ نقاطها معها (#195 فوق #239).
 *
 * ═══ ما يُحرس ═══
 *
 *   - تعديلُ الكمّيّات والأسعار والخصم اليدويّ يعيد خصمَ النقاط على البنود الجديدة
 *     بمنطق الإنشاء نفسِه: العددُ نفسُه والقيمةُ نفسُها، وحصّةُ كلّ بندٍ فيه.
 *   - تعديلٌ لا يجيز الاستبدالَ بعده يُردّ برسالةٍ واضحة، ولا يتغيّر شيء.
 *   - ومسودّةٌ عليها نقاطٌ لا يتغيّر عميلُها: النقاطُ من رصيده.
 *   - المسودّةُ لا تصرف نقطةً ولا تعيدها مهما حُفظت؛ والإصدارُ يصرفها مرّةً
 *     بخصمها كاملًا. وإلغاءُ المسودّة لا يحرّك نقطة.
 *   - تعديلان على النسخة نفسِها: الثاني يُردّ — ولا يتغيّر الرصيد.
 *   - والفاتورةُ الصادرة يُصحَّح مالُها بإشعارٍ دائنٍ يعيد النقاطَ بنسبته، مرّةً واحدة:
 *     حصّةُ كلّ بندٍ من خصم النقاط تتبعه بنسبة ما بقي منه، ولا تزيد لبندٍ زاد.
 */
class ADraftWithPointsIsEditedWithItsDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Customer $maryam;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ورود النقاط', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($this->shop->id);
        // بلا ضريبة: المبالغُ تُقرأ بلا كسور — وقواعدُ النقاط افتراضُها (١٠٠ نقطة بريال، وسقفٌ خمسون بالمئة)
        Setting::updateOrCreate(['business_id' => $this->shop->id, 'key' => 'vat_enabled'], ['value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@points-edit.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($this->owner);

        $this->maryam = $this->customer('مريم', 1000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(string $name, int $points): Customer
    {
        return Customer::create([
            'business_id' => $this->shop->id, 'name' => $name, 'phone' => '9'.str_pad((string) (++$this->n), 7, '1', STR_PAD_LEFT),
            'points' => $points, 'language' => 'ar', 'allow_credit_sales' => true,
        ]);
    }

    /** مسودّةٌ ببندٍ (٢ × ٥٠ = ١٠٠) واستبدالِ ألف نقطة = عشرة ريالات */
    private function draft(int $redeem = 1000, ?Customer $customer = null): CustomerInvoice
    {
        return CustomerInvoices::create($this->shop->id, $customer ?? $this->maryam, [
            'issued_at' => '2026-11-20', 'redeem_points' => $redeem,
        ], [['description' => 'باقة ورد', 'quantity' => 2, 'unit_price' => 50]], $this->owner->id);
    }

    /** نموذجُ التعديل كما ترسله الشاشة: خصمُ البند اليدويُّ وحده */
    private function form(CustomerInvoice $invoice, array $line = [], array $over = []): array
    {
        $invoice = $invoice->fresh('items');
        $items = $invoice->items->map(fn ($i) => [
            'item_id' => $i->id, 'description' => $i->description, 'quantity' => (float) $i->quantity,
            'unit_price' => (float) $i->unit_price, 'discount' => round((float) $i->discount - (float) $i->loyalty_discount, 3),
            'tax_rate' => (float) $i->tax_rate,
        ])->all();
        $items[0] = array_merge($items[0], $line);

        return array_merge([
            'expected_revision' => (int) $invoice->revision,
            'edit_token' => 'tok-'.(++$this->n),
            'customer_id' => $invoice->customer_id,
            'issued_at' => $invoice->issued_at->toDateString(),
            'due_at' => optional($invoice->due_at)->toDateString(),
            'payment_terms_days' => $invoice->payment_terms_days,
            'branch_id' => $invoice->branch_id,
            'notes' => $invoice->notes,
            'internal_notes' => $invoice->internal_notes,
            'reason' => 'تعديل الكمية حسب الطلب',
            'basis' => CustomerInvoiceRevision::ERROR,
            'items' => $items,
        ], $over);
    }

    private function edit(CustomerInvoice $invoice, array $form)
    {
        return $this->put(route('admin.customerInvoices.update', $invoice->id), $form);
    }

    /** صفُّ الورقة وبنودُها ورصيدُ العميل وحركاتُه — كلُّ ما يمسّه التعديل */
    private function state(CustomerInvoice $invoice, ?Customer $customer = null): array
    {
        $invoice = $invoice->fresh('items');

        return [
            [(int) $invoice->revision, (int) $invoice->customer_id, (float) $invoice->total, (int) $invoice->loyalty_points, (float) $invoice->loyalty_discount],
            $invoice->items->map(fn ($i) => [(float) $i->quantity, (float) $i->unit_price, (float) $i->discount, (float) $i->loyalty_discount])->all(),
            (int) ($customer ?? $this->maryam)->fresh()->points,
            PointTransaction::where('business_id', $this->shop->id)->count(),
        ];
    }

    private function shares(CustomerInvoice $invoice): float
    {
        return round((float) $invoice->fresh('items')->items->sum('loyalty_discount'), 3);
    }

    /* ═══ ١ · الخصمُ يُعاد على البنود الجديدة ═══ */

    public function test_a_quantity_change_reapplies_the_points_discount_and_issue_spends_the_points_once(): void
    {
        $draft = $this->draft();
        $this->assertEquals([90.0, 1000, 10.0], [(float) $draft->total, (int) $draft->loyalty_points, (float) $draft->loyalty_discount]);

        $this->edit($draft, $this->form($draft, ['quantity' => 3]))->assertSessionHasNoErrors();
        $draft = $draft->fresh('items');

        // ٣ × ٥٠ = ١٥٠ ناقص خصم النقاط عشرة — والحصّةُ في البند
        $this->assertEquals([140.0, 1000, 10.0, 10.0], [(float) $draft->total, (int) $draft->loyalty_points, (float) $draft->loyalty_discount, $this->shares($draft)]);
        $this->assertSame([1000, 0], [(int) $this->maryam->fresh()->points, PointTransaction::where('business_id', $this->shop->id)->count()], 'المسودّةُ صرفت نقاطًا');

        $issued = CustomerInvoices::issue($draft, $this->owner->id);
        $this->assertEquals(140.0, (float) $issued->total);
        $this->assertSame(0, (int) $this->maryam->fresh()->points);
        $this->assertSame(1, PointTransaction::where('business_id', $this->shop->id)->where('type', 'redeem')->count());

        // وإصدارٌ ثانٍ لا يصرف ثانيةً
        CustomerInvoices::issue($issued->fresh(), $this->owner->id);
        $this->assertSame(1, PointTransaction::where('business_id', $this->shop->id)->where('type', 'redeem')->count());
    }

    public function test_price_and_manual_discount_changes_keep_the_manual_and_the_points_discount_apart(): void
    {
        $draft = $this->draft();

        $this->edit($draft, $this->form($draft, ['unit_price' => 60, 'discount' => 5]))->assertSessionHasNoErrors();
        $draft = $draft->fresh('items');
        $item = $draft->items->first();

        // ٢ × ٦٠ = ١٢٠، خصمٌ يدويٌّ خمسة، وخصمُ النقاط عشرة: ١٠٥
        $this->assertEquals([15.0, 10.0, 105.0], [(float) $item->discount, (float) $item->loyalty_discount, (float) $draft->total]);
        $this->assertEquals(5.0, round((float) $item->discount - (float) $item->loyalty_discount, 3), 'اختلط الخصمُ اليدويُّ بخصم النقاط');

        // وحفظٌ ثانٍ بالبنود كما هي لا يضاعف خصمَ النقاط
        $this->edit($draft, $this->form($draft, ['quantity' => 2]))->assertSessionHasErrors();
        $this->assertEquals(105.0, (float) $draft->fresh()->total);
        $this->assertEquals(10.0, $this->shares($draft));
    }

    /* ═══ ٢ · وما لا يجيزه التعديلُ يُردّ ═══ */

    public function test_an_edit_that_no_longer_allows_the_points_is_refused_with_a_clear_message_and_nothing_changes(): void
    {
        $draft = $this->draft();
        $before = $this->state($draft);

        // ١ × ١٠ = ١٠: سقفُ الخمسين بالمئة خمسةُ ريالات — والمسودّةُ اختارت عشرة
        $this->edit($draft, $this->form($draft, ['quantity' => 1, 'unit_price' => 10]))->assertSessionHasErrors();
        $message = (string) collect(session('errors')?->all() ?? [])->implode(' ');
        $this->assertStringContainsString('1000 نقطة', $message);
        $this->assertStringContainsString('ألغِ المسودّة', $message);

        $this->assertSame($before, $this->state($draft));
        // والمعاينةُ تقوله قبل الحفظ
        $plan = CustomerInvoiceEdits::plan($draft->fresh('items', 'orders'), $this->form($draft, ['quantity' => 1, 'unit_price' => 10]), $this->owner);
        $this->assertNotEmpty($plan['blockers']);
    }

    public function test_a_draft_with_points_keeps_its_customer_and_one_without_points_may_change_it(): void
    {
        $draft = $this->draft();
        $other = $this->customer('سارة', 3000);
        $before = $this->state($draft);

        // النقاطُ اختيرت من رصيد مريم — فلا يُنقل خصمُها إلى غيرها، ولا يُصرف من رصيد سارة
        $this->edit($draft, $this->form($draft, [], ['customer_id' => $other->id]))->assertSessionHasErrors();
        $this->assertStringContainsString('لا يُغيَّر عميلُها', (string) collect(session('errors')?->all() ?? [])->implode(' '));
        $this->assertSame($before, $this->state($draft));
        $this->assertSame(3000, (int) $other->fresh()->points);

        // ومسودّةٌ بلا نقاط يتغيّر عميلُها
        $plain = $this->draft(0);
        $this->edit($plain, $this->form($plain, [], ['customer_id' => $other->id]))->assertSessionHasNoErrors();
        $this->assertSame($other->id, (int) $plain->fresh()->customer_id);
    }

    /* ═══ ٣ · لا صرفَ ولا إعادةَ مكرَّران ═══ */

    public function test_cancelling_a_draft_with_points_moves_no_points(): void
    {
        $draft = $this->draft();
        $this->edit($draft, $this->form($draft, ['quantity' => 3]))->assertSessionHasNoErrors();

        CustomerInvoices::cancel($draft->fresh(), 'لم تعد مطلوبة', $this->owner->id);

        $this->assertSame([1000, 0], [(int) $this->maryam->fresh()->points, PointTransaction::where('business_id', $this->shop->id)->count()]);
    }

    public function test_two_edits_of_the_same_version_save_once_and_the_points_stay_put(): void
    {
        $draft = $this->draft();
        $first = $this->form($draft, ['quantity' => 3]);
        $second = $this->form($draft, ['quantity' => 4]);

        $this->edit($draft, $first)->assertSessionHasNoErrors();
        $this->edit($draft, $second)->assertSessionHasErrors();

        $this->assertEquals([140.0, 10.0], [(float) $draft->fresh()->total, $this->shares($draft)]);
        $this->assertSame([1000, 0], [(int) $this->maryam->fresh()->points, PointTransaction::where('business_id', $this->shop->id)->count()]);
    }

    /* ═══ ٤ · والصادرةُ تُصحَّح بإشعارٍ يعيد النقاطَ بنسبته ═══ */

    public function test_correcting_an_issued_invoice_returns_its_points_in_proportion_once(): void
    {
        $issued = CustomerInvoices::issue($this->draft(), $this->owner->id)->fresh('items');
        $this->assertSame(0, (int) $this->maryam->fresh()->points);
        $this->assertEquals(90.0, (float) $issued->total);

        // والشاشةُ تعرض خصمَ البند اليدويَّ وحده، وخصمَ النقاط في سطره
        $shown = $this->get(route('admin.customerInvoices.edit', $issued->id))->assertOk()->viewData('page')['props']['invoice'];
        $this->assertEquals([0.0, ['points' => 1000, 'discount' => 10.0]], [$shown['items'][0]['discount'], $shown['loyalty']]);

        // واحدةٌ بدل اثنتين — ما رُدّ يحمل نصيبَه من خصم النقاط: إشعارٌ بنصف الورقة، فنصفُ النقاط
        $this->edit($issued, $this->correction($issued, ['quantity' => 1]))->assertSessionHasNoErrors();
        $this->assertEquals(45.0, $issued->fresh()->creditedTotal(), 'بقي خصمُ النقاط كاملًا على ما احتُفظ به — فخُصم للعميل مرّتين');
        $this->assertSame(500, (int) $this->maryam->fresh()->points);

        // وحفظٌ لا يغيّر شيئًا لا يعيد نقطة
        $this->edit($issued, $this->correction($issued->fresh('items'), ['quantity' => 1]))->assertSessionHasErrors();
        $this->assertSame(500, (int) $this->maryam->fresh()->points);

        // وتصحيحٌ ثانٍ يقرأ حصّةَ ما بقي من تصحيح الأوّل: السعرُ نصفُه — إشعارٌ بـ٢٢٫٥ ورُبعُ النقاط
        $this->edit($issued, $this->correction($issued->fresh('items'), ['unit_price' => 25]))->assertSessionHasNoErrors();
        $this->assertEquals(67.5, $issued->fresh()->creditedTotal());
        $this->assertSame(750, (int) $this->maryam->fresh()->points);

        // والورقةُ كما صدرت: رقمُها وإجماليُّها
        $this->assertEquals([$issued->number, 90.0], [$issued->fresh()->number, (float) $issued->fresh()->total]);
    }

    public function test_an_issued_line_that_grows_spends_no_new_points(): void
    {
        $issued = CustomerInvoices::issue($this->draft(), $this->owner->id)->fresh('items');

        // ثلاثٌ بدل اثنتين — خصمُ النقاط لا يزيد: إشعارٌ مدينٌ بالزيادة كلِّها
        $this->edit($issued, $this->correction($issued, ['quantity' => 3]))->assertSessionHasNoErrors();
        $this->assertEquals([50.0, 0.0], [$issued->fresh()->debitedTotal(), $issued->fresh()->creditedTotal()]);
        $this->assertSame([0, 1], [(int) $this->maryam->fresh()->points, PointTransaction::where('business_id', $this->shop->id)->count()]);
    }

    /** نموذجُ التصحيح كما ترسله الشاشة: خصمُ البند اليدويُّ وحده، وحصّةُ النقاط يعيدها الخادم */
    private function correction(CustomerInvoice $invoice, array $line): array
    {
        $lines = array_map(fn ($l) => [
            'key' => $l['key'], 'description' => $l['description'], 'quantity' => (float) $l['quantity'],
            'unit_price' => (float) $l['unit_price'], 'discount' => round((float) $l['discount'] - (float) ($l['loyalty_discount'] ?? 0), 3),
            'tax_rate' => (float) $l['tax_rate'],
        ], CustomerInvoiceEdits::state($invoice)['lines']);
        $lines[0] = array_merge($lines[0], $line);

        return $this->form($invoice, [], ['items' => $lines, 'reason' => 'كُتبت باقتان والمسلَّم واحدة']);
    }
}
