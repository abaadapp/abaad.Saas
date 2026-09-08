<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Support\CustomerInvoices;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سطورُ المستند تُقرأ بترتيبٍ مطلوب لا بترتيبٍ يقع.
 *
 * ═══ العطب ═══
 *
 * تسعُ علاقاتٍ في `app/Models` تردّ سطورَ مستندٍ بلا `ORDER BY`: بنودُ
 * الفاتورة والطلب وأمر الشراء وإشعاري التسليم والاستلام، وسطورُ القيد
 * والمسيرة والوصفة.
 *
 * وSQLite يردّها بترتيب الإدخال فتبدو مرتّبةً بلا طلب — **وPostgreSQL لا
 * يضمن ترتيبًا**، وهو محرّك الإنتاج. فالفاتورةُ المطبوعة والشاشةُ والـPDF
 * تعرض البنودَ بترتيبٍ يختلف بين قراءةٍ وأخرى: الأرقامُ صحيحة والسطورُ
 * مبعثرة، ولا خطأَ يقول شيئًا. والعميلُ يقارن ورقتَه بأمر شرائه فلا يجدهما
 * على نسق.
 *
 * ═══ وكيف ظهر ═══
 *
 * لم يظهر في تطويرٍ ولا في سويتةٍ محلّية: كشفه CI على PostgreSQL في اختبارٍ
 * يقرأ **أوّلَ** بندٍ من فاتورةٍ تجمع ثلاثة طلبات، فوجد ثانيها. وهو الصنفُ
 * نفسُه الذي وثّقه DEC-003: «لا يُفترض ترتيبٌ لم يُطلب».
 *
 * وهذا الملفّ يحرس القاعدةَ في موضعين: بالسلوك (فاتورةٌ تُقرأ فتُعيد بنودها
 * كما كُتبت) وبالمسح (لا علاقةَ سطورٍ جديدة تُكتب بلا ترتيب).
 */
class APapersLinesKeepTheirOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * علاقاتُ السطور — ما يُقرأ منها **مستندٌ** سطرًا سطرًا.
     *
     * و`Account::lines` و`BankAccount::lines` و`Product::orderItems` ليست
     * منها: جماعاتٌ تحليليّة تُجمَّع لا تُعرض. و`Account::balance` تكتب
     * `SUM(debit)` عبر العلاقة — و`ORDER BY` مع تجميعٍ بلا `GROUP BY` يرفضه
     * PostgreSQL بـ42803. فغيابُ ترتيبها صوابٌ لا إغفال.
     */
    private const LINE_RELATIONS = [
        'CustomerInvoice.php' => 'items',
        'DeliveryNote.php' => 'items',
        'GoodsReceiptNote.php' => 'items',
        'JournalEntry.php' => 'lines',
        'Order.php' => 'items',
        'PayrollRun.php' => 'lines',
        'PurchaseOrder.php' => 'items',
        'ProductVariant.php' => 'recipeItems',
    ];

    /** والفاتورةُ تردّ بنودها كما كُتبت — لا كما شاءت القاعدة */
    public function test_an_invoice_returns_its_lines_as_written(): void
    {
        $business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($business->id);

        $owner = User::create([
            'business_id' => $business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $customer = Customer::create([
            'business_id' => $business->id, 'name' => 'وزارة التراث', 'phone' => '90000001',
        ]);

        /*
         * وأسماءٌ لا ترتيبَ أبجديًّا لها ولا رقميًّا: لو رتّبها المحرّكُ
         * بالوصف أو بالثمن لظهر الفرق. والعشرةُ تكفي لتجاوز صفحةِ القراءة
         * الواحدة فلا يُخفي الحظُّ العطب.
         */
        $written = ['ياسمين', 'أوركيد', 'ورد جوري', 'زنبق', 'توليب', 'قرنفل', 'نرجس', 'خزامى', 'جربيرا', 'أقحوان'];

        $invoice = CustomerInvoices::create(
            $business->id,
            $customer,
            [],
            collect($written)->map(fn ($n, $i) => [
                'description' => $n, 'quantity' => 1, 'unit_price' => 100 - $i,
            ])->all(),
            $owner->id,
        );

        $this->assertSame(
            $written,
            $invoice->fresh()->items->pluck('description')->all(),
            'بنودُ الفاتورة عادت بغير ترتيبها المكتوب',
        );

        // وقراءةٌ ثانيةٌ من علاقةٍ لا من ذاكرة — ولو أُعيد التحميل مرارًا
        $this->assertSame(
            $written,
            $invoice->items()->get()->pluck('description')->all(),
        );
    }

    /**
     * ولا علاقةَ سطورٍ تُكتب بلا ترتيب.
     *
     * «قائمةٌ تُكتب باليد تنسى التاليَ دائمًا»: تسعٌ نُسيت، والعاشرةُ تُكتب غدًا. والفحصُ يقرأ المصدرَ لأنّ الأثرَ لا يظهر إلّا على
     * PostgreSQL وبحظٍّ — فاختبارُ سلوكٍ وحدَه يمرّ وهو أعمى.
     */
    public function test_no_line_relation_is_left_unordered(): void
    {
        $unordered = [];

        foreach (self::LINE_RELATIONS as $file => $relation) {
            $source = file_get_contents(base_path('app/Models/'.$file));

            $found = preg_match(
                '/public function '.$relation.'\(\): HasMany\s*\{?\s*\n?\s*return \$this->hasMany\([^;]*;/',
                $source,
                $m,
            );

            $this->assertSame(1, $found, "لم يُعثر على علاقة {$file}::{$relation} — أتغيّر اسمُها؟");

            if (! str_contains($m[0], 'orderBy')) {
                $unordered[] = $file.'::'.$relation;
            }
        }

        $this->assertSame([], $unordered, "علاقةُ سطورٍ بلا ترتيب:\n".implode("\n", $unordered));
    }
}
