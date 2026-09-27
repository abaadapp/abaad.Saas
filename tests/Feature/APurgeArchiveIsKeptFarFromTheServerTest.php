<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\PurgeRun;
use App\Models\User;
use App\Support\BusinessPurge;
use App\Support\Ledger;
use App\Support\Purge\Cipher;
use App\Support\Purge\Offsite;
use Illuminate\Bus\UniqueLock;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Tests\Support\CorruptingAdapter;
use Tests\TestCase;
use Throwable;
use ZipArchive;

/**
 * نسخةُ الأرشيف بعيدًا عن الخادم — مشفَّرةً، ومُستعادةً قبل أن يُمحى شيء.
 *
 * ═══ ولمَ ملفُّ حرّاسٍ ثانٍ ═══
 *
 * الأوّلُ يحرس المحوَ نفسَه: ما يُحذف ومن يبقى. وهذا يحرس ما قبله وما
 * بعده: أنّ نسخةً بعيدةً كُتبت ورُفعت وأنّها **مشفَّرة** فعلًا، وأنّها
 * نُزِّلت وفُكَّت وفُتحت وقُرئ بيانُها **قبل** أوّل حذف، وأنّ غيابَ
 * التخزين أو المفتاح أو عاملِ الطابور يوقف البابَ كلَّه، وأنّ إعادةَ
 * المحاولة لا تُعيد أرشفةً تمّت ولا تمحو على ما مُحي، وأنّ الأرشيفَ لا
 * ينزّله إلا من خُوِّل ويُقيَّد تنزيلُه.
 *
 * ولا بياناتِ إنتاجٍ في شيءٍ من هذا: شركتان تُصنعان في قاعدةٍ مؤقّتة،
 * وأقراصٌ مُزيَّفة، ومفتاحٌ عشوائيٌّ يُنسى بانتهاء الاختبار.
 */
class APurgeArchiveIsKeptFarFromTheServerTest extends TestCase
{
    use RefreshDatabase;

    private const OFFSITE = 'offsite-test';

    private Business $shop;

    private Business $neighbour;

    private User $root;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake(self::OFFSITE);

        /*
         * و`Storage::fake` تبني القرصَ ولا تكتبه في الإعدادات — فيُقرأ
         * محوّلُه `null`. وفي الواقع كلُّ قرصٍ معرَّفٌ في `filesystems`،
         * فيُعرَّف هنا بالجذر نفسِه كي يقيس الحارسُ ما يقع حقًّا.
         */
        config(['filesystems.disks.'.self::OFFSITE => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/'.self::OFFSITE),
        ]]);

        config([
            'purge.enabled' => true,
            'purge.key' => base64_encode(random_bytes(32)),
            'purge.offsite.disk' => self::OFFSITE,
            'purge.offsite.prefix' => 'purges',
            'purge.assume_worker' => true,
            'queue.default' => 'database',
        ]);

        $this->shop = $this->business('متجر الورد', 'wrood');
        $this->neighbour = $this->business('متجر الجار', 'jar');

        $this->root = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@abaad.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->merchant = User::create([
            'business_id' => $this->shop->id, 'name' => 'سعود', 'email' => 'saud@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function business(string $name, string $slug): Business
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '9689100000'.strlen($slug), 'city' => 'مسقط', 'site_slug' => $slug,
        ]);

        Currency::create(['business_id' => $b->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($b->id);

        Product::create([
            'business_id' => $b->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        return $b;
    }

    /* ═══════════════════ الأدوات ═══════════════════ */

    private function request(?User $as = null, ?string $confirm = null)
    {
        return $this->actingAs($as ?? $this->root)
            ->delete(route('super-admin.businesses.purge', $this->shop->id), [
                'confirm' => $confirm ?? $this->shop->name,
            ]);
    }

    private function purge(?User $as = null, ?string $confirm = null)
    {
        $res = $this->request($as, $confirm);
        $this->work();

        return $res;
    }

    /** ويسحب من الصفّ بلا هويّةٍ في يده — كعاملِ الخادم، انظر الملفَّ الآخر */
    private function work(): void
    {
        auth()->logout();

        foreach (DB::table('jobs')->orderBy('id')->get() as $row) {
            DB::table('jobs')->where('id', $row->id)->delete();

            $job = unserialize(json_decode((string) $row->payload, true)['data']['command']);

            try {
                $job->handle();
            } catch (Throwable $e) {
                $job->failed($e);
            } finally {
                /* وقفلُ الوحدانيّة يُفكّ كما يفكُّه الإطار — انظر الملفَّ الآخر */
                (new UniqueLock(app('cache.store')))->release($job);
            }
        }
    }

    private function purgeRow(): ?PurgeRun
    {
        return PurgeRun::where('business_id', $this->shop->id)->first();
    }

    /** ما رُفع إلى القرص المستقلّ */
    private function offsiteFiles(): array
    {
        return Storage::disk(self::OFFSITE)->allFiles('purges');
    }

    /* ═══════════════════ النسخة البعيدة ═══════════════════ */

    /**
     * نسخةٌ مشفَّرةٌ تُرفع، وبصمتُها تُسجَّل، ويُثبَت أنّها تُستعاد.
     *
     * والبصمةُ المسجَّلةُ للمشفَّر لا للصريح: بها يُتحقّق من الجسم في
     * التخزين بلا فكِّ تشفيره.
     */
    public function test_an_encrypted_copy_lands_off_the_server_with_its_hash(): void
    {
        $this->purge();

        $run = $this->purgeRow();

        $this->assertSame(PurgeRun::DONE, $run?->status, 'لم يتمّ الحذف: '.$run?->error);
        $this->assertSame(self::OFFSITE, $run?->offsite_disk);
        $this->assertNotNull($run?->offsite_path, 'لا نسخةَ بعيدة');
        $this->assertSame(64, strlen((string) $run?->offsite_sha256), 'بلا بصمةٍ للمشفَّر');
        $this->assertNotNull($run?->verified_at, 'لم تُسجَّل لحظةُ التحقّق من الاستعادة');

        $files = $this->offsiteFiles();
        $this->assertCount(1, $files, 'عددُ الأجسام المرفوعة ليس واحدًا');

        /* والبصمةُ المسجَّلةُ هي بصمةُ ما في التخزين فعلًا */
        $bytes = Storage::disk(self::OFFSITE)->get($files[0]);
        $this->assertSame($run?->offsite_sha256, hash('sha256', $bytes));
    }

    /** والمرفوعُ مشفَّرٌ فعلًا — لا ZIP يُفتح بلا مفتاح */
    public function test_what_lands_off_the_server_is_not_a_readable_zip(): void
    {
        $this->purge();

        $path = $this->offsiteFiles()[0] ?? null;
        $this->assertNotNull($path);

        $bytes = Storage::disk(self::OFFSITE)->get($path);

        $this->assertStringStartsNotWith('PK', $bytes, 'رُفع ZIP صريحًا بلا تشفير');
        $this->assertStringStartsWith('ABAAD-PURGE-', $bytes, 'ليس بترويسة التشفير المعروفة');

        /* ويُرفض فتحُه كملفٍّ مضغوط */
        $raw = tempnam(sys_get_temp_dir(), 'enc');
        file_put_contents($raw, $bytes);
        $zip = new ZipArchive;
        $this->assertNotTrue($zip->open($raw, ZipArchive::RDONLY), 'المرفوعُ يُفتح بلا مفتاح');
        @unlink($raw);
    }

    /**
     * والنسخةُ البعيدةُ تُفكّ وتُفتح ويُقرأ بيانُها — استعادةٌ حقيقيّة.
     *
     * وهذا هو الاختبارُ الذي طُلب صريحًا: لا يكفي أنّ الرفعَ نجح، بل أن
     * يُستعاد الأرشيفُ فعلًا في بيئةٍ معزولة.
     */
    public function test_the_remote_copy_really_restores_and_opens(): void
    {
        $this->purge();

        $run = $this->purgeRow();
        $out = tempnam(sys_get_temp_dir(), 'restored');

        $back = Offsite::restore((string) $run?->offsite_path, $out);

        /* بصمةُ ما استُعيد هي بصمةُ الأرشيف الأصليّ */
        $this->assertSame($run?->archive_sha256, $back['sha256'], 'المستعادُ ليس الأرشيفَ نفسَه');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($out, ZipArchive::RDONLY) === true, 'المستعادُ لا يُفتح');

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertIsArray($manifest, 'المستعادُ بلا بيان');
        $this->assertSame($this->shop->name, $manifest['business']['name']);

        /* ودفاترُه فيه — لا أسماؤها وحدَها */
        $this->assertNotSame('', trim((string) $zip->getFromName('books/accounts.csv')));

        $zip->close();
        @unlink($out);
    }

    /* ═══════════════════ البوّابات ═══════════════════ */

    /** بلا تخزينٍ مستقلٍّ مهيَّأ لا يبدأ الحذفُ — ولا يُكتفى بالقرص المحلّيّ */
    public function test_without_an_offsite_disk_nothing_starts(): void
    {
        config(['purge.offsite.disk' => null]);

        $this->request()->assertSessionHasErrors('confirm');

        $this->assertNotNull(Business::find($this->shop->id));
        $this->assertNull($this->purgeRow(), 'أُنشئ صفٌّ لحذفٍ لن يبدأ');
        $this->assertSame(0, DB::table('jobs')->count(), 'صُفّت مهمّةٌ بلا تخزين');
        $this->assertSame([], Storage::disk(BusinessPurge::DISK)->files(BusinessPurge::DIR), 'كُتب أرشيفٌ محلّيٌّ وحدَه');
    }

    /** وبلا مفتاح تشفيرٍ صالحٍ لا يبدأ */
    public function test_without_an_encryption_key_nothing_starts(): void
    {
        config(['purge.key' => null]);

        $this->request()->assertSessionHasErrors('confirm');

        $this->assertNotNull(Business::find($this->shop->id));
        $this->assertNull($this->purgeRow());
    }

    /** ومفتاحٌ بطولٍ خاطئ يُردّ كغيابه — لا يُقبل نصفُ مفتاح */
    public function test_a_short_key_is_refused(): void
    {
        config(['purge.key' => base64_encode(random_bytes(16))]);

        $this->request()->assertSessionHasErrors('confirm');
        $this->assertNotNull(Business::find($this->shop->id));
    }

    /** وقرصٌ لا يُكتب عليه يُردّ عند الفحص — لا يُكتشف بعد الأرشفة */
    public function test_an_unreachable_offsite_disk_is_refused_at_the_gate(): void
    {
        config(['purge.offsite.disk' => 'no-such-disk']);

        $this->request()->assertSessionHasErrors('confirm');

        $this->assertNotNull(Business::find($this->shop->id));
        $this->assertSame([], Storage::disk(BusinessPurge::DISK)->files(BusinessPurge::DIR));
    }

    /** ولا عاملَ طابورٍ يعمل: لا يُصفّ ما لا يُسحب */
    public function test_without_a_queue_worker_nothing_is_queued(): void
    {
        config(['purge.assume_worker' => false]);

        $this->request()->assertSessionHasErrors('confirm');

        $this->assertSame(0, DB::table('jobs')->count(), 'صُفّت مهمّةٌ بلا عامل');
        $this->assertNotNull(Business::find($this->shop->id));
    }

    /** وطابورٌ على `sync` يُردّ — فالتنفيذُ في الطلب هو ما هربنا منه */
    public function test_a_sync_queue_is_refused(): void
    {
        config(['queue.default' => 'sync']);

        $this->request()->assertSessionHasErrors('confirm');

        $this->assertNotNull(Business::find($this->shop->id));
    }

    /**
     * وقرصُ الأرشيف نفسُه لا يُقبل «نسخةً مستقلّة».
     *
     * وهو البديلُ الصامتُ الذي نُهي عنه: لا خطأَ يظهر، ولا يُكتشف الأمرُ
     * إلّا يومَ تُطلب النسخةُ ولا تكون.
     */
    public function test_the_archive_disk_itself_is_not_accepted_as_the_backup(): void
    {
        config(['purge.offsite.disk' => BusinessPurge::DISK]);

        $this->request()->assertSessionHasErrors([
            /* وبالسبب لا بأيّ سبب: من ضبطه يقرأ ما ينقصه */
            'confirm' => __('قرص النسخة الاحتياطية هو قرص الأرشيف نفسه — هذه ليست نسخة مستقلة. أُلغي الحذف.'),
        ]);

        $this->assertNotNull(Business::find($this->shop->id));
        $this->assertNull($this->purgeRow());
    }

    /** وقرصٌ محلّيٌّ جذرُه جذرُ الأرشيف لا يُقبل ولو اختلف اسمُه */
    public function test_a_second_name_for_the_same_folder_is_not_accepted(): void
    {
        config([
            'filesystems.disks.twin' => [
                'driver' => 'local',
                'root' => config('filesystems.disks.'.BusinessPurge::DISK.'.root'),
            ],
            'purge.offsite.disk' => 'twin',
        ]);

        $this->request()->assertSessionHasErrors('confirm');

        $this->assertNotNull(Business::find($this->shop->id));
    }

    /**
     * وفي الإنتاج لا يُقبل قرصٌ محلّيٌّ بحال — مسارٌ على الخادم ليس مستقلًّا عنه.
     *
     * ويُسأل الحارسُ في موضعه لا عبر HTTP: تبديلُ البيئة إلى الإنتاج يُشعل
     * فحصَ CSRF فيردّ الطلبَ ٤١٩ قبل أن يبلغ البوّابة — فيُقاس غيرُ المقصود.
     */
    public function test_in_production_a_local_disk_is_never_independent_enough(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue(app()->isProduction(), 'لم تتبدّل البيئة — فالحارسُ لا يقيس شيئًا');

        try {
            Offsite::assertReady();
            $this->fail('قُبل قرصٌ محلّيٌّ نسخةً مستقلّةً في الإنتاج');
        } catch (RuntimeException $e) {
            $this->assertSame(
                __('قرص النسخة الاحتياطية محلّي على هذا الخادم — ليس تخزينًا مستقلًّا. أُلغي الحذف.'),
                $e->getMessage(),
            );
        }
    }

    /** وخارج الإنتاج يُقبل المحلّيُّ للتجربة — وإلّا لم يُمكن اختبارُ شيء */
    public function test_outside_production_a_separate_local_disk_is_allowed(): void
    {
        $this->assertFalse(app()->isProduction());

        Offsite::assertReady();

        $this->assertTrue(true, 'لم يُلقِ الفحصُ خارج الإنتاج');
    }

    /**
     * والعاملُ لا يُسأل عن نفسه — سؤالٌ كان يُسقط الحذفَ وهو يعمل.
     *
     * ═══ وكيف وقع ═══
     *
     * كانت بوّابةُ التنفيذ تسأل «أثمّ عاملٌ يسحب من الطابور؟» وهي تُنفَّذ
     * في جوف العامل. و`pgrep` من داخل المهمّة ردّ صفرًا وعاملُها قائمٌ
     * يعمل، فسقط الحذفُ بجوابٍ خاطئ. كشفه تشغيلٌ في متصفّحٍ حقيقيّ لا
     * حارسٌ — فهذا حارسُه بعده.
     *
     * والجوابُ ماثلٌ في أنّ السطرَ يُنفَّذ: ما يُنفَّذ في الطابور فيه عامل.
     */
    public function test_the_worker_is_not_asked_whether_a_worker_exists(): void
    {
        $bid = $this->shop->id;

        $run = PurgeRun::create([
            'business_id' => $bid,
            'business_name' => (string) $this->shop->name,
            'requested_by' => $this->root->id,
            'requested_by_name' => (string) $this->root->name,
            'status' => PurgeRun::PENDING,
            'stage' => PurgeRun::QUEUED,
        ]);

        /* لا عاملَ في نظر `pgrep` — وهذه هي الحالُ التي أسقطت الحذف */
        config(['purge.assume_worker' => false]);

        BusinessPurge::execute($run);

        $this->assertSame(PurgeRun::DONE, $run->fresh()?->status, 'سقط الحذفُ لأنّ العاملَ سُئل عن نفسه');
        $this->assertNull(Business::find($bid), 'لم تُمحَ الشركة');
    }

    /* ═══════════════════ الأرشيفُ المعطوب ═══════════════════ */

    /** ونسخةٌ عُطبت في التخزين لا تمرّ التحقّقَ — والرسالةُ تقول إنّها لم تُستعَد */
    public function test_a_tampered_remote_copy_fails_verification(): void
    {
        $this->purge();

        $run = $this->purgeRow();
        $path = (string) $run?->offsite_path;

        /* يُعطب جسمٌ في التخزين بعد أن رُفع */
        $bytes = Storage::disk(self::OFFSITE)->get($path);
        Storage::disk(self::OFFSITE)->put($path, substr($bytes, 0, -40).str_repeat("\x00", 40));

        $this->expectException(RuntimeException::class);

        Offsite::assertRestorable(self::OFFSITE, $path, (string) $run?->archive_sha256);
    }

    /**
     * وأرشيفُ شركةٍ أخرى لا يُقبل مكانَ هذا — ولو كان سليمًا يُفتح.
     *
     * ═══ ولمَ لا يكفي أن يُفتح ═══
     *
     * التوقيعُ يكشف بايتًا تبدّل، وفتحُ الملفّ يكشف نصًّا ليس ZIP. وبينهما
     * حالةٌ لا يكشفها إلّا مقارنةُ البصمة: جسمٌ **سليمُ التشفير، صحيحُ
     * الصيغة، فيه بيانٌ يُقرأ** — لكنّه أرشيفُ شركةٍ أخرى، أو نسخةٌ أقدمُ
     * من هذه. يقع ذلك بخطأٍ في مسارٍ أو بكتابةٍ فوق جسم.
     *
     * فلو مُحيت شركةٌ وأرشيفُها في الحقيقة أرشيفُ غيرِها، لم يُكتشف ذلك
     * إلّا يومَ يُطلب — وهو آخرُ يومٍ يصلح للاكتشاف.
     */
    public function test_a_valid_archive_of_another_business_is_not_accepted(): void
    {
        $this->purge();

        $run = $this->purgeRow();

        /* أرشيفٌ سليمٌ تمامًا — يُفتح وبيانُه يُقرأ — لكنّه ليس هذا */
        $other = tempnam(sys_get_temp_dir(), 'other');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($other, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('manifest.json', json_encode(['schema' => 1, 'business' => ['id' => 999, 'name' => 'متجر آخر']]));
        $zip->addFromString('books/orders.csv', "id,total\n1,5\n");
        $zip->close();

        /* يُتحقّق أوّلًا أنّه سليمٌ فعلًا — وإلّا لم يقس الحارسُ ما يقصد */
        $check = new ZipArchive;
        $this->assertTrue($check->open($other, ZipArchive::RDONLY) === true, 'الأرشيفُ البديل ليس سليمًا');
        $this->assertIsArray(json_decode((string) $check->getFromName('manifest.json'), true));
        $check->close();

        $enc = tempnam(sys_get_temp_dir(), 'otherenc');
        Cipher::encrypt($other, $enc);
        Storage::disk(self::OFFSITE)->put((string) $run?->offsite_path, (string) file_get_contents($enc));

        try {
            Offsite::assertRestorable(self::OFFSITE, (string) $run?->offsite_path, (string) $run?->archive_sha256);
            $this->fail('قُبل أرشيفُ شركةٍ أخرى مكانَ هذا');
        } catch (RuntimeException $e) {
            $this->assertSame(
                __('النسخة المستعادة لا تطابق الأرشيف الأصلي — أُلغي الحذف ولم يُمسّ شيء.'),
                $e->getMessage(),
                'رُدّ لسببٍ آخر لا لاختلاف البصمة',
            );
        } finally {
            @unlink($other);
            @unlink($enc);
        }
    }

    /* ═══════════════════ تخزينٌ يردّ غيرَ ما كُتب ═══════════════════ */

    /** يُسجَّل قرصٌ يردّ غيرَ ما كُتب — وعتبتُه تُقرّر أيَّ العطبين يُحاكي */
    private function spoiledDisk(int $above): void
    {
        Storage::extend('spoiling', fn ($app, $config) => new FilesystemAdapter(
            new Filesystem($adapter = new CorruptingAdapter(new LocalFilesystemAdapter($config['root']), (int) ($config['above'] ?? 0))),
            $adapter,
            $config,
        ));

        config([
            'filesystems.disks.spoiled' => [
                'driver' => 'spoiling',
                'root' => storage_path('framework/testing/spoiled'),
                'above' => $above,
            ],
            'purge.offsite.disk' => 'spoiled',
        ]);
    }

    /**
     * قرصٌ يفسد كلَّ ما يُقرأ منه يُكشف في البوّابة — قبل أن يُصفَّ شيء.
     *
     * وفحصُ البوّابة يكتب جسمًا صغيرًا ويقرؤه ويقارن. فقرصٌ يردّ غيرَ ما
     * كُتب يُردّ هنا، ولا يُقال ذلك بعد أن يُبنى أرشيفٌ ويُرفع.
     */
    public function test_a_storage_that_alters_everything_is_caught_at_the_gate(): void
    {
        $this->spoiledDisk(0);

        $this->request()->assertSessionHasErrors('confirm');

        $this->assertNotNull(Business::find($this->shop->id));
        $this->assertNull($this->purgeRow(), 'أُنشئ صفٌّ لحذفٍ لن يبدأ');
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame([], Storage::disk(BusinessPurge::DISK)->files(BusinessPurge::DIR), 'كُتب أرشيفٌ على قرصٍ معطوب');
    }

    /**
     * وقرصٌ يمرّ بالفحص ثمّ يفسد الأرشيف: لا يُمحى صفٌّ واحد.
     *
     * ═══ وهذا العطبُ هو الذي يُخشى ═══
     *
     * قرصٌ يرفض الرفعَ يقول ذلك. والذي يُخشى قرصٌ يقول «تمّ» ويحفظ بايتًا
     * مبدَّلًا: نسخةٌ تبدو سليمةً سنواتٍ حتّى تُطلب. فيُسأل الكودُ: أتلحظ؟
     *
     * ويلحظه بالاستعادة: يُنزَّل الجسمُ ويُفكّ — وبايتٌ تبدّل يُسقط توقيعَ
     * HMAC قبل أن يُفكّ حرف. وهذا الحارسُ هو الذي يقتل طفرةَ «لا استعادةَ
     * للتحقّق قبل المحو».
     */
    public function test_a_storage_that_alters_the_archive_stops_before_any_delete(): void
    {
        $bid = $this->shop->id;

        /* يمرّ بالفحص (جسمٌ صغير) ويفسد الأرشيف (كبير) */
        $this->spoiledDisk(2048);

        $this->purge();

        $this->assertNotNull(Business::find($bid), 'مُحيت والنسخةُ البعيدةُ مبدَّلة');
        $this->assertSame(1, DB::table('products')->where('business_id', $bid)->count());

        $run = $this->purgeRow();
        $this->assertSame(PurgeRun::FAILED, $run?->status, 'لم تُعدّ ساقطة');
        $this->assertContains($run?->stage, [PurgeRun::UPLOADING, PurgeRun::VERIFYING], 'قيل إنّها سقطت في غير الرفع والتحقّق');
        $this->assertNull($run?->verified_at, 'كُتبت لحظةُ تحقّقٍ لم يقع');
    }

    /* ═══════════════════ إعادةُ المحاولة ═══════════════════ */

    /**
     * محاولةٌ تسقط في الحذف ثمّ تُعاد: لا أرشيفَ ثانٍ، ولا محوٌ على ما مُحي.
     *
     * ═══ وكيف يُسقط الحذفُ حقًّا ═══
     *
     * سطرُ قيدٍ عند الجار يشير إلى حسابٍ عند هذا المتجر — وهو عطبٌ وارد في
     * بياناتٍ قديمة. وقيدُ `journal_lines → accounts` من نوع RESTRICT، فحذفُ
     * حسابات المتجر يسقط، وتتراجع المعاملةُ كلُّها.
     *
     * والحارسُ يشهد لثلاثٍ: أنّ التراجعَ أعاد كلَّ شيء، وأنّ الصفَّ يقول أين
     * سقطت، وأنّ الإعادةَ بعد إصلاح العطب تُكمل من الحذف ولا تُعيد أرشفةً.
     */
    public function test_a_retry_after_a_failed_delete_does_not_rebuild_the_archive(): void
    {
        $bid = $this->shop->id;

        $mine = DB::table('accounts')->where('business_id', $bid)->orderBy('id')->value('id');
        $hisEntry = DB::table('journal_entries')->insertGetId([
            'business_id' => $this->neighbour->id, 'number' => 'JV-X', 'entry_date' => now()->toDateString(),
            'description' => 'قيدُ الجار', 'source' => 'manual', 'posted' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bad = DB::table('journal_lines')->insertGetId([
            'journal_entry_id' => $hisEntry, 'account_id' => $mine,
            'debit' => 5, 'credit' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->purge();

        /* سقطت وقد تمّ أرشيفُها — والشركةُ كما كانت */
        $run = $this->purgeRow();
        $this->assertSame(PurgeRun::FAILED, $run?->status);
        $this->assertSame(PurgeRun::DELETING, $run?->stage, 'قيل إنّها سقطت في غير الحذف');
        $this->assertNotNull(Business::find($bid), 'مُحيت والحذفُ سقط');
        $this->assertSame(1, DB::table('products')->where('business_id', $bid)->count(), 'لم تتراجع المعاملة');
        $this->assertNotNull($run?->verified_at, 'سقطت قبل أن يثبت الأرشيف');

        $archivesBefore = Storage::disk(BusinessPurge::DISK)->files(BusinessPurge::DIR);
        $this->assertCount(1, $archivesBefore);
        $this->assertCount(1, $this->offsiteFiles());
        $shaBefore = $run?->archive_sha256;

        /*
         * يُصلح العطبُ ثمّ تُعاد المحاولة — وبعد دقيقة.
         *
         * واسمُ الأرشيف يحمل الثانيةَ التي كُتب فيها. فلو أُعيد بناؤه في
         * الثانية نفسِها لأخذ الاسمَ نفسَه ودفع الأوّلَ عن مكانه، ولقرأ
         * الحارسُ ملفًّا واحدًا فظنّ أنّ شيئًا لم يُبنَ. فيُقدَّم الوقتُ
         * كي يكون لأيّ بناءٍ ثانٍ اسمٌ يُرى.
         */
        DB::table('journal_lines')->where('id', $bad)->delete();

        $this->travel(70)->seconds();

        $this->purge();

        $after = $this->purgeRow();
        $this->assertSame(PurgeRun::DONE, $after?->status, 'لم تكتمل الإعادة: '.$after?->error);
        $this->assertNull(Business::find($bid), 'بقيت الشركةُ بعد إعادةٍ ناجحة');

        /* ولا أرشيفَ ثانٍ: الأوّلُ ثبتت استعادتُه فلم يُبنَ غيرُه */
        $this->assertSame($archivesBefore, Storage::disk(BusinessPurge::DISK)->files(BusinessPurge::DIR), 'أُعيد بناءُ الأرشيف');
        $this->assertCount(1, $this->offsiteFiles(), 'رُفعت نسخةٌ ثانية');
        $this->assertSame($shaBefore, $after?->archive_sha256, 'تبدّلت بصمةُ الأرشيف في الإعادة');

        /* وصفٌّ واحدٌ للشركة مهما تكرّرت المحاولات */
        $this->assertSame(1, PurgeRun::where('business_id', $bid)->count());
    }

    /** ومهمّةٌ مكرّرةٌ على شركةٍ مُحيت تقول «تمّت» ولا تُعيد أرشفةً ولا محوًا */
    public function test_running_again_after_success_is_a_no_op(): void
    {
        $this->purge();

        $run = $this->purgeRow();
        $this->assertSame(PurgeRun::DONE, $run?->status);

        $archives = Storage::disk(BusinessPurge::DISK)->files(BusinessPurge::DIR);
        $offsite = $this->offsiteFiles();

        /* عاملٌ يسحب مهمّةً مكرّرةً بعد أن زالت الشركة */
        $out = BusinessPurge::execute($run->fresh());

        $this->assertSame(PurgeRun::DONE, $this->purgeRow()?->status);
        $this->assertSame([], $out['rows'], 'أعاد عدَّ صفوفٍ لشركةٍ زالت');
        $this->assertSame($archives, Storage::disk(BusinessPurge::DISK)->files(BusinessPurge::DIR), 'كُتب أرشيفٌ ثانٍ');
        $this->assertSame($offsite, $this->offsiteFiles(), 'رُفعت نسخةٌ ثانية');
    }

    /**
     * وصفُّ الحذف نفسُه لا يُمحى مع ما يمحوه.
     *
     * ═══ ولمَ حارسٌ لهذا ═══
     *
     * `business_purges` يحمل `business_id` ككلّ جدولٍ في النظام، و`scoped`
     * تقرأ الجداولَ من المخطَّط لا من قائمةٍ مكتوبة — فكان الجدولُ يدخل
     * المحوَ ويمحو صفَّه الذي يقول إنّ المحوَ جرى.
     *
     * وهو بابٌ يُفتح كلَّما أُضيف جدولٌ يوثّق فعلًا لا يحمل بياناتِ تاجر.
     */
    public function test_the_purge_record_is_not_deleted_by_the_purge_itself(): void
    {
        $bid = $this->shop->id;

        $this->purge();

        $this->assertNull(Business::find($bid), 'لم تُمحَ الشركة');

        $row = DB::table('business_purges')->where('business_id', $bid)->first();

        $this->assertNotNull($row, 'مُحي صفُّ الحذف مع ما محاه — فلا شهادةَ على ما جرى');
        $this->assertSame(PurgeRun::DONE, $row->status);
        $this->assertNotNull($row->archive_sha256, 'بقي الصفُّ بلا بصمةِ أرشيفه');
    }

    /* ═══════════════════ الاسترجاع والصلاحيّة ═══════════════════ */

    /** ومديرُ المنصّة يقرأ قائمةَ الأرشيفات — ولا يقرؤها تاجر */
    public function test_only_the_platform_admin_sees_the_archive_list(): void
    {
        $this->purge();

        $this->actingAs($this->root)->get(route('super-admin.businesses.purges'))->assertOk();

        $this->actingAs($this->merchant)->get(route('super-admin.businesses.purges'))->assertForbidden();
    }

    /** والقائمةُ لا تكشف مسارًا داخليًّا ولا اسمَ دلوٍ ولا مفتاحًا */
    public function test_the_list_leaks_no_paths_or_keys(): void
    {
        $this->purge();

        $body = $this->actingAs($this->root)->get(route('super-admin.businesses.purges'))->getContent();

        $run = $this->purgeRow();

        foreach ([(string) $run?->archive_path, (string) $run?->offsite_path, BusinessPurge::DIR, self::OFFSITE] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $body, 'كُشف في القائمة: '.$secret);
        }

        /* والبصمةُ الكاملةُ لا تُعرض — مقتطعُها يكفي للمطابقة */
        $this->assertStringNotContainsString((string) $run?->archive_sha256, (string) $body);
    }

    /** ولا ينزّل الأرشيفَ تاجرٌ ولو عرف رقمَ صفّه */
    public function test_a_merchant_cannot_download_an_archive(): void
    {
        $this->purge();
        $id = $this->purgeRow()->id;

        $this->actingAs($this->merchant)
            ->get(route('super-admin.businesses.purgeDownload', $id))
            ->assertForbidden();
    }

    /** والمفتاحُ المُقفل يُغلق بابَ التنزيل كما يُغلق بابَ الحذف */
    public function test_a_closed_flag_closes_the_download_door(): void
    {
        $this->purge();
        $id = $this->purgeRow()->id;

        config(['purge.enabled' => false]);

        $this->actingAs($this->root)
            ->get(route('super-admin.businesses.purgeDownload', $id))
            ->assertNotFound();
    }

    /** وما يُنزَّل هو الأرشيفُ نفسُه — ويُقيَّد من نزّله */
    public function test_a_download_yields_the_archive_and_is_logged(): void
    {
        $this->purge();

        $run = $this->purgeRow();

        $res = $this->actingAs($this->root)
            ->get(route('super-admin.businesses.purgeDownload', $run->id))
            ->assertOk();

        $bytes = $res->streamedContent();
        $this->assertSame($run?->archive_sha256, hash('sha256', $bytes), 'المنزَّلُ ليس الأرشيفَ');
        $this->assertStringStartsWith('PK', $bytes, 'المنزَّلُ ليس ملفًّا مضغوطًا');

        $row = DB::table('activity_logs')->where('action', 'downloaded')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'لم يُقيَّد التنزيل');
        $this->assertSame($this->root->id, (int) $row->user_id);
        $this->assertStringContainsString($this->shop->name, (string) $row->description);

        /* ولا يحمل السطرُ مسارًا ولا بصمة */
        $this->assertStringNotContainsString((string) $run?->offsite_path, (string) $row->description);
    }

    /**
     * وأرشيفٌ ضاع من الخادم يُستعاد من النسخة البعيدة — وهذا معنى النسخة.
     *
     * فلو كان التنزيلُ يقرأ القرصَ المحلّيَّ وحدَه لكانت النسخةُ البعيدةُ
     * حبرًا على ورق: تُرفع ولا تُقرأ أبدًا.
     */
    public function test_a_lost_local_archive_is_restored_from_the_remote_copy(): void
    {
        $this->purge();

        $run = $this->purgeRow();
        $sha = (string) $run?->archive_sha256;

        /* يُمحى الأرشيفُ المحلّيُّ كما يمحوه عطبُ قرصٍ أو تنظيفٌ خاطئ */
        Storage::disk(BusinessPurge::DISK)->delete((string) $run?->archive_path);
        $this->assertFalse(Storage::disk(BusinessPurge::DISK)->exists((string) $run?->archive_path));

        $bytes = $this->actingAs($this->root)
            ->get(route('super-admin.businesses.purgeDownload', $run->id))
            ->assertOk()
            ->streamedContent();

        $this->assertSame($sha, hash('sha256', $bytes), 'ما استُعيد من البعيد ليس الأرشيف');
    }

    /** وملفٌّ محلّيٌّ تبدّلت بصمتُه لا يُسلَّم — يُستعاد البعيدُ مكانَه */
    public function test_a_corrupted_local_archive_is_not_served(): void
    {
        $this->purge();

        $run = $this->purgeRow();
        $sha = (string) $run?->archive_sha256;

        Storage::disk(BusinessPurge::DISK)->put((string) $run?->archive_path, 'ملفٌّ عُطب على القرص');

        $bytes = $this->actingAs($this->root)
            ->get(route('super-admin.businesses.purgeDownload', $run->id))
            ->assertOk()
            ->streamedContent();

        $this->assertSame($sha, hash('sha256', $bytes), 'سُلّم الملفُّ المعطوب');
    }
}
