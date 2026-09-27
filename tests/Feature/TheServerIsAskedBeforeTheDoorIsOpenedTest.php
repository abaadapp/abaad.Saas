<?php

namespace Tests\Feature;

use App\Jobs\PurgeBusiness;
use App\Mail\QueueDownMail;
use App\Models\Business;
use App\Models\PurgeRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

/**
 * يُسأل الخادمُ قبل أن يُفتح البابُ — لا حين يُطلب المحو.
 *
 * ═══ ولمَ أمرُ فحصٍ وقد كُتبت البوّابات ═══
 *
 * البوّاباتُ تردُّ المحوَ إن غاب المفتاحُ أو التخزينُ أو العامل، وذاك جوابٌ
 * صحيحٌ يصل في أسوأ لحظة: مديرُ منصّةٍ كتب اسمَ شركةٍ وأقرّ محوَها ثمّ قيل
 * له «لا تخزينَ مهيَّأ». والأسوأُ منه أن يكون شيءٌ قد تبدّل بعد أن نجح
 * الفحصُ مرّةً وطُوي: مفتاحٌ دُوِّر، أو دلوٌ صار عامًّا.
 *
 * فيُسأل يوميًّا وبلا شركةٍ ولا حذف. وهذه حرّاسُ ذلك السؤال: أن يُجيب
 * بصدق، وأن **لا يقول سرًّا** في جوابه.
 */
class TheServerIsAskedBeforeTheDoorIsOpenedTest extends TestCase
{
    use RefreshDatabase;

    private const OFFSITE = 'offsite-check-test';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake(self::OFFSITE);

        config(['filesystems.disks.'.self::OFFSITE => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/'.self::OFFSITE),
        ]]);

        config([
            'purge.enabled' => false,
            'purge.key' => base64_encode(random_bytes(32)),
            'purge.offsite.disk' => self::OFFSITE,
            'purge.offsite.prefix' => 'purges',
            'purge.assume_worker' => true,
            'queue.default' => 'database',
        ]);
    }

    /* ═══════════════════ الجوابُ الصادق ═══════════════════ */

    /** خادمٌ مهيَّأٌ يُجاب عنه بلا عائق */
    public function test_a_ready_server_answers_without_an_obstacle(): void
    {
        $this->assertSame(0, Artisan::call('purge:check'), Artisan::output());
    }

    /** وبلا مفتاحٍ ولا تخزينٍ تُعدُّ العوائقُ ولا تُجمَّل */
    public function test_a_bare_server_says_what_is_missing(): void
    {
        config(['purge.key' => null, 'purge.offsite.disk' => null, 'purge.assume_worker' => false]);

        $code = Artisan::call('purge:check');
        $out = Artisan::output();

        $this->assertSame(1, $code, 'قيل «جاهز» عن خادمٍ بلا مفتاحٍ ولا تخزين');
        $this->assertStringContainsString('BUSINESS_PURGE_OFFSITE_DISK', $out);
        $this->assertStringContainsString('عامل', $out);
    }

    /** وطابورُ `sync` ليس طابورًا — تنفيذٌ داخل الطلب يموت عند المهلة */
    public function test_a_sync_queue_is_an_obstacle(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertSame(1, Artisan::call('purge:check'));
        $this->assertStringContainsString('sync', Artisan::output());
    }

    /* ═══════════════════ ولا سرَّ في المخرَج ═══════════════════ */

    /**
     * المفتاحُ لا يُطبع — وبصمتُه تُطبع.
     *
     * ═══ ولمَ بصمةٌ أصلًا ═══
     *
     * من حفظ نسخةً من المفتاح خارج الخادم يحتاج أن يعرف أنّها **هي** قبل
     * أن يعتمد عليها في استعادةٍ بعد سنين. ومقارنةُ بصمتين تُجيب ذلك بلا
     * أن يُنقل المفتاحُ في شاشةٍ أو سجلّ.
     */
    public function test_the_key_itself_never_reaches_the_screen(): void
    {
        $key = base64_encode(random_bytes(32));
        config(['purge.key' => $key]);

        Artisan::call('purge:check');
        $out = Artisan::output();

        $this->assertStringNotContainsString($key, $out, 'طُبع المفتاحُ نفسُه');
        $this->assertStringNotContainsString(substr($key, 0, 24), $out, 'طُبع صدرُ المفتاح');
        $this->assertStringContainsString(substr(hash('sha256', base64_decode($key)), 0, 12), $out, 'لا بصمةَ تُقارَن بها النسخةُ المحفوظة');
    }

    /** ولا اسمَ دلوٍ ولا كلمةَ سرٍّ ولا نقطةَ وصول */
    public function test_no_storage_credential_reaches_the_screen(): void
    {
        config(['filesystems.disks.'.self::OFFSITE => [
            'driver' => 's3',
            'key' => 'DO00KEYMATERIAL',
            'secret' => 'sEcReT-Of-ThE-sPaCe',
            'bucket' => 'abaad-private-archive',
            'endpoint' => 'http://127.0.0.1:1',
            'region' => 'fra1',
            'visibility' => 'private',
            'throw' => true,
        ]]);

        Artisan::call('purge:check');
        $out = Artisan::output();

        foreach (['DO00KEYMATERIAL', 'sEcReT-Of-ThE-sPaCe', 'abaad-private-archive', '127.0.0.1'] as $secret) {
            $this->assertStringNotContainsString($secret, $out, 'ظهر في المخرَج: '.$secret);
        }
    }

    /** ورفعٌ غيرُ خاصٍّ في إعدادات القرص عائقٌ يُقال */
    public function test_a_disk_that_uploads_publicly_is_an_obstacle(): void
    {
        config(['filesystems.disks.'.self::OFFSITE.'.driver' => 's3']);
        config(['filesystems.disks.'.self::OFFSITE.'.visibility' => 'public']);

        $code = Artisan::call('purge:check');
        $out = Artisan::output();

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('اضبط visibility=private', $out, 'لم يُقل إنّ الرفعَ ليس خاصًّا');
    }

    /**
     * ونسخةٌ على الخادم نفسِه تُقال في كلّ فحص — لا مرّةً عند ضبطها.
     *
     * من كتب `BUSINESS_PURGE_ALLOW_LOCAL=true` يعرف ما فعل يومَها. ومن يقرأ
     * هذا الفحصَ بعد سنتين — أو خلَفٌ جاء بعده — لا يقرأ `.env`، فيحسب أنّ
     * للأرشيف نسخةً بعيدةً. فتُقال الحالُ في كلّ مرّة، ملاحظةً لا عائقًا:
     * القرارُ قرارُ صاحبه، والصمتُ وحدَه ممنوع.
     */
    public function test_a_copy_that_lives_on_this_server_is_said_every_time(): void
    {
        $code = Artisan::call('purge:check');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('النسخة على الخادم نفسه', $out);
        $this->assertStringContainsString('يأخذ الشركةَ وأرشيفَها معًا', $out);
    }

    /* ═══════════════════ وخصوصيّةُ الدلو تُسأل من الشبكة ═══════════════════ */

    /**
     * دلوٌ يُعطي جسمَ الفحصِ لمن طلبه بلا تخويلٍ عائقٌ حاسم.
     *
     * ═══ ولمَ لا يكفي `visibility=private` ═══
     *
     * ذاك إعدادٌ يقول ما **نطلبه** عند الرفع. وسياسةُ الدلو تعلوه: دلوٌ
     * ضُبط عامًّا في لوحة المزوّد يُعطي كلَّ ما فيه لمن عرف الرابط، ولو
     * رفعنا بـ`private`. فيُطلب الجسمُ من الشبكة بلا مفتاحٍ ويُرى ما يعود.
     */
    public function test_a_publicly_readable_bucket_is_an_obstacle(): void
    {
        $this->withPublicUrl();
        Http::fake(['spaces.test/*' => Http::response('abaad-purge-public-probe', 200)]);

        $code = Artisan::call('purge:check');

        $this->assertSame(1, $code, 'قُبل دلوٌ يُقرأ من الشبكة بلا تخويل');
        $this->assertStringContainsString('الدلو عامّ', Artisan::output());
    }

    /** ودلوٌ يردُّ الطلبَ بلا تخويلٍ يمرّ — وهو المطلوب */
    public function test_a_bucket_that_refuses_an_anonymous_request_passes(): void
    {
        $this->withPublicUrl();
        Http::fake(['spaces.test/*' => Http::response('AccessDenied', 403)]);

        $code = Artisan::call('purge:check');
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('403', $out);
    }

    /** ولا يبقى جسمُ فحصٍ في التخزين بعد السؤال */
    public function test_the_probe_leaves_nothing_behind(): void
    {
        $this->withPublicUrl();
        Http::fake(['spaces.test/*' => Http::response('', 403)]);

        Artisan::call('purge:check');

        $this->assertSame([], Storage::disk(self::OFFSITE)->allFiles('purges'), 'بقي جسمُ فحصٍ في التخزين');
    }

    /* ═══════════════════ ومساحةٌ مشتركة تُقال ═══════════════════ */

    /**
     * مساحةٌ فيها غيرُ أرشيفنا تُذكر — تحذيرًا لا منعًا.
     *
     * مفتاحُ وصولٍ واحدٌ يُقرأ به كلُّ ما في المساحة، وخطأٌ في تنظيفٍ يأخذ
     * الاثنين. والحكمُ على ما فيها لمن هيّأها: الكودُ لا يعرف أيُّ ملفٍّ
     * غريبٍ مقصودٌ وأيُّه سهو — فيقول ولا يقرّر.
     */
    public function test_a_shared_space_is_flagged(): void
    {
        Storage::disk(self::OFFSITE)->put('purges/ملاحظة.txt', 'ليست نسخةً مشفَّرة');
        Storage::disk(self::OFFSITE)->put('backups/db.sql', 'نسخةُ قاعدةٍ أخرى');

        $code = Artisan::call('purge:check');
        $out = Artisan::output();

        $this->assertSame(0, $code, 'مساحةٌ مشتركة تحذيرٌ لا عائقٌ حاسم');
        $this->assertStringContainsString('ليس نسخةً مشفَّرة', $out);
        $this->assertStringContainsString('خارج مجلّد الأرشيف', $out);
    }

    /* ═══════════════════ وما عَلِق يُقال ═══════════════════ */

    /**
     * محوٌ بقي يقول «يعمل» ولا أحدَ يعمل — يُذكر ومعه ما يُقرأ به.
     *
     * مهمّةٌ قُتل عاملُها قبل أن تُكتب `failed` تترك صفَّها `running` أبدًا،
     * والفهرسُ الفريدُ يمنع محاولةً ثانيةً على الشركة نفسِها — فيقف البابُ
     * مغلقًا بلا سببٍ ظاهر.
     */
    public function test_a_purge_stuck_for_hours_is_named(): void
    {
        $shop = Business::create([
            'name' => 'متجرٌ عالق', 'type' => 'محل', 'status' => 'نشط',
            'phone' => '96891000099', 'city' => 'مسقط', 'site_slug' => 'aliq',
        ]);

        PurgeRun::create([
            'business_id' => $shop->id, 'business_name' => $shop->name,
            'status' => PurgeRun::RUNNING, 'stage' => PurgeRun::DELETING,
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        Artisan::call('purge:check');

        $this->assertStringContainsString('لم تتبدّل حالتُها', Artisan::output());
    }

    /* ═══════════════════ والسجلُّ يُكتب حين يكون خبرًا ═══════════════════ */

    /**
     * العائقُ يُكتب في السجلِّ إن كانت الميزةُ مفتوحةً — وحدَها.
     *
     * ميزةٌ مغلقةٌ بلا تخزينٍ مهيَّأٍ ليست عطبًا؛ تلك حالُها المقصودة. وسطرُ
     * تحذيرٍ يوميٌّ عن حالٍ مقصودةٍ يُعلّم من يقرأ السجلَّ أن يتجاهلَه — ثمّ
     * يتجاهلُ التحذيرَ الحقيقيَّ يومَ يأتي.
     */
    public function test_the_log_is_written_only_when_the_door_is_open(): void
    {
        config(['purge.offsite.disk' => null]);

        Log::shouldReceive('warning')->never();
        $this->assertSame(1, Artisan::call('purge:check'));
    }

    public function test_an_open_door_with_an_obstacle_is_logged(): void
    {
        config(['purge.enabled' => true, 'purge.offsite.disk' => null]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message) => str_contains((string) $message, 'purge:check'));

        $this->assertSame(1, Artisan::call('purge:check'));
    }

    /* ═══════════════════ وسقوطُ العاملِ يصل بريدًا ═══════════════════ */

    /**
     * سقوطٌ متكرّرٌ للعامل يصل إلى مديري المنصّة — لا إلى سجلٍّ لا يُقرأ.
     *
     * `Restart=always` يُعيده من سقوطٍ عابر، وسقوطٌ خمسَ مرّاتٍ في دقيقةٍ
     * يُترك ساقطًا عن قصد. وحينها لا أحدَ يسحب من الطابور، ولا شيءَ في
     * النظام يقول ذلك حتّى تُقرأ شاشةٌ عالقةٌ على «قيد التنفيذ».
     */
    public function test_a_fallen_worker_reaches_the_platform_admins(): void
    {
        Mail::fake();
        $this->withMailer();

        User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@abaad.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->assertSame(0, Artisan::call('abaad:queue-alert', ['unit' => 'abaad-queue.service']));

        Mail::assertSent(QueueDownMail::class, fn (QueueDownMail $mail) => $mail->hasTo('root@abaad.om'));
    }

    /** ولا يُراسَل تاجرٌ بعطبِ خادم */
    public function test_a_merchant_is_not_told_about_the_server(): void
    {
        Mail::fake();
        $this->withMailer();

        User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@abaad.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
        User::create([
            'business_id' => null, 'name' => 'سعود', 'email' => 'saud@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Artisan::call('abaad:queue-alert');

        Mail::assertNotSent(QueueDownMail::class, fn (QueueDownMail $mail) => $mail->hasTo('saud@abaad.om'));
    }

    /* ═══════════════════ والبابُ يُقفل على ما صُفّ قبله ═══════════════════ */

    /**
     * مهمّةٌ صُفَّت ثمّ أُقفل البابُ لا تمضي — والمفتاحُ يُقرأ عند التنفيذ لا عند الطلب.
     *
     * ═══ ولمَ يُقاس هذا وحدَه ═══
     *
     * حرّاسُ المسار تقيس الردَّ على طلبٍ يأتي والبابُ مقفل — 404. وهذه تقيس
     * غيرَه: طلبٌ مرّ والبابُ مفتوح، ثمّ أُقفل قبل أن يلتقطَ العاملُ مهمّتَه.
     * ومن يُقفل المفتاحَ على الخادم يفعله ليمنع محوًا، فلو مضى ما في الطابور
     * لكان الإقفالُ كلامًا: بياناتُ تاجرٍ تُمحى بعد قرارِ منعها.
     *
     * وبين الأمرين دقائق: المهمّةُ تحتمل ساعةً، وطابورٌ فيه ما قبلها يؤخّرها.
     */
    public function test_a_job_queued_before_the_door_closed_does_not_pass(): void
    {
        $shop = Business::create([
            'name' => 'متجرُ ما صُفّ', 'type' => 'محل', 'status' => 'نشط',
            'phone' => '96891000088', 'city' => 'مسقط', 'site_slug' => 'masuff',
        ]);

        $run = PurgeRun::create([
            'business_id' => $shop->id, 'business_name' => $shop->name,
            'status' => PurgeRun::PENDING, 'stage' => PurgeRun::QUEUED,
        ]);

        /* ثمّ أُقفل البابُ — والمهمّةُ في الطابور */
        config(['purge.enabled' => false]);

        /*
         * ويُسحب كما يسحب العامل: يُنفَّذ، وإن رُفع استثناءٌ نُوديت `failed`.
         *
         * وهي التي تكتب «فشلت» في الصفّ — لا `handle`. فاستدعاءُ `handle`
         * وحدَها يقيس نصفَ ما يقع على الخادم، ويقرأ صفًّا بقي `pending` عيبًا
         * وليس بعيب. وقد جُرِّب هذا على عاملٍ حقيقيّ: البوّابةُ تسقط، فيُكتب
         * `failed` و`stage=queued`، وتبقى الشركة.
         */
        $job = new PurgeBusiness($run->id);

        try {
            $job->handle();
            $this->fail('مضت المهمّةُ والبابُ مقفل');
        } catch (Throwable $e) {
            $job->failed($e);
        }

        $this->assertSame(1, Business::whereKey($shop->id)->count(), 'مُحيت شركةٌ والميزةُ مغلقة');
        $this->assertSame(PurgeRun::FAILED, $run->fresh()?->status);
    }

    /* ═══════════════════ الأدوات ═══════════════════ */

    /**
     * قرصٌ له رابطٌ عامّ — ويُبنى من جديد بعده.
     *
     * و`Storage::fake` تبني القرصَ وتحتفظ به، فضبطُ إعدادٍ بعدها لا يبلغ ما
     * بُني. فيُنسى القرصُ ليُبنى من الإعداد الجديد — وإلّا قِيس فحصُ
     * الخصوصيّة على قرصٍ لا رابطَ له فمرّ بلا سؤال.
     */
    private function withPublicUrl(): void
    {
        config(['filesystems.disks.'.self::OFFSITE.'.url' => 'https://spaces.test/archive']);

        Storage::forgetDisk(self::OFFSITE);
    }

    /** بريدٌ يبدو موصِّلًا — و`array` في الاختبارات لا يُرسل إلى أحد */
    private function withMailer(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test']);
    }
}
