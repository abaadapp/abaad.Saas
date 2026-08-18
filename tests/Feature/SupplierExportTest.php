<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تصدير المورّدين، والبحث عنهم من الشريط العلويّ.
 *
 * كان قسم العملاء يُصدَّر بثلاث صيغ والمورّدون بلا صيغةٍ واحدة — وهما قائمتا
 * أسماءٍ وأرقامِ تواصل بالبنية نفسها. وكان البحث الموحّد يعرف من نبيع له ولا
 * يعرف ممّن نشتري.
 */
class SupplierExportTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'status' => 'نشط']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Supplier::create([
            'business_id' => $this->business->id, 'name' => 'مؤسسة النور',
            'phone' => '90000001', 'email' => 'nour@x.om', 'contact_person' => 'سعيد',
        ]);
        Supplier::create([
            'business_id' => $this->business->id, 'name' => 'شركة الخليج', 'phone' => '90000002',
        ]);

        $this->actingAs($this->owner);
    }

    public function test_the_list_exports_as_excel(): void
    {
        $res = $this->get(route('admin.suppliers.export.xlsx'))->assertOk();

        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            $res->headers->get('content-type'),
        );
        $this->assertStringContainsString('suppliers-', $res->headers->get('content-disposition'));
    }

    public function test_the_list_exports_as_pdf(): void
    {
        $res = $this->get(route('admin.suppliers.export.pdf'))->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('content-type'));
    }

    public function test_the_list_exports_as_csv_with_every_supplier(): void
    {
        $csv = $this->get(route('admin.export.suppliers'))->assertOk()->streamedContent();

        $this->assertStringContainsString('مؤسسة النور', $csv);
        $this->assertStringContainsString('شركة الخليج', $csv);
        $this->assertStringContainsString('90000001', $csv);
    }

    /** التصدير لا يتسرّب إلى متجرٍ آخر */
    public function test_it_exports_only_this_business(): void
    {
        $other = Business::create(['name' => 'متجر آخر', 'status' => 'نشط']);
        Supplier::create(['business_id' => $other->id, 'name' => 'مورّد الجيران']);

        $csv = $this->get(route('admin.export.suppliers'))->assertOk()->streamedContent();

        $this->assertStringNotContainsString('مورّد الجيران', $csv);
    }

    /* ----------------------------- البحث العلويّ ----------------------------- */

    private function search(string $q): array
    {
        return $this->getJson(route('admin.search', ['q' => $q]))->assertOk()->json('groups');
    }

    public function test_the_top_search_now_finds_a_supplier(): void
    {
        $groups = collect($this->search('النور'));
        $suppliers = $groups->firstWhere('icon', 'truck');

        $this->assertNotNull($suppliers, 'البحث لا يعرف المورّدين');
        $this->assertSame('مؤسسة النور', $suppliers['items'][0]['label']);
    }

    public function test_it_finds_him_by_phone_and_by_the_person_you_call(): void
    {
        $this->assertNotNull(collect($this->search('90000001'))->firstWhere('icon', 'truck'));
        $this->assertNotNull(collect($this->search('سعيد'))->firstWhere('icon', 'truck'));
    }

    /**
     * الوجهة قائمةُ المورّدين مُرشَّحةً باسمه.
     *
     * لا صفحة لكلّ مورّد، ورابطٌ إلى صفحةٍ لا وجود لها أسوأ من غياب النتيجة.
     */
    public function test_the_result_leads_to_the_list_filtered_by_his_name(): void
    {
        $item = collect($this->search('النور'))->firstWhere('icon', 'truck')['items'][0];

        $this->assertStringContainsString('/suppliers', $item['url']);
        $this->assertStringContainsString(rawurlencode('مؤسسة النور'), $item['url']);
    }

    /** ولا يتجاوز البحثُ صلاحية صاحبه */
    public function test_a_seller_who_cannot_see_suppliers_does_not_read_them_from_the_search(): void
    {
        // «مبيعات» يدخل اللوحة ولا يملك «الموردين» — فالبحث يصمت عنهم
        $seller = User::create([
            'business_id' => $this->business->id, 'name' => 'بائع', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
        ]);

        $groups = $this->actingAs($seller)
            ->getJson(route('admin.search', ['q' => 'النور']))->assertOk()->json('groups');

        $this->assertNull(collect($groups)->firstWhere('icon', 'truck'));
    }

    /* ============================== الاستيراد ============================== */

    /** ملفّ CSV مؤقّت يُرفع كما يرفعه التاجر */
    private function upload(array $lines)
    {
        $path = tempnam(sys_get_temp_dir(), 'sup').'.csv';
        file_put_contents($path, implode("\n", $lines));

        return $this->post(route('admin.suppliers.import.upload'), [
            'file' => new \Illuminate\Http\UploadedFile($path, 'suppliers.csv', 'text/csv', null, true),
        ]);
    }

    private function previewRows(): array
    {
        return $this->get(route('admin.suppliers.import.preview'))
            ->assertOk()->viewData('page')['props']['rows'];
    }

    public function test_the_upload_writes_nothing_until_it_is_confirmed(): void
    {
        $this->upload(['الاسم,الهاتف', 'مصنع الشرق,90000009'])
            ->assertRedirect(route('admin.suppliers.import.preview'));

        $this->assertDatabaseMissing('suppliers', ['name' => 'مصنع الشرق']);

        $this->post(route('admin.suppliers.import.confirm'))
            ->assertRedirect(route('admin.suppliers.index'));

        $this->assertDatabaseHas('suppliers', [
            'business_id' => $this->business->id, 'name' => 'مصنع الشرق', 'phone' => '90000009',
        ]);
    }

    public function test_it_reads_the_header_whatever_the_column_order(): void
    {
        $this->upload(['الهاتف,الاسم,البريد', '90000009,مصنع الشرق,east@x.om']);

        $row = $this->previewRows()[0];

        $this->assertSame('مصنع الشرق', $row['name']);
        $this->assertSame('90000009', $row['phone']);
        $this->assertSame('east@x.om', $row['email']);
    }

    public function test_an_existing_supplier_is_updated_not_duplicated(): void
    {
        $this->upload(['الاسم,الهاتف', 'مؤسسة النور المحدثة,90000001']);

        $this->assertSame('update', $this->previewRows()[0]['status']);

        $this->post(route('admin.suppliers.import.confirm'));

        $this->assertSame(2, Supplier::where('business_id', $this->business->id)->count());
        $this->assertDatabaseHas('suppliers', ['phone' => '90000001', 'name' => 'مؤسسة النور المحدثة']);
    }

    /**
     * أخطرها: عمودٌ غائبٌ لا يُقرأ فراغًا.
     *
     * استيراد قائمة أسماءٍ وأرقام — وهو أكثر ما يُستورد — كان سيمحو بريد كل
     * مورّدٍ طابق واسم مسؤول التواصل معه.
     */
    public function test_a_column_the_file_never_mentions_is_not_wiped(): void
    {
        $this->upload(['الاسم,الهاتف', 'مؤسسة النور,90000001']);
        $this->post(route('admin.suppliers.import.confirm'));

        $supplier = Supplier::where('phone', '90000001')->first();

        $this->assertSame('nour@x.om', $supplier->email);
        $this->assertSame('سعيد', $supplier->contact_person);
    }

    public function test_a_row_without_a_name_and_a_broken_email_are_skipped(): void
    {
        $this->upload(['الاسم,الهاتف,البريد', ',90000010,x@y.om', 'اسم صالح,90000011,ليس بريدًا']);

        $rows = $this->previewRows();

        $this->assertSame('invalid', $rows[0]['status']);
        $this->assertSame('invalid', $rows[1]['status']);

        $this->post(route('admin.suppliers.import.confirm'));
        $this->assertSame(2, Supplier::where('business_id', $this->business->id)->count());
    }

    public function test_a_row_repeated_inside_the_file_is_imported_once(): void
    {
        $this->upload(['الاسم,الهاتف', 'مصنع الشرق,90000009', 'مصنع الشرق,90000009']);

        $rows = $this->previewRows();

        $this->assertSame('new', $rows[0]['status']);
        $this->assertSame('dup_file', $rows[1]['status']);
    }

    public function test_cancelling_leaves_the_database_untouched(): void
    {
        $this->upload(['الاسم,الهاتف', 'مصنع الشرق,90000009']);

        $this->post(route('admin.suppliers.import.cancel'))
            ->assertRedirect(route('admin.suppliers.index'));

        $this->assertDatabaseMissing('suppliers', ['name' => 'مصنع الشرق']);

        // والجلسة أُفرغت: المعاينة بعدها تردّ إلى القائمة لا إلى ملفٍّ معلّق
        $this->get(route('admin.suppliers.import.preview'))
            ->assertRedirect(route('admin.suppliers.index'));
    }

    public function test_an_unsupported_file_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sup').'.txt';
        file_put_contents($path, 'ليس جدولًا');

        $this->post(route('admin.suppliers.import.upload'), [
            'file' => new \Illuminate\Http\UploadedFile($path, 'x.txt', 'text/plain', null, true),
        ])->assertRedirect();

        $this->assertDatabaseMissing('suppliers', ['name' => 'ليس جدولًا']);
    }
}
