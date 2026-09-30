<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\BackupService;
use App\Support\Document\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ملفُّ النسخة المرفوع لا يكتب خارج متجره — مهما صُنع.
 *
 * ═══ ما كان يقع ═══
 *
 * الاستعادةُ كانت تثق بالملفّ إلّا في `business_id`. فمالكُ متجر A — وهو
 * وحده من يستعيد — كان يصنع ملفًّا يكتب في متجر B:
 *
 *   • بريدَ استرجاعٍ «موثَّقًا» على حساب مالك B (المطابقةُ بالبريد على المنصّة
 *     كلّها) — فيُسترجع حسابُ B منه. أو حسابًا جديدًا دورُه `super_admin`.
 *   • سطرَ طلبٍ تحت طلبِ B، ومخزونَ فرعٍ على فرعِ B.
 *   • مسارَ مرفقٍ يُشير إلى ملفّ B الخاصّ فيُنزَّل من «مرفق المصروف»، ومسارَ
 *     شعارٍ أو صورةٍ يُشير إلى ملفّ B فيُحذف حين يُبدَّل.
 *   • نطاقًا «مفعَّلًا» بلا موافقة.
 *
 * كلُّ اختبارٍ هنا يرفع ملفًّا صادقًا لمتجر A **عدّله مهاجم**، ثمّ يقيس متجرَ B.
 */
class ARestoreNeverReachesAnotherShopTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        $this->a = $this->shop('متجر A', 'owner-a@abaad.om');
        $this->b = $this->shop('متجر B', 'owner-b@abaad.om');
    }

    /** متجرٌ بمالكٍ وموظّفٍ وفرعٍ وزبونٍ وطلبٍ ومصروفٍ بمرفقٍ ومنتجٍ بصورةٍ وشعار */
    private function shop(string $name, string $email): array
    {
        $bid = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط'])->id;
        $owner = User::create([
            'business_id' => $bid, 'name' => 'المالك', 'email' => $email,
            'password' => bcrypt('secret12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $staff = User::create([
            'business_id' => $bid, 'name' => 'كاشير', 'email' => 'staff-'.$email,
            'password' => bcrypt('secret12345'), 'role' => 'cashier', 'status' => 'نشط',
        ]);
        $branch = DB::table('branches')->insertGetId(['business_id' => $bid, 'name' => 'الرئيسي', 'created_at' => now(), 'updated_at' => now()]);
        $customer = DB::table('customers')->insertGetId(['business_id' => $bid, 'name' => 'زبون', 'phone' => '9000000'.$bid, 'created_at' => now(), 'updated_at' => now()]);
        $order = DB::table('orders')->insertGetId([
            'business_id' => $bid, 'branch_id' => $branch, 'customer_id' => $customer,
            'number' => 'INV-'.$bid.'-1', 'subtotal' => 10, 'tax' => 0, 'total' => 10,
            'payment_method' => 'نقدي', 'status' => 'مكتمل', 'is_held' => false,
            'ordered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $order, 'name' => 'قميص', 'price' => 10, 'cost' => 6, 'quantity' => 1, 'total' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $attachment = "expenses/{$bid}/receipt.pdf";
        Storage::disk('local')->put($attachment, 'SECRET-RECEIPT-OF-'.$name);
        $expense = DB::table('expenses')->insertGetId([
            'business_id' => $bid, 'type' => 'إيجار', 'amount' => 5, 'method' => 'نقدي', 'status' => 'مدفوع',
            'attachment' => $attachment, 'attachment_name' => 'receipt.pdf', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $image = "products/{$bid}/rose.jpg";
        Storage::disk('public')->put($image, 'IMAGE-OF-'.$name);
        $product = DB::table('products')->insertGetId([
            'business_id' => $bid, 'name' => 'باقة', 'sku' => 'P-'.$bid, 'price' => 10, 'cost' => 6,
            'quantity' => 3, 'alert_qty' => 1, 'active' => true, 'image' => $image, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $logo = "logos/{$bid}/logo.png";
        Storage::disk('public')->put($logo, 'LOGO-OF-'.$name);
        DB::table('businesses')->where('id', $bid)->update(['logo' => $logo]);

        $cover = "document-covers/{$bid}/cover.png";
        Storage::disk('public')->put($cover, 'COVER-OF-'.$name);
        DB::table('settings')->insert(['business_id' => $bid, 'key' => Branding::COVER, 'value' => $cover, 'created_at' => now(), 'updated_at' => now()]);

        return compact('bid', 'owner', 'staff', 'branch', 'customer', 'order', 'expense', 'attachment', 'product', 'image', 'logo', 'cover');
    }

    /** نسخةُ A الصادقة، يعدّلها المهاجم ثمّ يرفعها مالكُ A */
    private function restoreTampered(callable $tamper): TestResponse
    {
        $payload = BackupService::payload($this->a['bid']);
        $payload = $tamper($payload);
        $file = UploadedFile::fake()->createWithContent('backup.json', json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $this->actingAs($this->a['owner'])
            ->post(route('admin.backup.restore'), ['backup' => $file, 'confirm' => true])
            ->assertRedirect();
    }

    private function refused(TestResponse $res): void
    {
        $this->assertSame('danger', session('toast')['type'] ?? null, 'نسخةٌ تعبر إلى متجرٍ آخر استُعيدت');
    }

    /** بصمةُ متجرٍ لا تتغيّر إن لم يُمسّ */
    private function census(int $bid): array
    {
        return [
            'orders' => DB::table('orders')->where('business_id', $bid)->count(),
            'items' => DB::table('order_items')->whereIn('order_id', DB::table('orders')->where('business_id', $bid)->select('id'))->count(),
            'customers' => DB::table('customers')->where('business_id', $bid)->count(),
            'branch_stocks' => DB::table('branch_stocks')->where('business_id', $bid)->count(),
            'expenses' => DB::table('expenses')->where('business_id', $bid)->count(),
        ];
    }

    /* ══════════════ ١ · الحسابات ══════════════ */

    public function test_a_file_cannot_plant_a_recovery_email_on_another_shops_owner(): void
    {
        $this->restoreTampered(function ($p) {
            $p['users'][] = [
                'email' => 'owner-b@abaad.om', 'name' => 'مخطوف', 'role' => 'admin',
                'recovery_email' => 'attacker@evil.test', 'recovery_email_verified_at' => '2026-01-01 00:00:00',
            ];

            return $p;
        });

        $b = $this->b['owner']->fresh();
        $this->assertNull($b->recovery_email, 'بريدُ استرجاعٍ من ملفّ متجرٍ آخر كُتب على مالك B');
        $this->assertNull($b->recovery_email_verified_at);
        $this->assertSame('المالك', $b->name);
        $this->assertSame($this->b['bid'], (int) $b->business_id);
    }

    public function test_a_file_cannot_touch_the_platform_admin(): void
    {
        $admin = User::create(['business_id' => null, 'name' => 'Super', 'email' => 'super@abaad.om', 'password' => bcrypt('x12345678'), 'role' => 'super_admin', 'status' => 'نشط']);

        $this->restoreTampered(function ($p) {
            $p['users'][] = ['email' => 'super@abaad.om', 'name' => 'مخطوف', 'recovery_email' => 'attacker@evil.test', 'recovery_email_verified_at' => '2026-01-01 00:00:00'];

            return $p;
        });

        $fresh = $admin->fresh();
        $this->assertNull($fresh->recovery_email);
        $this->assertSame('Super', $fresh->name);
        $this->assertNull($fresh->business_id);
    }

    public function test_a_file_cannot_mint_a_platform_admin(): void
    {
        $this->restoreTampered(function ($p) {
            $p['users'][] = ['email' => 'minted@evil.test', 'name' => 'مدير مزيَّف', 'role' => 'super_admin', 'recovery_email' => 'attacker@evil.test', 'recovery_email_verified_at' => '2026-01-01 00:00:00'];

            return $p;
        });

        $this->assertSame(0, User::where('role', 'super_admin')->count(), 'ملفٌّ صنع مديرَ منصّة');
        $minted = User::where('email', 'minted@evil.test')->first();
        $this->assertNotNull($minted, 'الموظّفُ الجديد في الملفّ يُنشأ كما كان');
        $this->assertSame($this->a['bid'], (int) $minted->business_id);
        $this->assertNull($minted->recovery_email);
    }

    /** وموظّفو المتجر نفسِه يُستعادون كما كانوا — بياناتُ عملهم لا مفاتيحُ حساباتهم */
    public function test_the_shops_own_staff_still_come_back(): void
    {
        $this->restoreTampered(function ($p) {
            foreach ($p['users'] as &$u) {
                if ($u['email'] === 'staff-owner-a@abaad.om') {
                    $u['name'] = 'كاشير مستعاد';
                    $u['recovery_email'] = 'attacker@evil.test';
                    $u['recovery_email_verified_at'] = '2026-01-01 00:00:00';
                }
            }

            return $p;
        });

        $staff = $this->a['staff']->fresh();
        $this->assertSame('كاشير مستعاد', $staff->name);
        $this->assertNull($staff->recovery_email, 'مفتاحُ حسابٍ جاء من ملفّ');
    }

    /** وحسابُ متجرٍ آخر المحذوفُ حسابُ غيره أيضًا — لا يُكتب عليه، ولا يُسقط الاستعادة */
    public function test_a_file_cannot_reach_another_shops_deleted_account(): void
    {
        $this->b['staff']->delete();

        $this->restoreTampered(function ($p) {
            $p['users'][] = ['email' => 'staff-owner-b@abaad.om', 'name' => 'مخطوف', 'deleted_at' => null];

            return $p;
        });

        $gone = User::withTrashed()->where('email', 'staff-owner-b@abaad.om')->first();
        $this->assertSame('كاشير', $gone->name, 'ملفُّ A كتب على حسابٍ محذوفٍ من B');
        $this->assertSame($this->b['bid'], (int) $gone->business_id);
        $this->assertTrue($gone->trashed(), 'ملفُّ A أعاد حسابًا حذفه B');
        $this->assertNotSame('danger', session('toast')['type'] ?? null, 'سطرٌ لغير المتجر يُترك، لا يُسقط الاستعادة');
    }

    /** وموظّفُ المتجر المحذوفُ في نسخته يعود محذوفًا كما كان — لا تسقط به الاستعادة */
    public function test_the_shops_own_deleted_staff_restore_as_they_were(): void
    {
        $this->a['staff']->delete();

        $this->restoreTampered(fn ($p) => $p);

        $this->assertNotSame('danger', session('toast')['type'] ?? null);
        $staff = User::withTrashed()->where('email', 'staff-owner-a@abaad.om')->first();
        $this->assertSame(1, User::withTrashed()->where('email', 'staff-owner-a@abaad.om')->count());
        $this->assertTrue($staff->trashed());
        $this->assertSame($this->a['bid'], (int) $staff->business_id);
    }

    /* ══════════════ ٢ · الدفاتر ══════════════ */

    public function test_a_line_under_another_shops_order_refuses_the_whole_restore(): void
    {
        $before = [$this->census($this->a['bid']), $this->census($this->b['bid'])];

        $res = $this->restoreTampered(function ($p) {
            $p['order_items'][] = ['id' => 990001, 'order_id' => $this->b['order'], 'name' => 'مدسوس', 'price' => 1, 'cost' => 0, 'quantity' => 1, 'total' => 1];

            return $p;
        });

        $this->refused($res);
        $this->assertSame($before, [$this->census($this->a['bid']), $this->census($this->b['bid'])], 'الاستعادةُ مسّت متجرًا');
        $this->assertNull(DB::table('order_items')->where('id', 990001)->first());
    }

    public function test_stock_on_another_shops_branch_refuses_the_whole_restore(): void
    {
        $before = [$this->census($this->a['bid']), $this->census($this->b['bid'])];

        $res = $this->restoreTampered(function ($p) {
            $p['branch_stocks'][] = ['id' => 990002, 'branch_id' => $this->b['branch'], 'product_id' => $this->a['product'], 'quantity' => 50];

            return $p;
        });

        $this->refused($res);
        $this->assertSame($before, [$this->census($this->a['bid']), $this->census($this->b['bid'])]);
    }

    public function test_an_order_pointing_at_another_shops_customer_refuses_the_whole_restore(): void
    {
        $res = $this->restoreTampered(function ($p) {
            foreach ($p['orders'] as &$o) {
                $o['customer_id'] = $this->b['customer'];
            }

            return $p;
        });

        $this->refused($res);
        $this->assertSame($this->a['customer'], (int) DB::table('orders')->where('id', $this->a['order'])->value('customer_id'));
    }

    /* ══════════════ ٣ · الملفّات ══════════════ */

    public function test_an_attachment_path_cannot_reach_another_shops_file(): void
    {
        $this->restoreTampered(function ($p) {
            foreach ($p['expenses'] as &$e) {
                $e['attachment'] = $this->b['attachment'];
            }

            return $p;
        });

        $this->assertNull(DB::table('expenses')->where('id', $this->a['expense'])->value('attachment'), 'مسارُ ملفّ B كُتب في مصروف A');
        $this->actingAs($this->a['owner'])->get(route('admin.expenses.attachment', $this->a['expense']))->assertNotFound();
        Storage::disk('local')->assertExists($this->b['attachment']);
    }

    public function test_the_shops_own_attachment_survives_its_restore(): void
    {
        $this->restoreTampered(fn ($p) => $p);

        $this->assertSame($this->a['attachment'], DB::table('expenses')->where('id', $this->a['expense'])->value('attachment'));
        $this->assertSame($this->a['image'], DB::table('products')->where('id', $this->a['product'])->value('image'));
        $this->assertSame($this->a['logo'], DB::table('businesses')->where('id', $this->a['bid'])->value('logo'));
    }

    public function test_a_logo_image_or_cover_path_cannot_point_at_another_shops_file(): void
    {
        $this->restoreTampered(function ($p) {
            $p['business']['logo'] = $this->b['logo'];
            foreach ($p['products'] as &$pr) {
                $pr['image'] = $this->b['image'];
            }
            foreach ($p['settings'] as &$s) {
                if ($s['key'] === Branding::COVER) {
                    $s['value'] = $this->b['cover'];
                }
            }

            return $p;
        });

        $this->assertSame($this->a['logo'], DB::table('businesses')->where('id', $this->a['bid'])->value('logo'), 'شعارُ B صار شعارَ A — وتبديلُه يحذف ملفَّ B');
        $this->assertNull(DB::table('products')->where('id', $this->a['product'])->value('image'));
        $this->assertNull(DB::table('settings')->where('business_id', $this->a['bid'])->where('key', Branding::COVER)->value('value'));

        foreach (['logo', 'image', 'cover'] as $k) {
            Storage::disk('public')->assertExists($this->b[$k]);
        }
    }

    /* ══════════════ ٤ · النطاق ══════════════ */

    public function test_a_file_cannot_claim_a_domain_the_shop_did_not_hold(): void
    {
        $this->restoreTampered(function ($p) {
            $p['website_domains'][] = [
                'id' => 990003, 'hostname' => 'shop-b.om', 'normalized_hostname' => 'shop-b.om',
                'type' => 'custom', 'is_primary' => true, 'status' => 'active',
            ];

            return $p;
        });

        $this->assertSame(0, DB::table('website_domains')->where('normalized_hostname', 'shop-b.om')->count(), 'ملفٌّ فعّل نطاقًا لم يكن للمتجر');
    }
}
