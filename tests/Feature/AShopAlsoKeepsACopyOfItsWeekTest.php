<?php

namespace Tests\Feature;

use App\Console\Commands\BackupRun;
use App\Http\Controllers\Admin\BusinessArchiveController;
use App\Jobs\BuildBusinessArchive;
use App\Models\Branch;
use App\Models\Business;
use App\Models\BusinessArchive;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\Archive\Archives;
use App\Support\Archive\Builder;
use App\Support\Archive\Period;
use App\Support\Archive\Policy;
use App\Support\Demo;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * وللمتجر نسخةٌ عن أسبوعٍ أُغلق كذلك — بنفس البنية لا ببنيةٍ ثانية.
 *
 * ═══ ما يحرسه هذا الملفّ ═══
 *
 * الأسبوعيُّ والشهريُّ يمرّان بالخدمة نفسِها (`Archives`)، والباني نفسِه
 * (`Builder`)، والأوراق نفسِها (`Sheets`). فما يُفحص هنا هو ما **يفترق**:
 *
 *  ١) حدُّ الأسبوع: من الاثنين إلى الأحد، مكتوبًا لا موروثًا من لغةٍ.
 *  ٢) الفهرسُ الفريد ثلاثيٌّ الآن: نوعٌ وبدايةٌ ومتجر. وأرشيفُ شهرِ سبتمبر
 *     وأرشيفُ أسبوعِ ١–٧ سبتمبر يبدآن في اليوم نفسه ولا يجتمعان في صفّ.
 *  ٣) مدّةُ الاحتفاظ بالأسابيع لا بالشهور — وهما رقمان مستقلّان.
 *  ٤) الجرسُ: سطرٌ واحدٌ لكلّ أرشيف مهما أُعيد بناؤه، ولا يعبر إلى جار.
 *
 * وما لا يُعاد فحصُه هنا: محتوى الأوراق، والعزلُ داخل الـZIP، وسلامةُ
 * الضغط — يحرسها `AShopKeepsAReadableCopyOfItsMonthTest`، والمسارُ واحد.
 */
class AShopAlsoKeepsACopyOfItsWeekTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $neighbour;

    private Branch $branch;

    /** ‏٧ → ١٣ سبتمبر ٢٠٢٦ — أسبوعٌ مُغلق ما دام «الآن» يومَ الأربعاء ١٦ */
    private Period $week;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        /*
         * والآنُ أربعاءُ ١٦ سبتمبر ٢٠٢٦ — موضعٌ مقصود.
         *
         * فالأسبوعُ الجاري ١٤ → ٢٠ (لم ينتهِ)، والمنقضي ٧ → ١٣ (داخلَ شهرٍ
         * واحد)، والذي قبله ٣١ أغسطس → ٦ سبتمبر (عابرٌ شهرين). فثلاثةُ
         * أشكال العنوان تُفحص من تاريخٍ واحد.
         */
        Carbon::setTestNow(Carbon::create(2026, 9, 16, 10, 0, 0));
        $this->app->setLocale('ar');

        $this->week = Period::week(Carbon::create(2026, 9, 9));

        $this->shop = Business::create(['name' => 'متجر الأسبوع', 'type' => 'عام', 'status' => 'نشط']);
        $this->neighbour = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);

        $this->sale($this->shop->id, 'ORD-1', '2026-09-09 12:00', 100);
        $this->sale($this->neighbour->id, 'ORD-N', '2026-09-09 12:00', 999);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ════════════════════ حدُّ الأسبوع ════════════════════ */

    /** الأسبوعُ من الاثنين إلى الأحد — ٧ → ١٣ سبتمبر عن أربعاءِ ١٦ */
    public function test_the_previous_week_runs_monday_to_sunday(): void
    {
        $p = Period::previous(Period::WEEKLY);

        $this->assertSame('2026-09-07', $p->start()->toDateString());
        $this->assertSame('2026-09-13', $p->endDate()->toDateString());
        $this->assertSame('2026-09-14', $p->next()->toDateString());
    }

    /**
     * ولا يتبع الأسبوعُ لغةَ من فتح الشاشة.
     *
     * `startOfWeek()` بلا وسيط تبدأ الأحد في الإنجليزيّة. فلو ورثها الحدُّ
     * لَصار أسبوعُ التاجر يتبدّل بتبديل لغته، ويُطلب أرشيفٌ لمدًى يخالف
     * المدى الذي بُني — والفهرسُ الفريد يردّه «موجودًا» وهو ليس هو.
     */
    public function test_the_week_starts_on_monday_in_every_language(): void
    {
        $this->app->setLocale('en');
        $english = Period::previous(Period::WEEKLY)->start()->toDateString();

        $this->app->setLocale('ar');
        $arabic = Period::previous(Period::WEEKLY)->start()->toDateString();

        $this->assertSame('2026-09-07', $english);
        $this->assertSame($english, $arabic);
    }

    /** وأسبوعٌ لم ينتهِ لا يُسمّى أرشيفًا */
    public function test_the_running_week_is_refused(): void
    {
        Queue::fake();

        $this->expectException(\RuntimeException::class);

        Archives::request($this->shop->id, Period::current(Period::WEEKLY), null);
    }

    /** ويومٌ في وسط الأسبوع يُنزَل إلى اثنينه — فلا سبعةُ أرشيفاتٍ لأسبوع */
    public function test_any_day_of_a_week_resolves_to_the_same_period(): void
    {
        $keys = [];

        foreach (['2026-09-07', '2026-09-09', '2026-09-13'] as $day) {
            $keys[] = Period::week(Carbon::parse($day))->start()->toDateString();
        }

        $this->assertSame(['2026-09-07'], array_unique($keys));
    }

    /* ════════════════════ البناء ════════════════════ */

    /** أسبوعٌ مُغلقٌ يصير ZIP يُفتح، وحالتُه «جاهز» */
    public function test_a_closed_week_becomes_a_zip_that_opens(): void
    {
        $archive = $this->build();

        $this->assertSame(BusinessArchive::READY, $archive->status);
        $this->assertNotNull($archive->storage_path);
        $this->assertContains('manifest.json', $this->namesIn($archive));
    }

    /** واسمُ الملفّ يحمل مفتاحَ الأسبوع لا مفتاحَ الشهر */
    public function test_the_stored_path_carries_the_iso_week(): void
    {
        $archive = $this->build();

        $this->assertStringContainsString('2026-W37', (string) $archive->storage_path);
    }

    /** وأسبوعٌ لا بيانات فيه يسقط بسببٍ يُقرأ، ولا يُكتب «جاهز» */
    public function test_an_empty_week_fails_and_says_so(): void
    {
        $archive = $this->build(Period::week(Carbon::create(2026, 3, 4)));

        $this->assertSame(BusinessArchive::FAILED, $archive->status);
        $this->assertNull($archive->storage_path);
        $this->assertNotEmpty($archive->failure_reason);
    }

    /** وما سقط لا يترك ملفًّا نصفَ مكتوبٍ على القرص */
    public function test_a_failed_weekly_build_leaves_no_file(): void
    {
        $this->build(Period::week(Carbon::create(2026, 3, 4)));

        $this->assertSame([], Storage::disk('local')->allFiles('archives'));
    }

    /** وحدُّ الأسبوع يُدخل آخرَ دقيقةٍ فيه ويُخرج أوّلَ دقيقةٍ بعده */
    public function test_the_last_minute_of_the_week_is_in_and_the_first_of_the_next_is_out(): void
    {
        $this->sale($this->shop->id, 'ORD-EDGE', '2026-09-13 23:59:59', 7);
        $this->sale($this->shop->id, 'ORD-OUT', '2026-09-14 00:00:01', 9);

        $sheet = $this->sheetText($this->build(), 'Sales');

        $this->assertStringContainsString('ORD-EDGE', $sheet);
        $this->assertStringNotContainsString('ORD-OUT', $sheet);
    }

    /* ════════════════════ لا تكرار ════════════════════ */

    /** ثلاثُ ضغطاتٍ لأسبوعٍ واحد تُنتج صفًّا واحدًا */
    public function test_three_requests_for_one_week_make_one_row(): void
    {
        Queue::fake();

        Archives::request($this->shop->id, $this->week, null);
        Archives::request($this->shop->id, $this->week, null);
        Archives::request($this->shop->id, $this->week, null);

        $this->assertSame(1, BusinessArchive::where('business_id', $this->shop->id)->count());
    }

    /** والقاعدةُ ترفض الثاني — الحارسُ فهرسٌ لا فحصٌ في PHP */
    public function test_the_database_refuses_a_second_row_for_the_same_week(): void
    {
        $row = [
            'business_id' => $this->shop->id, 'archive_type' => Period::WEEKLY,
            'period_start' => '2026-09-07', 'period_end' => '2026-09-13',
            'status' => BusinessArchive::PENDING,
        ];

        BusinessArchive::create($row);

        $this->expectException(QueryException::class);

        BusinessArchive::create($row);
    }

    /**
     * وأسبوعٌ وشهرٌ يبدآن في اليوم نفسه صفّان لا صفّ.
     *
     * ١ سبتمبر ٢٠٢٦ ثلاثاء، فأقربُ مثالٍ يبدأ فيه النوعان معًا هو شهرٌ
     * أوّلُه اثنين — ويونيو ٢٠٢٦ كذلك. ولو كان الفهرسُ على البداية وحدها
     * لَردّ أحدُهما مكانَ الآخر، فيُقال «موجودٌ من قبل» عن مدًى لم يُؤرشَف.
     */
    public function test_a_week_and_a_month_starting_the_same_day_are_two_rows(): void
    {
        $day = Carbon::create(2026, 6, 1);
        $this->assertTrue($day->isMonday(), 'المثالُ يحتاج شهرًا أوّلُه اثنين');

        $shared = [
            'business_id' => $this->shop->id, 'period_start' => '2026-06-01',
            'status' => BusinessArchive::PENDING,
        ];

        BusinessArchive::create($shared + ['archive_type' => Period::WEEKLY, 'period_end' => '2026-06-07']);
        BusinessArchive::create($shared + ['archive_type' => Period::MONTHLY, 'period_end' => '2026-06-30']);

        $this->assertSame(2, BusinessArchive::where('business_id', $this->shop->id)->count());
    }

    /**
     * وطلبُهما من الباب يُنتج صفّين كذلك — لا الصفَّ نفسَه مرّتين.
     *
     * ═══ وطبقتان لا طبقة ═══
     *
     * الحارسُ الذي قبله يكتب الصفّين مباشرةً فيقيس **الفهرس** في القاعدة.
     * وهذا يمرّ بـ`Archives::request` فيقيس **دالّة البحث**: لو سألت
     * بالبداية وحدها لَوجدت الأسبوعيَّ وردّته مكان الشهريّ — فيُقال
     * «موجودٌ من قبل» عن شهرٍ لم يُؤرشَف قطُّ، ولا تنكسر القاعدةُ لتقول.
     *
     * وأثبتت الطفرةُ ذلك: حذفُ شرط النوع من `find` لم يُسقط شيئًا قبل هذا.
     */
    public function test_asking_for_a_week_and_a_month_that_start_together_makes_two_rows(): void
    {
        Queue::fake();

        $day = Carbon::create(2026, 6, 1);
        $this->assertTrue($day->isMonday(), 'المثالُ يحتاج شهرًا أوّلُه اثنين');

        $weekly = Archives::request($this->shop->id, Period::week($day), null);
        $monthly = Archives::request($this->shop->id, Period::month(2026, 6), null);

        $this->assertNotSame($weekly->id, $monthly->id, 'رُدَّ الأسبوعيُّ مكان الشهريّ');
        $this->assertSame(2, BusinessArchive::where('business_id', $this->shop->id)->count());
    }

    /* ════════════════════ الأمرُ والمجدول ════════════════════ */

    /** الأمرُ يضع صفًّا لكلّ متجر ويدفع وظيفةً — ولا يبني شيئًا بنفسه */
    public function test_the_weekly_command_queues_a_row_for_every_shop(): void
    {
        Queue::fake();

        $this->artisan('archive:weekly')->assertExitCode(0);

        $this->assertSame(2, BusinessArchive::where('archive_type', Period::WEEKLY)->count());
        Queue::assertPushed(BuildBusinessArchive::class, 2);

        foreach (BusinessArchive::all() as $row) {
            $this->assertSame('2026-09-07', $row->period_start->toDateString());
        }
    }

    /** وتشغيلٌ ثانٍ لا يُنشئ صفًّا ثانيًا ولا يدفع وظيفةً ثانية */
    public function test_running_the_weekly_command_twice_changes_nothing(): void
    {
        Queue::fake();

        $this->artisan('archive:weekly')->assertExitCode(0);
        $this->artisan('archive:weekly')->assertExitCode(0);

        $this->assertSame(2, BusinessArchive::where('archive_type', Period::WEEKLY)->count());
    }

    /** ومتجرٌ بعينه يُطلب وحدَه */
    public function test_the_weekly_command_can_be_pointed_at_one_shop(): void
    {
        Queue::fake();

        $this->artisan('archive:weekly', ['--business' => $this->shop->id])->assertExitCode(0);

        $this->assertSame(1, BusinessArchive::count());
        $this->assertSame($this->shop->id, BusinessArchive::first()->business_id);
    }

    /** وأسبوعٌ بعينه يُطلب بيومٍ فيه */
    public function test_the_weekly_command_accepts_a_day_inside_the_week(): void
    {
        Queue::fake();

        $this->artisan('archive:weekly', ['--week' => '2026-09-02'])->assertExitCode(0);

        $this->assertSame('2026-08-31', BusinessArchive::first()->period_start->toDateString());
    }

    /** والأسبوعُ الجاري يُرفض من الأمر كذلك، ولا يُكتب صفّ */
    public function test_the_weekly_command_refuses_the_running_week(): void
    {
        Queue::fake();

        $this->artisan('archive:weekly', ['--week' => '2026-09-16'])->assertExitCode(1);

        $this->assertSame(0, BusinessArchive::count());
    }

    /** والمقبضُ المطفأ يوقف الأسبوعيَّ ولا يمسّ الشهريّ */
    public function test_switching_off_the_weekly_leaves_the_monthly_running(): void
    {
        Queue::fake();
        Setting::updateOrCreate(['business_id' => null, 'key' => Policy::WEEKLY], ['value' => '0']);

        $this->artisan('archive:weekly')->assertExitCode(0);
        $this->assertSame(0, BusinessArchive::count());

        $this->artisan('archive:monthly')->assertExitCode(0);
        $this->assertSame(2, BusinessArchive::where('archive_type', Period::MONTHLY)->count());
    }

    /** والمجدولُ ينادي الأسبوعيَّ اثنينًا واحدًا لا كلَّ يوم */
    public function test_the_scheduler_calls_the_weekly_on_mondays_only(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'archive:weekly'));

        $this->assertCount(1, $events);
        $this->assertSame('0 4 * * 1', $events->first()->expression);
    }

    /** والنسخةُ التقنيّةُ اليوميّة تبقى يوميّةً بعد كلّ هذا */
    public function test_the_daily_technical_backup_is_untouched(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'backup:run'));

        $this->assertCount(1, $events);
        $this->assertSame('0 2 * * *', $events->first()->expression);

        $this->artisan(BackupRun::class)->assertExitCode(0);
        $this->assertNotEmpty(Storage::disk('local')->allFiles('backups'));
    }

    /* ════════════════════ الجرس ════════════════════ */

    /** أرشيفٌ جاهزٌ يصل الجرسَ بسطرٍ واحد يقول «أسبوعي» ومداه */
    public function test_a_ready_weekly_archive_makes_exactly_one_bell_line(): void
    {
        $this->build();

        $this->actingAs($this->owner($this->shop));
        $lines = $this->bellLines();

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('الأسبوعي', $lines[0]['text']);
        $this->assertStringContainsString('سبتمبر', $lines[0]['text']);
    }

    /**
     * وإعادةُ البناء لا تُضاعف السطر.
     *
     * ═══ ولمَ هذا حارسُ بنيةٍ لا حارسُ سلوك ═══
     *
     * لا جدولَ إشعاراتٍ في هذا النظام: الجرسُ يُشتقّ من صفّ الأرشيف وقتَ
     * القراءة. فوظيفةٌ أُعيدت مرّتين أو عشرًا لا تُنتج إلّا سطرًا واحدًا —
     * لأنّ الصفَّ واحد. وهذا يقيس أنّ البنيةَ ما زالت كذلك: من كتب يومًا
     * جدولَ إشعاراتٍ مخزَّن يُسقط هذا الحارسَ أوّلَ ما يُسقط.
     */
    public function test_rebuilding_does_not_double_the_bell_line(): void
    {
        $archive = $this->build();

        (new Builder($archive->refresh()))->run();
        (new Builder($archive->refresh()))->run();

        $this->actingAs($this->owner($this->shop));

        $this->assertCount(1, $this->bellLines());
    }

    /** وما سقط يصل الجرسَ كذلك — ولا يُعرض نجاحٌ كاذب */
    public function test_a_failed_weekly_archive_reaches_the_bell(): void
    {
        $this->build(Period::week(Carbon::create(2026, 3, 4)));

        $this->actingAs($this->owner($this->shop));
        $lines = $this->bellLines();

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('تعذّر', $lines[0]['text']);
        $this->assertSame('warning', $lines[0]['color']);
    }

    /**
     * ولا يحمل سببُ الفشل مسارَ خادمٍ ولا أثرَ استثناء.
     *
     * الرسالةُ تُعرض في شاشةٍ يفتحها تاجر، وفي جرسه. وأثرُ الاستثناء يحمل
     * مساراتِ الخادم وأسماءَ ملفّاته — ولا يفهم منها شيئًا ولا يجوز أن يراها.
     */
    public function test_the_bell_never_leaks_a_server_path(): void
    {
        $this->build(Period::week(Carbon::create(2026, 3, 4)));

        $this->actingAs($this->owner($this->shop));
        $text = $this->bellLines()[0]['text'];

        foreach (['/var/', 'storage/app', 'Exception', 'SQLSTATE', '.php'] as $leak) {
            $this->assertStringNotContainsString($leak, $text);
        }
    }

    /** وجرسُ متجرٍ لا يحمل أرشيفَ جاره ولو بُني في اللحظة نفسها */
    public function test_one_shops_bell_never_carries_its_neighbours_archive(): void
    {
        $this->build();
        $this->buildFor($this->neighbour);

        $this->actingAs($this->owner($this->neighbour));
        $lines = $this->bellLines();

        $this->assertCount(1, $lines);
        $this->assertStringContainsString(
            (string) BusinessArchive::where('business_id', $this->neighbour->id)->value('id'),
            $lines[0]['url'],
        );
    }

    /** والرابطُ يفتح قسمَ النسخ على صفّ الأرشيف بعينه */
    public function test_the_bell_link_opens_the_archive_section_at_this_row(): void
    {
        $archive = $this->build();

        $this->actingAs($this->owner($this->shop));
        $url = $this->bellLines()[0]['url'];

        $this->assertStringContainsString('section=backup', $url);
        $this->assertStringContainsString('archive='.$archive->id, $url);

        $this->get($url)->assertOk();
    }

    /* ════════════════════ الاحتفاظ ════════════════════ */

    /** مدّةُ الأسبوعيّ بالأسابيع لا بالشهور — ورقمان مستقلّان */
    public function test_a_weekly_archive_expires_by_weeks(): void
    {
        Setting::updateOrCreate(
            ['business_id' => null, 'key' => Policy::WEEKLY_RETENTION_WEEKS],
            ['value' => '4'],
        );

        $archive = $this->build();

        $this->assertSame(now()->addWeeks(4)->toDateString(), $archive->expires_at->toDateString());
    }

    /** وتغييرُ مدّة الأسبوعيّ لا يمسّ الشهريّ */
    public function test_the_weekly_retention_does_not_touch_the_monthly(): void
    {
        Setting::updateOrCreate(
            ['business_id' => null, 'key' => Policy::WEEKLY_RETENTION_WEEKS],
            ['value' => '1'],
        );

        $monthly = Period::month(2026, 8);
        $this->sale($this->shop->id, 'ORD-AUG', '2026-08-10 12:00', 50);

        $archive = $this->build($monthly);

        $this->assertSame(
            now()->addMonthsNoOverflow(Policy::DEFAULT_RETENTION_MONTHS)->toDateString(),
            $archive->expires_at->toDateString(),
        );
    }

    /** وما مضى أجلُه يفقد ملفَّه ويبقى صفُّه */
    public function test_an_expired_weekly_archive_loses_its_file_and_keeps_its_row(): void
    {
        $archive = $this->build();
        $path = $archive->storage_path;
        $archive->update(['expires_at' => now()->subDay()]);

        $this->assertSame(1, Archives::prune());

        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame(BusinessArchive::EXPIRED, $archive->refresh()->status);
    }

    /* ════════════════════ البابُ والعزل ════════════════════ */

    /** صاحبُ المتجر ينزّل أرشيفَه */
    public function test_the_owner_downloads_his_own_weekly_archive(): void
    {
        $archive = $this->build();

        $this->actingAs($this->owner($this->shop))
            ->get(route('admin.archives.download', $archive->id))
            ->assertOk();
    }

    /** والجارُ يردّ ٤٠٤ ولو عرف الرقم — «لا تجده» لا «تجده ثمّ نمنعك» */
    public function test_a_neighbour_cannot_download_this_shops_weekly_archive(): void
    {
        $archive = $this->build();

        $this->actingAs($this->owner($this->neighbour))
            ->get(route('admin.archives.download', $archive->id))
            ->assertNotFound();
    }

    /** والكاشيرُ لا يُنزّل بيانات المتجر كلَّها */
    public function test_a_cashier_may_not_download_the_weekly_archive(): void
    {
        $archive = $this->build();

        $this->actingAs($this->staff($this->shop, 'cashier'))
            ->get(route('admin.archives.download', $archive->id))
            ->assertForbidden();
    }

    /** ومن بلا جلسةٍ يُردّ إلى الدخول لا إلى الملفّ */
    public function test_a_stranger_without_a_session_is_sent_to_login(): void
    {
        $archive = $this->build();

        $this->get(route('admin.archives.download', $archive->id))->assertRedirect();
    }

    /* ════════════════════ الشاشة ════════════════════ */

    /** الشاشةُ تفصل النوعين، ولا تخلط أسبوعًا بشهر */
    public function test_the_panel_separates_the_two_kinds(): void
    {
        $this->build();
        $this->sale($this->shop->id, 'ORD-AUG', '2026-08-10 12:00', 50);
        $this->build(Period::month(2026, 8));

        $panel = BusinessArchiveController::panel($this->shop->id, $this->owner($this->shop));

        $this->assertCount(1, $panel['weekly']);
        $this->assertCount(1, $panel['monthly']);
        $this->assertStringContainsString('سبتمبر', $panel['weekly'][0]['label']);
        $this->assertSame('أغسطس 2026', $panel['monthly'][0]['label']);
    }

    /**
     * والعنوانُ يُبنى وقتَ العرض بلغة القارئ — ولا يُخزَّن مترجَمًا.
     *
     * الصفُّ يحمل تاريخين. فالتاجرُ الذي بدّل لغتَه يرى العنوان بلغته في
     * اللحظة نفسِها، ولا يُعاد كتابةُ صفٍّ واحد في القاعدة.
     */
    public function test_the_period_label_reads_in_both_languages(): void
    {
        $this->build();
        $owner = $this->owner($this->shop);

        $this->app->setLocale('ar');
        $arabic = BusinessArchiveController::panel($this->shop->id, $owner)['weekly'][0]['label'];

        $this->app->setLocale('en');
        $english = BusinessArchiveController::panel($this->shop->id, $owner)['weekly'][0]['label'];

        $this->assertSame('7 – 13 سبتمبر 2026', $arabic);
        $this->assertSame('7 – 13 September 2026', $english);
    }

    /** وأسبوعٌ عابرٌ شهرين يُسمّى بشهريه لا بأحدهما */
    public function test_a_week_across_two_months_names_both(): void
    {
        $this->assertSame(
            '31 أغسطس – 6 سبتمبر 2026',
            Period::week(Carbon::create(2026, 9, 2))->label(),
        );
    }

    /** وأسبوعٌ عابرٌ سنتين يحمل السنتين */
    public function test_a_week_across_two_years_names_both(): void
    {
        $this->assertSame(
            '28 ديسمبر 2026 – 3 يناير 2027',
            Period::week(Carbon::create(2026, 12, 30))->label(),
        );
    }

    /** والزرُّ اليدويُّ يقبل النوعين، ويُنزل اليومَ إلى اثنينه */
    public function test_the_manual_button_accepts_a_weekly_period(): void
    {
        Queue::fake();

        $this->actingAs($this->owner($this->shop))
            ->post(route('admin.archives.store'), ['type' => 'weekly', 'period' => '2026-09-09'])
            ->assertRedirect();

        $row = BusinessArchive::where('archive_type', Period::WEEKLY)->first();

        $this->assertNotNull($row);
        $this->assertSame('2026-09-07', $row->period_start->toDateString());
    }

    /** ونوعٌ لا وجود له يُردّ بخطأ حقلٍ لا بانكسار */
    public function test_an_unknown_archive_type_is_refused(): void
    {
        $this->actingAs($this->owner($this->shop))
            ->post(route('admin.archives.store'), ['type' => 'daily', 'period' => '2026-09-09'])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, BusinessArchive::count());
    }

    /* ════════════════════ أدوات ════════════════════ */

    /** @return array<int, array<string, mixed>> أسطرُ الجرس التي تخصّ الأرشيف */
    private function bellLines(): array
    {
        return array_values(array_filter(
            Demo::allNotifications(),
            fn ($n) => str_starts_with((string) $n['key'], 'archive-'),
        ));
    }

    private function build(?Period $period = null, ?Business $business = null): BusinessArchive
    {
        $p = $period ?? $this->week;
        $shop = $business ?? $this->shop;

        $archive = BusinessArchive::create([
            'business_id' => $shop->id,
            'archive_type' => $p->type,
            'period_start' => $p->start()->toDateString(),
            'period_end' => $p->endDate()->toDateString(),
            'status' => BusinessArchive::PENDING,
        ]);

        (new Builder($archive))->run();

        return $archive->refresh();
    }

    private function buildFor(Business $business): BusinessArchive
    {
        return $this->build($this->week, $business);
    }

    /** @return array<int, string> أسماءُ ما في الـZIP */
    private function namesIn(BusinessArchive $archive): array
    {
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path((string) $archive->storage_path));

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    /** نصُّ ورقةٍ داخل الـZIP — تُستخرج ثمّ تُقرأ */
    private function sheetText(BusinessArchive $archive, string $name): string
    {
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path((string) $archive->storage_path));

        $entry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_contains((string) $zip->getNameIndex($i), $name)) {
                $entry = $zip->getNameIndex($i);
                break;
            }
        }

        $text = $entry === null ? '' : (string) $zip->getFromName($entry);
        $zip->close();

        /*
         * والقراءةُ من الـXML الخام لا بمكتبةِ جداول.
         *
         * `xl/sharedStrings.xml` داخل الـxlsx يحمل كلَّ نصٍّ في الورقة،
         * والمطلوبُ هنا وجودُ رقمِ طلبٍ من عدمه لا قيمةُ خليّةٍ بعينها.
         * وفتحُ الملفّ بمكتبةٍ كاملة لأجل ذلك يُبطئ الاختبارَ بلا فائدة.
         */
        $tmp = tempnam(sys_get_temp_dir(), 'wk').'.xlsx';
        file_put_contents($tmp, $text);

        $inner = new ZipArchive;
        $out = '';
        if ($inner->open($tmp) === true) {
            $out = (string) $inner->getFromName('xl/sharedStrings.xml');
            $inner->close();
        }
        @unlink($tmp);

        return $out;
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
