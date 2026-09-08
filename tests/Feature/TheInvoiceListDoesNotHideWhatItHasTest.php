<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * القائمةُ لا تُخفي ما عندها.
 *
 * ═══ العطب ═══
 *
 * كانت `limit(300)->get()`. ومتجرٌ يكتب عشرين فاتورةً في الشهر يبلغها في
 * سنة، وبعدها **تختفي أقدمُ فواتيره بلا كلمة**: لا رسالة، ولا صفحةٌ ثانية،
 * ولا رقمٌ يقول «٣٠٠ من ٤٢٠». فيظنّ التاجر أنّ ما يراه كلُّ ما لديه.
 *
 * ═══ وترشيحٌ بعد الجلب أسوأ ═══
 *
 * «المتأخّرة» كانت تُرشَّح في الذاكرة. ومع الصفحات يعني ذلك ترشيحَ عشرين
 * صفًّا من أربعمئة — فتقول الشاشة «لا متأخّرات» ولها عشرون في الصفحة
 * الثالثة. فنزل الترشيحُ إلى القاعدة.
 *
 * ═══ وقاعدتان تقولان الشيء نفسه ═══
 *
 * حالُ السداد تُقرأ في PHP لتُعرض، وتُكتب في SQL لتُرشَّح. وهما موضعان
 * يفترقان يومًا — فيُقابَلان هنا على مصفوفةِ حالاتٍ كاملة.
 */
class TheInvoiceListDoesNotHideWhatItHasTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $ministry;

    private Customer $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->ministry = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة الثقافة', 'customer_type' => 'جهة حكومية',
        ]);
        $this->company = Customer::create([
            'business_id' => $this->business->id, 'name' => 'شركة الواحة', 'customer_type' => 'شركة',
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->actingAs($this->owner);
    }

    /** فاتورةٌ صادرة — بمبلغها وتاريخ استحقاقها وعميلها */
    private function issued(float $total = 100, ?string $due = null, ?Customer $for = null, array $extra = []): CustomerInvoice
    {
        $invoice = CustomerInvoices::create($this->business->id, $for ?? $this->ministry, [
            'due_at' => $due,
        ] + $extra, [
            ['description' => 'توريد', 'quantity' => 1, 'unit_price' => $total],
        ], $this->owner->id);

        return CustomerInvoices::issue($invoice, $this->owner->id);
    }

    private function pay(CustomerInvoice $invoice, float $amount): void
    {
        CustomerPayments::record(
            $this->business->id,
            $invoice->customer,
            $amount,
            ['method' => 'نقدي', 'occurred_at' => now()],
            [$invoice->id => $amount],
            $this->owner->id,
        );
    }

    /* ═══════════════ الترقيم ═══════════════ */

    /** ٢٥ فاتورةً تُعرض على صفحتين — ولا تختفي منها خمس */
    public function test_the_list_is_paginated_and_says_how_many_there_are(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->issued(10 + $i);
        }

        $this->get(route('admin.customerInvoices.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('invoices', 20)
                ->where('pagination.total', 25)
                ->where('pagination.last_page', 2)
                ->where('pagination.current_page', 1)
                ->etc());

        $this->get(route('admin.customerInvoices.index', ['page' => 2]))
            ->assertInertia(fn ($p) => $p->has('invoices', 5)->etc());
    }

    /** والتاجرُ يختار عددَ الصفوف — وما ليس من الخيارات يسقط إلى العشرين */
    public function test_the_page_size_is_chosen_and_a_forged_one_falls_back(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->issued(10 + $i);
        }

        $this->get(route('admin.customerInvoices.index', ['per_page' => 50]))
            ->assertInertia(fn ($p) => $p->has('invoices', 25)->where('pagination.last_page', 1)->etc());

        // ‏٩٩٩٩ ليست خيارًا: من يكتبها في العنوان لا يجرّ الجدولَ كلَّه
        $this->get(route('admin.customerInvoices.index', ['per_page' => 9999]))
            ->assertInertia(fn ($p) => $p->has('invoices', 20)->etc());
    }

    /* ═══════════════ البحث ═══════════════ */

    public function test_search_finds_by_number_by_customer_and_by_purchase_order(): void
    {
        $target = $this->issued(500, null, $this->company, ['po_number' => 'PO-2026-774']);
        $this->issued(80);
        $this->issued(90);

        foreach ([$target->number, 'الواحة', 'PO-2026-774'] as $needle) {
            $this->get(route('admin.customerInvoices.index', ['q' => $needle]))
                ->assertInertia(fn ($p) => $p
                    ->has('invoices', 1)
                    ->where('invoices.0.id', $target->id)
                    ->etc());
        }
    }

    /* ═══════════════ المتأخّرة ═══════════════ */

    /**
     * والمتأخّرةُ تُوجد ولو كانت في الصفحة الثالثة.
     *
     * وهذا ما كان يسقط: خمسٌ وعشرون فاتورةً حديثة تملأ الصفحة الأولى،
     * والمتأخّرةُ أقدمُ منها فتقع خلفها. فترشيحٌ بعد الجلب لا يراها.
     */
    public function test_the_overdue_filter_reaches_beyond_the_first_page(): void
    {
        $late = $this->issued(700, now()->subDays(40)->toDateString());

        for ($i = 0; $i < 25; $i++) {
            $this->issued(10 + $i, now()->addDays(30)->toDateString());
        }

        $this->get(route('admin.customerInvoices.index', ['overdue' => 1]))
            ->assertInertia(fn ($p) => $p
                ->has('invoices', 1)
                ->where('invoices.0.id', $late->id)
                ->where('pagination.total', 1)
                ->etc());
    }

    /* ═══════════════ الاستحقاق ═══════════════ */

    public function test_the_due_date_range_narrows_the_list(): void
    {
        $soon = $this->issued(100, now()->addDays(3)->toDateString());
        $this->issued(100, now()->addDays(90)->toDateString());

        $this->get(route('admin.customerInvoices.index', [
            'due_from' => now()->toDateString(),
            'due_to' => now()->addDays(7)->toDateString(),
        ]))->assertInertia(fn ($p) => $p->has('invoices', 1)->where('invoices.0.id', $soon->id)->etc());
    }

    /* ═══════════════ الحارسُ: القاعدةُ تقول ما تقوله الشاشة ═══════════════ */

    /**
     * ترشيحُ القاعدة يطابق `paymentState()` — على كلّ حال.
     *
     * ثمانيةُ صفوفٍ تغطّي ما يمكن أن تكون عليه ورقة: مسودّة، وملغاة، وغيرُ
     * مدفوعة، وجزئيّة، ومدفوعة، ومتأخّرة، ومتأخّرةٌ سُدّد بعضُها، وأُغلقت
     * بإشعار دائن. ولكلّ حالٍ يُسأل الخادمُ عنها، ويُقابَل جوابُه بمن تقول
     * عنه الشاشة إنّه فيها. فإن افترق التعبيران سقط هذا.
     */
    public function test_the_database_filter_agrees_with_what_the_screen_reads(): void
    {
        CustomerInvoices::create($this->business->id, $this->ministry, [], [
            ['description' => 'مسودّة', 'quantity' => 1, 'unit_price' => 50],
        ], $this->owner->id);

        $cancelled = $this->issued(60);
        CustomerInvoices::cancel($cancelled, 'خطأ', $this->owner->id);

        $this->issued(100, now()->addDays(30)->toDateString());          // غير مدفوعة

        $partial = $this->issued(200, now()->addDays(30)->toDateString());
        $this->pay($partial, 50);                                        // مدفوعة جزئيًا

        $paid = $this->issued(300, now()->addDays(30)->toDateString());
        $this->pay($paid, 300);                                          // مدفوعة

        $this->issued(400, now()->subDays(10)->toDateString());           // متأخرة

        $lateAndPartly = $this->issued(500, now()->subDays(10)->toDateString());
        $this->pay($lateAndPartly, 100);                                 // متأخرة كذلك

        $credited = $this->issued(90, now()->addDays(30)->toDateString());
        CustomerInvoices::creditNote($credited, 90, 0, 'مرتجع', $this->owner->id);

        $all = CustomerInvoice::where('business_id', $this->business->id)->get();

        foreach (['مسودة', 'ملغاة', 'غير مدفوعة', 'مدفوعة جزئيًا', 'مدفوعة', 'متأخرة'] as $state) {
            $expected = $all->filter(fn ($i) => $i->paymentState() === $state)
                ->pluck('id')->sort()->values()->all();

            $got = CustomerInvoice::where('business_id', $this->business->id)
                ->paymentState($state)->orderBy('id')->pluck('id')->all();

            $this->assertSame($expected, $got, "ترشيحُ القاعدة يفترق عن الشاشة في «{$state}»");
        }

        // ولا حالَ بلا صفّ: ثمانيةُ صفوفٍ تغطّي الستّة
        $this->assertSame(8, $all->count());
    }

    /** وحالٌ لا يعرفها الخادم لا تفتح الجدول كلَّه */
    public function test_an_unknown_state_returns_nothing_not_everything(): void
    {
        $this->issued(100);
        $this->issued(200);

        $this->get(route('admin.customerInvoices.index', ['state' => 'مطبوخة']))
            ->assertInertia(fn ($p) => $p->has('invoices', 0)->where('pagination.total', 0)->etc());
    }

    /* ═══════════════ الجدار ═══════════════ */

    public function test_a_neighbours_invoices_never_appear(): void
    {
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        $theirs = Customer::create(['business_id' => $other->id, 'name' => 'عميلهم']);
        CustomerInvoices::issue(CustomerInvoices::create($other->id, $theirs, [], [
            ['description' => 'بند', 'quantity' => 1, 'unit_price' => 999],
        ]));

        $mine = $this->issued(100);

        $this->get(route('admin.customerInvoices.index'))
            ->assertInertia(fn ($p) => $p
                ->has('invoices', 1)
                ->where('invoices.0.id', $mine->id)
                ->where('pagination.total', 1)
                ->etc());
    }
}
