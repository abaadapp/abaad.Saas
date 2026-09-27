<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurgeRun;
use App\Models\User;
use App\Support\BusinessPurge;
use App\Support\Ledger;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;
use ZipArchive;

/**
 * الشركةُ تُمحى ودفاترُها تبقى — ولا يُمسّ جارُها بحرف.
 *
 * ═══ ولمَ ملفُّ حرّاسٍ بهذا الحجم ═══
 *
 * هذا الفعلُ لا تراجعَ فيه. وما لا يُتراجَع عنه يُحرَس قبل أن يقع لا بعده:
 * أنّ الأرشيفَ كُتب وفُتح وقُرئ قبل أن يُمحى صفٌّ واحد، وأنّ الجارَ لم
 * ينقص، وأنّ مديرَ المنصّة لم يُمحَ مع من مُحي، وأنّ الاسمَ يُقارَن في
 * الخادم لا في الشاشة، وأنّ ضغطتين لا تصيران عمليّتين.
 */
class APurgedBusinessLeavesItsBooksBehindTest extends TestCase
{
    use RefreshDatabase;

    /** قرصٌ يقوم مقامَ التخزين المستقلّ في الاختبار */
    private const OFFSITE = 'offsite-test';

    private Business $shop;

    private Business $neighbour;

    private User $root;

    private User $merchant;

    private User $neighbourUser;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        /*
         * بيئةٌ معزولةٌ كاملة: قرصان محلّيّان مُزيَّفان، وقرصٌ ثالثٌ يقوم
         * مقامَ التخزين المستقلّ، ومفتاحُ تشفيرٍ يُولَّد لكلّ اختبار.
         *
         * ولا شيءَ من هذا يمسّ إنتاجًا: `Storage::fake` مجلّدٌ مؤقّت، والمفتاحُ
         * عشوائيٌّ يُنسى بانتهاء الاختبار.
         */
        Storage::fake(self::OFFSITE);

        /* و`Storage::fake` لا تكتب القرصَ في الإعدادات — فيُعرَّف كما في الواقع */
        config(['filesystems.disks.'.self::OFFSITE => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/'.self::OFFSITE),
        ]]);

        config([
            'purge.enabled' => true,
            'purge.key' => base64_encode(random_bytes(32)),
            'purge.offsite.disk' => self::OFFSITE,
            'purge.offsite.prefix' => 'purges',
            /* عاملُ الطابور يُفترض موجودًا — وله حارسُه في الطرفين */
            'purge.assume_worker' => true,
            /* وطابورٌ حقيقيٌّ في القاعدة لا `sync`: فالمسارُ كلُّه يُختبر */
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

        $this->neighbourUser = User::create([
            'business_id' => $this->neighbour->id, 'name' => 'جار', 'email' => 'jar@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** متجرٌ بكلّ ما فيه: فرعٌ وعملةٌ ودليلُ حساباتٍ وصنفٌ وزبونٌ وطلبٌ وقيد */
    private function business(string $name, string $slug): Business
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط',
            'phone' => '9689000000'.strlen($slug), 'city' => 'مسقط', 'site_slug' => $slug,
        ]);

        Currency::create(['business_id' => $b->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
        Ledger::seedChart($b->id);

        $product = Product::create([
            'business_id' => $b->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 10, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);

        $customer = Customer::create(['business_id' => $b->id, 'name' => 'زبون', 'phone' => '96890000000']);

        $order = Order::create([
            'business_id' => $b->id, 'customer_id' => $customer->id, 'status' => 'مكتمل',
            'number' => 'ORD-'.$slug,
            'total' => 20, 'subtotal' => 20, 'payment_method' => 'نقدي', 'ordered_at' => now(),
        ]);

        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => 'باقة ورد',
            'quantity' => 1, 'price' => 20, 'total' => 20, 'cost' => 8, 'addons_total' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /* قيدٌ بسطوره — وهو ما يُسقط المحوَ إن حُذفت الحسابات قبل سطوره */
        $entry = DB::table('journal_entries')->insertGetId([
            'business_id' => $b->id, 'number' => 'JV-'.$slug, 'entry_date' => now()->toDateString(),
            'description' => 'قيدُ بيع', 'source' => 'manual', 'posted' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $accounts = DB::table('accounts')->where('business_id', $b->id)->limit(2)->pluck('id')->all();

        foreach ($accounts as $i => $accountId) {
            DB::table('journal_lines')->insert([
                'journal_entry_id' => $entry, 'account_id' => $accountId,
                'debit' => $i === 0 ? 20 : 0, 'credit' => $i === 0 ? 0 : 20,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        /* مرفقُ مصروفٍ في مجلّد المتجر — يُؤرشَف ثمّ يُحذف */
        Storage::disk('local')->put("expenses/{$b->id}/receipt.pdf", 'إيصالُ '.$name);

        return $b;
    }

    /**
     * يُطلب الحذفُ ثمّ يُشغَّل الطابورُ — فيُختبر المسارُ كما يجري حقًّا.
     *
     * والمهمّةُ تُصفّ في جدول `jobs` ويسحبها `queue:work` كما يسحبها عاملٌ
     * على الخادم: فتُختبر الأسطرُ التي تُسلسل المهمّةَ وتفكُّها، لا دالّةٌ
     * تُنادى مباشرةً. وطلبٌ مرفوضٌ لا يُصفّ شيئًا، فالتشغيلُ لا يضرّه.
     */
    private function purge(?User $as = null, ?string $confirm = null)
    {
        $res = $this->request($as, $confirm);
        $this->work();

        return $res;
    }

    /** الطلبُ وحدَه — بلا تشغيلِ الطابور */
    private function request(?User $as = null, ?string $confirm = null)
    {
        return $this->actingAs($as ?? $this->root)
            ->delete(route('super-admin.businesses.purge', $this->shop->id), [
                'confirm' => $confirm ?? $this->shop->name,
            ]);
    }

    /**
     * يسحب ما في الطابور كما يسحبه عامل — بلا هويّةٍ في يده، ومن الصفّ نفسِه.
     *
     * ═══ ولمَ لا يُستدعى `queue:work` ═══
     *
     * استدعاؤه داخل الاختبار يفتح اتّصالًا ثانيًا بالقاعدة ويتركه بمعاملةٍ
     * مفتوحة، فيقف عليه `migrate` الذي يبدأ به صنفُ الاختبار التالي —
     * وتقع **عقدةُ قفلٍ** على PostgreSQL تُسقط اختبارًا لا علاقةَ له
     * بالحذف. وحارسٌ يُسقط جارَه ليس حارسًا.
     *
     * فيُقرأ الصفُّ من `jobs` كما يقرؤه العامل، **ويُفكّ تسلسلُ حمولته**
     * — فتبقى الأسطرُ التي تُسلسل المهمّةَ وتفكُّها مشمولةً — ثمّ تُنفَّذ.
     * وما يسقط يُمرَّر إلى `failed` كما يفعل العاملُ عند `tries = 1`.
     *
     * ═══ وتُنسى الهويّةُ قبل السحب ═══
     *
     * الاختبارُ يعمل في العمليّة التي دخلت بـ`actingAs`، وعاملُ الخادم لا
     * جلسةَ له. فاختبارٌ لا ينسى الهويّةَ يُخفي كلَّ ما يعتمد عليها، ويقول
     * «السجلُّ يعرف من حذف» وهو على الخادم لا يعرف.
     */
    private function work(): void
    {
        auth()->logout();

        foreach (DB::table('jobs')->orderBy('id')->get() as $row) {
            DB::table('jobs')->where('id', $row->id)->delete();

            $payload = json_decode((string) $row->payload, true);
            $job = unserialize($payload['data']['command']);

            try {
                $job->handle();
            } catch (Throwable $e) {
                /* `tries = 1`: لا إعادةَ — يُكتب السقوطُ ويُمضى */
                $job->failed($e);
            } finally {
                /*
                 * وقفلُ الوحدانيّة يُفكّ كما يفكُّه الإطارُ بعد كلّ مهمّة.
                 *
                 * `ShouldBeUnique` تأخذ قفلًا عند الصفّ ويُفكّ عند الانتهاء —
                 * نجاحًا كان أو سقوطًا. وتركُه مأخوذًا هنا يجعل إعادةَ
                 * المحاولة تُطوى في صمت، فيُقرأ «لم تُعد» حيث الإعادةُ
                 * تعمل على الخادم.
                 */
                (new UniqueLock(app('cache.store')))->release($job);
            }
        }
    }

    /** صفُّ الحذف لهذا المتجر */
    private function purgeRow(): ?PurgeRun
    {
        return PurgeRun::where('business_id', $this->shop->id)->first();
    }

    /* ═══════════ المحو ═══════════ */

    /**
     * المتجرُ يُمحى بكلّ صفوفه — ولا يسقط المحوُ على قيد RESTRICT.
     *
     * `journal_lines → accounts` هو القيدُ الوحيد من نوعه، وحذفُ الحسابات
     * قبل سطورها يُسقط العمليّة كلَّها. فالحارسُ يُنشئ قيدًا بسطورٍ ثمّ يمحو.
     */
    public function test_the_shop_and_all_its_rows_are_gone(): void
    {
        $bid = $this->shop->id;

        $entries = DB::table('journal_entries')->where('business_id', $bid)->pluck('id');
        $lines = DB::table('journal_lines')->whereIn('journal_entry_id', $entries)->pluck('id');
        $orders = DB::table('orders')->where('business_id', $bid)->pluck('id');
        $items = DB::table('order_items')->whereIn('order_id', $orders)->pluck('id');

        $this->assertGreaterThan(0, $lines->count(), 'الحارسُ لا يحرس شيئًا — لا سطورَ قيدٍ أصلًا');

        $this->purge()->assertRedirect();

        $this->assertNull(Business::find($bid), 'بقي صفُّ الشركة');

        foreach (['products', 'orders', 'customers', 'branches', 'currencies', 'accounts', 'journal_entries'] as $table) {
            $this->assertSame(0, DB::table($table)->where('business_id', $bid)->count(), $table.' لم يُمحَ');
        }

        $this->assertSame(0, DB::table('journal_lines')->whereIn('id', $lines)->count(), 'سطورُ القيد بقيت يتيمة');
        $this->assertSame(0, DB::table('order_items')->whereIn('id', $items)->count(), 'بنودُ الطلب بقيت يتيمة');

        /* وسطورُ الجار في الجدولين نفسِهما لم تُمسّ */
        $this->assertGreaterThan(0, DB::table('journal_lines')->count(), 'ذهبت سطورُ الجار معها');
        $this->assertGreaterThan(0, DB::table('order_items')->count());
    }

    /**
     * والجداولُ الثمانيةُ التي لا قيدَ لها تُمحى صراحةً.
     *
     * `users` و`settings` و`activity_logs` وأخواتُها تحمل `business_id` بلا
     * قيدٍ أجنبيّ — فلو تُرك الأمرُ للتتالي بقيت صفوفُها تشير إلى شركةٍ لا
     * وجودَ لها.
     */
    public function test_the_tables_without_a_foreign_key_are_wiped_too(): void
    {
        $bid = $this->shop->id;
        DB::table('settings')->insert(['business_id' => $bid, 'key' => 'x', 'value' => '1', 'created_at' => now(), 'updated_at' => now()]);

        $this->purge();

        foreach (['users', 'settings'] as $table) {
            $this->assertSame(0, DB::table($table)->where('business_id', $bid)->count(), $table.' بقي يتيمًا');
        }
    }

    /* ═══════════ الأرشيف ═══════════ */

    /** الدفاترُ تُكتب في ملفٍّ يُفتح ويُقرأ — ببيانٍ وبصمة */
    public function test_the_books_are_archived_before_anything_is_deleted(): void
    {
        $this->purge();

        $files = Storage::disk(BusinessPurge::DISK)->files(BusinessPurge::DIR);
        $this->assertCount(1, $files, 'لا أرشيف');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk(BusinessPurge::DISK)->path($files[0]), ZipArchive::RDONLY) === true);

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

        $this->assertIsArray($manifest);
        $this->assertSame($this->shop->name, $manifest['business']['name']);
        $this->assertSame($this->root->id, $manifest['purged_by']['id']);

        /* والدفاترُ فيه فعلًا لا أسماؤها في بيانٍ وحده */
        foreach (['orders', 'order_items', 'journal_entries', 'journal_lines', 'accounts'] as $book) {
            $csv = $zip->getFromName("books/{$book}.csv");
            $this->assertIsString($csv, $book.' غائبٌ عن الأرشيف');
            $this->assertNotSame('', trim($csv), $book.' فارغٌ في الأرشيف');
        }

        /* وبصمةُ كلّ دفترٍ تُطابق ما فيه */
        $orders = (string) $zip->getFromName('books/orders.csv');
        $this->assertSame(hash('sha256', $orders), $manifest['books']['orders']['sha256']);

        /* ومرفقُ المصروف منسوخٌ قبل أن تُحذف نسختُه العاملة */
        $this->assertIsString($zip->getFromName('files/expenses/'.$this->shop->id.'/receipt.pdf'));

        $zip->close();
    }

    /** وأرشيفٌ لا يُكتب يمنع المحو — لا يُمحى شيءٌ ثمّ يُقال «تعذّرت الأرشفة» */
    public function test_nothing_is_deleted_when_the_archive_cannot_be_written(): void
    {
        $bid = $this->shop->id;

        /* مجلّدُ الأرشيف يصير ملفًّا، فيتعذّر إنشاء الملفّ فيه */
        Storage::disk(BusinessPurge::DISK)->put(BusinessPurge::DIR, 'ليس مجلّدًا');

        $this->purge();

        $this->assertNotNull(Business::find($bid), 'مُحيت الشركة والأرشيفُ لم يُكتب');
        $this->assertSame(1, DB::table('products')->where('business_id', $bid)->count());

        /* والصفُّ يقول أين سقطت — في الأرشفة، قبل أن يُمسّ شيء */
        $run = $this->purgeRow();
        $this->assertSame(PurgeRun::FAILED, $run?->status);
        $this->assertSame(PurgeRun::ARCHIVING, $run?->stage, 'قيل إنّها سقطت في غير الأرشفة');
        $this->assertNotNull($run?->error, 'سقطت بلا سببٍ مكتوب');
    }

    /* ═══════════ الجار ═══════════ */

    /** ولا يُمسّ جارٌ بحرف — لا صفٌّ ولا ملفّ */
    public function test_the_neighbour_loses_nothing(): void
    {
        $nid = $this->neighbour->id;
        $before = collect(BusinessPurge::counts($nid));

        $this->purge();

        $this->assertNotNull(Business::find($nid), 'مُحي الجار');
        $this->assertSame($before->all(), BusinessPurge::counts($nid), 'نقص صفٌّ عند الجار');
        $this->assertNotNull(User::find($this->neighbourUser->id), 'مُحي مستخدمُ الجار');
        $this->assertTrue(Storage::disk('local')->exists("expenses/{$nid}/receipt.pdf"), 'مُحي مرفقُ الجار');
    }

    /* ═══════════ المستخدمون ═══════════ */

    /**
     * حساباتُ الشركة وحدَها تُمحى — ومديرُ المنصّة لا يُمسّ.
     *
     * و`business_id` عمودٌ واحد في هذا المخطَّط: لا عضويّةَ متعدّدةَ الشركات
     * تُمثَّل. فالحارسُ يشهد للحال القائمة، ويسقط يومَ يُضاف جدولُ وصلٍ بين
     * المستخدم والشركة — وهو يومٌ يجب أن يُعاد النظرُ فيه هنا.
     */
    public function test_only_this_shops_users_go_and_the_platform_admin_stays(): void
    {
        $this->purge();

        $this->assertNull(User::withTrashed()->find($this->merchant->id), 'بقي حسابُ الشركة');
        $this->assertNotNull(User::find($this->root->id), 'مُحي مديرُ المنصّة');
        $this->assertNotNull(User::find($this->neighbourUser->id), 'مُحي حسابُ الجار');

        $this->assertFalse(
            Schema::hasTable('business_user'),
            'أُضيف جدولُ وصلٍ بين المستخدم والشركة — فأعِد النظر في BusinessPurge::users',
        );
    }

    /** وجلسةُ من مُحي حسابُه تُبطَل معه */
    public function test_the_sessions_of_the_deleted_users_are_revoked(): void
    {
        DB::table('sessions')->insert([
            'id' => 'sess-1', 'user_id' => $this->merchant->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'x', 'payload' => 'y', 'last_activity' => time(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'sess-2', 'user_id' => $this->neighbourUser->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'x', 'payload' => 'y', 'last_activity' => time(),
        ]);

        $this->purge();

        $this->assertNull(DB::table('sessions')->where('id', 'sess-1')->first(), 'بقيت جلسةُ من مُحي');
        $this->assertNotNull(DB::table('sessions')->where('id', 'sess-2')->first(), 'أُبطلت جلسةُ الجار');
    }

    /* ═══════════ الملفّات ═══════════ */

    /** ملفّاتُ الشركة تُحذف بعد المحو، وملفٌّ يشترك فيه غيرُها لا يُمسّ */
    public function test_its_files_go_and_a_shared_path_does_not(): void
    {
        $bid = $this->shop->id;
        $shared = 'products/shared.jpg';

        Storage::disk('public')->put($shared, 'صورةٌ مشتركة');
        Storage::disk('public')->put('logos/mine.png', 'شعاري');

        $this->shop->forceFill(['logo' => 'logos/mine.png'])->save();
        DB::table('products')->where('business_id', $bid)->update(['image' => $shared]);
        DB::table('products')->where('business_id', $this->neighbour->id)->update(['image' => $shared]);

        $this->purge();

        $this->assertFalse(Storage::disk('local')->exists("expenses/{$bid}/receipt.pdf"), 'بقي مرفقُ المصروف');
        $this->assertFalse(Storage::disk('public')->exists('logos/mine.png'), 'بقي الشعار');
        $this->assertTrue(Storage::disk('public')->exists($shared), 'حُذف ملفٌّ يشير إليه صفُّ الجار');
    }

    /* ═══════════ الصلاحيّة والتأكيد ═══════════ */

    /** ولا يمحو إلا مديرُ المنصّة */
    public function test_a_merchant_cannot_purge(): void
    {
        $bid = $this->shop->id;

        $this->actingAs($this->merchant)
            ->delete(route('super-admin.businesses.purge', $bid), ['confirm' => $this->shop->name])
            ->assertForbidden();

        $this->assertNotNull(Business::find($bid));
    }

    /** واسمٌ لا يُطابق يُردّ — ولا يُمحى شيء */
    public function test_a_wrong_name_is_refused(): void
    {
        $bid = $this->shop->id;

        foreach (['', 'متجر الور', 'متجر الجار', 'Wrood'] as $typed) {
            $this->purge(confirm: $typed)->assertSessionHasErrors('confirm');
        }

        $this->assertNotNull(Business::find($bid), 'مُحيت باسمٍ لا يطابق');
        $this->assertSame(1, DB::table('orders')->where('business_id', $bid)->count());
    }

    /** ومسافةٌ زائدةٌ في طرف الاسم لا تُردّ — نسخُ الاسم يلتقطها */
    public function test_stray_spaces_around_the_name_are_forgiven(): void
    {
        $this->purge(confirm: '  '.$this->shop->name.' ')->assertSessionHasNoErrors();
        $this->assertNull(Business::find($this->shop->id));
    }

    /** والمفتاحُ المُقفل يردّ البابَ كلَّه — لا زرَّ ولا مسار */
    public function test_a_closed_flag_answers_404(): void
    {
        config(['purge.enabled' => false]);
        $bid = $this->shop->id;

        $this->purge()->assertNotFound();

        $this->assertNotNull(Business::find($bid));
        $this->assertSame([], Storage::disk(BusinessPurge::DISK)->files(BusinessPurge::DIR));
    }

    /** وعاملان على شركةٍ واحدة: القفلُ يردّ الثاني ولا يمحو على ما مُحي */
    public function test_the_lock_stops_a_second_worker_on_the_same_business(): void
    {
        $lock = cache()->lock('business-purge:'.$this->shop->id, 60);
        $this->assertTrue($lock->get());

        $this->purge();

        $this->assertNotNull(Business::find($this->shop->id), 'مُحيت والقفلُ بيد غيرها');
        $this->assertSame(PurgeRun::FAILED, $this->purgeRow()?->status);

        $lock->release();
    }

    /** وطلبان متلاحقان: الثاني يُردّ ما دامت الأولى تعمل */
    public function test_a_second_request_while_one_is_active_is_refused(): void
    {
        $this->request()->assertSessionHasNoErrors();

        /* والأولى ما زالت في الطابور لم يسحبها عامل */
        $this->assertSame(PurgeRun::PENDING, $this->purgeRow()?->status);

        $this->request()->assertSessionHasErrors('confirm');

        $this->assertSame(1, PurgeRun::count(), 'أُنشئ صفٌّ ثانٍ للشركة نفسِها');
        $this->assertSame(1, DB::table('jobs')->count(), 'صُفّت مهمّةٌ ثانية');
    }

    /* ═══════════ وما كان يعمل يبقى ═══════════ */

    /** والتعطيلُ وإعادةُ التشغيل على حالهما — بابٌ آخرُ لم يُمسّ */
    public function test_disabling_and_reactivating_still_work(): void
    {
        $this->actingAs($this->root)
            ->delete(route('super-admin.businesses.destroy', $this->shop->id))
            ->assertRedirect();

        $this->assertSame('معطل', (string) $this->shop->fresh()->status);
        $this->assertNotNull(Business::find($this->shop->id), 'التعطيلُ محا الشركة');

        $this->actingAs($this->root)->post(route('super-admin.businesses.activate', $this->shop->id));

        $this->assertNotSame('معطل', (string) $this->shop->fresh()->status);
    }

    /* ═══════════ السجلّ ═══════════ */

    /** وسطرٌ يبقى يقول مَن محا وماذا وأين الأرشيف — بلا سرٍّ ولا بيانِ زبون */
    public function test_a_minimal_audit_row_outlives_the_business(): void
    {
        $bid = $this->shop->id;

        $this->purge();

        $row = DB::table('activity_logs')->where('subject_id', $bid)->where('action', 'deleted')
            ->orderByDesc('id')->first();

        $this->assertNotNull($row, 'لا سطرَ في السجلّ');
        $this->assertNull($row->business_id, 'قُيّد على شركةٍ لم تعد موجودة');
        /*
         * والهويّةُ تُقرأ من السطر لا من الجلسة.
         *
         * المهمّةُ تعمل في عاملٍ لا جلسةَ له، فكان السطرُ يقول «زائر» —
         * وأصلُ الشرط أن يُثبت السجلُّ مَن أجرى الحذف. انظر
         * `PurgeBusiness::handle`.
         */
        $this->assertSame($this->root->id, (int) $row->user_id, 'لا يُعرف من محا');
        $this->assertSame($this->root->name, (string) $row->user_name, 'قُيّد باسمٍ غير اسم من محا');
        $this->assertStringContainsString($this->shop->name, $row->description);
        $this->assertStringContainsString(BusinessPurge::DIR, $row->description, 'لا يقول أين الأرشيف');

        /* ولا يحمل سرًّا: كلمةُ المرور المجزّأة لا تُنسخ إلى السجلّ */
        $this->assertStringNotContainsString('$2y$', $row->description);
        $this->assertStringNotContainsString('@abaad.om', $row->description);
    }

    /**
     * ودفترُ بيع أبعادٍ نفسِها يبقى — ورابطُه وحدَه يُفكّ.
     *
     * `crm_leads` سجلُّ أبعادٍ عن تاجرٍ محتمَل، لا بياناتُ تاجر: عمودُه
     * `converted_business_id` بقيد `nullOnDelete` عن قصد. فمحوُ الشركة يُفرغ
     * الرابطَ ويُبقي الصفَّ — ومن باع يبقى له تاريخُ بيعه.
     */
    public function test_abaads_own_sales_lead_survives_with_its_link_cut(): void
    {
        $lead = DB::table('crm_leads')->insertGetId([
            'phone' => '96890001111', 'name' => 'صاحبُ الورد', 'business_name' => 'متجر الورد',
            'source' => 'whatsapp', 'status' => 'converted', 'stage' => 'won',
            'converted_business_id' => $this->shop->id, 'converted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->purge();

        $row = DB::table('crm_leads')->find($lead);

        $this->assertNotNull($row, 'مُحي دفترُ بيع أبعادٍ مع الشركة');
        $this->assertNull($row->converted_business_id, 'بقي الرابطُ إلى شركةٍ لا وجودَ لها');
        $this->assertSame('96890001111', $row->phone);
    }
}
