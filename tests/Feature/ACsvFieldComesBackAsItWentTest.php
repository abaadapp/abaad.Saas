<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حقلٌ في CSV يعود كما خرج — ولو كان فيه شرطةٌ مائلة.
 *
 * ═══ العطب الذي أُغلق ═══
 *
 * `fputcsv` بلا `escape` تستعمل الشرطةَ المائلة حرفَ هروب — وليست من CSV في
 * شيء. فحقلٌ فيه شرطةٌ قبل علامة اقتباس — واسمُ منتجٍ بمقاسٍ بالبوصة يكتبه
 * التاجر هكذا — يُكتب بصورةٍ يقرؤها إكسل ومَن سواه **محرَّفةً**: يُزاح
 * الاقتباسُ إلى آخر الحقل ويختفي حرفٌ.
 *
 * ولا شيء يقول إنّها تبدّلت: الملفُّ يُفتح، والصفوفُ بعددها، والقيمةُ غيرُها.
 * وتقريرُ حالٍ كاذب أسوأ من غياب التقرير.
 *
 * ═══ ولمَ رحلةُ ذهابٍ وعودة ═══
 *
 * حارسٌ يبحث عن `escape:` في المصدر يُخدَع بأيّ كتابةٍ أخرى تفعل الشيءَ نفسه.
 * وهذه الحالات تكتب الحقلَ ثمّ تقرؤه ثمّ تقارن — فتقيس السلوكَ لا الهجاء.
 */
class ACsvFieldComesBackAsItWentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** @return list<list<string>> صفوفُ الملفّ كما يقرؤها من يفتحه */
    private function rowsOf(string $url): array
    {
        $csv = $this->actingAs($this->owner)->get($url)->streamedContent();

        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $csv);

        $rows = [];
        $handle = fopen($path, 'r');
        // ويُقرأ بالقارئ القياسيّ — لا بقارئٍ نضبطه ليوافق ما كتبنا
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        unlink($path);

        return $rows;
    }

    /**
     * اسمٌ فيه شرطةٌ مائلةٌ قبل اقتباس — وهو ما كان يتلف.
     *
     * `مقاس 5\"` اسمٌ يكتبه من يبيع أوانيَ أو شرائط.
     */
    public function test_a_backslash_before_a_quote_survives_the_round_trip(): void
    {
        $name = 'مقاس 5\\" أحمر';

        Customer::create(['business_id' => $this->business->id, 'name' => $name, 'phone' => '91000001']);

        $rows = $this->rowsOf(route('admin.export.customers'));
        $names = array_map(fn ($r) => $r[1] ?? '', $rows);

        $this->assertContains($name, $names,
            'الاسمُ عاد محرَّفًا — الشرطةُ المائلة أكلت حرفًا: '.json_encode($names, JSON_UNESCAPED_UNICODE));
    }

    /** وشرطةٌ في آخر الحقل تعود كما هي */
    public function test_a_trailing_backslash_survives(): void
    {
        $name = 'ملاحظة تنتهي بشرطة\\';

        Customer::create(['business_id' => $this->business->id, 'name' => $name, 'phone' => '91000002']);

        $names = array_map(fn ($r) => $r[1] ?? '', $this->rowsOf(route('admin.export.customers')));

        $this->assertContains($name, $names);
    }

    /** والفاصلةُ داخل الحقل لا تكسر الصفّ إلى عمودين */
    public function test_a_comma_inside_a_field_does_not_split_the_row(): void
    {
        Customer::create([
            'business_id' => $this->business->id,
            'name' => 'الورد, الهدايا', 'phone' => '91000003',
        ]);

        $rows = $this->rowsOf(route('admin.export.customers'));
        $header = count($rows[0]);

        foreach ($rows as $i => $row) {
            $this->assertCount($header, $row, "الصفّ $i انقسم — الفاصلة كسرت الأعمدة");
        }
        $this->assertContains('الورد, الهدايا', array_map(fn ($r) => $r[1] ?? '', $rows));
    }

    /** والاقتباسُ وحدَه يُضاعَف كما يقول المعيار، فيعود واحدًا */
    public function test_a_quote_inside_a_field_survives(): void
    {
        $name = 'محل "الورد"';

        Customer::create(['business_id' => $this->business->id, 'name' => $name, 'phone' => '91000004']);

        $this->assertContains($name, array_map(fn ($r) => $r[1] ?? '', $this->rowsOf(route('admin.export.customers'))));
    }

    /**
     * وعلامةُ الترتيب (BOM) تبقى — بلا هذه تفتح إكسل العربيةَ رموزًا.
     *
     * وتُفحص هنا لأنّها في الدالّة نفسها: من يمسّ سطرَ الكتابة قد يمسّها.
     */
    public function test_the_bom_is_still_written(): void
    {
        Customer::create(['business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '91000005']);

        $csv = $this->actingAs($this->owner)->get(route('admin.export.customers'))->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'اختفت BOM — تفتح إكسل العربيةَ رموزًا');
    }

    /** والعربيّةُ تخرج سليمةً لا مُرمَّزة */
    public function test_arabic_survives(): void
    {
        Customer::create(['business_id' => $this->business->id, 'name' => 'سالم الحارثي', 'phone' => '91000006']);

        $csv = $this->actingAs($this->owner)->get(route('admin.export.customers'))->streamedContent();

        $this->assertTrue(mb_check_encoding($csv, 'UTF-8'));
        $this->assertStringContainsString('سالم الحارثي', $csv);
    }

    /**
     * ولا تحذيرَ إهمالٍ يخرج من تصديرٍ واحد.
     *
     * ═══ ولمَ يُقاس هذا ═══
     *
     * الترويسةُ نصوصٌ عربيّةٌ بلا شرطةٍ ولا اقتباس، فتركُ `escape` فيها لا
     * يُتلف حرفًا — ولا تمسكه رحلةُ الذهاب والعودة. لكنّه يُطلق تحذيرَ
     * PHP 8.4 عند كلّ تصدير، ويصير في PHP 9 سلوكًا آخر.
     *
     * والتحذيرُ نفسُه خطر: لو فُتح `display_errors` يومًا على خادمٍ —
     * وهو سطرٌ واحدٌ في `php.ini` — كُتب نصُّ التحذير **داخل الملفّ**،
     * فيفتح التاجر جدولًا أوّلُ سطرٍ فيه رسالةُ PHP.
     *
     * فيُقاس ما يُقاس: نداءٌ واحدٌ بلا تحذيرٍ واحد.
     */
    public function test_exporting_emits_no_deprecation(): void
    {
        Customer::create(['business_id' => $this->business->id, 'name' => 'سالم', 'phone' => '91000007']);

        $seen = [];
        set_error_handler(
            function (int $no, string $msg) use (&$seen) {
                $seen[] = $msg;

                return true;
            },
            E_DEPRECATED | E_USER_DEPRECATED,
        );

        try {
            $this->actingAs($this->owner)->get(route('admin.export.customers'))->streamedContent();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $seen, 'تصديرٌ واحد أطلق تحذيرًا: '.implode(' | ', $seen));
    }

    /** وتقاريرُ CSV تكتب بالقاعدة نفسها — لا بقاعدةٍ ثانية */
    public function test_report_csv_uses_the_same_rule(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Admin/ReportDownloadController.php'));

        $this->assertStringContainsString("escape: ''", $source,
            'تقاريرُ CSV تُكتب بافتراضِ PHP — فتتلف كما كانت تتلف الجداول');
    }
}
