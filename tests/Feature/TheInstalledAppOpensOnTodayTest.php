<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use App\Support\Demo;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `/app` — ما يفتحه التطبيقُ المثبَّت: أرقامُ اليوم وأزرارٌ قليلة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الضيفُ يُحوَّل إلى الدخول، ثمّ يعود إلى `/app` (`redirect()->intended`).
 *   - البطاقاتُ هي أوّلُ بطاقات اللوحة بقيمها: الهاتفُ لا يقول رقمًا غيرَ ما
 *     تقوله اللوحة (`Demo::todayCards` من دوالّ `adminStats`).
 *   - الزرُّ لا يُرسل لمن لا يفتح صفحتَه، وكلُّ زرٍّ يُرسل يفتح فعلًا عبر
 *     النواة بوسطائها — لا بابَ يردّ بـ403 في تطبيقٍ لا شريطَ رجوعٍ فيه.
 *   - ومن لا يملك «لوحة التحكم» يُحوَّل إلى صفحته (الكاشيرُ إلى نقطة البيع).
 *     والآيبادُ والحاسوبُ يُحوَّلان إلى اللوحة في الواجهة (`AppHome.tsx`).
 */
class TheInstalledAppOpensOnTodayTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create([
            'name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567',
        ]);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
    }

    private function sale(Business $shop, Branch $branch, float $total, Carbon $at): void
    {
        Order::create([
            'business_id' => $shop->id, 'branch_id' => $branch->id, 'channel' => 'pos',
            'number' => 'INV-'.uniqid(), 'status' => 'مكتمل', 'payment_status' => 'مدفوع', 'is_held' => false,
            'payment_method' => 'نقدي', 'subtotal' => $total, 'discount' => 0, 'tax' => 0, 'total' => $total,
            'ordered_at' => $at,
        ]);
    }

    private function user(string $role): User
    {
        return User::create([
            'business_id' => $this->shop->id, 'name' => 'م '.$role, 'email' => $role.'@abaad.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    /** @return array<string, mixed> */
    private function props(User $user): array
    {
        $response = $this->actingAs($user)->get('/app')->assertOk();
        $page = $response->viewData('page');
        $this->assertSame('Admin/AppHome', $page['component']);

        return $page['props'];
    }

    public function test_a_guest_signs_in_and_comes_back_to_the_app(): void
    {
        $this->get('/app')->assertRedirect(route('login'));

        $owner = $this->user('admin');
        $this->post(route('login.attempt'), ['email' => $owner->email, 'password' => 'password'])
            ->assertRedirect('/app');
    }

    public function test_the_cards_are_the_dashboards_first_cards_to_the_digit(): void
    {
        $owner = $this->user('admin');

        $cards = $this->props($owner)['cards'];
        $labels = array_column($cards, 'label');
        $this->assertSame(['مبيعات اليوم', 'مقبوضات اليوم', 'مستحقات العملاء'], $labels, 'بلا Paymob ولا رابطٍ حقيقيّ: لا بطاقةَ روابط');

        $dashboard = $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk()->viewData('page')['props']['stats'];
        $this->assertSame(array_slice($dashboard, 0, count($cards)), $cards);
    }

    /**
     * «مبيعات اليوم» تُقاس على أمس — ومن متجره وحده.
     *
     * البطاقةُ نُقلت من `adminStats` إلى `salesTodayCard`، ولم يكن يحرس
     * مقارنتَها بأمسِ حارس: يومٌ يُقارَن بنفسه كان يمرّ «0%» على كلّ حال.
     */
    public function test_todays_sales_are_this_shops_and_measured_against_yesterday(): void
    {
        $this->sale($this->shop, $this->branch, 10, now()->subDay()->setTime(12, 0));
        $this->sale($this->shop, $this->branch, 5, now()->setTime(0, 30));

        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $this->sale($other, Branch::create(['business_id' => $other->id, 'name' => 'الرئيسي']), 900, now()->setTime(0, 30));

        $card = $this->props($this->user('admin'))['cards'][0];

        $this->assertSame('مبيعات اليوم', $card['label']);
        $this->assertSame(Demo::money(5), $card['value']);
        $this->assertSame(['−50%', false], [$card['trend'], $card['up']]);
    }

    public function test_the_owner_gets_every_button(): void
    {
        $links = $this->props($this->user('admin'))['links'];

        $this->assertSame(['orders', 'customers', 'paymentLink', 'sales'], array_column($links, 'key'));
        $this->assertSame(route('admin.finance.paymentLinks'), $links[2]['url']);
        $this->assertSame(route('admin.dashboard'), $this->props($this->user('manager'))['fullUrl']);
    }

    /** دورُ المبيعات لا يملك المالية: لا زرَّ «رابط دفع» — والباقي كما هو */
    public function test_a_button_is_sent_only_to_who_may_open_it(): void
    {
        $this->assertSame(['orders', 'customers', 'sales'], array_column($this->props($this->user('sales'))['links'], 'key'));
        $this->assertSame([], $this->props($this->user('inventory'))['links']);
    }

    public function test_every_button_sent_opens_through_the_kernel(): void
    {
        foreach (['admin', 'sales', 'accountant', 'delivery'] as $role) {
            $user = $this->user($role);
            foreach ($this->props($user)['links'] as $link) {
                $this->actingAs($user)->get($link['url'])->assertOk();
            }
        }
    }

    /**
     * من لا يرى اللوحة يُحوَّل إلى صفحته — لا ٤٠٣ ولا شاشةُ الأرقام.
     *
     * الكاشيرُ يثبّت التطبيقَ على آيباد الصندوق فيفتح على نقطة البيع، ومن
     * مُنح الطلبات والعملاء وحدهما يفتح على أوّل قسمٍ له. والوجهةُ تفتح
     * فعلًا، ولا تعود إلى `/app`.
     */
    public function test_whoever_may_not_see_the_dashboard_is_sent_to_their_own_page(): void
    {
        $cashier = $this->user('cashier');
        $this->actingAs($cashier)->get('/app')->assertRedirect(route('pos.index'));

        $clerk = $this->user('sales');
        $clerk->forceFill(['permissions' => ['orders', 'customers']])->save();
        $this->assertFalse($clerk->refresh()->allows('dashboard'));

        $home = Permissions::homeFor($clerk);
        $this->assertStringNotContainsString('/app', $home);
        $this->assertNotSame(route('admin.dashboard'), $home);
        $this->actingAs($clerk)->get('/app')->assertRedirect($home);
        $this->actingAs($clerk)->get($home)->assertOk();
    }

    /** وحسابٌ بلا وجهةٍ يُرفض — لا يدور بين `/app` والدخول */
    public function test_an_account_with_nowhere_to_go_is_refused_not_looped(): void
    {
        $nobody = $this->user('sales');
        $nobody->forceFill(['permissions' => []])->save();

        $this->assertSame(route('login'), Permissions::homeFor($nobody->refresh()));
        $this->actingAs($nobody)->get('/app')->assertForbidden();
    }
}
