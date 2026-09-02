<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Support\Sheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * الملفُّ يُقرأ بترميزه هو — لا بالترميز الذي نتمنّاه.
 *
 * «حفظ باسم ‹CSV›» في إكسل على ويندوز عربيّ يكتب الملفَّ بترميز
 * **Windows-1256** لا UTF-8. وهي أكثرُ طريقةٍ يُخرج بها صاحبُ محلٍّ جردَه.
 *
 * وكان الأثر أسوأ من رسالة خطأ: الاستيراد **ينجح** ولا يقول شيئًا. الترويسة
 * «الاسم,السعر,الكمية» تصل بايتاتٍ لا تُقرأ عربيّةً، فلا يتعرّف الكاشفُ على
 * عمود سعرٍ ولا كميّة — يُسنِد «السعر» إلى «التصنيف» و«الكمية» إلى رمز
 * الصنف، ويعدّ صفَّ العناوين منتجًا. فيدخل المتجرَ صنفٌ اسمه طلاسم بسعر صفر،
 * وتضيع أسعارُ الملفّ كلِّه.
 *
 * والفحص على الأبواب الأربعة: المنتجات والعملاء والموردون وكشف البنك — كلُّها
 * تقرأ من `Sheet` وحدها، فبابٌ يقرأ بنفسه يفوته التصحيح.
 */
class ImportFileEncodingTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /** ملفٌّ كما يحفظه إكسل العربيّ على ويندوز */
    private function windowsFile(string $utf8, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'-'.$name;
        file_put_contents($path, (string) iconv('UTF-8', 'CP1256//IGNORE', $utf8));

        return new UploadedFile($path, $name, null, null, true);
    }

    private function utf8File(string $utf8, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'-'.$name;
        file_put_contents($path, $utf8);

        return new UploadedFile($path, $name, null, null, true);
    }

    /* ------------------------------ الترميز ------------------------------ */

    public function test_a_windows_arabic_file_is_read_as_arabic(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'enc').'.csv';
        file_put_contents($path, (string) iconv('UTF-8', 'CP1256//IGNORE', "الاسم,السعر\nباقة ورد,4.5\n"));

        $this->assertSame('CP1256', Sheet::encoding($path));
        $this->assertSame([['الاسم', 'السعر'], ['باقة ورد', '4.5']], array_map(
            fn ($r) => array_map(fn ($v) => (string) $v, $r),
            Sheet::rows($path),
        ));
    }

    public function test_a_utf8_file_is_left_exactly_as_it_is(): void
    {
        /*
         * ولا يُخمَّن على ملفٍّ سليم: تحويلُ UTF-8 من CP1256 يُخرج طلاسم من
         * نصٍّ صحيح — والعطبُ يُصلَح فيقع عكسُه على الأغلبية.
         */
        $path = tempnam(sys_get_temp_dir(), 'enc').'.csv';
        file_put_contents($path, "الاسم,السعر\nباقة ورد,4.5\n");

        $this->assertSame('UTF-8', Sheet::encoding($path));
        $this->assertSame('باقة ورد', (string) Sheet::rows($path)[1][0]);
    }

    public function test_a_file_with_a_byte_order_mark_is_still_utf8(): void
    {
        // إكسل يكتب «UTF-8 مع BOM» أحيانًا، وهي UTF-8 صحيحة
        $path = tempnam(sys_get_temp_dir(), 'enc').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF"."الاسم,السعر\nباقة ورد,4.5\n");

        $this->assertSame('UTF-8', Sheet::encoding($path));
    }

    /* ------------------------- الأبواب الأربعة ------------------------- */

    public function test_products_import_from_a_windows_file_keeps_names_and_prices(): void
    {
        $this->post(route('admin.products.import.upload'), [
            'file' => $this->windowsFile("الاسم,السعر,الكمية\nباقة ورد,4.500,10\n", 'p.csv'),
        ])->assertRedirect(route('admin.products.import.preview'));

        $this->post(route('admin.products.import.confirm'));

        $product = Product::where('business_id', $this->business->id)->first();

        $this->assertNotNull($product, 'لم يدخل شيء');
        $this->assertSame('باقة ورد', $product->name, 'الاسم دخل طلاسم');
        $this->assertSame('4.500', (string) $product->price, 'السعر ضاع — عمودُه لم يُعرَف');
        $this->assertSame(1, Product::where('business_id', $this->business->id)->count(), 'صفُّ العناوين دخل منتجًا');
    }

    public function test_customers_import_from_a_windows_file_keeps_names(): void
    {
        $this->post(route('admin.customers.import.upload'), [
            'file' => $this->windowsFile("الاسم,الهاتف\nزبونٌ كريم,91234567\n", 'c.csv'),
        ]);
        $this->post(route('admin.customers.import.confirm'));

        $this->assertDatabaseHas('customers', [
            'business_id' => $this->business->id, 'name' => 'زبونٌ كريم',
        ]);
    }

    public function test_suppliers_import_from_a_windows_file_keeps_names(): void
    {
        $this->post(route('admin.suppliers.import.upload'), [
            'file' => $this->windowsFile("الاسم,الهاتف\nمشتل الوادي,91111111\n", 's.csv'),
        ]);
        $this->post(route('admin.suppliers.import.confirm'));

        $this->assertDatabaseHas('suppliers', [
            'business_id' => $this->business->id, 'name' => 'مشتل الوادي',
        ]);
    }

    /* --------------------------- بابٌ واحد للقراءة --------------------------- */

    /**
     * ولا متحكّمَ يفتح الملفَّ بنفسه.
     *
     * أربعةُ أبوابٍ تقرأ ملفّات التجّار، وثلاثةٌ منها كانت تكتب سطر القراءة
     * بيدها. فتصحيحُ الترميز في واحدٍ يترك ثلاثةً على حالها — ولا يظهر ذلك
     * إلّا حين يستورد تاجرٌ عملاءه بعد أن نجح في منتجاته.
     */
    public function test_no_importer_opens_a_file_on_its_own(): void
    {
        $guilty = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Http/Controllers'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (preg_match('/IOFactory::(load|createReaderForFile)\s*\(/', $source)) {
                $guilty[] = $file->getFilename();
            }
        }

        $this->assertSame([], $guilty, 'متحكّمٌ يقرأ ملفًّا بنفسه — يفوته تصحيح الترميز');
    }
}
