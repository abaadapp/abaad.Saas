<?php

namespace Tests\Feature;

use App\Console\Commands\BackupRun;
use App\Http\Controllers\Admin\BusinessArchiveController;
use App\Jobs\BuildBusinessArchive;
use App\Models\Branch;
use App\Models\Business;
use App\Models\BusinessArchive;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceItem;
use App\Models\Expense;
use App\Models\Order;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Setting;
use App\Models\User;
use App\Support\Archive\Archives;
use App\Support\Archive\Builder;
use App\Support\Archive\Period;
use App\Support\Archive\Policy;
use App\Support\Demo;
use App\Support\Permissions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use ZipArchive;

/**
 * الأرشيفُ الشهريّ — ما يُقرأ، لا ما يُستعاد.
 *
 * والحرّاسُ هنا يقيسون ثلاثةً لا يُرى أحدُها بالعين:
 *
 *  ١) **العزل**: أرشيفُ متجرٍ لا يحمل صفَّ جاره ولا مرفقَه، ولا يُنزّله.
 *  ٢) **صدقُ الحال**: «جاهز» لا تُكتب إلّا على ملفٍّ تامّ، و«فشل» تُكتب
 *     بسببٍ يُقرأ، والملفُّ الناقص يُحذف.
 *  ٣) **الحدود**: شهرٌ مُغلق، بحدَّين لا يُسقطان ما وقع في آخر دقيقةٍ منه
 *     ولا يجرّان ما وقع في أوّل دقيقةٍ بعده.
 *
 * ولا يُفحص شكلُ الورقة: عرضُ عمودٍ أو لونُ رأسٍ يتبدّلان بلا أن يُخطئ
 * شيء، واختبارٌ يقيسهما يسقط كلّما حُسّن التصميم فيُحذف بعد مرّتين.
 */
class AShopKeepsAReadableCopyOfItsMonthTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $neighbour;

    private Branch $branch;

    /** أغسطس ٢٠٢٦ — شهرٌ مُغلق ما دام «الآن» في سبتمبر */
    private Period $period;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow(Carbon::create(2026, 9, 5, 10, 0, 0));

        /*
         * واللغةُ تُثبَّت عربيّةً — ولغةُ المجموعة إنجليزيّة.
         *
         * الرسائلُ مكتوبةٌ بالعربية في المصدر ولها ترجمةٌ في `lang/en.json`،
         * فحارسٌ يقرأ نصًّا يقرأ المترجَمَ لا الأصل — ويسقط يوم تُصحَّح
         * صياغةٌ إنجليزيّة لا علاقةَ لها بما يحرسه.
         *
         * وقد وقع ذلك في أوّل تشغيل: أربعةُ حرّاسٍ سقطوا لأنّ الرسالة خرجت
         * إنجليزيّة — وهو في ذاته دليلٌ أنّ الترجمة موصولة.
         */
        $this->app->setLocale('ar');

        $this->period = Period::of(2026, 8);

        $this->shop = Business::create(['name' => 'متجر الأرشيف', 'type' => 'عام', 'status' => 'نشط']);
        $this->neighbour = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);

        $this->sale($this->shop->id, 'ORD-1', '2026-08-15 12:00', 100);
        $this->sale($this->neighbour->id, 'ORD-N', '2026-08-15 12:00', 999);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ============================ ما يُبنى ============================ */

    public function test_a_closed_month_becomes_a_zip_that_opens(): void
    {
        $archive = $this->build();

        $this->assertSame(BusinessArchive::READY, $archive->status, (string) $archive->failure_reason);
        $this->assertNotNull($archive->storage_path);
        $this->assertTrue(Storage::disk('local')->exists($archive->storage_path));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->pathOf($archive)) === true, 'الملفّ لا يُفتح ZIP');
        $this->assertGreaterThan(0, $zip->numFiles);
        $zip->close();
    }

    public function test_the_zip_carries_the_sheets_the_month_has_data_for(): void
    {
        $names = $this->namesIn($this->build());

        $this->assertContains('Excel/Sales.xlsx', $names);
        $this->assertContains('README.pdf', $names);
        $this->assertContains('manifest.json', $names);
    }

    /**
     * ورقةٌ بلا صفٍّ لا تُكتب.
     *
     * وملفٌّ فارغٌ برؤوسٍ وحدها يجعل من يفتحه يظنّ بياناتِه ضاعت — وهو أسوأ
     * من غيابه، لأنّ الغيابَ يُسأل عنه والفراغَ يُصدَّق.
     */
    public function test_a_sheet_with_no_rows_is_not_written_at_all(): void
    {
        $names = $this->namesIn($this->build());

        $this->assertNotContains('Excel/Payroll.xlsx', $names);
        $this->assertNotContains('Excel/Supplier-Invoices.xlsx', $names);
    }

    public function test_a_month_with_nothing_in_it_fails_and_says_so(): void
    {
        $archive = $this->build(Period::of(2026, 3));

        $this->assertSame(BusinessArchive::FAILED, $archive->status);
        $this->assertNotNull($archive->failure_reason);
        $this->assertNull($archive->storage_path);
    }

    /* ============================ العزل ============================ */

    public function test_the_sheet_holds_this_shop_and_not_its_neighbour(): void
    {
        $sheet = $this->sheetText($this->build(), 'Excel/Sales.xlsx');

        $this->assertStringContainsString('ORD-1', $sheet);
        $this->assertStringNotContainsString('ORD-N', $sheet);
    }

    public function test_an_attachment_of_another_shop_never_enters_the_archive(): void
    {
        Storage::disk('local')->put('receipts/mine.pdf', 'ورقتي');
        Storage::disk('local')->put('receipts/theirs.pdf', 'ورقةُ الجار');

        $this->expense($this->shop->id, 'receipts/mine.pdf', 'mine.pdf');
        $this->expense($this->neighbour->id, 'receipts/theirs.pdf', 'theirs.pdf');

        /*
         * والقياسُ بالعدّ لا بالبحث عن اسم الجار.
         *
         * الملفُّ يُسمّى بمعرّف صفّه لا باسم مرفقه، فمرفقُ الجار يدخل باسمٍ
         * لا يقول إنّه له. وحارسٌ يبحث عن كلمة «theirs» يمرّ وهو أعمى —
         * وقد مرّ: أثبتت الطفرةُ أنّ رفعَ `business_id` من الاستعلام لا
         * يُسقطه.
         */
        $receipts = array_filter(
            $this->namesIn($this->build()),
            fn ($n) => str_starts_with($n, 'Receipts/Expenses/'),
        );

        $this->assertCount(1, $receipts, 'مرفقُ الجار دخل أرشيفَ هذا المتجر');
    }

    /**
     * مسارٌ يخرج من القرص لا يُفتح — ولو جاء من صفٍّ يملكه المتجر.
     *
     * العمودُ نصّ، والنصُّ قد يحمل `../../../.env` من استيرادٍ قديم. وقراءةُ
     * مسارٍ من عمودٍ ثمّ فتحُه بلا قياسٍ هي الثغرةُ بعينها.
     */
    public function test_a_path_that_climbs_out_of_the_disk_is_refused(): void
    {
        /*
         * والهدفُ ملفٌّ **موجودٌ** خارج القرص — لا مسارٌ لا شيءَ فيه.
         *
         * أوّلُ صياغةٍ لهذا الحارس استعملت `../../../../.env` وهو غيرُ موجود
         * في بيئة الاختبار: فكان يُردّ لأنّه **غيرُ موجود**، لا لأنّه
         * **خارج**. فمرّ الحارسُ وهو لا يقيس الحمايةَ التي يدّعيها — وأثبتت
         * الطفرةُ ذلك: رفعُ فحص الجذر لم يُسقطه.
         */
        $outside = dirname(Storage::disk('local')->path('')).'/سرٌّ-خارج-القرص.txt';
        file_put_contents($outside, 'ما لا يخرج في أرشيفٍ يُنزَّل');

        try {
            $this->expense($this->shop->id, '../'.basename($outside), 'secret.txt');

            $names = $this->namesIn($this->build());
            $receipts = array_filter($names, fn ($n) => str_starts_with($n, 'Receipts/'));

            $this->assertSame([], $receipts, 'ملفٌّ خارج القرص دخل الأرشيف');
        } finally {
            @unlink($outside);
        }
    }

    /** ملفٌّ حُذف من القرص وبقي صفُّه لا يُسقط أرشيفَ سنةٍ كاملة */
    public function test_a_missing_attachment_does_not_sink_the_archive(): void
    {
        $this->expense($this->shop->id, 'receipts/gone.pdf', 'gone.pdf');

        $archive = $this->build();

        $this->assertSame(BusinessArchive::READY, $archive->status, (string) $archive->failure_reason);
    }

    /* ==================== الفاتورةُ بقالبها لا بغيره ==================== */

    /**
     * الفواتيرُ تدخل الأرشيف مرسومةً بالقالب المعتمَد نفسِه.
     *
     * ولا يُقاس شكلُ الورقة هنا — يُقاس أنّ الرسمَ مرّ من
     * `CustomerInvoiceController::paper`، وهي الدالّةُ التي ترسم المعاينةَ
     * وزرَّ الطباعة. فلو بُني قالبٌ ثانٍ «للأرشيف» لَما وُجد ملفٌّ هنا.
     */
    public function test_an_issued_invoice_is_drawn_with_the_live_invoice_template(): void
    {
        $this->invoice($this->shop->id, 'CINV-000001', '2026-08-20');

        $names = $this->namesIn($this->build());

        $this->assertContains('Invoices/Customer/CINV-000001.pdf', $names);
    }

    public function test_the_invoice_template_is_the_one_the_screen_uses(): void
    {
        /*
         * وحارسُ المصدر: لا قالبَ فاتورةٍ ثانٍ يُبنى.
         *
         * البناءُ ينادي `CustomerInvoiceController::paper` نصًّا. ولو استُبدلت
         * بعرضٍ من عند الأرشيف لَما كُشف ذلك بفحص ملفّ PDF — فيُقرأ المصدر.
         */
        $source = file_get_contents(app_path('Support/Archive/Builder.php'));

        $this->assertStringContainsString('CustomerInvoiceController::paper(', $source);
        $this->assertStringNotContainsString("view('documents/v1", $source);
    }

    /* ============================ الحدود ============================ */

    public function test_the_last_minute_of_the_month_is_inside_and_the_first_of_the_next_is_out(): void
    {
        $this->sale($this->shop->id, 'ORD-EDGE-IN', '2026-08-31 23:59:59', 5);
        $this->sale($this->shop->id, 'ORD-EDGE-OUT', '2026-09-01 00:00:00', 5);

        $sheet = $this->sheetText($this->build(), 'Excel/Sales.xlsx');

        $this->assertStringContainsString('ORD-EDGE-IN', $sheet);
        $this->assertStringNotContainsString('ORD-EDGE-OUT', $sheet);
    }

    public function test_december_rolls_back_into_the_previous_year(): void
    {
        Carbon::setTestNow(Carbon::create(2027, 1, 3, 4, 0, 0));

        $previous = Period::previous();

        $this->assertSame(2026, $previous->year);
        $this->assertSame(12, $previous->month);
    }

    /**
     * الحادي والثلاثون من مارس يرجع إلى **فبراير** لا إلى مارس.
     *
     * و`subMonth` عليه تردّ ٣ مارس (لأنّ فبراير لا ٣١ فيه) — فيُؤرشَف مارس
     * مرّتين ولا يُؤرشَف فبراير أبدًا. وهي طفرةٌ تقع مرّةً في السنة وتمرّ
     * صامتة، ولا يكشفها إلّا يومٌ بعينه.
     */
    public function test_a_leap_february_is_not_skipped_from_a_thirty_one_day_month(): void
    {
        Carbon::setTestNow(Carbon::create(2028, 3, 31, 9, 0, 0));

        $previous = Period::previous();

        $this->assertSame(2, $previous->month, 'الشهرُ المنقضي عن ٣١ مارس هو فبراير');
        $this->assertSame(2028, $previous->year);
        $this->assertSame('2028-02-29', $previous->end()->format('Y-m-d'), 'فبراير الكبيسة ٢٩ يومًا');
    }

    public function test_the_current_month_is_refused_because_it_has_not_ended(): void
    {
        $this->expectExceptionMessageMatches('/لم ينتهِ/u');

        Archives::request($this->shop->id, Period::current(), null);
    }

    /* ======================== التكرارُ والوحدانيّة ======================== */

    public function test_two_requests_for_one_month_make_one_archive(): void
    {
        Queue::fake();

        Archives::request($this->shop->id, $this->period, null);
        Archives::request($this->shop->id, $this->period, null);
        Archives::request($this->shop->id, $this->period, null);

        $this->assertSame(1, BusinessArchive::where('business_id', $this->shop->id)->count());
    }

    public function test_the_database_refuses_a_second_row_for_the_same_month(): void
    {
        BusinessArchive::create([
            'business_id' => $this->shop->id, 'year' => 2026, 'month' => 8,
            'status' => BusinessArchive::PENDING,
        ]);

        $this->expectException(QueryException::class);

        BusinessArchive::create([
            'business_id' => $this->shop->id, 'year' => 2026, 'month' => 8,
            'status' => BusinessArchive::PENDING,
        ]);
    }

    public function test_a_failed_archive_is_tried_again_when_asked_again(): void
    {
        Queue::fake();

        $archive = Archives::request($this->shop->id, $this->period, null);
        $archive->update(['status' => BusinessArchive::FAILED, 'failure_reason' => 'سقط']);

        $again = Archives::request($this->shop->id, $this->period, null);

        $this->assertSame(BusinessArchive::PENDING, $again->status);
        $this->assertNull($again->failure_reason);
        $this->assertSame($archive->id, $again->id, 'الإعادةُ تُحيي الصفَّ ولا تُنشئ ثانيًا');
        Queue::assertPushed(BuildBusinessArchive::class);
    }

    /**
     * ووظيفتان للصفّ نفسِه لا تدخلان الطابور معًا.
     *
     * `ShouldBeUnique` تمسك قفلًا باسم الصفّ، فالدفعةُ الثانية تُسقَط ما دام
     * قائمًا. وهي توفيرُ عملٍ لا حارسُ صحّة — الحارسُ فهرسٌ فريدٌ في القاعدة،
     * وهو مقيسٌ فوق. ويُقاس هذا كي لا يُظنّ أنّ الطابور يبني الأرشيف مرّتين.
     */
    public function test_one_row_never_holds_two_jobs_at_once(): void
    {
        Queue::fake();

        Archives::request($this->shop->id, $this->period, null);
        Archives::request($this->shop->id, $this->period, null);

        Queue::assertPushed(BuildBusinessArchive::class, 1);
    }

    /** وظيفةٌ أُعيدت بعد نجاحٍ لا تكتب فوق ملفٍّ سليمٍ يُنزَّل الآن */
    public function test_a_retried_job_does_not_rebuild_a_ready_archive(): void
    {
        $archive = $this->build();
        $before = $archive->completed_at;

        Carbon::setTestNow(Carbon::create(2026, 9, 5, 11, 0, 0));
        (new BuildBusinessArchive($archive->id))->handle();

        $this->assertSame(
            optional($before)->toIso8601String(),
            optional($archive->refresh()->completed_at)->toIso8601String(),
        );
    }

    /* ============================ الأبواب ============================ */

    public function test_the_owner_downloads_his_own_archive(): void
    {
        $archive = $this->build();

        $this->actingAs($this->owner($this->shop))
            ->get(route('admin.archives.download', $archive->id))
            ->assertOk();
    }

    public function test_a_cashier_may_not_download_the_whole_company(): void
    {
        $archive = $this->build();

        $this->actingAs($this->staff($this->shop, 'cashier'))
            ->get(route('admin.archives.download', $archive->id))
            ->assertForbidden();
    }

    public function test_an_employee_granted_the_action_by_name_may_download(): void
    {
        $archive = $this->build();

        $accountant = $this->staff($this->shop, 'accountant');
        $accountant->update(['permissions' => ['settings', Permissions::BUSINESS_EXPORT]]);

        $this->actingAs($accountant)
            ->get(route('admin.archives.download', $archive->id))
            ->assertOk();
    }

    /** والبابُ الآخر يُحرَس كما يُحرَس الأوّل: من لا يُنزّل لا يُنشئ */
    public function test_a_cashier_may_not_ask_for_an_archive_either(): void
    {
        $this->actingAs($this->staff($this->shop, 'cashier'))
            ->post(route('admin.archives.store'), ['period' => '2026-08'])
            ->assertForbidden();

        $this->assertSame(0, BusinessArchive::count());
    }

    public function test_the_owner_may_ask_and_the_row_appears(): void
    {
        $this->actingAs($this->owner($this->shop))
            ->post(route('admin.archives.store'), ['period' => '2026-08'])
            ->assertRedirect();

        $this->assertSame(1, BusinessArchive::where('business_id', $this->shop->id)->count());
    }

    /** وشهرٌ جارٍ يُردّ من الباب برسالةٍ لا بصفٍّ يُكتب */
    public function test_asking_for_the_current_month_through_the_door_changes_nothing(): void
    {
        $this->actingAs($this->owner($this->shop))
            ->post(route('admin.archives.store'), ['period' => '2026-09'])
            ->assertRedirect();

        $this->assertSame(0, BusinessArchive::count());
    }

    /** وصيغةٌ لا تُفهم تُردّ بالتحقّق لا بانفجار */
    public function test_a_malformed_period_is_refused(): void
    {
        foreach (['2026-13', 'abc', '2026-00', '../../etc', '2026'] as $bad) {
            $this->actingAs($this->owner($this->shop))
                ->post(route('admin.archives.store'), ['period' => $bad])
                ->assertSessionHasErrors('period');
        }

        $this->assertSame(0, BusinessArchive::count());
    }

    public function test_a_neighbour_cannot_download_this_shops_archive(): void
    {
        $archive = $this->build();

        $this->actingAs($this->owner($this->neighbour))
            ->get(route('admin.archives.download', $archive->id))
            ->assertNotFound();
    }

    public function test_a_stranger_without_a_session_is_sent_to_login(): void
    {
        $archive = $this->build();

        $this->get(route('admin.archives.download', $archive->id))
            ->assertRedirect(route('login'));
    }

    public function test_an_expired_archive_is_no_longer_served(): void
    {
        $archive = $this->build();
        $archive->update(['expires_at' => now()->subDay()]);

        $this->actingAs($this->owner($this->shop))
            ->get(route('admin.archives.download', $archive->id))
            ->assertNotFound();
    }

    /**
     * والزرُّ والبابُ يقولان الشيءَ نفسه.
     *
     * فحصان لسؤالٍ واحد يفترقان يوم يُبدَّل أحدُهما: يبقى الزرُّ مرسومًا بعد
     * أن أُغلق الباب، أو يُغلق البابُ والزرُّ يدعو إليه.
     */
    public function test_the_button_and_the_door_agree_on_what_is_downloadable(): void
    {
        $archive = $this->build();
        $owner = $this->owner($this->shop);

        foreach ([null, now()->subDay(), now()->addMonth()] as $expiry) {
            $archive->update(['expires_at' => $expiry]);
            $archive->refresh();

            $panel = BusinessArchiveController::panel($this->shop->id, $owner);
            $drawn = $panel['items'][0]['downloadable'];

            $status = $this->actingAs($owner)
                ->get(route('admin.archives.download', $archive->id))->getStatusCode();

            $this->assertSame($drawn, $status === 200, 'الزرُّ يقول غيرَ ما يفعل الباب');
        }
    }

    /* ======================== الاحتفاظُ والتنظيف ======================== */

    public function test_an_expired_archive_loses_its_file_and_keeps_its_row(): void
    {
        $archive = $this->build();
        $path = $archive->storage_path;
        $archive->update(['expires_at' => now()->subDay()]);

        $this->assertSame(1, Archives::prune(12));

        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame(BusinessArchive::EXPIRED, $archive->refresh()->status);
        $this->assertNull($archive->storage_path);
    }

    public function test_retention_of_zero_months_deletes_nothing(): void
    {
        $archive = $this->build();
        $archive->update(['expires_at' => now()->subDay()]);

        $this->assertSame(0, Archives::prune(0));
        $this->assertSame(BusinessArchive::READY, $archive->refresh()->status);
    }

    public function test_the_workspace_is_gone_when_the_build_ends(): void
    {
        $this->build();

        /*
         * والمسارُ من القرص لا من `storage_path` مكتوبًا بيدنا.
         *
         * تحت `Storage::fake` جذرُ القرص غيرُ `storage/app/private` — فكان
         * الحارسُ يسأل عن مجلّدٍ لا يُنشأ أصلًا فيمرّ دائمًا. وأثبتت الطفرةُ
         * ذلك: تعطيلُ المحو لم يُسقطه.
         */
        $this->assertDirectoryDoesNotExist(
            Storage::disk('local')->path('archive-workspace/'.BusinessArchive::first()->id),
        );
    }

    /* ============================ البيان ============================ */

    public function test_the_manifest_fingerprints_every_file_it_lists(): void
    {
        $archive = $this->build();
        $zip = new ZipArchive;
        $zip->open($this->pathOf($archive));

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

        $this->assertSame($this->shop->id, $manifest['business_id']);
        $this->assertSame('2026-08', $manifest['period']);
        $this->assertNotEmpty($manifest['files']);

        foreach ($manifest['files'] as $file) {
            $bytes = $zip->getFromName($file['path']);

            $this->assertNotFalse($bytes, "بيانٌ يذكر ملفًّا ليس في الأرشيف: {$file['path']}");
            $this->assertSame($file['sha256'], hash('sha256', $bytes));
            $this->assertSame($file['size'], strlen($bytes));
        }

        $zip->close();
    }

    public function test_the_row_carries_the_checksum_of_the_zip_itself(): void
    {
        $archive = $this->build();

        $this->assertSame(
            hash_file('sha256', $this->pathOf($archive)),
            $archive->checksum,
        );
        $this->assertSame((int) filesize($this->pathOf($archive)), $archive->file_size);
    }

    /* ======================= صدقُ الحال ======================= */

    /**
     * أرشيفٌ تجاوز السقفَ يسقط ولا يُقَصّ.
     *
     * والقياسُ بملفٍّ يتجاوزه فعلًا لا بحيلةٍ في الإعداد: سقفٌ بميجابايت،
     * وبياناتٌ تُنتج أكبرَ منه. وهذا يُثبت أنّ الفحصَ يقع على **الملفّ بعد
     * ضغطه** لا على تقديرٍ قبله.
     */
    public function test_an_archive_bigger_than_the_ceiling_fails_instead_of_being_cut(): void
    {
        Setting::create(['business_id' => null, 'key' => Policy::MAX_MB, 'value' => '1']);
        $this->fatMonth();

        $archive = $this->build();

        $this->assertSame(BusinessArchive::FAILED, $archive->status);
        $this->assertStringContainsString('تجاوز الحدّ المسموح', (string) $archive->failure_reason);
        $this->assertNull($archive->storage_path);
    }

    public function test_a_failed_build_leaves_no_file_behind(): void
    {
        Setting::create(['business_id' => null, 'key' => Policy::MAX_MB, 'value' => '1']);
        $this->fatMonth();

        $this->build();

        $this->assertSame([], Storage::disk('local')->allFiles('archives'));
    }

    /**
     * حدّا الحجم يُسألان مباشرةً — والأدنى لا يُبلَغ ببناءٍ حقيقيّ.
     *
     * ZIP فيه ورقةُ تعريفٍ لا ينزل تحت نصف كيلوبايت بحال، فالحدُّ الأدنى
     * كان بلا حارس. ونُقل الحكمُ إلى `Policy::sizeVerdict` ليُقاس بعينه.
     */
    public function test_a_stub_file_is_refused_and_a_sane_one_is_not(): void
    {
        $this->assertNotNull(Policy::sizeVerdict(Policy::MIN_BYTES - 1), 'ملفٌّ مبتورٌ يجب أن يُردّ');
        $this->assertNull(Policy::sizeVerdict(Policy::MIN_BYTES), 'ملفٌّ سليمٌ لا يُردّ');
        $this->assertNull(Policy::sizeVerdict(50_000));
    }

    public function test_the_ceiling_is_judged_in_the_same_one_place(): void
    {
        Setting::create(['business_id' => null, 'key' => Policy::MAX_MB, 'value' => '1']);

        $this->assertNull(Policy::sizeVerdict(1024 * 1024));
        $this->assertNotNull(Policy::sizeVerdict(1024 * 1024 + 1));
    }

    /** وسقفُ صفرٍ يعني «بلا سقف» — كما تعنيه مدّةُ احتفاظٍ صفرٌ */
    public function test_a_ceiling_of_zero_means_no_ceiling(): void
    {
        Setting::create(['business_id' => null, 'key' => Policy::MAX_MB, 'value' => '0']);

        $this->assertSame(PHP_INT_MAX, Policy::maxBytes());
        $this->assertSame(BusinessArchive::READY, $this->build()->status);
    }

    public function test_a_dead_job_marks_the_row_failed_so_no_one_waits_forever(): void
    {
        Queue::fake();
        $archive = Archives::request($this->shop->id, $this->period, null);
        $archive->update(['status' => BusinessArchive::PROCESSING]);

        (new BuildBusinessArchive($archive->id))->failed(new \RuntimeException('مات'));

        $this->assertSame(BusinessArchive::FAILED, $archive->refresh()->status);
        $this->assertNotNull($archive->failure_reason);
    }

    /** وسببُ الفشل لا يحمل مساراتِ الخادم إلى شاشةِ تاجر */
    public function test_a_failure_reason_never_leaks_server_paths(): void
    {
        $reason = (string) $this->build(Period::of(2026, 3))->failure_reason;

        $this->assertStringNotContainsString(base_path(), $reason);
        $this->assertStringNotContainsString('/var/', $reason);
        $this->assertStringNotContainsString('.php', $reason);
    }

    /* ======================= الرواتبُ والحسّاس ======================= */

    /**
     * من لا يقرأ المسيرة في الشاشة لا يجدها في ملفّه.
     *
     * والفعلان موجودان منفصلين في النظام أصلًا (`payroll.view`) — فالأرشيف
     * يتبعهما ولا يفتح بابًا خلفيًّا عليهما.
     */
    public function test_payroll_is_left_out_when_the_asker_may_not_read_it(): void
    {
        $sales = $this->staff($this->shop, 'sales');
        $sales->update(['permissions' => ['settings', Permissions::BUSINESS_EXPORT]]);

        $this->payroll();

        $names = $this->namesIn($this->build(null, $sales->id));

        $this->assertNotContains('Excel/Payroll.xlsx', $names);
    }

    public function test_payroll_is_written_for_whoever_may_read_it(): void
    {
        $this->payroll();

        $names = $this->namesIn($this->build(null, $this->owner($this->shop)->id));

        $this->assertContains('Excel/Payroll.xlsx', $names);
    }

    /* ======================= المنصّةُ لا تُكسر ======================= */

    public function test_a_disabled_archive_refuses_and_says_why(): void
    {
        Setting::create(['business_id' => null, 'key' => Policy::ENABLED, 'value' => '0']);

        $this->expectExceptionMessageMatches('/غير مفعّل/u');

        Archives::request($this->shop->id, $this->period, null);
    }

    /**
     * ولا قرصَ بعيدٍ مضبوطٌ اليوم — فيُقال ذلك ولا يُدَّعى غيرُه.
     *
     * واسمٌ لقرصٍ لا وجود له في `filesystems` يُردّ `null`: اسمٌ مكتوب خطأً
     * لا يجوز أن يوقف النسخَ المحلّيَّ الذي يعمل.
     */
    public function test_remote_backup_is_off_until_a_real_disk_is_named(): void
    {
        $this->assertNull(Policy::remoteDisk());
        $this->assertFalse(Policy::backupRemoteEnabled());

        Setting::create(['business_id' => null, 'key' => Policy::REMOTE_DISK, 'value' => 'قرصٌ لا وجود له']);
        Setting::create(['business_id' => null, 'key' => Policy::BACKUP_REMOTE, 'value' => '1']);

        $this->assertNull(Policy::remoteDisk(), 'اسمٌ لا يقابله قرصٌ معرَّف لا يُقبل');
        $this->assertFalse(Policy::backupRemoteEnabled());
    }

    /* ============================ الجرس ============================ */

    /**
     * الأرشيفُ الجاهز يُنبَّه به في الجرس — ولمن يملك تنزيله وحده.
     *
     * والإشعارُ مشتقٌّ من الصفّ لا مخزَّن، فلا شيءَ يحتاج أن يُمحى بعد أن
     * يُقرأ. وقياسُه هنا يمنع بابًا يُدعى إليه ويَردّ بـ٤٠٣.
     */
    public function test_a_ready_archive_reaches_the_bell_of_whoever_may_take_it(): void
    {
        $this->build();

        $this->actingAs($this->owner($this->shop));
        $texts = implode(' ', array_column(Demo::allNotifications(), 'text'));

        $this->assertStringContainsString('أغسطس', $texts);
    }

    public function test_the_bell_stays_silent_for_whoever_may_not_take_it(): void
    {
        $this->build();

        $this->actingAs($this->staff($this->shop, 'cashier'));
        $texts = implode(' ', array_column(Demo::allNotifications(), 'text'));

        $this->assertStringNotContainsString('أرشيف', $texts);
    }

    /** وأرشيفٌ مضت مدّتُه لا يُدعى إليه: بابُه مغلق */
    public function test_an_expired_archive_is_not_announced(): void
    {
        $archive = $this->build();
        $archive->update(['expires_at' => now()->subDay()]);

        $this->actingAs($this->owner($this->shop));
        $texts = implode(' ', array_column(Demo::allNotifications(), 'text'));

        $this->assertStringNotContainsString('أرشيف', $texts);
    }

    /* ==================== النسخةُ التقنيّة لا تُكسر ==================== */

    /**
     * قرصٌ بعيد يسقط لا يُفقد النسخةَ المحلّيّة.
     *
     * وهو أخطرُ ما في هذه الإضافة: رميُ استثناءٍ من النسخ البعيد يجعل
     * الملتقِطَ يحذف المحلّيّة — فينتهي المتجر **بلا نسخةٍ أصلًا** لأنّ
     * مزوّدًا بعيدًا لم يُجب.
     */
    public function test_a_broken_remote_disk_never_costs_the_local_backup(): void
    {
        config(['filesystems.disks.ghost' => ['driver' => 'local', 'root' => '/لا/وجود/له', 'throw' => true]]);
        Setting::create(['business_id' => null, 'key' => Policy::REMOTE_DISK, 'value' => 'ghost']);
        Setting::create(['business_id' => null, 'key' => Policy::BACKUP_REMOTE, 'value' => '1']);

        $this->artisan('backup:run')->assertSuccessful();

        $this->assertNotEmpty(
            array_filter(Storage::disk('local')->allFiles('backups'), fn ($f) => str_ends_with($f, '.json')),
            'سقط النسخُ المحلّيُّ بسبب قرصٍ بعيدٍ لا يعمل',
        );
    }

    /** والبصمةُ تقول كم نُسخ بعيدًا — فيُرى الانقطاعُ ولا يُخفى */
    public function test_the_backup_stamp_records_whether_anything_went_offsite(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $stamp = json_decode(
            (string) Storage::disk('local')->get(BackupRun::STAMP),
            true,
        );

        $this->assertArrayHasKey('offsite_disk', $stamp);
        $this->assertArrayHasKey('offsite_copied', $stamp);
        $this->assertNull($stamp['offsite_disk'], 'لا قرصَ بعيدًا مضبوطًا — فيُقال ذلك');
        $this->assertSame(0, $stamp['offsite_copied']);
    }

    public function test_the_daily_technical_backup_still_runs_beside_the_archive(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $this->assertNotEmpty(Storage::disk('local')->allFiles('backups'));
    }

    /* ============================ أدوات ============================ */

    private function build(?Period $period = null, ?int $userId = null): BusinessArchive
    {
        $archive = BusinessArchive::create([
            'business_id' => $this->shop->id,
            'year' => ($period ?? $this->period)->year,
            'month' => ($period ?? $this->period)->month,
            'status' => BusinessArchive::PENDING,
            'generated_by' => $userId,
        ]);

        (new Builder($archive))->run();

        return $archive->refresh();
    }

    private function pathOf(BusinessArchive $archive): string
    {
        return Storage::disk('local')->path($archive->storage_path);
    }

    /** @return array<int, string> أسماءُ ما في الـZIP */
    private function namesIn(BusinessArchive $archive): array
    {
        if ($archive->storage_path === null) {
            return [];
        }

        $zip = new ZipArchive;
        $zip->open($this->pathOf($archive));

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    /**
     * نصُّ ورقةٍ داخل الـZIP — بالقيم لا بالتنسيق.
     *
     * والقراءةُ بـPhpSpreadsheet لا ببحثٍ في بايتات xlsx: الملفّ مضغوطٌ في
     * ذاته، فبحثٌ نصّيٌّ فيه يُخطئ في الاتّجاهين.
     */
    private function sheetText(BusinessArchive $archive, string $inner): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sheet').'.xlsx';

        $zip = new ZipArchive;
        $zip->open($this->pathOf($archive));
        file_put_contents($tmp, $zip->getFromName($inner));
        $zip->close();

        $book = IOFactory::load($tmp);
        $text = '';

        foreach ($book->getActiveSheet()->toArray(null, true, false, false) as $row) {
            $text .= implode('|', array_map(fn ($c) => (string) $c, $row))."\n";
        }

        $book->disconnectWorksheets();
        @unlink($tmp);

        return $text;
    }

    /**
     * شهرٌ بمبيعاتٍ تتجاوز ورقتُها ميجابايتًا بعد الضغط.
     *
     * ═══ والوزنُ من عرض الصفّ لا من عددها ═══
     *
     * أوّلُ صياغةٍ بلغت الميجابايتَ بثمانيةَ عشرَ ألفَ صفّ — فبنت
     * PhpSpreadsheet ربعَ مليون خليّة، **وأسقطت اختبارًا آخر في المجموعة
     * بنفاد الذاكرة** بعد أن كان يمرّ وحده. واختبارٌ يُسقط جارَه عطبٌ وإن
     * كان هو ناجحًا.
     *
     * فصار الوزنُ في عرض الصفّ: ثلاثةُ حقولٍ طويلةٍ عشوائيّةٍ في ثلاثة آلاف
     * صفّ تبلغ الحجمَ نفسَه بسدس الخلايا.
     *
     * والعشوائيّةُ لا زينة: نصٌّ متكرّرٌ يُضغط في الـZIP إلى لا شيء فلا يبلغ
     * السقفَ أبدًا — فيمرّ الاختبارُ وهو لا يقيس شيئًا. و`base64` أكثفُ من
     * `bin2hex`: الأخيرةُ أربعةُ بتاتٍ في الحرف، فنصفُها هواءٌ يُضغط.
     */
    private function fatMonth(): void
    {
        $rows = [];

        for ($i = 0; $i < 3000; $i++) {
            $rows[] = [
                'business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
                'number' => 'ORD-F'.$i, 'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'total' => 10,
                'payment_method' => base64_encode(random_bytes(150)),
                'status' => 'مكتمل', 'is_held' => false,
                'customer_name' => base64_encode(random_bytes(150)),
                'employee_name' => base64_encode(random_bytes(150)),
                'ordered_at' => '2026-08-15 12:00:00',
                'created_at' => '2026-08-15 12:00:00', 'updated_at' => '2026-08-15 12:00:00',
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            Order::insert($chunk);
        }
    }

    private function sale(int $bid, string $number, string $at, float $total): Order
    {
        return Order::create([
            'business_id' => $bid,
            'branch_id' => $bid === $this->shop->id ? $this->branch->id : null,
            'number' => $number, 'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total,
            'payment_method' => 'نقدي', 'status' => 'مكتمل', 'is_held' => false,
            'ordered_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function expense(int $bid, string $path, string $name): Expense
    {
        return Expense::create([
            'business_id' => $bid, 'type' => 'إيجار', 'description' => 'اختبار',
            'amount' => 10, 'method' => 'نقدي', 'spent_at' => '2026-08-10',
            'attachment' => $path, 'attachment_name' => $name,
        ]);
    }

    private function invoice(int $bid, string $number, string $issuedAt): CustomerInvoice
    {
        $customer = Customer::create(['business_id' => $bid, 'name' => 'عميل', 'created_at' => '2026-07-01']);

        $invoice = CustomerInvoice::create([
            'business_id' => $bid, 'customer_id' => $customer->id, 'number' => $number,
            'status' => CustomerInvoice::ISSUED, 'issued_at' => $issuedAt, 'due_at' => '2026-09-20',
            'currency' => 'OMR', 'subtotal' => 100, 'discount_total' => 0, 'tax_total' => 5, 'total' => 105,
            'customer_name' => 'عميل',
        ]);

        CustomerInvoiceItem::create([
            'customer_invoice_id' => $invoice->id, 'description' => 'خدمة',
            'quantity' => 1, 'unit_price' => 100, 'discount' => 0,
            'tax_rate' => 5, 'tax_amount' => 5, 'line_total' => 105, 'sort_order' => 1,
        ]);

        return $invoice;
    }

    private function payroll(): void
    {
        $run = PayrollRun::create([
            'business_id' => $this->shop->id, 'number' => 'PR-1', 'period' => '2026-08',
            'status' => 'معتمدة', 'gross' => 500, 'deductions' => 0, 'net' => 500,
            'created_at' => '2026-08-28', 'updated_at' => '2026-08-28',
        ]);

        PayrollLine::create([
            'payroll_run_id' => $run->id, 'user_id' => $this->staff($this->shop, 'sales')->id,
            'employee_name' => 'موظّف', 'basic' => 500, 'allowances' => 0,
            'overtime' => 0, 'deductions' => 0, 'net' => 500, 'paid' => false,
        ]);
    }

    private function owner(Business $business): User
    {
        return $this->staff($business, 'admin');
    }

    private function staff(Business $business, string $role): User
    {
        return User::firstOrCreate(
            ['email' => $role.'-'.$business->id.'@test.om'],
            [
                'business_id' => $business->id, 'name' => $role,
                'password' => bcrypt('secret-secret'), 'role' => $role, 'status' => 'نشط',
            ],
        );
    }
}
