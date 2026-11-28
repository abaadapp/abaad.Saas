<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 *   - و`/app` تحرسها صلاحيّةُ «لوحة التحكم» نفسُها.
 */
class TheInstalledAppOpensOnTodayTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create([
            'name' => 'ورد الخوير', 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '91234567',
        ]);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
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

    public function test_the_app_is_guarded_by_the_dashboard_permission(): void
    {
        $user = $this->user('sales');
        $user->forceFill(['permissions' => ['orders', 'customers']])->save();

        $this->assertFalse($user->refresh()->allows('dashboard'));
        $this->actingAs($user)->get('/app')->assertForbidden();
    }
}
