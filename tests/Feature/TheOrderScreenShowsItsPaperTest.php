<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * شاشةُ الطلب قسمان: تفاصيلُه، والورقةُ كما تُطبع.
 *
 * ═══ ولمَ تُحرَس ═══
 *
 * الورقةُ المعروضة والورقةُ المطبوعة **وصفةٌ واحدة** (`PdfController::saleHtml`).
 * ولو بُنيت متغيّراتُ العرض بيدها لصارت نسخةً ثانية: يُضاف رمزُ تقييمٍ إلى
 * المطبوع فلا يبلغ المعروض، أو يُقرأ رقمُ المشتري الضريبيّ في أحدهما دون
 * الآخر — فيرى التاجر ورقةً ويستلم زبونُه غيرَها، وهو خلافٌ لا يُكتشف إلّا
 * بعد أن تُسلَّم الورقة.
 *
 * وهي HTML لا PDF: الرسمُ نصًّا نحوُ سبعةِ أجزاء من مئةٍ من ثمن توليد ملفّ،
 * فلا يُشغَّل محرّكُ طباعةٍ كامل لكلّ من فتح الصفحة ليقرأ حالةَ طلب.
 */
class TheOrderScreenShowsItsPaperTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create([
            'name' => 'زهور الخليج', 'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط',
        ]);
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الفرع الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        Setting::create(['business_id' => $this->business->id, 'key' => 'paper', 'value' => 'A4']);

        $this->order = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $branch->id, 'branch' => $branch->name,
            'number' => 'INV-000001', 'status' => 'مكتمل', 'customer_name' => 'شركة الواحة',
            'employee_name' => 'سالم', 'payment_method' => 'نقدي',
            'subtotal' => 12.5, 'tax' => 0.625, 'total' => 13.125, 'ordered_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $this->order->id, 'name' => 'باقة ورد جوري',
            'price' => 12.5, 'quantity' => 1, 'total' => 12.5,
        ]);
    }

    private function screen(): AssertableInertia
    {
        $response = $this->actingAs($this->owner)
            ->get(route('admin.orders.show', $this->order->number));

        $response->assertOk();

        return $response->viewData('page') ? AssertableInertia::fromTestResponse($response) : throw new \RuntimeException('لا صفحة');
    }

    /** الصفحةُ تحمل الورقةَ مرسومةً ومقاسَها — لا رابطًا يُطلب لاحقًا */
    public function test_the_screen_carries_the_drawn_paper(): void
    {
        $this->screen()
            ->has('paper.html')
            ->has('paper.size')
            ->where('paper.size', 'A4');
    }

    /** وهي HTML لا PDF — فلا يُشغَّل محرّكُ الطباعة لمن جاء يقرأ حالة */
    public function test_the_paper_is_markup_not_a_pdf(): void
    {
        $html = (string) $this->screen()->toArray()['props']['paper']['html'];

        $this->assertStringNotContainsString('%PDF', $html);

        // وهي قطعةٌ لا مستندٌ كامل: `PaperFrame` يضعها في إطارٍ يبنيه بنفسه
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('class="paper"', $html);
        $this->assertStringContainsString('باقة ورد جوري', $html, 'الورقةُ بلا أصنافها');
        $this->assertStringContainsString('INV-000001', $html);
    }

    /**
     * والمعروضةُ هي المطبوعة حرفًا بحرف.
     *
     * وهو الحارسُ الذي يمنع أن تفترق الوصفتان: أيُّ متغيّرٍ يُضاف لأحدهما
     * دون الآخر يُسقط هذا الاختبار في اللحظة.
     */
    public function test_what_is_shown_is_what_is_printed(): void
    {
        $shown = (string) $this->screen()->toArray()['props']['paper']['html'];

        $this->actingAs($this->owner);
        $printed = PdfController::saleHtml(
            (int) $this->business->id,
            $this->order->fresh()->load('items'),
        )['html'];

        $this->assertSame($printed, $shown, 'المعروضةُ تفترق عن المطبوعة');
    }

    /** وشريطُ الطابعة الحراريّة يصل بمقاسه — لا يُقرأ ورقةَ A4 */
    public function test_a_thermal_shop_gets_its_strip(): void
    {
        Setting::where('business_id', $this->business->id)
            ->where('key', 'paper')->update(['value' => '80mm']);

        $this->screen()->where('paper.size', '80mm');
    }
}
