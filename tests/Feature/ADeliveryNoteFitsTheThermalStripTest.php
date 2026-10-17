<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\DeliveryPaper;
use App\Support\DocumentTemplates;
use App\Support\InvoiceBranding;
use App\Support\OrderStatus;
use App\Support\Paper;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PdfText;
use Tests\TestCase;

/**
 * سندُ التسليم على شريط طابعة الصندوق — ٥٨ و٨٠.
 *
 * ═══ وما يُثبَت ═══
 *
 *  · الأقسامُ بترتيب من يحمل الورقة: المتجرُ ← «سند تسليم» ← الطلبُ وموعده
 *    ← المستلِم ← الأصناف ← الهديّة ← الاستلام — في الـHTML بالعربيّة،
 *    وفي الـPDF نفسِه بالإنجليزيّة (العربيّةُ المُشكَّلة رموزُ حروفٍ فيه).
 *  · العنوانُ الطويلُ والتعليماتُ الطويلةُ والأسماءُ الطويلةُ تُطبع كاملةً،
 *    وتلتفّ عند الفراغ (`Paper::keepWords`)، والرابطُ الطويلُ ينكسر.
 *  · خمسون صنفًا وأكثر: كلٌّ مرّةً واحدة، والشريطُ بعرضه، وصفحاتُه ≤ ٢ م.
 *  · خياراتُ الطلب المخصَّص الظاهرةُ وحدها، ورسالةُ البطاقة بأسطرها.
 *  · «لا تذكر اسمي»: لا اسمَ مُهدٍ ولا مشترٍ ولا هاتفَه ولا سطرَ «من:».
 *  · ولا مالَ بحال: لا عمودَ سعرٍ ولا إجماليَّ ولا وسيلةَ دفعٍ ولا عملة.
 *  · والبابان يطبعانه لمن يملكهما، ومتجرٌ آخر لا يبلغه.
 */
class ADeliveryNoteFitsTheThermalStripTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private const ADDRESS = 'مسقط، الخوير، شارع الوزارات، مجمع الواحة السكني، مبنى رقم 12، الشقة 403، الطابق الرابع، '
        .'بجانب مسجد السلطان قابوس الصغير ومقابل محطة الوقود، البوابة الزرقاء الثانية على اليمين بعد الدوّار '
        .'https://maps.google.com/?q=23.5880,58.4059&z=17&hl=ar&entry=delivery-note-thermal';

    private const NOTES = 'الرجاء الاتصال قبل الوصول بعشر دقائق، والبوابة الخلفية مفتوحة بعد الساعة الرابعة عصرًا، '
        .'ولا تترك الطلب عند الحارس. إن لم يردّ أحد فاتصل بالرقم الثاني، وإن تأخّرت عن الموعد فأرسل رسالة قبلها، '
        .'والمستلِمة لا تعرف أنّ الطلب هديّة فلا تذكر ذلك ولا اسم المتجر عند الاتصال.';

    private const CARD = "كل عام وأنتِ بخير يا سارة\nأتمنى لكِ سنةً مليئةً بالفرح والنجاح والصحة، وأن تتحقّق كلُّ أحلامك واحدًا بعد الآخر\n\nمع خالص المحبة";

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create(['name' => 'ROSE', 'type' => 'Flowers', 'status' => 'نشط', 'city' => 'Muscat', 'phone' => '+968 9525 9066']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'Khuwair']);
    }

    /** @return array<string, array{int}> */
    public static function widths(): array
    {
        return ['58mm' => [58], '80mm' => [80]];
    }

    private function width(int $mm): void
    {
        DocumentTemplates::save($this->shop->id, 'sale', ['strip' => $mm.'mm']);
    }

    private function order(array $over = [], int $items = 3, ?callable $name = null): Order
    {
        $order = Order::create($over + [
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'branch' => 'Khuwair',
            'number' => 'INV-000777', 'status' => OrderStatus::READY, 'is_held' => false,
            'customer_name' => 'Buyer Person', 'employee_name' => 'Cashier',
            'payment_method' => 'تحويل بنكي', 'payment_status' => 'مدفوع',
            'subtotal' => 98.765, 'discount' => 1.111, 'tax' => 2.222, 'delivery_fee' => 3.333, 'total' => 99.999,
            'ordered_at' => now(), 'scheduled_for' => now()->addDay()->setTime(16, 30),
            'fulfillment_type' => 'delivery',
            'recipient_name' => 'سارة بنت محمد البلوشي', 'recipient_phone' => '+968 9123 4567',
            'delivery_address' => self::ADDRESS, 'delivery_notes' => self::NOTES,
            'is_gift' => true, 'occasion_type' => 'birthday', 'card_message' => self::CARD,
            'sender_name' => 'Ahmed Sender', 'hide_sender' => false,
        ]);

        $name ??= fn (int $i) => 'Item '.$i.' Premium long-stem white roses bouquet with silk ribbon and a handwritten card';
        for ($i = 1; $i <= $items; $i++) {
            OrderItem::create(['order_id' => $order->id, 'name' => $name($i), 'price' => 12.345, 'quantity' => $i % 3 + 1, 'total' => 12.345]);
        }

        OrderItem::create([
            'order_id' => $order->id, 'product_id' => null, 'name' => 'تنسيق خاص', 'price' => 30, 'quantity' => 1, 'total' => 30,
            'custom_details' => ['v' => 2, 'fields' => [
                ['label' => 'اللون', 'values' => [['label' => 'أحمر'], ['label' => 'أبيض']], 'internal' => false],
                ['label' => 'الحجم', 'values' => [['label' => 'كبير']], 'internal' => false],
                ['label' => 'الإضافة', 'values' => [['label' => 'شوكولاتة بلجيكية']], 'internal' => false],
                ['label' => 'تكلفة المورّد', 'values' => [['label' => 'سرّيّ داخليّ']], 'internal' => true],
            ]],
        ]);

        return $order->load('items');
    }

    private function html(Order $order): string
    {
        return DeliveryPaper::strip($this->shop->id, $order);
    }

    /** النصُّ المقروء — بلا وسومٍ ولا أنماط، وفراغاتُه واحدة */
    private static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function pdf(Order $order, string $lang = 'ar'): string
    {
        $was = app()->getLocale();
        app()->setLocale($lang);

        try {
            return (string) DeliveryPaper::stripPdf($this->shop->id, $order)->getContent();
        } finally {
            app()->setLocale($was);
        }
    }

    /**
     * كلٌّ موجودٌ وبعد ما قبله.
     *
     * ونصُّ الـPDF يُقارَن بلا فراغات: الخطُّ العريض يكتب الفراغَ رمزًا خاصًّا
     * (U+E288) لا يعرفه `PdfText`، فـ«Delivery note» في العنوان تُستخرج بلا فراغ —
     * ولولا حذفُه لطابقت «Delivery notes» في قسم المستلِم وبدا ما بعدها في غير موضعه.
     */
    private function assertInOrder(string $text, array $marks, string $case = '', bool $spaceless = false): void
    {
        $squash = fn (string $s) => $spaceless ? (string) preg_replace('/[\s\p{Z}\p{Co}]+/u', '', $s) : $s;
        $text = $squash($text);
        $at = -1;
        foreach ($marks as $mark) {
            $mark = $squash($mark);
            $found = mb_strpos($text, $mark, $at + 1);
            $this->assertNotFalse($found, "{$case}: «{$mark}» missing or out of order");
            $at = $found;
        }
    }

    /* ═════════════ الترتيب ═════════════ */

    #[DataProvider('widths')]
    public function test_the_sections_come_in_the_order_the_note_is_read(int $mm): void
    {
        $this->width($mm);
        $text = self::text($this->html($this->order()));

        $this->assertInOrder($text, [
            'ROSE', 'سند تسليم',
            'رقم الطلب', 'INV-000777', 'موعد التسليم', 'نوع التسليم', 'توصيل',
            'بيانات المستلم', 'سارة بنت محمد البلوشي', '+968 9123 4567', 'العنوان', 'ملاحظات التوصيل',
            'الأصناف', 'Item 1 Premium', 'تنسيق خاص',
            'هدية', 'المناسبة', 'عيد ميلاد', 'Ahmed Sender', 'رسالة البطاقة', 'كل عام وأنتِ بخير',
            'اسم المستلم', 'التوقيع', 'تاريخ/وقت الاستلام',
        ], "{$mm}mm");
        $this->assertSame(1, mb_substr_count($text, 'سند تسليم'), 'the title once');

        // والـPDF نفسُه — بالإنجليزيّة لأنّ العربيّةَ المُشكَّلة لا تُقرأ نصًّا منه
        $pages = PdfText::pages($this->pdf($this->order(['number' => 'INV-000778']), 'en'));
        $this->assertCount(1, $pages);
        $this->assertSame((float) $mm, $pages[0]['width']);
        $this->assertInOrder($pages[0]['text'], [
            'ROSE', 'Delivery note', 'Order no.', 'INV-000778', 'Delivery time', 'Delivery type',
            'Recipient details', 'Address', 'Delivery notes',
            'Items', 'Item 1 Premium', 'Item 3 Premium',
            'Gift', 'Occasion', 'From', 'Ahmed Sender', 'Card message',
            'Recipient name', 'Signature', 'Received at',
        ], "{$mm}mm PDF", spaceless: true);
    }

    /* ═════════════ الطويلُ كاملًا، يلتفّ ولا يُقصّ ═════════════ */

    #[DataProvider('widths')]
    public function test_a_long_address_and_long_instructions_print_whole(int $mm): void
    {
        $this->width($mm);
        $order = $this->order();
        $text = self::text($this->html($order));

        $this->assertStringContainsString(self::ADDRESS, $text);
        $this->assertStringContainsString(self::NOTES, $text);

        // كلماتٌ لا تنكسر في وسطها، والرابطُ الطويلُ وحده يُترك للكسر
        $html = $this->html($order);
        $this->assertStringContainsString('<span style="white-space:nowrap">الخوير،</span>', $html);
        $this->assertStringContainsString(' https://maps.google.com/?q=23.5880,58.4059&amp;z=17&amp;hl=ar&amp;entry=delivery-note-thermal', $html);

        $pages = PdfText::pages($this->pdf($order));
        $this->assertCount(1, $pages);
        $this->assertSame((float) $mm, $pages[0]['width']);
        $this->assertStringContainsString('maps.google.com', $pages[0]['text']);
    }

    /** وما يحفظ الكلمات: كلُّ كلمةٍ لا تنكسر، والطويلُ جدًّا ينكسر، والأسطرُ تبقى */
    public function test_words_are_kept_whole_and_lines_are_kept(): void
    {
        $html = Paper::keepWords("سطرٌ أوّل  فيه <b>وسم</b>\nhttps://example.test/a-very-long-path-that-must-be-allowed-to-break");

        $this->assertSame(
            '<span style="white-space:nowrap">سطرٌ</span> <span style="white-space:nowrap">أوّل</span> '
            .'<span style="white-space:nowrap">فيه</span> <span style="white-space:nowrap">&lt;b&gt;وسم&lt;/b&gt;</span>'
            .'<br>https://example.test/a-very-long-path-that-must-be-allowed-to-break',
            $html,
        );
        $this->assertSame('', Paper::keepWords(null));
    }

    #[DataProvider('widths')]
    public function test_long_item_names_many_options_and_fifty_items_print_whole(int $mm): void
    {
        $this->width($mm);
        $order = $this->order(items: 55, name: fn (int $i) => $i % 2
            ? 'Item '.$i.' Premium long-stem white roses bouquet with silk ribbon and a handwritten card'
            : 'صنف '.$i.' باقة ورد جوري أحمر فاخرة مع شريط حريري وبطاقة إهداء مكتوبة باليد');
        $html = $this->html($order);
        $text = self::text($html);

        for ($i = 1; $i <= 55; $i++) {
            $name = $i % 2
                ? 'Item '.$i.' Premium long-stem white roses bouquet with silk ribbon and a handwritten card'
                : 'صنف '.$i.' باقة ورد جوري أحمر فاخرة مع شريط حريري وبطاقة إهداء مكتوبة باليد';
            $this->assertSame(1, substr_count($text, $name), "item {$i} once");
        }

        // والخياراتُ الظاهرةُ كلُّها، والداخليُّ لا يُطبع
        $this->assertStringContainsString('اللون: أحمر · أبيض', $text);
        $this->assertStringContainsString('الحجم: كبير', $text);
        $this->assertStringContainsString('الإضافة: شوكولاتة بلجيكية', $text);
        $this->assertStringNotContainsString('تكلفة المورّد', $text);
        $this->assertStringNotContainsString('سرّيّ داخليّ', $text);

        // عمودان في كلّ صفٍّ: الصنفُ وكميّتُه — لا عمودَ سعرٍ خاويًا
        preg_match('#<table class="items">(.*?)</table>#s', $html, $table);
        preg_match_all('#<tr>(.*?)</tr>#s', $table[1], $rows);
        $this->assertCount(56, $rows[1]);
        foreach ($rows[1] as $row) {
            $this->assertSame(2, substr_count($row, '<td'));
        }
        $this->assertStringNotContainsString('<th', $table[1]);

        // والشريطُ واحدٌ بعرضه، وأوّلُ الأصناف وأوسطُها وآخرُها فيه بترتيبها
        $pages = PdfText::pages($this->pdf($order));
        foreach ($pages as $page) {
            $this->assertSame((float) $mm, $page['width']);
            $this->assertLessThanOrEqual(2000, $page['height']);
        }
        $all = implode(' ', array_column($pages, 'text'));
        $this->assertInOrder($all, ['Item 1 Premium', 'Item 27 Premium', 'Item 55 Premium'], "{$mm}mm PDF", spaceless: true);
    }

    #[DataProvider('widths')]
    public function test_a_long_card_message_keeps_its_lines(int $mm): void
    {
        $this->width($mm);
        $html = $this->html($this->order());

        $this->assertStringContainsString(Paper::keepWords(self::CARD), $html);
        $this->assertSame(3, substr_count(Paper::keepWords(self::CARD), '<br>'), 'three line breaks, the blank line kept');
        foreach (preg_split('/\n/u', self::CARD) as $line) {
            $this->assertStringContainsString($line, self::text($html));
        }
    }

    /* ═════════════ «لا تذكر اسمي» ═════════════ */

    #[DataProvider('widths')]
    public function test_a_hidden_sender_leaves_no_trace_of_the_buyer(int $mm): void
    {
        $this->width($mm);
        $buyer = Customer::create(['business_id' => $this->shop->id, 'name' => 'Ahmed Sender', 'phone' => '+968 9999 0001']);
        $order = $this->order([
            'customer_id' => $buyer->id, 'customer_name' => 'Ahmed Sender',
            'sender_name' => 'Ahmed Sender', 'hide_sender' => true,
        ]);
        $html = $this->html($order);
        $text = self::text($html);

        $this->assertStringNotContainsString('Ahmed Sender', $text);
        $this->assertStringNotContainsString('9999 0001', $text);
        $this->assertStringNotContainsString('<td class="k">'.__('من').'</td>', $html);
        $this->assertStringNotContainsString('المرسل', $text);
        $this->assertStringNotContainsString('المُهدي', $text);
        // والقسمُ باقٍ بما يُقال: المناسبةُ والبطاقة
        $this->assertInOrder($text, ['هدية', 'عيد ميلاد', 'رسالة البطاقة']);

        $pdf = PdfText::pages($this->pdf($order, 'en'))[0]['text'];
        $this->assertStringNotContainsString('From', $pdf);
        $this->assertStringNotContainsString('Ahmed Sender', $pdf);
    }

    /** وهديّةٌ بلا اسم مستلِم لا تستعير اسمَ المشتري ولا هاتفَه */
    public function test_a_gift_never_borrows_the_buyer_as_recipient(): void
    {
        $buyer = Customer::create(['business_id' => $this->shop->id, 'name' => 'Ahmed Sender', 'phone' => '+968 9999 0001']);
        $order = $this->order([
            'customer_id' => $buyer->id, 'customer_name' => 'Ahmed Sender',
            'recipient_name' => null, 'recipient_phone' => null, 'hide_sender' => true,
        ]);
        $text = self::text($this->html($order));

        $this->assertStringNotContainsString('Ahmed Sender', $text);
        $this->assertStringNotContainsString('9999 0001', $text);
        $this->assertStringContainsString(self::ADDRESS, $text);
    }

    /**
     * وبطاقةٌ كُتبت عند الصندوق هديّةٌ كذلك — ولو لم يُعلَّم الطلبُ هديّةً.
     *
     * `is_gift` علمُ صفحة الإهداء في الموقع، والصندوقُ يكتب المناسبةَ والبطاقةَ
     * بلا العلم. فطلبٌ كهذا يحمل قسمَ الهديّة، ولا يستعير المشتريَ مستلِمًا.
     */
    public function test_a_card_written_at_the_till_is_a_gift_too(): void
    {
        $text = self::text($this->html($this->order([
            'is_gift' => false, 'recipient_name' => null, 'recipient_phone' => null, 'customer_name' => 'Buyer Person',
        ])));

        $this->assertInOrder($text, ['هدية', 'عيد ميلاد', 'رسالة البطاقة', 'كل عام وأنتِ بخير']);
        $this->assertStringNotContainsString('Buyer Person', $text);
    }

    /** وبيعةٌ تُوصَل إلى صاحبها: هو المستلِم، و«عميل نقدي» ليس اسمًا */
    public function test_a_plain_delivery_reads_the_customer_as_recipient(): void
    {
        $buyer = Customer::create(['business_id' => $this->shop->id, 'name' => 'Khalid', 'phone' => '+968 9000 1122']);
        $plain = [
            'is_gift' => false, 'occasion_type' => null, 'card_message' => null, 'sender_name' => null,
            'recipient_name' => null, 'recipient_phone' => null,
        ];

        $text = self::text($this->html($this->order($plain + ['customer_id' => $buyer->id, 'customer_name' => 'Khalid'])));
        $this->assertInOrder($text, ['بيانات المستلم', 'Khalid', '+968 9000 1122']);
        $this->assertStringNotContainsString('هدية', $text);

        $walkIn = self::text($this->html($this->order($plain + ['number' => 'INV-000779', 'customer_name' => 'عميل نقدي'])));
        $this->assertStringNotContainsString('عميل نقدي', $walkIn);
    }

    /* ═════════════ ولا مال ═════════════ */

    #[DataProvider('widths')]
    public function test_no_money_reaches_the_recipient(int $mm): void
    {
        $this->width($mm);
        $order = $this->order();
        $text = self::text($this->html($order));

        foreach (['السعر', 'الإجمالي', 'المبلغ', 'المجموع', 'الخصم', 'الضريبة', 'رسوم التوصيل', 'وسيلة الدفع',
            'طريقة الدفع', 'حالة الدفع', 'مدفوع', 'تحويل بنكي', 'نقدي', 'OMR', 'ر.ع', '12.345', '98.765', '99.999', '3.333'] as $money) {
            $this->assertStringNotContainsString($money, $text, "«{$money}» on a delivery note");
        }

        $pdf = PdfText::pages($this->pdf($order, 'en'))[0]['text'];
        foreach (['Price', 'Total', 'Subtotal', 'Discount', 'Tax', 'Payment', 'Paid', 'Bank transfer', 'OMR', '12.345', '99.999'] as $money) {
            $this->assertStringNotContainsString($money, $pdf, "«{$money}» in the PDF");
        }
    }

    /* ═════════════ مقابضُ القالب ═════════════ */

    public function test_the_delivery_template_knobs_reach_the_strip(): void
    {
        Storage::fake('public');
        InvoiceBranding::storeLogo($this->shop, UploadedFile::fake()->image('logo.png', 120, 40));
        $order = $this->order();

        $html = $this->html($order);
        $this->assertStringContainsString('<img src="'.InvoiceBranding::logo($this->shop->id).'"', $html);
        $this->assertStringContainsString('Khuwair', self::text($html));
        $this->assertStringContainsString('التوقيع', self::text($html));

        DocumentTemplates::save($this->shop->id, 'delivery', ['show_logo' => false, 'show_branch' => false, 'show_signature' => false]);
        $html = $this->html($order);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('Khuwair', self::text($html));
        $this->assertStringNotContainsString('التوقيع', self::text($html));
        $this->assertStringNotContainsString('اسم المستلم', self::text($html));
    }

    /* ═════════════ البابان ═════════════ */

    public function test_the_order_page_and_the_preparation_board_both_print_it(): void
    {
        $owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'Owner', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $preparer = User::create([
            'business_id' => $this->shop->id, 'name' => 'مجهّز', 'email' => 'prep@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'cashier', 'job_title' => 'كاشير',
            'status' => 'نشط', 'permissions' => ['preparation'],
        ]);
        $order = $this->order();

        $this->assertSame('preparation', Permissions::sectionFromRoute('admin.preparation.deliveryNoteThermal'));

        foreach ([[$owner, 'admin.orders.deliveryNoteThermal'], [$preparer, 'admin.preparation.deliveryNoteThermal']] as [$user, $route]) {
            $res = $this->actingAs($user)->get(route($route, $order->number));
            $res->assertOk();
            $this->assertSame('application/pdf', $res->headers->get('content-type'), $route);
            // والبابُ يُخرج الشريطَ لا ورقةَ A4: عرضُه عرضُ طابعة الصندوق (٨٠ افتراضًا)
            $this->assertSame(80.0, PdfText::pages((string) $res->getContent())[0]['width'], $route);
        }

        // ومتجرٌ آخر لا يبلغ سندَ هذا
        $other = Business::create(['name' => 'BLOOM', 'type' => 'Flowers', 'status' => 'نشط']);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'Other', 'email' => 'other@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($stranger)->get(route('admin.orders.deliveryNoteThermal', $order->number))->assertNotFound();
    }
}
