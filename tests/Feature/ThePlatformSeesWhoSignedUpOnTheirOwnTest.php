<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\NotificationState;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Lists\SelfSignupsList;
use App\Support\Signup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * مديرُ المنصّة يرى من فتح متجرَه بنفسه — في صفحة الشركات وفي جرسه.
 *
 * ═══ وما يحرسه ═══
 *
 *  ١. الأرقامُ الأربعة من التسجيلات الذاتيّة وحدها، بتعاريف `Business`:
 *     لا اليدويّةُ ولا القديمةُ ولا التجريبيّة.
 *  ٢. قسمُ صفحة الشركات وصفحتُه الكاملة: صفوفُ الشركات نفسُها، مقصوصةٌ من
 *     الخادم، وتفاصيلُها من مصادرها — بلا استعلامٍ لكلّ صفّ.
 *  ٣. البابان لمدير المنصّة وحده.
 *  ٤. الجرسُ يقول «تسجيل جديد من الموقع» لكلّ مديرٍ في المنصّة، ولا يقوله
 *     لتاجر، ولا عن شركةٍ أُضيفت يدويًّا. والإخفاءُ لكلٍّ منهم وحده.
 */
class ThePlatformSeesWhoSignedUpOnTheirOwnTest extends TestCase
{
    use RefreshDatabase;

    private Plan $basic;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        RateLimiter::clear('signup:127.0.0.1');

        $this->basic = Plan::create(['name' => 'الباقة الأساسية', 'code' => Plan::CODE_BASIC, 'monthly_price' => 9.9, 'yearly_price' => 99]);
        $this->boss = $this->superAdmin('boss@abaad.om');
    }

    private function superAdmin(string $email): User
    {
        return User::create([
            'name' => 'مدير '.$email, 'email' => $email, 'password' => bcrypt('password'),
            'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /** شركةٌ مختومةٌ بما يُمرَّر — في تجربتها افتراضًا */
    private function shop(string $name, array $over = [], bool $paid = false): Business
    {
        $b = Business::create($over + [
            'name' => $name, 'type' => 'محل ورود', 'owner_name' => 'مالك '.$name,
            'phone' => '+96890000000', 'status' => 'نشط', 'plan_id' => $this->basic->id,
            'starts_at' => now()->subDays(3)->toDateString(),
            'ends_at' => now()->addDays(11)->toDateString(),
            'registration_source' => Business::SOURCE_SELF_SIGNUP,
        ]);

        if ($paid) {
            Invoice::create([
                'number' => 'INV-'.$b->id, 'business_id' => $b->id, 'plan_id' => $this->basic->id,
                'amount' => 9.9, 'issued_at' => now()->toDateString(), 'status' => 'مدفوعة',
            ]);
        }

        return $b;
    }

    /** الحالاتُ كلُّها — وما لا يُعدّ معها */
    private function mixedPopulation(): array
    {
        return [
            'trialing' => $this->shop('في التجربة'),
            'expired' => $this->shop('انتهت', ['ends_at' => now()->subDays(2)->toDateString()]),
            'subscribed' => $this->shop('دفع', [], paid: true),
            // دفع ثمّ انقضى اشتراكُه — ليس «انتهت تجربتُه بلا دفع» ولا «مشترك»
            'lapsed' => $this->shop('دفع وانقضى', ['ends_at' => now()->subDays(5)->toDateString()], paid: true),
            // عُطّل في تجربته — ليس «في التجربة»
            'disabled' => $this->shop('معطّل', ['status' => 'معطل']),
            // وما لا يدخل القسمَ أصلًا
            'demo' => $this->shop('تجريبي', ['is_demo' => true]),
            'manual' => $this->shop('يدوي', ['registration_source' => Business::SOURCE_PLATFORM_MANUAL]),
            'legacy' => $this->shop('قديم', ['registration_source' => null]),
        ];
    }

    /* ═══════════════ ١ · الأرقام ═══════════════ */

    public function test_the_four_numbers_count_self_signups_only_by_the_existing_definitions(): void
    {
        $this->mixedPopulation();

        $this->assertSame([
            'total' => 5,       // الخمسة المختومة الحقيقيّة — لا التجريبيّ ولا اليدويّ ولا القديم
            'trialing' => 1,    // `Business::trialing`
            'expired' => 1,     // انقضى بلا فاتورةٍ مدفوعة
            'subscribed' => 1,  // `Business::subscribed`
        ], SelfSignupsList::stats());
    }

    /** وما يقوله الرقمُ يقوله الصفّ — الترشيحُ والحالةُ من التعاريف نفسِها */
    public function test_each_row_says_the_state_its_number_counts(): void
    {
        $shops = $this->mixedPopulation();
        $this->actingAs($this->boss);

        foreach (SelfSignupsList::STATES as $state) {
            $rows = $this->get(route('super-admin.businesses.self-signups.index', ['state' => $state]))
                ->assertOk()->viewData('page')['props']['rows'];

            $this->assertSame([$shops[$state]->id], array_column($rows, 'id'), $state);
            $this->assertSame($state, $rows[0]['state']);
        }

        $all = collect($this->get(route('super-admin.businesses.self-signups.index'))->viewData('page')['props']['rows'])->keyBy('id');
        $this->assertNull($all[$shops['lapsed']->id]['state'], 'يُعرض بحالته لا في أقرب الثلاث');
        $this->assertNull($all[$shops['disabled']->id]['state']);
        $this->assertFalse($all->has($shops['manual']->id));
        $this->assertFalse($all->has($shops['legacy']->id));
        $this->assertFalse($all->has($shops['demo']->id));
    }

    /* ═══════════════ ٢ · القسمُ وصفحتُه ═══════════════ */

    public function test_the_businesses_page_carries_the_section_above_its_own_table(): void
    {
        $this->mixedPopulation();
        foreach (range(1, 6) as $i) {
            $this->travel(1)->minutes();
            $this->shop("جديد {$i}");
        }

        $this->actingAs($this->boss)->get(route('super-admin.businesses.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Businesses/Index')
                ->where('selfSignups.stats.total', 11)
                ->has('selfSignups.recent', SelfSignupsList::RECENT)
                // الأحدثُ أوّلًا
                ->where('selfSignups.recent.0.name', 'جديد 6')
                // والجدولُ الرئيسيّ كما كان: كلُّ الشركات الحقيقيّة، يدويّةً وقديمة
                ->has('businesses', 10)
                ->where('pagination.total', 13));
    }

    public function test_an_empty_section_says_so(): void
    {
        $this->actingAs($this->boss)->get(route('super-admin.businesses.index'))
            ->assertInertia(fn ($page) => $page
                ->where('selfSignups.stats.total', 0)
                ->has('selfSignups.recent', 0));
    }

    public function test_the_full_list_is_cut_by_the_server_and_searchable(): void
    {
        foreach (range(1, SelfSignupsList::PER_PAGE + 3) as $i) {
            $this->shop("متجر {$i}");
        }
        $this->shop('Ribbon Flowers', ['owner_name' => 'سعود']);
        $this->actingAs($this->boss);

        $first = $this->get(route('super-admin.businesses.self-signups.index'))->viewData('page')['props'];
        $this->assertCount(SelfSignupsList::PER_PAGE, $first['rows']);
        $this->assertSame(SelfSignupsList::PER_PAGE + 4, $first['pagination']['total']);

        $second = $this->get(route('super-admin.businesses.self-signups.index', ['page' => 2]))->viewData('page')['props'];
        $this->assertCount(4, $second['rows']);

        $found = $this->get(route('super-admin.businesses.self-signups.index', ['q' => 'سعود']))->viewData('page')['props']['rows'];
        $this->assertSame(['Ribbon Flowers'], array_column($found, 'name'));

        // وحالةٌ لا يعرفها لا تُرشّح ولا تُسقط الصفحة
        $this->get(route('super-admin.businesses.self-signups.index', ['state' => "x' or 1=1"]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.state', null)->where('pagination.total', SelfSignupsList::PER_PAGE + 4));
    }

    /** التفاصيلُ من مصادرها: المالكُ والإعدادُ والمنتجاتُ والطلباتُ وختمُ التهيئة */
    public function test_a_row_tells_what_the_business_did_since_it_signed_up(): void
    {
        RateLimiter::clear('signup:127.0.0.1');
        $this->post(route('register.store'), [
            'name' => 'سعود', 'phone' => '+96891234567', 'type' => 'محل ورود', 'shop' => 'Ribbon Flowers',
            'team_size' => '6–10 موظفين', 'address' => 'القرم', 'email' => 'saud@example.com', 'password' => 'abaad2026',
        ]);
        $this->post(route('logout'));
        $b = Business::firstOrFail();

        $branch = Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);
        foreach (['ورد أحمر', 'ورد أبيض'] as $name) {
            Product::create(['business_id' => $b->id, 'name' => $name, 'price' => 5, 'cost' => 2, 'quantity' => 10, 'alert_qty' => 1, 'active' => true]);
        }
        foreach ([[3, 'مكتمل'], [1, 'مكتمل'], [0, Order::CANCELLED]] as $n => [$daysAgo, $status]) {
            Order::create([
                'business_id' => $b->id, 'branch_id' => $branch->id, 'number' => 'INV-00'.$n, 'customer_name' => 'زبون',
                'status' => $status, 'payment_status' => 'مدفوع', 'is_held' => false, 'payment_method' => 'نقدي',
                'subtotal' => 5, 'discount' => 0, 'tax' => 0, 'total' => 5, 'ordered_at' => now()->subDays($daysAgo),
            ]);
        }
        $b->update(['identity_confirmed_at' => now()]);

        $row = $this->actingAs($this->boss)->get(route('super-admin.businesses.self-signups.index'))
            ->viewData('page')['props']['rows'][0];

        $this->assertSame('Ribbon Flowers', $row['name']);
        $this->assertSame('سعود', $row['owner']);
        $this->assertSame('محل ورود', $row['type']);
        $this->assertSame('+96891234567', $row['phone']);
        $this->assertSame('saud@example.com', $row['contactEmail']);
        $this->assertSame('saud@example.com', $row['loginEmail']);
        $this->assertSame('6–10 موظفين', $row['teamSize']);
        $this->assertSame('القرم', $row['address']);
        $this->assertSame('الباقة الأساسية', $row['plan']);
        $this->assertSame(now()->toDateString(), $row['trialStarts']);
        $this->assertSame(now()->addDays(Signup::TRIAL_DAYS)->toDateString(), $row['trialEnds']);
        $this->assertSame(14, $row['daysLeft']);
        $this->assertSame(SelfSignupsList::TRIALING, $row['state']);
        $this->assertNotNull($row['setupConfirmedAt']);
        $this->assertNotNull($row['lastLogin'], 'دخولُ التسجيل مختوم');
        $this->assertSame(2, $row['products']);
        // الملغاةُ لا تُعدّ بيعًا — كما في «آخر بيعة» بقائمة الشركات
        $this->assertSame(now()->subDays(3)->toDateString(), $row['firstSale']);
        $this->assertSame(now()->subDays(1)->toDateString(), $row['lastSale']);
    }

    /** صفحةٌ بعشرين صفًّا تكلّف ما تكلّفه صفحةٌ بصفّين */
    public function test_the_list_costs_the_same_for_two_rows_and_for_twenty(): void
    {
        $this->actingAs($this->boss);
        $cost = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('super-admin.businesses.self-signups.index'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        foreach (range(1, 2) as $i) {
            $this->shop("قليل {$i}");
        }
        $few = $cost();

        foreach (range(1, 18) as $i) {
            $this->shop("كثير {$i}");
        }
        $many = $cost();

        $this->assertSame($few, $many, "صفحةُ الصفّين {$few} استعلامًا، وصفحةُ العشرين {$many}");
    }

    /* ═══════════════ ٣ · لمدير المنصّة وحده ═══════════════ */

    public function test_only_the_platform_manager_reaches_the_self_signups(): void
    {
        $shop = $this->shop('متجر تاجر');
        foreach (['admin', 'manager', 'cashier'] as $role) {
            $user = User::create([
                'business_id' => $shop->id, 'name' => $role, 'email' => "{$role}@shop.om",
                'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            ]);

            $this->actingAs($user)->get(route('super-admin.businesses.self-signups.index'))->assertForbidden();
            $this->actingAs($user)->get(route('super-admin.businesses.index'))->assertForbidden();
        }

        auth()->logout();
        $this->get(route('super-admin.businesses.self-signups.index'))->assertRedirect(route('login'));
    }

    /* ═══════════════ ٤ · الجرس ═══════════════ */

    private function feed(User $user): array
    {
        $door = $user->isSuperAdmin() ? 'super-admin.notifications.feed' : 'admin.notifications.feed';

        return $this->actingAs($user)->getJson(route($door))->assertOk()->json('items');
    }

    private function signUp(): Business
    {
        RateLimiter::clear('signup:127.0.0.1');
        $this->post(route('register.store'), [
            'name' => 'سعود', 'phone' => '+96891234567', 'type' => 'محل ورود', 'shop' => 'Ribbon Flowers',
            'email' => 'saud@example.com', 'password' => 'abaad2026',
        ])->assertRedirect();
        $this->post(route('logout'));

        return Business::where('registration_source', Business::SOURCE_SELF_SIGNUP)->firstOrFail();
    }

    public function test_a_signup_rings_every_platform_manager_and_opens_the_business(): void
    {
        $business = $this->signUp();
        $other = $this->superAdmin('second@abaad.om');

        foreach ([$this->boss, $other] as $manager) {
            $row = collect($this->feed($manager))->firstWhere('key', 'self-signup-business-'.$business->id);

            $this->assertNotNull($row, $manager->email.': لا تسجيلَ في جرسه');
            $this->assertSame('تسجيل جديد من الموقع: Ribbon Flowers — سعود', $row['text']);
            $this->assertSame(route('super-admin.businesses.show', $business->id), $row['url']);
            $this->assertStringContainsString('الباقة الأساسية', $row['time']);
            $this->assertStringContainsString('تجربة حتى '.now()->addDays(14)->format('d-m-Y'), $row['time']);
            $this->assertSame('info', $row['kind'], 'خبرٌ يُقرأ ويُخفى لا مهمّةٌ تُنجَز');
        }
    }

    public function test_no_merchant_hears_of_it(): void
    {
        $business = $this->signUp();
        $owner = User::where('business_id', $business->id)->firstOrFail();
        $stranger = $this->shop('متجر آخر', ['registration_source' => null]);
        $merchant = User::create([
            'business_id' => $stranger->id, 'name' => 'تاجر', 'email' => 'merchant@shop.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        foreach ([$owner, $merchant] as $user) {
            $keys = array_column($this->feed($user), 'key');
            $this->assertEmpty(preg_grep('/^self-signup-business-/', $keys), $user->email);
        }
    }

    /** وما أُضيف يدويًّا أو قبل الختم أو تجريبيًّا أو قبل أسبوع — لا يُقال «من الموقع» */
    public function test_only_a_recent_self_signup_rings(): void
    {
        $this->actingAs($this->boss)->post(route('super-admin.businesses.store'), [
            'name' => 'متجر يدوي', 'type' => 'عام', 'status' => 'نشط',
            'login_username' => 'manualshop', 'login_password' => 'secret123',
        ]);
        $this->shop('قديم', ['registration_source' => null]);
        $this->shop('تجريبي', ['is_demo' => true]);
        $old = $this->shop('قبل أسبوع');
        $old->forceFill(['created_at' => now()->subDays(SelfSignupsList::BELL_DAYS + 1)])->save();
        $fresh = $this->shop('اليوم');

        $keys = preg_grep('/^self-signup-business-/', array_column($this->feed($this->boss), 'key'));

        $this->assertSame(['self-signup-business-'.$fresh->id], array_values($keys));
    }

    public function test_hiding_it_is_each_managers_own_and_writes_no_state(): void
    {
        $business = $this->signUp();
        $key = 'self-signup-business-'.$business->id;
        $other = $this->superAdmin('second@abaad.om');

        $this->actingAs($this->boss)->postJson(route('super-admin.notifications.dismiss'), ['key' => $key])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertNotContains($key, array_column($this->feed($this->boss), 'key'), 'أخفاه فلا يعود');
        $this->assertContains($key, array_column($this->feed($other), 'key'), 'وإخفاؤه لا يُخفيه عن غيره');
        $this->assertSame(0, NotificationState::count(), 'لا صفَّ حالةٍ لمن لا متجرَ له');

        // والاسمُ يتبدّل والمفتاحُ رقمُ الشركة — فيبقى مخفيًّا
        $business->update(['name' => 'Ribbon Flowers & Gifts']);
        $this->assertNotContains($key, array_column($this->feed($this->boss), 'key'));
    }
}
