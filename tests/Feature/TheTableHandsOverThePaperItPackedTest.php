<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\DeliveryPaper;
use App\Support\DocumentTemplates;
use App\Support\OrderStatus;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * من يجهّز الشحنة يطبع الورقةَ التي تمشي معها.
 *
 * ═══ العطب ═══
 *
 * لوحةُ التجهيز تنقل الطلبَ إلى «خرج للتوصيل» ولا تطبع شيئًا — ولا رابطَ
 * واحدٌ يخرج من بطاقتها. والورقةُ موجودةٌ وجاهزة: تحمل المستلِمَ فوق المشتري،
 * وأصنافَها بلا أسعار، وخانةَ توقيع. لكنّ بابَها الوحيد كان في صفحة الطلب
 * داخل «المبيعات» — ومسارُها يُشتقّ منه قسمُ `orders`.
 *
 * فمن يملك «التجهيز» وحدَه يفتح لوحته ٢٠٠ ويُردّ عن سندها ٤٠٣. والنتيجةُ أن
 * يطبعها صاحبُ المحلّ بيده لكلّ طلب، أو يمنح المجهِّزَ «المبيعات» — فيفتح له
 * الفواتيرَ والإجماليّاتِ وأرباحَ المحلّ، وهي عينُ ما فُصل قسمُ التجهيز ليمنعه.
 *
 * ═══ وما لا يُخفى عنه لا يُقفل دونه ═══
 *
 * ليس في الورقة ما لا يراه على بطاقته أصلًا: الأصنافُ والمستلِمُ وعنوانُه
 * وموعدُه. والأسعارُ مُطفأةٌ في قالبها افتراضًا — وهو ما يحرسه آخرُ اختبارٍ
 * هنا، فالوعدُ مكتوبٌ في الشاشة («قالبُها يُخفي الأسعار») ووعدٌ بلا حارسٍ
 * يُخلَف يومًا.
 */
class TheTableHandsOverThePaperItPackedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $preparer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create(['name' => 'محل ورد', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);

        // صلاحيّتُه «التجهيز» وحدَها — لا «المبيعات»
        $this->preparer = User::create([
            'business_id' => $this->shop->id, 'name' => 'مجهّز', 'email' => 'p@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'job_title' => 'كاشير',
            'status' => 'نشط', 'permissions' => ['preparation'],
        ]);
    }

    private function order(array $over = []): Order
    {
        $order = Order::create(array_merge([
            'business_id' => $this->shop->id,
            'number' => 'INV-000001',
            'status' => OrderStatus::READY,
            'total' => 25.500,
            'ordered_at' => now(),
            'scheduled_for' => now()->addHours(3),
            'is_held' => false,
            'customer_name' => 'سالم المشتري',
            'recipient_name' => 'نورة المستلِمة',
            'recipient_phone' => '99887766',
            'delivery_address' => 'الخوير · شارع ٣٣',
        ], $over));

        OrderItem::create([
            'order_id' => $order->id, 'name' => 'باقة ورد أحمر',
            'quantity' => 1, 'price' => 25.500, 'total' => 25.500,
        ]);

        return $order;
    }

    /* ══════════════ ١ · البابُ يُفتح لمن يجهّز ══════════════ */

    /** وقسمُه `preparation` — لا «المبيعات» */
    public function test_the_delivery_note_belongs_to_the_table_not_to_the_sales_desk(): void
    {
        $this->assertSame('preparation', Permissions::sectionFromRoute('admin.preparation.deliveryNote'));
    }

    /** فمن يملك «التجهيز» وحدَه يطبعها */
    public function test_a_preparer_prints_the_paper_that_travels_with_the_box(): void
    {
        $order = $this->order();

        $res = $this->actingAs($this->preparer)
            ->get(route('admin.preparation.deliveryNote', $order->number));

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
    }

    /**
     * وصفحةُ الطلب تبقى مغلقةً دونه — فالبابُ الذي فُتح هو بابُ اللوحة.
     *
     * ولولا هذا الاختبار لَمرّ الأوّلُ ولو مُنح المجهِّزُ «المبيعات» كلَّها
     * في الطريق: يُقال إنّ اللوحة فُتحت وقد فُتح ما فُصل عنها.
     */
    public function test_the_sales_screen_stays_shut_to_him(): void
    {
        $order = $this->order();

        $this->actingAs($this->preparer)
            ->get(route('admin.orders.deliveryNote', $order->number))
            ->assertForbidden();

        $this->actingAs($this->preparer)
            ->get(route('admin.orders.index'))
            ->assertForbidden();
    }

    /* ══════════════ ٢ · وحصرُه حصرُ اللوحة نفسِها ══════════════ */

    /** لا يطبع سندَ متجرٍ ليس متجرَه */
    public function test_he_does_not_print_another_shops_paper(): void
    {
        $other = Business::create(['name' => 'محل الجار', 'status' => 'نشط']);
        Order::create([
            'business_id' => $other->id, 'number' => 'INV-000009', 'status' => OrderStatus::READY,
            'total' => 10, 'ordered_at' => now(), 'scheduled_for' => now()->addHour(), 'is_held' => false,
        ]);

        $this->actingAs($this->preparer)
            ->get(route('admin.preparation.deliveryNote', 'INV-000009'))
            ->assertNotFound();
    }

    /** ولا سندَ طلبٍ سُلّم وأُغلق — خرج من لوحته فخرج من متناوله */
    public function test_a_closed_order_leaves_his_reach_with_the_board(): void
    {
        $order = $this->order(['status' => OrderStatus::DELIVERED]);

        $this->actingAs($this->preparer)
            ->get(route('admin.preparation.deliveryNote', $order->number))
            ->assertNotFound();
    }

    /**
     * و«خرج للتوصيل» تبقى في متناوله — فالورقةُ تُطبع بعد الضغطة كما قبلها.
     *
     * لو عُدّت مغلقةً لَصار الزرُّ يُبطل نفسَه: يضغط «خرج للتوصيل» ثمّ يطلب
     * الورقةَ فلا يجدها.
     */
    public function test_the_paper_survives_the_button_that_sends_it_out(): void
    {
        $order = $this->order(['status' => OrderStatus::OUT_FOR_DELIVERY]);

        $this->actingAs($this->preparer)
            ->get(route('admin.preparation.deliveryNote', $order->number))
            ->assertOk();
    }

    /* ══════════════ ٣ · وما فيها هو ما وُعد به ══════════════ */

    /**
     * الورقةُ تخرج بلا أسعار — والوعدُ مكتوبٌ في شاشة الطلب حرفًا.
     *
     * وتُقرأ HTML لا PDF: الثانيةُ مضغوطةٌ لا يُقرأ منها نصّ، فحارسٌ عليها
     * لا يقول شيئًا.
     */
    public function test_the_paper_carries_the_recipient_and_not_the_price(): void
    {
        $order = $this->order();

        // ومن الدالّة نفسِها التي يمرّ بها المسار — لا نسخةٍ تُبنى في الاختبار
        $html = DeliveryPaper::html($this->shop->id, $order->fresh('items'));

        $this->assertStringContainsString('نورة المستلِمة', $html, 'السندُ لا يقول لمن يُسلَّم');
        $this->assertStringContainsString('الخوير', $html, 'السندُ بلا عنوان');
        $this->assertStringContainsString('باقة ورد أحمر', $html);

        $this->assertStringNotContainsString('25.500', $html, 'ثمنُ الهديّة يُقرأ على من استلمها');

        /*
         * ═══ وقالبُها قالبُ «سند التسليم» — لا قالبٌ آخر ═══
         *
         * السطرُ أعلاه وحدَه لا يقوله: ورقةٌ تُبنى بقالب الفاتورة قد تخرج بلا
         * أسعارٍ كذلك، فيُقرأ غيابُها إذنًا وهو صدفة. جُرّبت الطفرةُ
         * (`'delivery'` ← `'sale'`) فنجت، وهو ما كشفه.
         *
         * فيُشعَل **مقبضُ سند التسليم** في إعدادات هذا المتجر: إن تبدّلت
         * الورقةُ فالقالبُ قالبُه، وإن لم تتبدّل فهي تُبنى بقالبٍ لا يملكه
         * التاجرُ من شاشته أصلًا.
         */
        Setting::updateOrCreate(
            ['business_id' => $this->shop->id, 'key' => DocumentTemplates::key('delivery', 'show_prices')],
            ['value' => '1'],
        );

        $withPrices = DeliveryPaper::html($this->shop->id, $order->fresh('items'));

        $this->assertStringContainsString(
            '25.500',
            $withPrices,
            'مقبضُ «سند التسليم» لا يُدير ورقتَه — القالبُ المستعمَل ليس قالبَه',
        );
    }

    /**
     * والبطاقةُ تعرض الزرَّ — فبابٌ لا يُعرض لا يُفتح.
     *
     * كلُّ ما فوق يشهد أنّ المسارَ يعمل ويُحرَس، ولا يشهد أنّ أحدًا يبلغه:
     * اللوحةُ كانت بلا رابطٍ واحد، والمسارُ لو وُجد لَما وصله أحد. فيُقاس
     * وجودُ الرابط في الشاشة كما يُقاس عملُ المسار.
     */
    public function test_the_board_actually_shows_the_button(): void
    {
        $board = file_get_contents(base_path('resources/js/Pages/Admin/Preparation/Index.tsx'));

        $this->assertStringContainsString(
            "route('admin.preparation.deliveryNote'",
            $board,
            'لوحةُ التجهيز بلا رابطٍ إلى سند التسليم',
        );
    }

    /** وبابا الورقة يبنيانها من موضعٍ واحد — فلا تفترق النسختان */
    public function test_both_doors_build_the_very_same_paper(): void
    {
        foreach ([
            'app/Http/Controllers/Admin/PreparationController.php',
            'app/Http/Controllers/Admin/DocumentPrintController.php',
        ] as $file) {
            $this->assertStringContainsString(
                'DeliveryPaper::pdf(',
                file_get_contents(base_path($file)),
                "{$file}: يبني سندَ التسليم بنفسه بدل المصدر الواحد",
            );
        }
    }
}
