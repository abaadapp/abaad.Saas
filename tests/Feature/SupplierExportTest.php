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
}
