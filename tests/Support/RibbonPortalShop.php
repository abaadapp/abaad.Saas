<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Ledger;
use App\Support\OrderStatus;
use App\Support\RibbonPortal\Access;
use App\Support\SalesChannel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/** متجرُ RIBBON كما يقرؤه النظام: معرّفُه في القائمة ونطاقُه مثبَّتٌ له — ومتجرٌ جارٌ بلا شيءٍ منها */
trait RibbonPortalShop
{
    protected Business $ribbon;

    protected Business $neighbour;

    protected User $owner;

    protected Branch $branch;

    protected Customer $maryam;

    protected Product $bouquet;

    protected function openRibbon(): void
    {
        // عدّادُ المسار (عشرٌ في الدقيقة) يُحرس باسمه في اختباره — وهنا تُحرس عدّاداتُ الدخول نفسُها
        $this->withoutMiddleware(ThrottleRequests::class);
        // وروابطُ اللوحة على مضيفها ولو سبقها طلبٌ على مضيف البوابة
        URL::forceRootUrl('http://localhost');

        $this->ribbon = $this->shop('RIBBON', 'ribbon');
        $this->neighbour = $this->shop('متجر الجار', 'neighbour');
        // رقمُ المتجر كما أُنشئ هنا — لا رقمٌ مقصود: القائمةُ تُقرأ بمعرّفه
        config([
            'storefront.ribbon_customer_portal' => ['business' => (int) $this->ribbon->id, 'enabled' => true],
            'storefront.subdomain_businesses' => [(int) $this->ribbon->id => 'ribbon'],
        ]);

        $this->branch = Branch::create(['business_id' => $this->ribbon->id, 'name' => 'الخوير']);
        $this->owner = $this->staffer('سعود', 'admin');
        $this->maryam = $this->buyer('مريم البلوشي', '91234567');
        $this->bouquet = Product::create(['business_id' => $this->ribbon->id, 'name' => 'باقة', 'price' => 10, 'cost' => 4, 'quantity' => 1000, 'alert_qty' => 1, 'active' => true]);
    }

    protected function shop(string $name, string $slug): Business
    {
        $shop = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط', 'phone' => '24000000']);
        $shop->forceFill(['site_slug' => $slug, 'identity_confirmed_at' => now()])->save();
        Ledger::seedChart($shop->id);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);

        return $shop;
    }

    protected function staffer(string $name, string $role, ?array $permissions = null, ?Business $shop = null): User
    {
        return User::create([
            'business_id' => ($shop ?? $this->ribbon)->id, 'name' => $name, 'email' => uniqid('p').'@portal.test',
            'password' => bcrypt('x'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions,
        ]);
    }

    protected function buyer(string $name, ?string $phone, ?Business $shop = null, array $over = []): Customer
    {
        return Customer::create($over + ['business_id' => ($shop ?? $this->ribbon)->id, 'name' => $name, 'phone' => $phone, 'language' => 'ar', 'points' => 0, 'allow_credit_sales' => true]);
    }

    protected function order(Customer $customer, string $number, array $over = []): Order
    {
        $order = Order::create($over + [
            'business_id' => $customer->business_id, 'branch_id' => null, 'branch' => 'الخوير', 'customer_id' => $customer->id, 'customer_name' => $customer->name,
            'number' => $number, 'employee_name' => 'الكاشير سالم', 'status' => OrderStatus::COMPLETED, 'is_held' => false, 'channel' => SalesChannel::POS,
            'payment_method' => 'نقدي', 'payment_status' => 'مدفوع', 'subtotal' => 20, 'total' => 20, 'ordered_at' => now(), 'internal_notes' => 'سرٌّ داخليّ',
        ]);
        $order->items()->create(['name' => 'باقة '.$number, 'quantity' => 2, 'price' => 10, 'total' => 20, 'cost' => 3.777]);

        return $order;
    }

    /* ─────────── من يفتح البوابة ─────────── */

    protected function my(string $path = '', ?string $cookie = null): TestResponse
    {
        return $this->visitor($cookie)->get('http://ribbon.abaadapp.om/my'.$path);
    }

    protected function myJson(string $path, ?string $cookie = null): TestResponse
    {
        return $this->visitor($cookie)->getJson('http://ribbon.abaadapp.om/my'.$path);
    }

    /** زائرٌ بلا جلسة نظام — وكعكتُه إن كانت */
    protected function visitor(?string $cookie): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = $cookie === null ? [] : [Access::COOKIE => $cookie];

        return $this->withCredentials();
    }

    protected function key(Customer $customer): string
    {
        return explode('#', Access::share($customer->fresh(), null)['url'])[1];
    }

    protected function attempt(string $key, string $phone): TestResponse
    {
        return $this->visitor(null)->postJson('http://ribbon.abaadapp.om/my/session', ['key' => $key, 'phone' => $phone]);
    }

    /** يدخل برابطه ورقمه — ويردّ كعكةَ جلسته */
    protected function enter(Customer $customer, ?string $phone = null): string
    {
        $response = $this->attempt($this->key($customer), $phone ?? (string) $customer->phone)->assertOk();

        return (string) $response->getCookie(Access::COOKIE)->getValue();
    }
}
