<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ورقةُ الشراء تقول ما تحفظه، ولا تترك خلفها شيئًا.
 *
 * ═══ سؤالان في ملفٍّ واحد لأنّهما صمتان ═══
 *
 * ما جُمع هنا يشترك في صفةٍ واحدة: **لا أحد يشتكي منه**. لا رسالةَ خطأ ولا
 * صفحةً بيضاء ولا زرًّا لا يُجيب — وما لا يُشتكى منه لا يُكتشف إلّا بحارسٍ
 * يسأل عنه بالاسم.
 *
 *  • **رقمٌ يُرى وغيرُه يُكتب.** حقلُ الضريبة يُفرَّغ لأنّ المورّد غير
 *    مسجَّلٍ ضريبيًّا، فتقول الشاشةُ مئةً ويُحفظ مئةٌ وخمسة. ثمّ يصل سندُ
 *    المورّد بمئةٍ فتردُّه المطابقةُ بمقدار ضريبةٍ لم يفرضها أحد.
 *
 *  • **ملفٌّ لا صفَّ يشير إليه.** أمرٌ يُحذف، أو نموذجٌ يُرسل مرّتين، أو سندٌ
 *    يُمحى — وتبقى الورقةُ على القرص الخاصّ أبدًا. لا تُقرأ لأنّ بابَها يسأل
 *    عن صفٍّ ذهب، ولا تُمحى لأنّ لا أحد يعرف بها. وفيها أسعارُ شرائك.
 *
 *  • **حملٌ ينمو بالصفوف.** استعلامٌ لكلّ سطرٍ في الشاشة: لا يُرى في متجرٍ
 *    جديد، ويخنق من عنده تاريخ. والمقياسُ هنا ليس رقمًا مطلقًا — القاعدةُ
 *    مشتركةٌ مع سائر اللوحة وتتبدّل — بل **ألّا يتغيّر العدد** حين تتضاعف
 *    الصفوف.
 */
class APurchasePaperSaysWhatItSavesAndLeavesNothingBehindTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private Branch $branch;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'مزرعة الورد']);
    }

    /** @param  array<string, mixed>  $over */
    private function order(array $over = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
            'payment_method' => 'نقدي',
            'items' => [['name' => 'ورد', 'cost' => 2, 'quantity' => 3]],
        ], $over);
    }

    private function withVat(string $rate = '5'): void
    {
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => 'vat_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => $this->business->id, 'key' => 'vat_rate'], ['value' => $rate]);
    }

    /* ─────────────── أوّلًا: ما يُرى هو ما يُحفظ ─────────────── */

    /**
     * حقلٌ أفرغه التاجر يعني صفرًا — لا نسبةَ متجره.
     *
     * والشاشةُ ترسل الحقلَ دائمًا، فإفراغُه قولٌ صريح لا سهو.
     */
    public function test_an_emptied_tax_field_means_no_tax(): void
    {
        $this->withVat();

        $this->actingAs($this->owner)->post('/admin/purchases', $this->order([
            'tax_rate' => '',
            'items' => [['name' => 'ورد', 'cost' => 100, 'quantity' => 1]],
        ]))->assertSessionHasNoErrors();

        $po = PurchaseOrder::sole();

        $this->assertSame('0.00', (string) $po->tax_rate, 'فُرضت نسبةُ المتجر على حقلٍ أفرغه التاجر');
        $this->assertSame('0.000', (string) $po->tax);
        $this->assertSame('100.000', (string) $po->total, 'الشاشةُ أرت مئةً وحُفظ غيرُها');
    }

    /**
     * وحقلٌ لم يُرسل أصلًا يبقى على نسبة المتجر.
     *
     * تكاملٌ قديم لا يعرف الحقلَ لا تتبدّل عليه القاعدةُ تحت قدميه — وهذا
     * نصفُ الحارس: بلا هذا الشطر يصير الإصلاحُ كسرًا.
     */
    public function test_an_absent_tax_field_still_falls_back_to_the_shop(): void
    {
        $this->withVat();

        $this->actingAs($this->owner)->post('/admin/purchases', $this->order([
            'items' => [['name' => 'ورد', 'cost' => 100, 'quantity' => 1]],
        ]))->assertSessionHasNoErrors();

        $po = PurchaseOrder::sole();

        $this->assertSame('5.00', (string) $po->tax_rate);
        $this->assertSame('105.000', (string) $po->total);
    }

    /**
     * والمعاينةُ تُرسم بالنسبة التي سيحفظ بها الحفظ.
     *
     * وشحنٌ يُضاف كي يفترق الإجماليُّ عن مجموع البنود: لولاه لكان الرقمان
     * واحدًا، فيقع الحارسُ على «مئة» المكتوبة في سطر المجموع ويقول «وجدتُها»
     * مهما كان الإجماليُّ أسفلَ منها — وحارسٌ يمرّ على المطفأ والمشتعل معًا
     * ليس حارسًا.
     */
    public function test_the_preview_reads_the_tax_field_as_the_save_does(): void
    {
        $this->withVat();

        $paper = [
            'tax_rate' => '',
            'shipping_cost' => 20,
            'items' => [['name' => 'ورد', 'cost' => 100, 'quantity' => 1]],
        ];

        $body = preg_replace('/\s+/u', ' ', (string) $this->actingAs($this->owner)
            ->postJson('/admin/purchases/preview', $paper + ['supplier_id' => $this->supplier->id])
            ->assertOk()->json('html')) ?? '';

        $this->actingAs($this->owner)->post('/admin/purchases', $this->order($paper))
            ->assertSessionHasNoErrors();

        $saved = PurchaseOrder::sole();

        $this->assertSame('120.000', (string) $saved->total);
        $this->assertStringContainsString('120.000', $body, 'الورقةُ لا تحمل الإجماليَّ الذي حُفظ');
        $this->assertStringNotContainsString('126.000', $body, 'الورقةُ حملت ضريبةً لن تُحفظ');
    }

    /* ─────────────── ثانيًا: ولا ورقةَ تبقى بلا صفّ ─────────────── */

    /** أمرٌ يُحذف يأخذ مرفقَه معه — لا الإيصالَ وحدَه */
    public function test_a_deleted_order_takes_its_supplier_quote_with_it(): void
    {
        $this->actingAs($this->owner)->post('/admin/purchases', $this->order([
            'attachment' => UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf'),
        ]));

        $po = PurchaseOrder::sole();
        $this->actingAs($this->owner)->post('/admin/purchases/'.$po->id.'/receipt', [
            'receipt' => UploadedFile::fake()->create('paid.pdf', 10, 'application/pdf'),
        ]);

        $po->refresh();
        $quote = $po->attachment;
        $paid = $po->receipt;

        $this->assertNotNull($quote);
        $this->assertNotNull($paid);

        $this->actingAs($this->owner)->delete('/admin/purchases/'.$po->id);

        $this->assertNull(PurchaseOrder::find($po->id));
        Storage::disk('local')->assertMissing($quote);
        Storage::disk('local')->assertMissing($paid);
    }

    /** ونموذجٌ أُرسل مرّتين لا يترك نسخةً ثانيةً على القرص */
    public function test_a_twice_sent_form_leaves_no_second_copy(): void
    {
        foreach ([1, 2] as $_) {
            $this->actingAs($this->owner)->post('/admin/purchases', $this->order([
                'form_token' => 'one-and-the-same',
                'attachment' => UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf'),
            ]));
        }

        $po = PurchaseOrder::sole();

        $this->assertSame(
            [$po->attachment],
            Storage::disk('local')->allFiles('purchase-orders/'.$this->business->id),
            'بقيت على القرص ورقةٌ لا صفَّ يشير إليها',
        );
    }

    /** وسندُ مورّدٍ يُمحى يأخذ فاتورتَه معه */
    public function test_a_deleted_supplier_invoice_takes_its_paper_with_it(): void
    {
        $this->actingAs($this->owner)->post('/admin/purchases/invoices', [
            'supplier_id' => $this->supplier->id,
            'supplier_ref' => 'INV-1',
            'issued_at' => now()->toDateString(),
            'subtotal' => 10,
            'tax' => 0,
            'attachment' => UploadedFile::fake()->create('bill.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $invoice = SupplierInvoice::sole();
        $paper = $invoice->attachment;
        $this->assertNotNull($paper);
        Storage::disk('local')->assertExists($paper);

        $this->actingAs($this->owner)->delete('/admin/purchases/invoices/'.$invoice->id);

        $this->assertNull(SupplierInvoice::find($invoice->id));
        Storage::disk('local')->assertMissing($paper);
    }

    /* ─────────────── ثالثًا: وحملُ الشاشة لا ينمو بالصفوف ─────────────── */

    private function queriesOn(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    /**
     * سجلُّ المشتريات وقائمةُ الأوامر: العددُ نفسُه لصفَّين ولاثني عشر.
     *
     * والطلبُ الأوّل يُطرح: ما يُذكر مرّةً في الطلب لا يُعدّ مرّتين، فيُقاس
     * على قاعدةٍ ساخنة لا على أوّل لمسة.
     */
    public function test_no_purchase_screen_costs_more_because_it_has_more_rows(): void
    {
        $screens = ['/admin/purchases', '/admin/purchases/orders', '/admin/purchases/invoices'];

        foreach ($screens as $url) {
            $this->queriesOn($url);
        }

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->owner)->post('/admin/purchases', $this->order());
        }

        $few = [];
        foreach ($screens as $url) {
            $few[$url] = $this->queriesOn($url);
        }

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->owner)->post('/admin/purchases', $this->order());
        }

        foreach ($screens as $url) {
            $this->assertSame($few[$url], $this->queriesOn($url), $url.' فتح استعلامًا لكلّ صفّ');
        }
    }

    /**
     * وصفٌّ قديمٌ بلا اسمِ مورّدٍ محفوظ لا يفتح استعلامَه الخاصّ.
     *
     * `supplier_name` لقطةٌ تُكتب منذ هجرةٍ متأخّرة، وما قبلها فارغٌ منها —
     * فلا يظهر العطبُ في متجرٍ جديد، ويظهر عند من له تاريخ وحدَه.
     */
    public function test_an_old_row_without_a_cached_supplier_name_is_read_with_the_rest(): void
    {
        $legacy = fn (int $n) => PurchaseOrder::create([
            'business_id' => $this->business->id, 'branch_id' => $this->branch->id,
            'number' => 'PO-'.(40000 + $n), 'supplier_id' => $this->supplier->id,
            'supplier_name' => null, 'status' => 'مُرسل', 'total' => 5, 'ordered_at' => now(),
        ]);

        for ($i = 0; $i < 6; $i++) {
            $legacy($i);
        }

        $this->queriesOn('/admin/purchases/orders');
        $few = $this->queriesOn('/admin/purchases/orders');

        for ($i = 6; $i < 14; $i++) {
            $legacy($i);
        }

        $this->assertSame($few, $this->queriesOn('/admin/purchases/orders'), 'صفٌّ بلا اسمِ مورّدٍ فتح استعلامَه');
    }

    /**
     * وعددُ الأصناف في السجلّ هو عددُها — لا صفرٌ رخيصُ الثمن.
     *
     * سؤالُ الكلفة أعلاه وسؤالُ القيمة هنا سؤالان: عمودٌ يُقرأ بلا استعلامٍ
     * ويردّ صفرًا يُرضي الأوّلَ ويكذب على الثاني.
     */
    public function test_the_register_says_how_many_lines_each_order_has(): void
    {
        $this->actingAs($this->owner)->post('/admin/purchases', $this->order([
            'items' => [
                ['name' => 'ورد', 'cost' => 2, 'quantity' => 3],
                ['name' => 'أصيص', 'cost' => 1, 'quantity' => 4],
                ['name' => 'شريط', 'cost' => 1, 'quantity' => 5],
            ],
        ]));

        $rows = $this->actingAs($this->owner)->get('/admin/purchases')
            ->assertOk()->viewData('page')['props']['rows'];

        $this->assertSame(3, $rows[0]['items'], 'السجلّ قال '.$rows[0]['items'].' صنفًا من ثلاثة');
    }
}
