<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\JobTitle;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * كل تصديرٍ يُنزَّل ملفًّا يفتح — لا ردًّا ناجحًا وحسب.
 *
 * الفحص الموجود يسأل «هل ردّت الصفحة ٢٠٠؟» على قاعدةٍ فارغة، وهو سؤالٌ لا
 * يمسّ التصدير: مسارٌ يبني ملفّ xlsx معطوبًا، أو يردّ صفحة خطأٍ بترويسة
 * ٢٠٠، أو يخرج ملفًّا فارغًا لأن الاستعلام لم يجد شيئًا — كلّها تمرّ.
 *
 * وهذا يفتح الملفّ: PDF يبدأ بـ%PDF، وxlsx أرشيفُ zip يبدأ بـPK، وكلاهما
 * على بياناتٍ حقيقية لا على متجرٍ فارغ. والعطب هنا لا يظهر للتاجر إلا حين
 * يرسل الملفّ إلى محاسبه فيردّه إليه.
 */
class ExportIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $platform;

    private Order $order;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::create([
            'name' => 'متجر الورود', 'type' => 'محل ورود', 'status' => 'نشط',
            'email' => 'shop@abaadapp.om', 'phone' => '+968 90000000',
            'ends_at' => now()->addYear(),
        ]);
        $branch = Branch::create(['business_id' => $business->id, 'name' => 'الخوير']);
        JobTitle::create(['business_id' => $business->id, 'name' => 'مدير', 'role' => 'admin']);
        Currency::create([
            'business_id' => $business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);

        foreach (['vat_rate' => '5', 'vat_number' => 'OM1234567', 'shop_name' => 'متجر الورود'] as $k => $v) {
            Setting::create(['business_id' => $business->id, 'key' => $k, 'value' => $v]);
        }

        $this->owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->platform = User::create([
            'name' => 'مدير المنصة', 'email' => 'admin@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $category = Category::create(['business_id' => $business->id, 'name' => 'باقات']);
        $product = Product::create([
            'business_id' => $business->id, 'category_id' => $category->id,
            'name' => 'باقة ورد أحمر', 'sku' => 'ROSE-01',
            'price' => 12.5, 'cost' => 6, 'quantity' => 20, 'alert_qty' => 5, 'active' => true,
        ]);

        $this->customer = Customer::create([
            'business_id' => $business->id, 'branch_id' => $branch->id,
            'name' => 'أحمد بن سالم', 'phone' => '+968 91111111',
        ]);

        $this->order = Order::create([
            'business_id' => $business->id, 'branch_id' => $branch->id,
            'customer_id' => $this->customer->id, 'customer_name' => $this->customer->name,
            'number' => 'ORD-1001', 'status' => 'مكتمل', 'payment_method' => 'نقدي',
            'subtotal' => 25, 'discount' => 0, 'tax' => 1.25, 'total' => 26.25,
            'ordered_at' => now()->subDay(), 'is_held' => false,
            'employee_name' => 'المالك',
        ]);
        OrderItem::create([
            'order_id' => $this->order->id, 'product_id' => $product->id,
            'name' => $product->name, 'price' => 12.5, 'quantity' => 2, 'total' => 25,
        ]);

        Expense::create([
            'business_id' => $business->id, 'reference' => 'EXP-1001',
            'type' => 'إيجار', 'description' => 'إيجار المحل', 'amount' => 300,
            'spent_at' => now()->subDays(2),
        ]);
    }

    /**
     * كل مسارات التصدير بلا معاملات — تُقرأ من جدول المسارات لا من قائمةٍ تُنسى.
     *
     * ولا تُقرأ في مزوّد بيانات: المزوّدات تُنفَّذ قبل إقلاع التطبيق، فلا واجهة
     * ساكنة ولا مسارات. والحلقة داخل الاختبار تجمع كل ما سقط بدل أن تقف عند
     * أوّله — من يصلح تصديرًا واحدًا يريد أن يعرف البقيّة معه.
     *
     * @return string[]
     */
    private function plainExportRoutes(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true))
            ->map(fn ($r) => $r->uri())
            ->filter(fn (string $uri) => ! str_contains($uri, '{')
                && preg_match('#(export|xlsx|/pdf$|/csv$|-pdf$|exportPdf)#i', $uri))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * الملفّ يُقرأ لا يُصدَّق.
     *
     * الردّ المتدفّق (streamDownload) لا محتوى له حتى يُشغَّل، فقراءة
     * getContent منه تعود فارغة — ويمرّ اختبارٌ لا يفحص شيئًا.
     */
    private function body(TestResponse $res): string
    {
        return $res->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
            ? $res->streamedContent()
            : $res->getContent();
    }

    /**
     * النوع يُقرأ من الردّ لا من شكل المسار.
     *
     * فالمسار يكذب: ‎/admin/export/expenses‎ لا يقول إنه csv، و‎/reports/pdf‎
     * قد يردّ صفحة خطأٍ html بترويسة ٢٠٠. والاسم المعروض على المتصفّح هو ما
     * يقرّر بأيّ برنامجٍ يُفتح الملفّ — فهو ما يجب أن يُطابق محتواه.
     */
    private function kindOf(TestResponse $res): string
    {
        $name = (string) $res->headers->get('content-disposition');
        $type = (string) $res->headers->get('content-type');

        if (preg_match('/\.(xlsx|csv|pdf)/i', $name, $m)) {
            return mb_strtolower($m[1]);
        }

        return match (true) {
            str_contains($type, 'spreadsheetml') => 'xlsx',
            str_contains($type, 'csv') => 'csv',
            str_contains($type, 'pdf') => 'pdf',
            default => $type,
        };
    }

    private function assertRealFile(TestResponse $res, string $uri): void
    {
        $res->assertOk();
        $body = $this->body($res);
        $kind = $this->kindOf($res);

        $this->assertNotSame('', $body, "/{$uri} — ملفّ فارغ");

        match ($kind) {
            // xlsx أرشيف zip: السليم يبدأ بـPK، والمعطوب لا يفتح في Excel
            'xlsx' => $this->more($body, 'PK', 1000, $uri, 'أرشيف xlsx'),
            // BOM أوّل الملفّ هو ما يجعل Excel يقرأ العربية بدل رموزٍ مبعثرة
            'csv' => $this->more($body, "\xEF\xBB\xBF", 20, $uri, 'ملفّ csv'),
            'pdf' => $this->more($body, '%PDF', 1000, $uri, 'ملفّ PDF'),
            default => $this->fail("/{$uri} — نوعٌ لا يُنزَّل ملفًّا: {$kind}"),
        };
    }

    /** يبدأ بما يجب، وأكبر من أن يكون قشرةً فارغة */
    private function more(string $body, string $magic, int $min, string $uri, string $what): void
    {
        $this->assertStringStartsWith($magic, $body, "/{$uri} — ليس {$what} سليمًا");
        $this->assertGreaterThan($min, strlen($body), "/{$uri} — {$what} أصغر من أن يحمل بيانات");
    }

    public function test_every_export_downloads_a_file_that_opens(): void
    {
        $broken = [];

        foreach ($this->plainExportRoutes() as $uri) {
            $as = str_starts_with($uri, 'super-admin') ? $this->platform : $this->owner;

            try {
                $this->assertRealFile($this->actingAs($as)->get('/'.$uri), $uri);
            } catch (\Throwable $e) {
                $broken[] = $e->getMessage();
            }

            /*
             * الردّ يُطلق بعد فحصه: المسح يمرّ على أكثر من ثلاثين ملفًّا،
             * وبعضها يُبنى كاملًا في الذاكرة. وتركُها معلّقة يرفع أرضية
             * العملية فتسقط اختباراتٌ بعده لا علاقة لها به.
             */
            gc_collect_cycles();
        }

        $this->assertSame([], $broken, "تصديرات معطوبة:\n".implode("\n", $broken));
    }

    public function test_the_sweep_actually_covers_the_exports(): void
    {
        /*
         * حارسٌ على الحارس: لو تغيّر شكل المسارات يومًا وصار المرشِّح يستبعد
         * كل شيء، لمرّ الاختبار أعلاه أخضرَ وهو لا يفحص ملفًّا واحدًا — وهو
         * أسوأ من غيابه، لأنه يُطمئن.
         */
        $this->assertGreaterThan(25, count($this->plainExportRoutes()));
    }

    // ————— ما يحتاج معرّفًا —————

    public function test_the_order_pdf_opens(): void
    {
        $this->assertRealFile(
            $this->actingAs($this->owner)->get(route('admin.orders.pdf', $this->order->number)),
            'orders/pdf',
        );
    }

    public function test_the_a4_tax_invoice_opens(): void
    {
        $this->assertRealFile(
            $this->actingAs($this->owner)->get(route('admin.orders.taxInvoice', $this->order->number)),
            'orders/tax-invoice/pdf',
        );
    }

    public function test_the_customer_statement_opens(): void
    {
        $this->assertRealFile(
            $this->actingAs($this->owner)->get(route('admin.customers.statement', $this->customer->id)),
            'customers/statement/pdf',
        );
    }

    // ————— ما وراء الترويسة —————

    public function test_a_second_render_in_the_same_process_still_works(): void
    {
        /*
         * قوالب التقارير حملت يومًا دوالَّ معرَّفةً في أعلى القالب، وهي عامّة
         * على مستوى العملية: فأوّل تصديرٍ ينجح والثاني يسقط بـ«Cannot
         * redeclare». ولا يظهر في المتصفّح — كل طلبٍ عمليةٌ جديدة — بل في
         * عاملِ الطوابير الذي يقتله أوّل تقريرين.
         */
        foreach ([1, 2] as $_) {
            $this->assertRealFile(
                $this->actingAs($this->owner)->get(route('admin.reports.pdf')),
                'reports/pdf',
            );
        }
    }

    public function test_the_filename_reaches_the_browser(): void
    {
        // بلا Content-Disposition يُعرض الملفّ في التبويب باسمٍ عشوائي بدل أن يُحفظ
        $res = $this->actingAs($this->owner)->get(route('admin.products.export.xlsx'));

        $this->assertStringContainsString('attachment', (string) $res->headers->get('content-disposition'));
    }

    public function test_one_store_cannot_export_anothers_numbers(): void
    {
        /*
         * أخطر ما في التصدير: ملفٌّ واحد يحمل كل شيء. فحدّ المتجر لو سقط في
         * استعلامٍ واحدٍ منها لخرجت أرقام الجار في جدولٍ يُرسَل إلى محاسب.
         */
        $other = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $other->id, 'name' => 'فرعهم']);
        Product::create([
            'business_id' => $other->id, 'name' => 'سرٌّ لا يخرج', 'sku' => 'SECRET-1',
            'price' => 999, 'cost' => 1, 'quantity' => 3, 'active' => true,
        ]);

        $body = $this->body($this->actingAs($this->owner)->get(route('admin.export.products')));

        $this->assertStringNotContainsString('SECRET-1', $body);
        $this->assertStringContainsString('ROSE-01', $body);
    }
}
