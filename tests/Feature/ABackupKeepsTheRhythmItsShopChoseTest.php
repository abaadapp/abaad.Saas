<?php

namespace Tests\Feature;

use App\Console\Commands\BackupRun;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use App\Support\Archive\Policy;
use App\Support\BackupService;
use App\Support\BackupTooLarge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * النسخُ على الخادم — بتكرارٍ يختاره المتجر، مضغوطًا، ويُستعاد بضغطة.
 *
 * ═══ وما يُحرَس ═══
 *
 * ١) التكرار: اليوميُّ كلَّ ليلة (والقديمُ بلا إعدادٍ يوميّ)، والأسبوعيُّ
 *    والشهريُّ متى مضت مدّتُهما، واليدويُّ لا يُنسخ في المجدول أبدًا —
 *    و«إنشاء نسخة الآن» يعمل للجميع.
 * ٢) الضغط: الملفُّ `.json.gz` حقًّا، ويُفكّ فيحمل جداول المتجر كلَّها.
 * ٣) الاستعادة: آخرُ نسخةٍ تعود بالمال كلّه، وقبلها نسخةُ أمان — وإن
 *    سقطت نسخةُ الأمان لم يُمسّ شيء.
 * ٤) العزل: لا يُنزّل متجرٌ نسخةَ غيره ولا يستعيدها، بالزرّ ولا بالرفع.
 * ٥) التنظيف لا يأخذ آخرَ نسخةٍ لمتجرٍ مهما قدمت.
 */
class ABackupKeepsTheRhythmItsShopChoseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // قرصٌ وهميّ: الاختبارُ لا يكتب في storage/app/private الحقيقيّ
        Storage::fake('local');
    }

    /* ============================ التكرار ============================ */

    public function test_an_old_shop_that_never_chose_is_backed_up_daily(): void
    {
        [$shop] = $this->shop();

        $this->assertSame('daily', BackupService::frequency($shop->id));

        $this->artisan('backup:run')->assertSuccessful();
        $this->travel(1)->days();
        $this->artisan('backup:run')->assertSuccessful();

        $this->assertCount(2, $this->files($shop->id), 'اليوميُّ لم يُنسخ كلَّ ليلة');
    }

    public function test_a_daily_shop_is_backed_up_every_night_even_after_a_manual_copy(): void
    {
        [$shop, $owner] = $this->shop();
        $this->choose($shop, 'daily');

        $this->actingAs($owner)->post(route('admin.backup.create'))->assertRedirect();
        $this->travel(1)->minutes();
        $this->artisan('backup:run')->assertSuccessful();

        $this->assertCount(2, $this->files($shop->id));
    }

    public function test_a_weekly_shop_waits_seven_days(): void
    {
        [$shop] = $this->shop();
        $this->choose($shop, 'weekly');

        $this->artisan('backup:run')->assertSuccessful();
        $this->assertCount(1, $this->files($shop->id), 'أوّلُ نسخةٍ لا تنتظر أسبوعًا');

        foreach (range(1, 6) as $day) {
            $this->travel(1)->days();
            $this->artisan('backup:run')->assertSuccessful();
        }

        $this->assertCount(1, $this->files($shop->id), 'نُسخ الأسبوعيُّ قبل أن يمضي أسبوع');

        $this->travel(1)->days();
        $this->artisan('backup:run')->assertSuccessful();

        $this->assertCount(2, $this->files($shop->id), 'مضى أسبوعٌ ولم يُنسخ');
    }

    public function test_a_monthly_shop_waits_a_month(): void
    {
        [$shop] = $this->shop();
        $this->choose($shop, 'monthly');

        // احتفاظٌ أطولُ من شهر: التنظيفُ يحذف القديمةَ متى جاءت بعدها أحدث، والعدُّ يقرأ الاثنتين
        $run = fn () => $this->artisan('backup:run', ['--keep' => 60])->assertSuccessful();

        $run();

        $this->travel(20)->days();
        $run();
        $this->assertCount(1, $this->files($shop->id), 'نُسخ الشهريُّ بعد عشرين يومًا');

        $this->travel(12)->days();
        $run();
        $this->assertCount(2, $this->files($shop->id), 'مضى شهرٌ ولم يُنسخ');
    }

    public function test_a_manual_shop_is_never_backed_up_by_the_schedule(): void
    {
        [$shop, $owner] = $this->shop();
        $this->choose($shop, 'manual');

        $this->artisan('backup:run')->assertSuccessful();
        $this->travel(40)->days();
        $this->artisan('backup:run')->assertSuccessful();

        $this->assertCount(0, $this->files($shop->id), 'نسخ المجدولُ متجرًا اختار «يدويًّا فقط»');

        $stamp = json_decode(Storage::disk('local')->get(BackupRun::STAMP), true);
        $this->assertSame(1, $stamp['skipped']);
        $this->assertSame(0, $stamp['written']);

        // و«إنشاء نسخة الآن» يعمل له مهما كان التكرار
        $this->actingAs($owner)->post(route('admin.backup.create'))
            ->assertRedirect()->assertSessionHas('toast.type', 'success');

        $this->assertCount(1, $this->files($shop->id));
    }

    public function test_force_backs_up_a_shop_whose_turn_has_not_come(): void
    {
        [$shop] = $this->shop();
        $this->choose($shop, 'manual');

        $this->artisan('backup:run', ['--force' => true])->assertSuccessful();

        $this->assertCount(1, $this->files($shop->id));
    }

    public function test_the_frequency_is_saved_as_a_setting_and_only_a_known_one(): void
    {
        [$shop, $owner] = $this->shop();

        $this->actingAs($owner)->post(route('admin.backup.frequency'), ['frequency' => 'weekly'])
            ->assertSessionHasNoErrors();

        $this->assertSame('weekly', Setting::where('business_id', $shop->id)
            ->where('key', 'backup_frequency')->value('value'));

        $this->actingAs($owner)->post(route('admin.backup.frequency'), ['frequency' => 'hourly'])
            ->assertSessionHasErrors('frequency');

        $this->assertSame('weekly', BackupService::frequency($shop->id));
    }

    /* ============================ الضغط ============================ */

    public function test_the_stored_backup_is_really_gzip_and_carries_every_ledger(): void
    {
        [$shop, $owner] = $this->shop();

        $this->actingAs($owner)->post(route('admin.backup.create'))->assertRedirect();

        [$path] = $this->files($shop->id);
        $this->assertStringEndsWith('.json.gz', $path);

        $raw = Storage::disk('local')->get($path);
        $this->assertSame("\x1f\x8b", substr($raw, 0, 2), 'الملفُّ يُسمّى gz ولا يُضغط');

        $data = json_decode(gzdecode($raw), true);
        $this->assertSame($shop->id, $data['meta']['business_id']);

        foreach ([
            'orders', 'order_items', 'accounts', 'journal_entries', 'journal_lines', 'transactions',
            'expenses', 'bank_accounts', 'purchase_orders', 'purchase_order_items', 'suppliers',
            'supplier_invoices', 'products', 'branch_stocks', 'inventory_movements', 'customers',
            'users', 'settings',
        ] as $table) {
            $this->assertContains($table, $data['meta']['tables'], "{$table} ليس في النسخة");
            $this->assertNotEmpty($data[$table], "{$table} فارغٌ في النسخة والمتجرُ فيه سطور");
        }
    }

    public function test_no_secret_is_in_the_stored_backup(): void
    {
        [$shop, $owner] = $this->shop();

        $this->actingAs($owner)->post(route('admin.backup.create'));

        [$path] = $this->files($shop->id);
        $text = gzdecode(Storage::disk('local')->get($path));
        $data = json_decode($text, true);

        foreach ($data['users'] as $row) {
            $this->assertArrayNotHasKey('password', $row);
            $this->assertArrayNotHasKey('remember_token', $row);
        }

        $this->assertStringNotContainsString('EAAG-سرّ-', $text, 'رمزُ الوصول خرج في النسخة');
        $this->assertStringNotContainsString((string) DB::table('users')->where('id', $owner->id)->value('password'), $text);
    }

    public function test_a_compressed_file_and_an_old_plain_file_both_restore(): void
    {
        [$shop, $owner] = $this->shop();

        $plain = BackupService::json($shop->id);
        $gz = gzencode($plain);

        foreach (['backup.json' => $plain, 'backup.json.gz' => $gz] as $name => $content) {
            DB::table('expenses')->where('business_id', $shop->id)->delete();

            $this->actingAs($owner)->post(route('admin.backup.restore'), [
                'backup' => UploadedFile::fake()->createWithContent($name, $content),
                'confirm' => true,
            ])->assertRedirect()->assertSessionHas('toast.type', 'success');

            $this->assertSame(1, DB::table('expenses')->where('business_id', $shop->id)->count(), "{$name} لم يُستعد");
        }
    }

    /* ============================ الاستعادة ============================ */

    public function test_restoring_the_latest_brings_the_money_back_after_a_safety_copy(): void
    {
        [$shop, $owner] = $this->shop();
        $before = $this->money($shop->id);

        $this->actingAs($owner)->post(route('admin.backup.create'));
        $this->travel(1)->minutes();

        // ثمّ يضيع المال: قيدٌ ومصروفٌ ومعاملةٌ وحسابٌ بنكيٌّ وأمرُ شراء
        DB::table('journal_lines')->whereIn('journal_entry_id',
            DB::table('journal_entries')->where('business_id', $shop->id)->select('id'))->delete();
        DB::table('journal_entries')->where('business_id', $shop->id)->delete();
        DB::table('transactions')->where('business_id', $shop->id)->delete();
        DB::table('expenses')->where('business_id', $shop->id)->delete();
        DB::table('purchase_order_items')->delete();
        DB::table('purchase_orders')->where('business_id', $shop->id)->delete();
        DB::table('bank_accounts')->where('business_id', $shop->id)->delete();

        $this->actingAs($owner)->post(route('admin.backup.latest.restore'), ['confirm' => true])
            ->assertRedirect()->assertSessionHas('toast.type', 'success');

        $this->assertSame($before, $this->money($shop->id), 'عاد المتجرُ بمالٍ ناقص');

        // ونسخةُ الأمان كُتبت — وتحمل الحالَ قبل الاستعادة لا بعدها
        $safety = $this->files($shop->id, 'safety');
        $this->assertCount(1, $safety, 'استُعيد بلا نسخة أمان');
        $data = json_decode(gzdecode(Storage::disk('local')->get($safety[0])), true);
        $this->assertSame([], $data['expenses'], 'نسخةُ الأمان أُخذت بعد الاستعادة');

        // ولا تصير «آخرَ نسخة»: الضغطةُ الثانية تعيد الشيءَ نفسَه لا تتأرجح
        $this->assertStringContainsString('abadpos-backup-', BackupService::latest($shop->id)['name']);
    }

    public function test_the_latest_restore_asks_to_be_confirmed(): void
    {
        [$shop, $owner] = $this->shop();
        $this->actingAs($owner)->post(route('admin.backup.create'));
        DB::table('expenses')->where('business_id', $shop->id)->delete();

        $this->actingAs($owner)->post(route('admin.backup.latest.restore'))
            ->assertSessionHasErrors('confirm');

        $this->assertSame(0, DB::table('expenses')->where('business_id', $shop->id)->count());
        $this->assertCount(0, $this->files($shop->id, 'safety'));
    }

    public function test_an_uploaded_restore_also_takes_a_safety_copy_first(): void
    {
        [$shop, $owner] = $this->shop();
        $file = UploadedFile::fake()->createWithContent('b.json.gz', gzencode(BackupService::json($shop->id)));

        // ثمّ يتغيّر المتجر: هذا ما يجب أن تحفظه نسخةُ الأمان
        DB::table('expenses')->where('business_id', $shop->id)->update(['amount' => 777]);

        $this->actingAs($owner)->post(route('admin.backup.restore'), ['backup' => $file, 'confirm' => true])
            ->assertRedirect()->assertSessionHas('toast.type', 'success');

        $this->assertSame(150.0, (float) DB::table('expenses')->where('business_id', $shop->id)->value('amount'));

        $safety = $this->files($shop->id, 'safety');
        $this->assertCount(1, $safety, 'استُعيد ملفٌّ مرفوع بلا نسخة أمان');
        $data = BackupService::readFile($safety[0]);
        $this->assertSame(777.0, (float) $data['expenses'][0]['amount'], 'نسخةُ الأمان لا تحمل حالَ المتجر قبل الاستعادة');
    }

    public static function restores(): array
    {
        return ['آخر نسخة' => ['latest'], 'ملفٌّ مرفوع' => ['upload']];
    }

    #[DataProvider('restores')]
    public function test_a_failed_safety_copy_means_nothing_is_restored(string $how): void
    {
        [$shop, $owner] = $this->shop();

        // آخرُ نسخةٍ أمس، واليومَ لا يُكتب في مجلّد اليوم شيء
        $this->travel(-1)->days();
        $this->actingAs($owner)->post(route('admin.backup.create'));
        $this->travelBack();
        $file = UploadedFile::fake()->createWithContent('b.json', BackupService::json($shop->id));

        Storage::disk('local')->put('backups/'.now()->format('Y-m-d'), 'ملفٌّ يسدّ مكانَ المجلّد');

        DB::table('expenses')->where('business_id', $shop->id)->update(['amount' => 999]);
        $before = $this->money($shop->id);

        $response = $how === 'latest'
            ? $this->actingAs($owner)->post(route('admin.backup.latest.restore'), ['confirm' => true])
            : $this->actingAs($owner)->post(route('admin.backup.restore'), ['backup' => $file, 'confirm' => true]);

        $response->assertRedirect()->assertSessionHas('toast.type', 'danger');

        $this->assertSame($before, $this->money($shop->id), 'بدأت الاستعادة ونسخةُ الأمان لم تُكتب');
        $this->assertSame(999.0, (float) DB::table('expenses')->where('business_id', $shop->id)->value('amount'));
        $this->assertCount(0, $this->files($shop->id, 'safety'));
    }

    public function test_a_copy_that_lost_the_ledger_is_not_accepted_as_a_safety_copy(): void
    {
        /*
         * والتحقّقُ جدولًا جدولًا لا الطلباتُ وحدها: نسخةٌ طلباتُها كاملة
         * ودفترُ أستاذها ضائع كانت تمرّ — وهي طريقُ الرجوع الوحيد قبل المحو.
         */
        [$shop] = $this->shop();

        $data = BackupService::payload($shop->id);
        $data['journal_lines'] = [];
        $path = 'backups/'.now()->format('Y-m-d').'/abadpos-safety-'.$shop->id.'-x.json.gz';
        Storage::disk('local')->put($path, gzencode(json_encode($data)));

        $verify = new \ReflectionMethod(BackupService::class, 'verify');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/journal_lines/');

        $verify->invoke(null, Storage::disk('local'), $path, $shop->id);
    }

    /* ======================= حدُّ ما بعد فكّ الضغط ======================= */

    public function test_a_gzip_bomb_is_refused_with_422_before_it_fills_memory(): void
    {
        [$shop, $owner] = $this->shop();

        // ستُّ مئة ميجابايت بعد الفكّ، وأقلُّ من ميجابايتٍ قبله
        $bomb = $this->bomb(600);
        $this->assertLessThan(2 * 1024 * 1024, strlen($bomb));

        DB::table('expenses')->where('business_id', $shop->id)->update(['amount' => 999]);

        memory_reset_peak_usage();
        $base = memory_get_usage();

        $this->actingAs($owner)->post(
            route('admin.backup.restore'),
            ['backup' => UploadedFile::fake()->createWithContent('bomb.json.gz', $bomb), 'confirm' => true],
            ['Accept' => 'application/json'],
        )->assertStatus(422)->assertJsonValidationErrors(['backup' => '50']);

        /*
         * والفكُّ وقف عند الحدّ: لو فُكّ الملفُّ كلُّه لبلغت الذاكرةُ ستَّ مئة
         * ميجابايت — فوق حدّ الاختبار نفسِه (512M).
         */
        $this->assertLessThan(200 * 1024 * 1024, memory_get_peak_usage() - $base, 'فُكّ الملفُّ كلُّه قبل أن يُقاس');

        $this->assertSame(999.0, (float) DB::table('expenses')->where('business_id', $shop->id)->value('amount'));
        $this->assertCount(0, $this->files($shop->id, 'safety'), 'أُخذت نسخةُ أمانٍ لملفٍّ مرفوض');
    }

    public function test_the_screen_reads_the_limit_under_the_upload_field(): void
    {
        [, $owner] = $this->shop();

        $this->actingAs($owner)->post(route('admin.backup.restore'), [
            'backup' => UploadedFile::fake()->createWithContent('bomb.json.gz', $this->bomb(60)),
            'confirm' => true,
        ])->assertSessionHasErrors('backup');
    }

    public function test_a_stored_latest_that_inflates_past_the_limit_is_refused_too(): void
    {
        [$shop, $owner] = $this->shop();

        Storage::disk('local')->put(
            'backups/'.now()->format('Y-m-d').'/abadpos-backup-'.$shop->id.'-'.now()->format('Y-m-d-His').'.json.gz',
            $this->bomb(60),
        );

        $this->actingAs($owner)->postJson(route('admin.backup.latest.restore'), ['confirm' => true])
            ->assertStatus(422)->assertJsonValidationErrors('backup');

        $this->assertSame(1, DB::table('expenses')->where('business_id', $shop->id)->count());
        $this->assertCount(0, $this->files($shop->id, 'safety'));
    }

    public function test_just_under_the_limit_is_read_and_just_over_is_not(): void
    {
        $payload = json_encode(['meta' => ['app' => 'AbadPOS'], 'pad' => str_repeat('x', 1000)]);
        $gz = gzencode($payload);

        $this->assertIsArray(BackupService::decode($gz, strlen($payload)));
        $this->assertIsArray(BackupService::decode($payload, strlen($payload)));

        foreach ([$gz, $payload] as $raw) {
            try {
                BackupService::decode($raw, strlen($payload) - 1);
                $this->fail('تجاوز الحدَّ بايتًا وقُرئ');
            } catch (BackupTooLarge) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(50 * 1024 * 1024, BackupService::MAX_JSON_BYTES);
    }

    public function test_there_is_nothing_to_restore_or_download_before_the_first_backup(): void
    {
        [$shop, $owner] = $this->shop();

        $this->actingAs($owner)->get(route('admin.backup.latest.download'))
            ->assertRedirect()->assertSessionHas('toast.type', 'danger');

        $this->actingAs($owner)->post(route('admin.backup.latest.restore'), ['confirm' => true])
            ->assertRedirect()->assertSessionHas('toast.type', 'danger');

        $this->assertSame(1, DB::table('expenses')->where('business_id', $shop->id)->count());
    }

    public function test_the_latest_is_downloaded_as_it_was_stored(): void
    {
        [$shop, $owner] = $this->shop();
        $this->actingAs($owner)->post(route('admin.backup.create'));

        $response = $this->actingAs($owner)->get(route('admin.backup.latest.download'))->assertOk();

        $this->assertStringContainsString('.json.gz', $response->headers->get('Content-Disposition'));
        $this->assertSame($shop->id, BackupService::decode($response->streamedContent())['meta']['business_id']);
    }

    /* ============================== العزل ============================== */

    public function test_a_shop_never_downloads_or_restores_its_neighbours_backup(): void
    {
        [$shop, $owner] = $this->shop();
        [$neighbour, $neighbourOwner] = $this->shop('الجار', 'j@abaadapp.om');

        // للجار نسخة، ولمتجرنا لا نسخة
        $this->actingAs($neighbourOwner)->post(route('admin.backup.create'));

        $this->actingAs($owner)->get(route('admin.backup.latest.download'))
            ->assertRedirect()->assertSessionHas('toast.type', 'danger');

        $this->actingAs($owner)->post(route('admin.backup.latest.restore'), ['confirm' => true])
            ->assertSessionHas('toast.type', 'danger');

        $this->assertSame(1, DB::table('expenses')->where('business_id', $shop->id)->count());
        $this->assertSame(1, DB::table('expenses')->where('business_id', $neighbour->id)->count());

        // ولمّا صارت لمتجرنا نسخة: الذي يُنزَّل نسختُه هو لا نسخةُ الجار
        $this->travel(1)->minutes();
        $this->actingAs($owner)->post(route('admin.backup.create'));
        $body = $this->actingAs($owner)->get(route('admin.backup.latest.download'))->streamedContent();
        $this->assertSame($shop->id, BackupService::decode($body)['meta']['business_id']);
    }

    public function test_an_uploaded_backup_of_another_shop_is_refused_before_anything_is_wiped(): void
    {
        [$shop, $owner] = $this->shop();
        [$neighbour] = $this->shop('الجار', 'j@abaadapp.om');

        $theirs = gzencode(BackupService::json($neighbour->id));

        $this->actingAs($owner)->post(route('admin.backup.restore'), [
            'backup' => UploadedFile::fake()->createWithContent('theirs.json.gz', $theirs),
            'confirm' => true,
        ])->assertSessionHas('toast.type', 'danger');

        $this->assertSame(1, DB::table('expenses')->where('business_id', $shop->id)->count(), 'مُحي المتجرُ لأجل نسخة جاره');
        $this->assertSame(1, DB::table('expenses')->where('business_id', $neighbour->id)->count());
    }

    /* ============================== التنظيف ============================== */

    public function test_pruning_never_takes_a_shops_last_backup(): void
    {
        [$monthly] = $this->shop();
        [$daily] = $this->shop('اليوميّ', 'd@abaadapp.om');
        $this->choose($monthly, 'manual');

        // نسختان قديمتان للمتجر اليدويّ، وقديمةٌ لليوميّ
        $this->travel(-40)->days();
        BackupService::store($monthly->id);
        BackupService::store($daily->id);
        $this->travelBack();
        $this->travel(-30)->days();
        BackupService::store($monthly->id);
        $this->travelBack();

        [$older, $last] = $this->files($monthly->id);

        $this->artisan('backup:run', ['--keep' => 14])->assertSuccessful();

        $disk = Storage::disk('local');
        $this->assertTrue($disk->exists($last), 'حُذفت آخرُ نسخةٍ للمتجر — فبقي بلا نسخة');
        $this->assertFalse($disk->exists($older), 'بقيت نسخةٌ قديمةٌ ليست الأخيرة');

        // واليوميُّ نُسخ الليلة، فقديمتُه ليست الأخيرة — تُحذف
        $this->assertCount(1, $this->files($daily->id));
    }

    /* ============================== الشاشة ============================== */

    public function test_the_screen_shows_the_last_success_its_size_and_whether_it_went_offsite(): void
    {
        [$shop, $owner] = $this->shop();

        Storage::fake('offsite-backup-test');
        config(['filesystems.disks.offsite-backup-test' => [
            'driver' => 'local', 'root' => storage_path('framework/testing/disks/offsite-backup-test'),
        ]]);
        Setting::create(['business_id' => null, 'key' => Policy::REMOTE_DISK, 'value' => 'offsite-backup-test']);
        Setting::create(['business_id' => null, 'key' => Policy::BACKUP_REMOTE, 'value' => '1']);

        $this->actingAs($owner)->post(route('admin.backup.create'));
        [$path] = $this->files($shop->id);

        $this->assertTrue(Storage::disk('offsite-backup-test')->exists($path), 'لم تُنسخ إلى التخزين الخارجي');

        $this->actingAs($owner)->get(route('admin.settings.index', ['section' => 'backup']))
            ->assertInertia(fn ($page) => $page
                ->where('backups.frequency', 'daily')
                ->where('backups.offsite_enabled', true)
                ->where('backups.latest.offsite', true)
                ->where('backups.latest.name', basename($path))
                ->where('backups.latest.bytes', Storage::disk('local')->size($path))
                ->has('backups.latest.created_at'));
    }

    public function test_an_older_backup_whose_offsite_fate_is_unknown_says_so(): void
    {
        [$shop, $owner] = $this->shop();

        // نسخةٌ من قبل هذا التغيير: `.json` بلا جوابٍ محفوظ عن البعيد
        Storage::disk('local')->put(
            'backups/'.now()->format('Y-m-d').'/abadpos-backup-'.$shop->id.'-'.now()->format('Y-m-d-His').'.json',
            BackupService::json($shop->id),
        );

        $latest = BackupService::latest($shop->id);

        $this->assertNotNull($latest);
        $this->assertNull($latest['offsite']);
        $this->assertFalse($latest['compressed']);

        // وتُستعاد كما هي
        DB::table('expenses')->where('business_id', $shop->id)->delete();
        $this->actingAs($owner)->post(route('admin.backup.latest.restore'), ['confirm' => true])
            ->assertSessionHas('toast.type', 'success');
        $this->assertSame(1, DB::table('expenses')->where('business_id', $shop->id)->count());
    }

    /* ============================== أدوات ============================== */

    /** ملفٌّ مضغوطٌ يفكّ إلى `$mb` ميجابايتًا — يُبنى قطعةً قطعة فلا يملأ ذاكرةَ الاختبار */
    private function bomb(int $mb): string
    {
        $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        $megabyte = str_repeat('0', 1024 * 1024);
        $out = deflate_add($context, '{"meta":{"app":"AbadPOS","version":3},"pad":"', ZLIB_NO_FLUSH);

        for ($i = 0; $i < $mb; $i++) {
            $out .= deflate_add($context, $megabyte, ZLIB_NO_FLUSH);
        }

        return $out.deflate_add($context, '"}', ZLIB_FINISH);
    }

    private function choose(Business $shop, string $frequency): void
    {
        BackupService::setFrequency($shop->id, $frequency);
    }

    /** ملفّاتُ نسخ متجرٍ واحد على القرص — بترتيبها الزمنيّ */
    private function files(int $bid, string $kind = 'backup'): array
    {
        $files = array_values(array_filter(
            Storage::disk('local')->allFiles('backups'),
            fn ($f) => str_starts_with(basename($f), "abadpos-{$kind}-{$bid}-"),
        ));

        usort($files, fn ($a, $b) => strcmp(basename($a), basename($b)));

        return $files;
    }

    /** بصمةُ المال: عددُ السطور ومجاميعُها في كلّ دفترٍ ماليّ */
    private function money(int $bid): array
    {
        $entries = DB::table('journal_entries')->where('business_id', $bid)->select('id');

        return [
            'journal_entries' => DB::table('journal_entries')->where('business_id', $bid)->count(),
            'journal_debit' => (float) DB::table('journal_lines')->whereIn('journal_entry_id', $entries)->sum('debit'),
            'journal_credit' => (float) DB::table('journal_lines')->whereIn('journal_entry_id', $entries)->sum('credit'),
            'transactions' => (float) DB::table('transactions')->where('business_id', $bid)->sum('amount'),
            'expenses' => (float) DB::table('expenses')->where('business_id', $bid)->sum('amount'),
            'bank_accounts' => (float) DB::table('bank_accounts')->where('business_id', $bid)->sum('opening_balance'),
            'purchase_orders' => (float) DB::table('purchase_orders')->where('business_id', $bid)->sum('total'),
            'orders' => (float) DB::table('orders')->where('business_id', $bid)->sum('total'),
        ];
    }

    /** متجرٌ فيه سطرٌ في كلّ دفترٍ يُخشى عليه */
    private function shop(string $name = 'ورود مسقط', string $email = 'o@abaadapp.om'): array
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        $bid = $shop->id;
        $at = ['created_at' => now(), 'updated_at' => now()];

        $owner = User::create([
            'business_id' => $bid, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $branch = DB::table('branches')->insertGetId(['business_id' => $bid, 'name' => 'الرئيسي'] + $at);
        $product = DB::table('products')->insertGetId([
            'business_id' => $bid, 'name' => 'قميص', 'sku' => 'SH-'.$bid,
            'price' => 10, 'cost' => 6, 'quantity' => 20, 'alert_qty' => 2, 'active' => true,
        ] + $at);
        DB::table('branch_stocks')->insert(['business_id' => $bid, 'branch_id' => $branch, 'product_id' => $product, 'quantity' => 20] + $at);
        DB::table('inventory_movements')->insert([
            'business_id' => $bid, 'product_id' => $product, 'product_name' => 'قميص',
            'type' => 'إضافة كمية', 'quantity' => '+20',
        ] + $at);

        $customer = DB::table('customers')->insertGetId(['business_id' => $bid, 'name' => 'زبون', 'phone' => '9000000'.$bid] + $at);
        $order = DB::table('orders')->insertGetId([
            'business_id' => $bid, 'branch_id' => $branch, 'customer_id' => $customer,
            'number' => 'INV-'.$bid, 'subtotal' => 10, 'tax' => 0.5, 'total' => 10.5,
            'payment_method' => 'نقدي', 'status' => 'مكتمل', 'is_held' => false, 'ordered_at' => now(),
        ] + $at);
        DB::table('order_items')->insert([
            'order_id' => $order, 'product_id' => $product, 'name' => 'قميص',
            'price' => 10, 'cost' => 6, 'quantity' => 1, 'total' => 10,
        ] + $at);

        $account = DB::table('accounts')->insertGetId([
            'business_id' => $bid, 'code' => '1100', 'name' => 'الصندوق',
            'type' => 'أصل', 'normal_side' => 'debit', 'system_key' => 'cash',
        ] + $at);
        $entry = DB::table('journal_entries')->insertGetId([
            'business_id' => $bid, 'number' => 'JE-'.$bid, 'description' => 'قيد',
            'entry_date' => now()->toDateString(), 'source' => 'يدوي',
        ] + $at);
        DB::table('journal_lines')->insert([
            ['journal_entry_id' => $entry, 'account_id' => $account, 'debit' => 10.5, 'credit' => 0] + $at,
            ['journal_entry_id' => $entry, 'account_id' => $account, 'debit' => 0, 'credit' => 10.5] + $at,
        ]);

        DB::table('transactions')->insert([
            'business_id' => $bid, 'reference' => 'INV-'.$bid, 'type' => 'دخل', 'amount' => 10.5, 'occurred_at' => now(),
        ] + $at);
        DB::table('expenses')->insert(['business_id' => $bid, 'type' => 'إيجار', 'amount' => 150, 'spent_at' => now()->toDateString()] + $at);
        DB::table('bank_accounts')->insert(['business_id' => $bid, 'bank_name' => 'بنك مسقط', 'opening_balance' => 1200] + $at);

        $supplier = DB::table('suppliers')->insertGetId(['business_id' => $bid, 'name' => 'مورّد'] + $at);
        $po = DB::table('purchase_orders')->insertGetId([
            'business_id' => $bid, 'number' => 'PO-'.$bid, 'supplier_id' => $supplier, 'total' => 60,
        ] + $at);
        DB::table('purchase_order_items')->insert(['purchase_order_id' => $po, 'product_id' => $product, 'name' => 'قميص', 'cost' => 6, 'quantity' => 10] + $at);
        DB::table('supplier_invoices')->insert([
            'business_id' => $bid, 'supplier_id' => $supplier, 'supplier_ref' => 'SI-'.$bid,
            'total' => 60, 'paid' => 0, 'issued_at' => now()->toDateString(),
        ] + $at);

        DB::table('settings')->insert(['business_id' => $bid, 'key' => 'vat_rate', 'value' => '5'] + $at);

        DB::table('whatsapp_connections')->insert([
            'business_id' => $bid, 'owner_type' => 'business', 'provider' => 'meta',
            'access_token' => 'EAAG-سرّ-'.$bid, 'status' => 'connected',
        ] + $at);

        return [$shop, $owner];
    }
}
