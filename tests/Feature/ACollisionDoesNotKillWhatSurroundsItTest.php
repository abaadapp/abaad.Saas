<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Support\Contention;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * اصطدامٌ متوقَّع لا يقتل ما حوله.
 *
 * ═══ ما كان ═══
 *
 * أربعةُ مواضع تكتب صفًّا وتتوقّع أن يردّها فهرسٌ فريد أحيانًا، فتلتقط
 * الاصطدام وتمضي. وهو صحيحٌ على SQLite وMySQL.
 *
 * **وممنوعٌ على PostgreSQL:** أوّلُ أمرٍ يفشل داخل معاملة يُجهضها كلَّها،
 * فكلُّ أمرٍ بعده يُردّ حتى تُلغى. فالتقاطُ الاستثناء لا يُنقذ شيئًا —
 * المعاملةُ ميّتةٌ في اليد.
 *
 * وقاعدةُ الإنتاج PostgreSQL. فتغييرُ حالةِ طلبٍ يبعث رسالةً مكرّرة كان
 * **يُجهض تغييرَ الحالة نفسِه**، لا الرسالةَ وحدها.
 *
 * ═══ وهذه الاختباراتُ تسقط على PostgreSQL وحدَه ═══
 *
 * على SQLite تمرّ بلا فرقٍ سواءٌ أكانت نقطةُ الحفظ موضوعةً أم لا — المحرّكُ
 * لا يُجهض. فهي مكتوبةٌ لوظيفة PostgreSQL في CI: هناك تحرس، وهنا توثّق.
 */
class ACollisionDoesNotKillWhatSurroundsItTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
    }

    /* ==================== الأداةُ نفسها ==================== */

    /** الكتابةُ الناجحة تعود بقيمتها */
    public function test_a_write_that_lands_returns_its_value(): void
    {
        $row = Contention::attempt(fn () => Setting::create([
            'business_id' => $this->business->id, 'key' => 'k1', 'value' => '1',
        ]));

        $this->assertNotNull($row);
        $this->assertDatabaseHas('settings', ['key' => 'k1']);
    }

    /**
     * والمصطدمةُ تعود بـnull — والمعاملةُ المحيطة تبقى حيّة.
     *
     * وهذا هو الحارس: بلا نقطة الحفظ يُردّ الاستعلامُ التالي على PostgreSQL
     * بـ`25P02` فيسقط الاختبار.
     */
    public function test_a_collision_returns_null_and_leaves_the_transaction_alive(): void
    {
        WhatsAppMessage::create($this->message('dup-1'));

        DB::transaction(function () {
            $again = Contention::attempt(fn () => WhatsAppMessage::create($this->message('dup-1')));

            $this->assertNull($again);

            // ‏والمعاملةُ ما زالت تقبل: قراءةً ثمّ كتابةً
            $this->assertSame(1, WhatsAppMessage::count());

            Setting::create(['business_id' => $this->business->id, 'key' => 'after', 'value' => 'ok']);
        });

        $this->assertDatabaseHas('settings', ['key' => 'after']);
        $this->assertSame(1, WhatsAppMessage::count());
    }

    /** وإعادةُ المحاولة تنجح بعد اصطدام — كلُّ محاولةٍ في نقطة حفظها */
    public function test_a_retry_succeeds_after_a_collision(): void
    {
        WhatsAppMessage::create($this->message('taken'));

        DB::transaction(function () {
            $key = 'taken';

            $row = Contention::retry(function () use (&$key) {
                $mine = $key;
                // ‏المحاولةُ الأولى تصطدم، والثانية تكتب
                $key = 'free';

                return WhatsAppMessage::create($this->message($mine));
            }, 3);

            $this->assertSame('free', $row->dedupe_key);

            // ‏والمعاملةُ حيّةٌ بعد الاصطدام الأوّل
            Setting::create(['business_id' => $this->business->id, 'key' => 'after-retry', 'value' => 'ok']);
        });

        $this->assertDatabaseHas('whatsapp_messages', ['dedupe_key' => 'free']);
        $this->assertDatabaseHas('settings', ['key' => 'after-retry']);
    }

    /** وبعد آخر محاولةٍ يُرمى الاستثناء ولا يُبتلع صامتًا */
    public function test_a_retry_that_never_lands_throws(): void
    {
        WhatsAppMessage::create($this->message('always'));

        $this->expectException(UniqueConstraintViolationException::class);

        Contention::retry(fn () => WhatsAppMessage::create($this->message('always')), 2);
    }

    /* ==================== المواضعُ الأربعة ==================== */

    /**
     * رسالةُ واتساب مكرّرة لا تُسقط ما حولها.
     *
     * والحدثُ نفسُه يقع مرّتين واقعًا لا استثناءً: طلبٌ يُنقل إلى «جاهز» ثمّ
     * يُعاد ثمّ يُنقل إليها ثانيةً.
     */
    public function test_a_duplicate_whatsapp_message_does_not_abort_its_transaction(): void
    {
        WhatsAppMessage::create($this->message('1:1:ready'));

        DB::transaction(function () {
            $this->assertNull(
                Contention::attempt(fn () => WhatsAppMessage::create($this->message('1:1:ready'))),
            );

            $this->assertSame(1, WhatsAppMessage::where('dedupe_key', '1:1:ready')->count());
        });
    }

    /** ورصيدُ فرعٍ يُنشأ مرّتين يُقرأ ويُعدَّل ولا يُجهض معاملةَ البيع */
    public function test_a_duplicate_branch_stock_row_does_not_abort_its_transaction(): void
    {
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة', 'price' => 1, 'cost' => 1, 'quantity' => 0,
        ]);

        BranchStock::adjust($this->business->id, $branch->id, $product->id, 5);

        DB::transaction(function () use ($branch, $product) {
            // الصفُّ موجودٌ الآن: هذه تُعدّله ولا تُنشئه
            BranchStock::adjust($this->business->id, $branch->id, $product->id, 3);

            Setting::create(['business_id' => $this->business->id, 'key' => 'after-stock', 'value' => 'ok']);
        });

        $this->assertSame(8, (int) BranchStock::where('branch_id', $branch->id)
            ->where('product_id', $product->id)->value('quantity'));
        $this->assertDatabaseHas('settings', ['key' => 'after-stock']);
    }

    /**
     * ورقمُ الفاتورة في نقطة البيع يُعاد طلبُه فينجح.
     *
     * وحلقةُ المحاولات كانت بلا معنى على PostgreSQL: الأولى تُجهض المعاملة،
     * فتسقط الأربعُ الباقيات لسببٍ وقع مرّةً — ويُردّ الكاشيرُ عن بيعةٍ
     * رقمُها كان متاحًا.
     */
    public function test_the_till_still_numbers_an_order_after_a_collision(): void
    {
        $branch = Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        $user = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'k@abaad.om',
            'password' => bcrypt('password'), 'role' => 'cashier', 'status' => 'نشط',
        ]);

        $taken = Order::create([
            'business_id' => $this->business->id, 'branch_id' => $branch->id,
            'number' => 'POS-000001', 'status' => 'مكتمل', 'total' => 1,
            'customer_name' => '—', 'created_at' => now(),
        ]);

        DB::transaction(function () use ($branch, $taken) {
            $n = 1;

            $order = Contention::retry(function () use ($branch, &$n) {
                return Order::create([
                    'business_id' => $this->business->id, 'branch_id' => $branch->id,
                    'number' => 'POS-'.str_pad((string) $n++, 6, '0', STR_PAD_LEFT),
                    'status' => 'مكتمل', 'total' => 2, 'customer_name' => '—',
                ]);
            }, 5);

            $this->assertSame('POS-000002', $order->number);
            $this->assertNotSame($taken->id, $order->id);
        });

        $this->assertSame(2, Order::count());

        unset($user);
    }

    /**
     * ولا يُبتلع اصطدامٌ خارج نقطة حفظ بعد اليوم.
     *
     * أربعةُ مواضع أُصلحت، وخامسٌ يُكتب غدًا ينسخ سطرَ الالتقاط من جاره
     * فيعود العطبُ صامتًا — ولا يظهر إلّا على PostgreSQL، أي عند التاجر لا
     * عندنا. ونقطةُ الأثر تحرس نفسها.
     *
     * والاستثناءُ الوحيد `Contention` نفسُها: هي موضعُ نقطة الحفظ.
     */
    public function test_no_one_swallows_a_collision_outside_a_savepoint(): void
    {
        $offenders = [];

        foreach ([base_path('app'), base_path('routes')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();

                if (str_ends_with($path, 'Contention.php')) {
                    continue;
                }

                $source = file_get_contents($path);

                if (! str_contains($source, 'catch (UniqueConstraintViolationException')
                    && ! str_contains($source, 'catch (\\Illuminate\\Database\\UniqueConstraintViolationException')) {
                    continue;
                }

                $offenders[] = str_replace(base_path().'/', '', $path);
            }
        }

        $this->assertSame([], $offenders, 'اصطدامٌ يُلتقط خارج Contention — يُجهض المعاملة على PostgreSQL');
    }

    /** @return array<string, mixed> */
    private function message(string $key): array
    {
        return [
            'business_id' => $this->business->id,
            'dedupe_key' => $key,
            'source_mode' => 'shared',
            'event_type' => 'order_ready',
            'recipient_phone' => '+96890000000',
            'status' => 'queued',
        ];
    }
}
