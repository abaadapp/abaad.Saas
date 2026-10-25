<?php

namespace Tests\Feature;

use App\Http\Controllers\PdfController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerPaymentLink;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Demo;
use App\Support\DocumentPaper;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * اسمُ العميل كما كُتب — يُحفظ ويُقرأ هو نفسُه، بأيّ لغةٍ كانت الواجهة.
 *
 * ═══ ما كان ═══
 *
 * «Ahmed Al Balushi» يُكتب في `name` «أحمد البلوشي» بنقلٍ آليّ والأصلُ يُنحّى
 * إلى `name_en`، ثمّ تختار الشاشةُ بينهما بلغة الواجهة (`Demo::ln`). فالعميلُ
 * الواحد يُقرأ باسمين، ويُطبع على فاتورته اسمٌ لم يكتبه أحد.
 *
 * ═══ وما يُحرس ═══
 *
 *   - كلُّ بابٍ يحفظ الاسمَ كما كُتب — عربيًّا وإنجليزيًّا وخليطًا: شاشةُ
 *     العملاء، والصندوق، ونافذةُ الفاتورة، والموقع، والاستيراد، والتعديل.
 *   - وكلُّ شاشةٍ وورقةٍ تقرؤه هو نفسُه باللغتين: القائمة، والملفّ، وقائمةُ
 *     الصندوق، والطلبات، وصفحةُ الطلب، والإيصال، والفاتورة.
 *   - والقديمُ لا يُعاد كتابته: `name_en` المحفوظُ قبلُ يبقى، والبحثُ يجده.
 *   - ولغةُ رسائل واتساب اختيارٌ مستقلّ عن الاسم.
 *   - والمورّدُ على قاعدته (`LocalName`) — لم تُمسّ.
 *
 * انظر `Customers::asEntered`.
 */
class ACustomersNameIsKeptAsWrittenTest extends TestCase
{
    use RefreshDatabase;

    /** عربيّ، وإنجليزيّ، وخليط — كما يكتبها الناس */
    private const NAMES = ['أحمد البلوشي', 'Ahmed Al Balushi', 'Mohammed محمد'];

    private Business $shop;

    private Branch $branch;

    private User $owner;

    private Product $rose;

    private int $phone = 99330000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-01 10:00:00');
        app()->setLocale('ar');
        config(['storefront.ribbon_english_checkout_businesses' => [], 'storefront.ribbon_gift_card_product_businesses' => [], 'storefront.ribbon_free_gift_card_message_businesses' => []]);

        $this->shop = Business::create([
            'name' => 'RIBBON', 'type' => 'محل ورد', 'status' => 'نشط', 'phone' => '96895259066',
            'city' => 'مسقط', 'site_slug' => 'ribbon', 'tier' => 'gold', 'storefront_theme' => 'ribbon',
        ]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الخوير']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@abaad.om',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        MarketingSettings::save($this->shop->id, 'website', [
            'store_on' => '1', 'store_pay_cod' => '1',
            'store_delivery_areas' => 'الخوير', 'store_delivery_slots' => '9 ص – 12 م', 'store_delivery_fee' => '2',
        ]);

        $this->rose = Product::create([
            'business_id' => $this->shop->id, 'name' => 'باقة ورد', 'price' => 20, 'cost' => 8,
            'quantity' => 100, 'alert_qty' => 1, 'active' => true, 'published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function phone(): string
    {
        return (string) ++$this->phone;
    }

    private function as(string $locale): static
    {
        return $this->actingAs($this->owner)->withSession(['locale' => $locale, 'current_branch' => $this->branch->id]);
    }

    private function row(string $phone): Customer
    {
        return Customer::where('business_id', $this->shop->id)->where('phone', $phone)->sole();
    }

    /** الإيصالُ والفاتورة كما يُطبعان — نصًّا بلا وسوم */
    private function paper(Order $order, string $locale, bool $thermal): string
    {
        $was = app()->getLocale();
        app()->setLocale($locale);

        try {
            $html = PdfController::saleHtml($this->shop->id, $order->fresh('items'), thermal: $thermal)['html'];
        } finally {
            app()->setLocale($was);
        }

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('#<style.*?</style>#su', '', $html))));
    }

    /* ═════════════ كلُّ بابٍ يحفظه كما كُتب ═════════════ */

    public function test_the_customers_screen_saves_the_name_as_written_in_either_language(): void
    {
        foreach (['ar', 'en'] as $locale) {
            foreach (self::NAMES as $name) {
                $phone = $this->phone();

                $this->as($locale)->post(route('admin.customers.store'), [
                    'name' => $name, 'phone' => $phone, 'language' => 'ar',
                ])->assertSessionHasNoErrors();

                $this->assertSame($name, $this->row($phone)->name, "شاشة العملاء بلغة {$locale}");
                $this->assertNull($this->row($phone)->name_en, 'كُتب اسمٌ ثانٍ لم يكتبه أحد');
            }
        }
    }

    /** وحقلٌ «بالإنجليزية» من صفحةٍ قديمة لا يُكتب — الاسمُ واحد */
    public function test_a_stale_english_name_field_is_not_written(): void
    {
        $phone = $this->phone();

        $this->as('ar')->post(route('admin.customers.store'), [
            'name' => 'أحمد البلوشي', 'name_en' => 'Ahmed', 'phone' => $phone, 'language' => 'ar',
        ])->assertSessionHasNoErrors();

        $this->assertSame('أحمد البلوشي', $this->row($phone)->name);
        $this->assertNull($this->row($phone)->name_en);
    }

    public function test_the_till_saves_the_name_as_written_and_hands_it_back_as_is(): void
    {
        foreach (['ar', 'en'] as $locale) {
            foreach (self::NAMES as $name) {
                $phone = $this->phone();

                $this->as($locale)->postJson(route('pos.customers.store'), [
                    'name' => $name, 'phone' => $phone, 'language' => 'en',
                ])->assertOk()
                    ->assertJsonPath('customer.name', $name)
                    ->assertJsonPath('customer.label', $name);

                $this->assertSame($name, $this->row($phone)->name, "الصندوق بلغة {$locale}");
                $this->assertNull($this->row($phone)->name_en);
            }
        }
    }

    public function test_the_invoice_screen_saves_the_name_as_written(): void
    {
        foreach (self::NAMES as $name) {
            $phone = $this->phone();

            $this->as('en')->post(route('admin.customerInvoices.storeCustomer'), [
                'name' => $name, 'phone' => $phone, 'language' => 'ar',
            ])->assertSessionHasNoErrors();

            $this->assertSame($name, $this->row($phone)->name);
            $this->assertNull($this->row($phone)->name_en);
        }
    }

    public function test_the_website_saves_the_buyers_name_as_written(): void
    {
        foreach (['ar' => 'ar', 'en' => 'en'] as $lang) {
            foreach (self::NAMES as $name) {
                $phone = '968'.$this->phone();

                $this->postJson('/s/ribbon/checkout'.($lang === 'en' ? '?lang=en' : ''), [
                    'items' => [['id' => $this->rose->id, 'qty' => 1]],
                    'fulfil' => 'delivery', 'pay' => 'cod', 'name' => $name, 'phone' => $phone,
                    'area' => 'الخوير', 'address' => 'Way 3021, villa 12', 'date' => '2027-02-03', 'slot' => '9 ص – 12 م',
                ])->assertOk();

                $this->assertSame($name, $this->row($phone)->name);
                $this->assertNull($this->row($phone)->name_en);
                $this->assertSame($name, Order::latest('id')->first()->customer_name, 'الطلبُ حمل اسمًا غيرَ ما كتبه الزبون');
            }
        }
    }

    public function test_an_import_keeps_the_name_as_written(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "الاسم,الهاتف,اللغة\nأحمد البلوشي,99440001,العربية\nAhmed Al Balushi,99440002,English\nMohammed محمد,99440003,ar\n");

        $this->as('en')->post(route('admin.customers.import.upload'), [
            'file' => new UploadedFile($path, 'customers.csv', 'text/csv', null, true),
        ]);
        $this->as('en')->post(route('admin.customers.import.confirm'));

        $this->assertSame('أحمد البلوشي', $this->row('99440001')->name);
        $this->assertSame('Ahmed Al Balushi', $this->row('99440002')->name);
        $this->assertSame('Mohammed محمد', $this->row('99440003')->name);
        $this->assertSame(0, Customer::whereNotNull('name_en')->count());
    }

    /* ═════════════ والتعديلُ كذلك — ولا يمحو ما حُفظ قبلُ ═════════════ */

    public function test_editing_saves_the_name_as_written_and_keeps_the_earlier_english_name(): void
    {
        // عميلٌ قديم نُقل اسمُه آليًّا: العربيُّ المنقول في `name` والأصلُ في `name_en`
        $old = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'أحمد البلوشي', 'name_en' => 'Ahmed Al Balushi',
            'phone' => $this->phone(), 'language' => 'en',
        ]);

        // تعديلُ الهاتف وحده: الاسمان كما هما
        $this->as('en')->put(route('admin.customers.update', $old->id), [
            'name' => 'أحمد البلوشي', 'phone' => $phone = $this->phone(), 'language' => 'en',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['أحمد البلوشي', 'Ahmed Al Balushi', $phone], [$old->fresh()->name, $old->fresh()->name_en, $old->fresh()->phone]);

        // ويُعاد إلى ما كتبه صاحبُه — وحقلٌ «بالإنجليزية» قديمٌ لا يُكتب
        $this->as('ar')->put(route('admin.customers.update', $old->id), [
            'name' => 'Ahmed Al Balushi', 'name_en' => 'Something else', 'phone' => $phone, 'language' => 'en',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Ahmed Al Balushi', $old->fresh()->name);
        $this->assertSame('Ahmed Al Balushi', $old->fresh()->name_en, 'مُحي ما حُفظ قبلُ أو كُتب فوقه');

        // وخليطٌ يبقى خليطًا
        $this->as('en')->put(route('admin.customers.update', $old->id), [
            'name' => 'Mohammed محمد', 'phone' => $phone, 'language' => 'en',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Mohammed محمد', $old->fresh()->name);
    }

    /* ═════════════ وكلُّ شاشةٍ وورقةٍ تقرؤه هو نفسُه ═════════════ */

    public function test_every_screen_and_paper_reads_the_same_name_in_both_languages(): void
    {
        foreach (self::NAMES as $i => $name) {
            /*
             * و`name_en` و`customer_name_en` محمولان عمدًا — كما في سجلٍّ قديم.
             * فإن قرأ موضعٌ أحدَهما بالإنجليزية ظهر «Legacy» بدل الاسم.
             */
            $customer = Customer::create([
                'business_id' => $this->shop->id, 'name' => $name, 'name_en' => "Legacy {$i}",
                'phone' => $this->phone(), 'language' => 'ar',
            ]);
            $order = Order::create([
                'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'branch' => 'الخوير',
                'number' => 'INV-00010'.$i, 'status' => 'مكتمل', 'customer_id' => $customer->id,
                'customer_name' => $name, 'customer_name_en' => "Legacy {$i}",
                'employee_name' => 'الكاشير', 'payment_method' => 'نقدي', 'payment_status' => 'مدفوع',
                'subtotal' => 20, 'discount' => 0, 'tax' => 0, 'total' => 20, 'ordered_at' => now(),
            ]);
            OrderItem::create(['order_id' => $order->id, 'name' => 'باقة ورد', 'price' => 20, 'quantity' => 1, 'total' => 20]);

            foreach (['ar', 'en'] as $locale) {
                $at = "بلغة {$locale} للاسم «{$name}»";

                $list = collect($this->as($locale)->get(route('admin.customers.index'))->viewData('page')['props']['customers']);
                $this->assertSame($name, $list->firstWhere('id', $customer->id)['label'] ?? 'غائب', "قائمة العملاء {$at}");

                $profile = $this->as($locale)->get(route('admin.customers.show', $customer->id))->viewData('page')['props']['customer'];
                $this->assertSame($name, $profile['label'] ?? 'غائب', "ملفّ العميل {$at}");

                $orders = collect($this->as($locale)->get(route('admin.orders.index'))->viewData('page')['props']['orders']);
                $this->assertSame($name, $orders->firstWhere('id', $order->number)['customer'] ?? 'غائب', "قائمة الطلبات {$at}");

                $page = $this->as($locale)->get(route('admin.orders.show', $order->number))->viewData('page')['props']['order'];
                $this->assertSame($name, $page['customer'] ?? 'غائب', "صفحة الطلب {$at}");

                // قائمةُ الصندوق
                $this->actingAs($this->owner);
                app()->setLocale($locale);
                $this->assertSame($name, collect(Demo::customers())->firstWhere('id', $customer->id)['label'] ?? 'غائب', "قائمة الصندوق {$at}");
                app()->setLocale('ar');

                // والإيصالُ الحراريّ والفاتورة
                foreach ([true, false] as $thermal) {
                    $text = $this->paper($order, $locale, $thermal);
                    $this->assertStringContainsString($name, $text, ($thermal ? 'الإيصال ' : 'الفاتورة ').$at);
                    $this->assertStringNotContainsString("Legacy {$i}", $text, ($thermal ? 'الإيصال ' : 'الفاتورة ').$at);
                }

                // وورقةُ المستند — طرفُ «العميل»
                app()->setLocale($locale);
                $party = collect(DocumentPaper::forSale($order->fresh('items'))['parties'])->firstWhere('cap', 'العميل');
                app()->setLocale('ar');
                $this->assertSame($name, $party['lines'][0] ?? 'غائب', "ورقة المستند {$at}");
            }
        }
    }

    /** والجرسُ كذلك: «عميل راكد» و«وصلت دفعة… عبر رابط الدفع» */
    public function test_the_bell_names_the_customer_as_written_in_both_languages(): void
    {
        $customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'Mohammed محمد', 'name_en' => 'Legacy',
            'phone' => $this->phone(), 'language' => 'ar',
        ]);
        Order::create([
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id,
            'customer_name' => 'Mohammed محمد', 'number' => 'OLD-1', 'status' => 'مكتمل',
            'subtotal' => 10, 'total' => 10, 'ordered_at' => now()->subDays(200),
        ]);
        $link = CustomerPaymentLink::create([
            'business_id' => $this->shop->id, 'customer_id' => $customer->id, 'reference' => 'ref-'.uniqid(),
            'amount' => 5, 'currency' => 'OMR', 'status' => CustomerPaymentLink::PAID, 'paid_at' => now(), 'is_live' => true,
        ]);

        $this->actingAs($this->owner);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $bell = collect(Demo::allNotifications());

            $dormant = $bell->first(fn ($n) => str_starts_with($n['key'], 'dormant-'));
            $paid = $bell->firstWhere('key', 'link-paid-'.$link->id);
            $this->assertNotNull($dormant, "لا تنبيهَ ركودٍ بلغة {$locale}");
            $this->assertNotNull($paid, "لا تنبيهَ دفعةٍ بلغة {$locale}");
            $this->assertStringContainsString('Mohammed محمد', $dormant['text'], "الراكد بلغة {$locale}");
            $this->assertStringContainsString('Mohammed محمد', $paid['text'], "الدفعة بلغة {$locale}");
            $this->assertStringNotContainsString('Legacy', $dormant['text'].$paid['text']);
        }
        app()->setLocale('ar');
    }

    /* ═════════════ والقديمُ لا يُعاد كتابته ═════════════ */

    public function test_an_old_customer_is_not_rewritten_and_reads_one_name(): void
    {
        $old = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'أحمد البلوشي', 'name_en' => 'Ahmed Al Balushi',
            'phone' => $phone = $this->phone(), 'language' => 'en',
        ]);

        foreach (['ar', 'en'] as $locale) {
            $this->as($locale)->get(route('admin.customers.index'))->assertOk();
            $this->as($locale)->get(route('admin.customers.show', $old->id))->assertOk();
        }

        // وبيعةٌ له من الصندوق
        $this->as('en')->postJson(route('pos.checkout'), [
            'items' => [['id' => $this->rose->id, 'name' => 'باقة ورد', 'qty' => 1]],
            'customer' => 'أحمد البلوشي', 'customer_id' => $old->id,
            'payment_method' => 'نقدي', 'client_uuid' => uniqid('n', true),
        ])->assertOk();

        $this->assertSame(['أحمد البلوشي', 'Ahmed Al Balushi', $phone], [$old->fresh()->name, $old->fresh()->name_en, $old->fresh()->phone]);
        $this->assertSame('أحمد البلوشي', Order::where('customer_id', $old->id)->sole()->customer_name);
    }

    /** والبحثُ يجد الاسمَ كما كُتب — ويجد القديمَ بأصله اللاتينيّ المحفوظ */
    public function test_search_finds_the_name_as_written_and_an_earlier_english_name(): void
    {
        foreach (self::NAMES as $name) {
            Customer::create(['business_id' => $this->shop->id, 'name' => $name, 'phone' => $this->phone(), 'language' => 'ar']);
        }
        Customer::create(['business_id' => $this->shop->id, 'name' => 'سالم الحارثي', 'name_en' => 'Salim Al Harthi', 'phone' => $this->phone(), 'language' => 'ar']);

        $found = fn (string $q, string $locale) => array_column(
            $this->as($locale)->get(route('admin.customers.index', ['q' => $q]))->viewData('page')['props']['customers'],
            'label',
        );

        foreach (['ar', 'en'] as $locale) {
            $this->assertSame(['Ahmed Al Balushi'], $found('Balushi', $locale));
            $this->assertSame(['أحمد البلوشي'], $found('البلوشي', $locale));
            $this->assertSame(['Mohammed محمد'], $found('Mohammed', $locale));
            $this->assertSame(['Mohammed محمد'], $found('محمد', $locale));
            $this->assertSame(['سالم الحارثي'], $found('Salim', $locale), 'لم يُجد القديمُ بأصله');
        }
    }

    /** والصندوقُ يربط البيعةَ بالاسم الذي يعرضه — وبالأصل القديم كذلك */
    public function test_the_till_links_a_sale_by_the_name_it_shows(): void
    {
        $new = Customer::create(['business_id' => $this->shop->id, 'name' => 'Ahmed Al Balushi', 'phone' => $this->phone(), 'language' => 'en']);
        $old = Customer::create(['business_id' => $this->shop->id, 'name' => 'سالم الحارثي', 'name_en' => 'Salim Al Harthi', 'phone' => $this->phone(), 'language' => 'ar']);

        foreach ([[$new, 'Ahmed Al Balushi'], [$old, 'Salim Al Harthi']] as [$customer, $typed]) {
            $this->as('en')->postJson(route('pos.checkout'), [
                'items' => [['id' => $this->rose->id, 'name' => 'باقة ورد', 'qty' => 1]],
                'customer' => $typed, 'payment_method' => 'نقدي', 'client_uuid' => uniqid('l', true),
            ])->assertOk();

            $this->assertSame($customer->id, (int) Order::latest('id')->first()->customer_id, "لم تُربط البيعة بـ«{$typed}»");
        }
    }

    /* ═════════════ ولغةُ الرسائل اختيارٌ مستقلّ ═════════════ */

    public function test_the_message_language_does_not_follow_the_name(): void
    {
        $this->as('ar')->postJson(route('pos.customers.store'), [
            'name' => 'أحمد البلوشي', 'phone' => $a = $this->phone(), 'language' => 'en',
        ])->assertOk();
        $this->as('en')->post(route('admin.customers.store'), [
            'name' => 'Ahmed Al Balushi', 'phone' => $b = $this->phone(), 'language' => 'ar',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['أحمد البلوشي', 'en'], [$this->row($a)->name, $this->row($a)->language]);
        $this->assertSame(['Ahmed Al Balushi', 'ar'], [$this->row($b)->name, $this->row($b)->language]);
    }

    /* ═════════════ والمورّدُ على قاعدته ═════════════ */

    public function test_a_suppliers_name_keeps_its_own_rule(): void
    {
        $this->as('ar')->post(route('admin.suppliers.store'), ['name' => 'Ahmed Al Shamsi'])->assertSessionHasNoErrors();

        $supplier = Supplier::where('business_id', $this->shop->id)->sole();
        $this->assertSame('Ahmed Al Shamsi', $supplier->name_en);
        $this->assertSame('أحمد الشامسي', $supplier->name, 'تغيّرت قاعدة المورّد');
    }
}
