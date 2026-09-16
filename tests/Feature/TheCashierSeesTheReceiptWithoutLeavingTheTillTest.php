<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الكاشير يرى ورقةَ الزبون ولا يغادر صندوقَه.
 *
 * ═══ ما كان يقع ═══
 *
 * لم يكن في نقطة البيع بابٌ إلى الفاتورة إلّا `receipt.pdf`: ملفٌّ يفتحه
 * المتصفّح بقارئه في لسانٍ آخر. وعلى الآيباد والهاتف يملأ القارئُ الشاشةَ
 * ولا شريطَ ألسنةٍ يُرى — فتختفي شاشةُ البيع، ويقف الكاشير أمام ورقةٍ لا
 * يعرف كيف يرجع منها والزبون واقف.
 *
 * فصار للورقة بابان: هذا يعطي نصَّها لتُرسَم في مكانها، وذاك يبقى كما كان
 * للطباعة والتحميل.
 *
 * ═══ وما يحرسه هذا الملفّ ═══
 *
 * أنّ البابَ الجديد لا يفتح على متجر الجار، وأنّه يعطي الشريطَ الذي يخرج
 * من الطابعة لا ورقةَ A4، وأنّ الوصفة واحدةٌ فلا تفترق المعروضةُ عن
 * المطبوعة، وأنّ البابَ القديم لم يُكسر. والسلوكُ في المتصفّح — أنّ
 * النافذة تُفتح فوق الشاشة وتُغلق عنها — في
 * `tests/js/pos-receipt-preview.test.tsx`.
 */
class TheCashierSeesTheReceiptWithoutLeavingTheTillTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $theirs;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        $this->theirs = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);

        $this->cashier = User::create([
            'business_id' => $this->mine->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
    }

    private function order(int $businessId, string $number): Order
    {
        $order = Order::create([
            'business_id' => $businessId, 'number' => $number,
            'total' => 12.5, 'status' => 'مكتمل', 'is_held' => false, 'ordered_at' => now(),
        ]);

        $order->items()->create([
            'name' => 'وردة حمراء', 'quantity' => 2, 'price' => 6.25, 'total' => 12.5,
        ]);

        return $order->fresh('items');
    }

    public function test_the_till_gets_the_paper_as_text_not_as_a_file(): void
    {
        $this->order($this->mine->id, 'INV-1');

        $res = $this->actingAs($this->cashier)
            ->getJson(route('pos.receipt.paper', 'INV-1'))
            ->assertOk();

        $res->assertJsonStructure(['html', 'size']);
        $this->assertStringContainsString('INV-1', $res->json('html'));
    }

    public function test_it_shows_the_strip_the_till_prints_not_an_a4_sheet(): void
    {
        /*
         * والمقاسُ شريط.
         *
         * صندوقُ البيع يطبع شريطًا حراريًّا، وفاتورةُ A4 بابُها شاشةُ الطلب.
         * ومعاينةٌ تُري ورقةً غيرَ التي تخرج من الطابعة تجعل الكاشير يجيب
         * زبونَه عن ورقةٍ لن يستلمها.
         */
        $this->order($this->mine->id, 'INV-2');

        $size = $this->actingAs($this->cashier)
            ->getJson(route('pos.receipt.paper', 'INV-2'))
            ->json('size');

        $this->assertContains($size, ['80mm', '58mm'], 'المعاينةُ تُري ورقةً لا يطبعها الصندوق');
    }

    public function test_the_preview_and_the_print_share_one_recipe(): void
    {
        /*
         * ولو بُنيت المعاينةُ بيدها لَافترقت عن المطبوعة يومًا: يُضاف رمزُ
         * تقييمٍ إلى المطبوع فلا يبلغ المعروض — ولا يُكتشف إلّا بعد أن يأخذ
         * الزبون ورقته.
         */
        $order = $this->order($this->mine->id, 'INV-3');

        $shown = $this->actingAs($this->cashier)
            ->getJson(route('pos.receipt.paper', 'INV-3'))
            ->json('html');

        $printed = PdfController::saleHtml($this->mine->id, $order, thermal: true);

        $this->assertSame($printed['html'], $shown);
    }

    public function test_a_cashier_cannot_preview_another_businesses_receipt(): void
    {
        $this->order($this->theirs->id, 'INV-JAR');

        // ‏404 لا 403: إيصالُ الجار لا يوجد عندنا، ولا يُقال «موجودٌ وممنوع»
        $this->actingAs($this->cashier)
            ->getJson(route('pos.receipt.paper', 'INV-JAR'))
            ->assertNotFound();
    }

    public function test_a_number_that_never_existed_is_not_found(): void
    {
        $this->actingAs($this->cashier)
            ->getJson(route('pos.receipt.paper', 'INV-NOPE'))
            ->assertNotFound();
    }

    public function test_a_guest_gets_no_paper(): void
    {
        $this->order($this->mine->id, 'INV-4');

        $this->get(route('pos.receipt.paper', 'INV-4'))->assertRedirect();
    }

    public function test_the_old_print_door_still_opens(): void
    {
        // والبابُ الجديد بابٌ ثانٍ: الطباعةُ والتحميلُ ما زالا على هذا
        $this->order($this->mine->id, 'INV-5');

        $this->actingAs($this->cashier)
            ->get(route('pos.receipt.pdf', 'INV-5'))
            ->assertOk();
    }
}
