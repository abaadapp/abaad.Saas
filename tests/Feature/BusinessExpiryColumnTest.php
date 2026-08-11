<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * جدول الشركات يقول متى ينتهي كلٌّ منها، لا متى سجّل وحده.
 *
 * كان يعرض «الحالة»: تقول «نشط» ولا تقول إن الاشتراك ينتهي بعد يومين. فمن
 * يجب أن يُتّصل به قبل أن يقف صندوقه لا يُعرف إلا بفتح كل ملفٍّ على حدة.
 */
class BusinessExpiryColumnTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = User::create([
            'name' => 'مدير المنصة', 'email' => 'admin@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    private function row(array $attrs): array
    {
        $b = Business::create(array_merge(
            ['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط'],
            $attrs,
        ));
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        $rows = $this->actingAs($this->platform)
            ->get(route('super-admin.businesses.index'))
            ->viewData('page')['props']['businesses'];

        return collect($rows)->firstWhere('id', $b->id);
    }

    public function test_the_row_carries_the_expiry_date(): void
    {
        $row = $this->row(['ends_at' => '2027-03-01']);

        $this->assertSame('2027-03-01', $row['expires']);
    }

    public function test_the_days_left_are_counted_on_the_server(): void
    {
        // الواجهة تلوّن بالرقم ولا تحسبه — حسابُ التواريخ في المتصفّح يتبع
        // منطقة زمنيةً غير منطقة الخادم، فيختلف اليوم عند الحدّ
        $row = $this->row(['ends_at' => now()->addDays(3)->toDateString()]);

        $this->assertSame(3, $row['daysLeft']);
    }

    public function test_an_expired_one_reports_a_negative_number(): void
    {
        $row = $this->row(['ends_at' => now()->subDays(4)->toDateString()]);

        $this->assertLessThan(0, $row['daysLeft']);
    }

    public function test_a_business_without_an_end_date_says_so(): void
    {
        /*
         * لا تاريخ = لا مدّة محدَّدة، ولا يُقفل صاحبها أبدًا. وعرضُ «—» يجعلها
         * تُقرأ كبيانٍ ناقص، فيُفتح ملفّها بحثًا عمّا ليس فيه.
         */
        $row = $this->row(['ends_at' => null]);

        $this->assertNull($row['expires']);
        $this->assertNull($row['daysLeft']);
    }
}
