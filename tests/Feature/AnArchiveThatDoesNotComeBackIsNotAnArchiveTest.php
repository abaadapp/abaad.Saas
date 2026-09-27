<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\PurgeRun;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Purge\Cipher;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use ZipArchive;

/**
 * الأرشيفُ يعود إلى دفترٍ فارغٍ صفًّا صفًّا — أو ليس أرشيفًا.
 *
 * ═══ ولمَ حرّاسٌ لهذا بعد كلّ ما سبق ═══
 *
 * الحرّاسُ الأوائلُ أثبتوا أنّ النسخةَ البعيدةَ تُنزَّل وتُفكُّ ويُفتح
 * ملفُّها ويُقرأ بيانُه. وذاك يُثبت أنّ **الملفَّ سليم**، ولا يُثبت أنّ
 * **الدفاترَ تُستعاد**: عمودٌ نقص، أو صفٌّ انكسر عند فاصلةٍ داخل حقل، أو
 * حقلٌ فارغٌ حيث لا يُقبل الفراغ — كلُّها تمرّ في «الملفُّ يُفتح» وتسقط
 * يومَ تُطلب الدفاترُ فعلًا.
 *
 * ويومُ تُطلب فيه هو مراجعةٌ ضريبيّةٌ بعد سنين، ولا شركةَ في النظام تُقارَن
 * بها. فتُعاد هنا **الآن** إلى قاعدةٍ فارغةٍ ويُقارَن العددُ بالبيان.
 *
 * ولا بياناتِ إنتاجٍ في شيءٍ من هذا: شركةٌ تُصنع في قاعدةٍ مؤقّتة، وقرصٌ
 * مُزيَّف، وقاعدةُ هدفٍ في ملفٍّ يُمحى بانتهاء الاختبار.
 */
class AnArchiveThatDoesNotComeBackIsNotAnArchiveTest extends TestCase
{
    use RefreshDatabase;

    private const OFFSITE = 'offsite-restore-test';

    private const TARGET = 'restore_target';

    private Business $shop;

    private User $root;

    /** @var list<string> */
    private array $temps = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake(self::OFFSITE);

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

        $this->shop = Business::create([
            'name' => 'متجر الريحان', 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '96891000077', 'city' => 'مسقط', 'site_slug' => 'rayhan',
        ]);

        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($this->shop->id);

        foreach (['باقة ورد', 'إكليل', 'بوكيه "خاصّ", بفاصلة'] as $i => $name) {
            Product::create([
                'business_id' => $this->shop->id, 'name' => $name, 'price' => 20 + $i, 'cost' => 8,
                'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
            ]);
        }

        $this->root = User::create([
            'business_id' => null, 'name' => 'مدير المنصة', 'email' => 'root@abaad.om',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temps as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /* ═══════════════════ الرحلةُ كاملة ═══════════════════ */

    /**
     * أرشيفٌ يُصنع ويُشفَّر ويُرفع ويُنزَّل ويُفكُّ ويعود صفوفًا في قاعدةٍ فارغة.
     *
     * وهذا الحارسُ وحدَه يقطع الدورةَ من طرفها إلى طرفها — وما دونه يقيس
     * أجزاءَها. وأهمُّ ما فيه المقارنةُ الأخيرة: تُقرأ الصفوفُ من قاعدة
     * الهدف لا من الملفّ، فقيدٌ يرفض صفًّا يظهر هنا ولا يظهر في عدّادٍ
     * يقوله المُدخِل عن نفسه.
     */
    public function test_the_archive_goes_back_into_an_empty_database_row_for_row(): void
    {
        $this->purge();

        $run = $this->row();
        $this->assertSame(PurgeRun::DONE, $run?->status, 'لم يتمّ الحذف: '.$run?->error);
        $this->assertSame(0, Business::whereKey($this->shop->id)->count(), 'بقيت الشركة');

        $this->target();

        $code = Artisan::call('purge:restore', [
            'run' => $run->id,
            '--into' => self::TARGET,
        ]);

        $this->assertSame(0, $code, "سقطت الاستعادة:\n".Artisan::output());

        $db = DB::connection(self::TARGET);

        $this->assertSame(1, $db->table('businesses')->count(), 'لم تعد الشركة');
        $this->assertSame('متجر الريحان', $db->table('businesses')->value('name'));
        $this->assertSame($this->shop->id, (int) $db->table('businesses')->value('id'), 'عاد الصفُّ بمعرِّفٍ آخر');

        $this->assertSame(3, $db->table('products')->count(), 'نقصت الأصناف');
        $this->assertSame(1, $db->table('branches')->count());
        $this->assertSame(1, $db->table('currencies')->count());
        $this->assertGreaterThan(5, $db->table('accounts')->count(), 'لم يعد دليلُ الحسابات');

        /* وصنفٌ في اسمه فاصلةٌ وعلامةُ اقتباسٍ يعود كما كُتب — لا منقوصًا */
        $this->assertSame(
            1,
            $db->table('products')->where('name', 'بوكيه "خاصّ", بفاصلة')->count(),
            'انكسر صفٌّ عند فاصلةٍ داخل حقل',
        );

        /*
         * وحقلٌ كان `null` يعود `null` لا نصًّا فارغًا.
         *
         * ═══ ولمَ يُقاس هذا ═══
         *
         * CSV لا يفرّق بينهما: كلاهما حقلٌ خالٍ. فلو عاد `''` مكان `null`
         * لتساوت الأعدادُ ومرّت المقارنةُ — ثمّ قرأ محاسبٌ «صورةَ الصنف:
         * (فراغ)» حيث لم تكن صورةٌ أصلًا، و`whereNull` في أيّ تقريرٍ يُبنى
         * على الأرشيف لا يجد شيئًا. فيُسأل المخطَّطُ عن العمود، وتُقاس
         * نتيجتُه هنا.
         */
        $this->assertSame(3, $db->table('products')->whereNull('image')->count(), 'عاد `null` نصًّا فارغًا');

        /* وعمودٌ لا يقبل الفراغَ يعود بقيمته — لا `null` يُسقط الإدخال */
        $this->assertSame(3, $db->table('products')->whereNotNull('name')->count());
    }

    /** وقراءةٌ بلا `--into` لا تكتب حرفًا — تقول ما في الأرشيف وتسكت */
    public function test_a_reading_restore_writes_nothing(): void
    {
        $this->purge();
        $this->target();

        $code = Artisan::call('purge:restore', ['run' => $this->row()?->id]);
        $out = Artisan::output();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('متجر الريحان', $out);
        $this->assertSame(0, DB::connection(self::TARGET)->table('businesses')->count(), 'كُتب في الهدف بلا --into');
    }

    /* ═══════════════════ والفشلُ يمنع ولا يُنصِّف ═══════════════════ */

    /**
     * دفترٌ تبدّل فيه بايتٌ يُردُّ الأرشيفُ كلُّه — ولا يُعاد منه صفّ.
     *
     * ═══ ولمَ يُردُّ كلُّه لا الدفترُ وحدَه ═══
     *
     * أرشيفٌ عُبث بدفترٍ منه لا يُعرف ما عُبث به غيرُه. واستعادةٌ جزئيّةٌ
     * تُعطي دفاترَ متناقضةً تُقرأ على أنّها الأصل — وذاك أسوأ من لا شيء.
     */
    public function test_a_tampered_sheet_refuses_the_whole_archive(): void
    {
        $this->purge();

        $run = $this->row();
        $abs = Storage::disk('local')->path((string) $run?->archive_path);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($abs) === true);
        $zip->addFromString('books/products.csv', "\xEF\xBB\xBFid,name\n1,مبدَّل\n");
        $zip->close();

        $code = Artisan::call('purge:restore', ['--archive' => $abs, '--into' => $this->target()]);
        $out = Artisan::output();

        $this->assertSame(1, $code, 'قُبل أرشيفٌ عُبث به');
        $this->assertStringContainsString('بصمة', $out);
        $this->assertSame(0, DB::connection(self::TARGET)->table('businesses')->count(), 'كُتب من أرشيفٍ معطوب');
    }

    /**
     * ومفتاحٌ ضاع يعني أرشيفًا لا يُفكّ — ويُقال ذلك بكلمةٍ لا بصفوفٍ فارغة.
     *
     * وهذا هو الثمنُ المعلومُ لتشفيرٍ حقيقيّ: لا بابَ خلفيًّا. فتُحفظ نسخةُ
     * المفتاح بعيدًا — انظر `deploy/README.md`.
     */
    public function test_a_lost_key_cannot_open_the_remote_copy(): void
    {
        $this->purge();

        $enc = Storage::disk(self::OFFSITE)->path((string) $this->row()?->offsite_path);

        /* مفتاحٌ آخرُ — كمن فقد مفتاحَه فجاء بغيره */
        config(['purge.key' => base64_encode(random_bytes(32))]);

        $code = Artisan::call('purge:restore', ['--archive' => $enc, '--into' => $this->target()]);

        $this->assertSame(1, $code, 'فُكّ الأرشيفُ بمفتاحٍ ليس مفتاحَه');
        $this->assertSame(0, DB::connection(self::TARGET)->table('businesses')->count());
    }

    /* ═══════════════════ ولا يُكتب في قاعدةٍ عاملة ═══════════════════ */

    /** الإنتاجُ يُردُّ بلا نظرٍ في شيءٍ آخر */
    public function test_a_restore_is_refused_on_a_production_server(): void
    {
        $this->purge();
        $this->target();

        app()['env'] = 'production';

        $code = Artisan::call('purge:restore', ['run' => $this->row()?->id, '--into' => self::TARGET]);

        $this->assertSame(1, $code, 'أُعيدت الدفاتر على خادم إنتاج');
        $this->assertStringContainsString('production', Artisan::output());
        $this->assertSame(0, DB::connection(self::TARGET)->table('businesses')->count());
    }

    /** واتّصالُ الافتراضِ هو القاعدةُ العاملة — لا يُكتب فيه أرشيفُ ميت */
    public function test_a_restore_into_the_working_connection_is_refused(): void
    {
        $this->purge();

        $code = Artisan::call('purge:restore', [
            'run' => $this->row()?->id,
            '--into' => (string) config('database.default'),
        ]);

        $this->assertSame(1, $code, 'أُعيدت الدفاتر في قاعدة النظام العاملة');
    }

    /**
     * واتّصالٌ باسمٍ آخرَ يشير إلى القاعدة نفسِها يُردُّ أيضًا.
     *
     * ═══ ولمَ لا يكفي فحصُ الاسم ═══
     *
     * من يُهيّئ اختبارًا يكتب `restore_test` في `config/database.php` ثمّ
     * ينسى فيتركه على قاعدة الإنتاج. والاسمُ مختلفٌ والقاعدةُ واحدة —
     * فيُقارَن ما يشير إليه الاتّصالُ فعلًا: محوّلُه ومضيفُه ومنفذُه وقاعدتُه.
     */
    public function test_a_second_name_for_the_working_database_is_refused(): void
    {
        $this->purge();

        config(['database.connections.twin' => config('database.connections.'.config('database.default'))]);

        $code = Artisan::call('purge:restore', ['run' => $this->row()?->id, '--into' => 'twin']);
        $out = Artisan::output();

        $this->assertSame(1, $code, 'أُعيدت الدفاتر في القاعدة العاملة باسمٍ آخر');
        $this->assertStringContainsString('باسمٍ آخر', $out, 'رُدَّ لسببٍ آخر — لا لأنّه القاعدةُ نفسُها');

        /*
         * ولا صفَّ عاد إلى القاعدة العاملة.
         *
         * وهذا هو الموضعُ الذي يحمل فيه فحصُ البصمةِ وزنَه: بعد محوِ آخرِ
         * شركةٍ تصير جداولُ الدفاترِ في القاعدة العاملة **فارغةً**، فيمرّ
         * شرطُ الفراغِ ولا يبقى قبل الكتابةِ فيها سواه.
         */
        $this->assertSame(0, Business::count(), 'عادت الدفاتر إلى القاعدة العاملة');
    }

    /**
     * وقاعدةٌ فيها صفٌّ واحدٌ ليست فارغة — والإعادةُ تُدخل المعرِّفاتَ الأصليّة.
     *
     * فلو مضت لاصطدمت بما هناك، أو — وهو الأسوأ — خالطت أرشيفَ شركةٍ
     * ببياناتِ أخرى فلا يُعرف أيُّ صفٍّ من أيّ.
     */
    public function test_a_restore_into_a_filled_database_is_refused(): void
    {
        $this->purge();
        $this->target();

        DB::connection(self::TARGET)->table('businesses')->insert([
            'name' => 'شركةٌ ساكنة', 'type' => 'محل', 'status' => 'نشط',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $code = Artisan::call('purge:restore', ['run' => $this->row()?->id, '--into' => self::TARGET]);
        $out = Artisan::output();

        $this->assertSame(1, $code, 'أُعيدت الدفاتر فوق قاعدةٍ ليست فارغة');
        $this->assertStringContainsString('ليس فارغًا', $out);
        $this->assertSame(1, DB::connection(self::TARGET)->table('businesses')->count(), 'مُسّ ما كان هناك');
    }

    /** وقاعدةٌ بلا جداولَ تُردُّ بكلمةٍ تقول ما يُفعل، لا بخطأٍ من المحرّك */
    public function test_a_restore_into_an_unmigrated_database_is_refused(): void
    {
        $this->purge();
        $this->target(migrate: false);

        $code = Artisan::call('purge:restore', ['run' => $this->row()?->id, '--into' => self::TARGET]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('migrate', Artisan::output());
    }

    /* ═══════════════════ ومفتاحُ الأرشيف ليس APP_KEY ═══════════════════ */

    /**
     * مفتاحُ الأرشيفِ مفتاحٌ آخر — ولو تساويا سقطت النسختان معًا.
     *
     * `APP_KEY` يُدوَّر عند الحاجة وحاضرٌ في كلّ نسخةٍ من `.env`. وأرشيفُ
     * عشرِ سنينَ يُفكّ بمفتاحٍ يجب أن يبقى عشرًا ويُحفظ خارج الخادم. فجمعُهما
     * في قيمةٍ واحدةٍ يعني أنّ تدويرَ الأوّل يُفقد كلَّ أرشيف، وأنّ من قرأ
     * `.env` صار يفكُّ النسخةَ البعيدةَ أيضًا.
     */
    public function test_the_archive_key_may_not_be_the_app_key(): void
    {
        $app = base64_encode(random_bytes(32));
        config(['app.key' => 'base64:'.$app]);

        /* والصورتان معًا: بالبادئة وبلا بادئة — القيمةُ واحدة */
        foreach ([$app, 'base64:'.$app] as $raw) {
            config(['purge.key' => $raw]);

            try {
                Cipher::key();
                $this->fail('قُبل APP_KEY مفتاحًا للأرشيف: '.$raw);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('APP_KEY', $e->getMessage());
            }
        }
    }

    /** ولا يبدأ الحذفُ بمفتاحٍ كهذا — البوّابةُ تقرأ ما يقرؤه المُشفِّر */
    public function test_no_purge_starts_when_the_archive_key_is_the_app_key(): void
    {
        $app = base64_encode(random_bytes(32));
        config(['app.key' => 'base64:'.$app, 'purge.key' => $app]);

        $this->purge();

        $this->assertSame(1, Business::whereKey($this->shop->id)->count(), 'مُحيت شركةٌ بمفتاحٍ ليس مستقلًّا');
        $this->assertNotSame(PurgeRun::DONE, $this->row()?->status);
    }

    /* ═══════════════════ الأدوات ═══════════════════ */

    private function purge(): void
    {
        $this->actingAs($this->root)
            ->delete(route('super-admin.businesses.purge', $this->shop->id), ['confirm' => $this->shop->name]);

        auth()->logout();

        foreach (DB::table('jobs')->orderBy('id')->get() as $job) {
            DB::table('jobs')->where('id', $job->id)->delete();

            $work = unserialize(json_decode((string) $job->payload, true)['data']['command']);

            try {
                $work->handle();
            } catch (Throwable $e) {
                $work->failed($e);
            } finally {
                (new UniqueLock(app('cache.store')))->release($work);
            }
        }
    }

    private function row(): ?PurgeRun
    {
        return PurgeRun::where('business_id', $this->shop->id)->first();
    }

    /**
     * قاعدةُ هدفٍ في ملفٍّ مؤقّت — تُهجَّر ثمّ تُمحى بانتهاء الاختبار.
     *
     * و`foreign_key_constraints` مغلقةٌ فيها عن قصد: الأرشيفُ دفاترُ شركةٍ
     * واحدةٍ لا قاعدةٌ كاملة، وفيه أعمدةٌ تشير إلى مستخدمين وجداولَ ليست
     * منه. وقاعدةُ الاستعادة تُقرأ ولا تُشغَّل عليها التطبيقُ.
     */
    private function target(bool $migrate = true): string
    {
        $file = storage_path('framework/testing/restore-'.bin2hex(random_bytes(6)).'.sqlite');
        touch($file);
        $this->temps[] = $file;

        config(['database.connections.'.self::TARGET => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        if ($migrate) {
            Artisan::call('migrate', ['--database' => self::TARGET, '--force' => true]);
        }

        return self::TARGET;
    }
}
