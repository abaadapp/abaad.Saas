<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\BoutiqueAccount;
use App\Support\Boutiques;
use App\Support\CustomerInvoices;
use App\Support\CustomerPayments;
use App\Support\Ledger;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * كلُّ بابٍ في التطبيق — لا عيّنةٌ منه — مغلقٌ على حساب صاحب البوتيك إلّا رفَّه.
 *
 * حسابٌ مربوطٌ ببوتيكه أُعطي كلَّ قسمٍ وكلَّ فعلٍ وصندوقَ واتساب، ودورَ «admin»
 * بصلاحيّاتٍ يدويّة (أوسعُ ما يُربط). ثمّ يُطرق كلُّ مسارٍ مسجَّلٍ يطلب الدخول،
 * بكلّ طريقةٍ يقبلها: ما ليس في قائمة رفِّه يُردّ قبل أن يبلغ متحكّمَه.
 *
 * وما يُفتح له من رفِّه يُقرأ كلُّه — الصفحةُ وما يُؤجَّل منها — فلا يحمل
 * مبلغًا واحدًا من مال المتجر: لا إجماليَّ طلبٍ ولا فاتورةٍ ولا ورقةٍ ولا
 * قيدٍ ولا حركة. المبالغُ «كناري» فريدة تُقرأ من القاعدة بعد البيع.
 *
 * والقائمةُ المفتوحة مكتوبةٌ هنا حرفًا بحرف: بابٌ يُضاف إلى `BoutiqueAccount`
 * يُسقط هذا الاختبار حتى يُراجَع.
 */
class EveryDoorOutsideABoutiqueShelfIsShutTest extends TestCase
{
    use RefreshDatabase;

    /** ما يُفتح له — نسخةٌ مستقلّة عن `BoutiqueAccount` عن قصد */
    private const OPEN = [
        'logout', 'profile.edit', 'profile.update', 'impersonate.stop', 'subscription.expired',
        'admin.language.update', 'admin.currency.switch',
        'admin.products.index', 'admin.products.create', 'admin.products.store',
        'admin.products.show', 'admin.products.edit', 'admin.products.update',
        'admin.products.quick', 'admin.products.toggle', 'admin.products.destroy',
        'admin.products.duplicate', 'admin.products.websiteStatus',
        'admin.products.images.store', 'admin.products.images.order', 'admin.products.images.destroy',
        'admin.products.images.destroyMain', 'admin.products.images.promote',
        'admin.products.variants.store', 'admin.products.variants.update', 'admin.products.variants.destroy',
    ];

    private Business $shop;

    private User $owner;

    private User $rich;

    private Boutique $lama;

    private Product $ring;

    /** @var array<string, Product> أصنافٌ ليست لها: ريم، والمحلّ، ومتجرٌ آخر */
    private array $foreign = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->app->setLocale('ar');
        foreach (array_keys((array) config('storefront')) as $key) {
            if (str_starts_with($key, 'ribbon_') && str_ends_with($key, '_businesses')) {
                config(['storefront.'.$key => []]);
            }
        }

        $this->shop = Business::create(['name' => 'متجر الورد', 'type' => 'عام', 'status' => 'نشط', 'tier' => 'gold', 'boutiques_enabled' => true, 'boutique_components_enabled' => true]);
        Currency::create(['business_id' => $this->shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($this->shop->id);
        $branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);
        $this->owner = User::create(['business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@abaad.om', 'password' => bcrypt('secret-pass-1'), 'role' => 'admin', 'status' => 'نشط']);

        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك ريم', 'commission_rate' => 10, 'active' => true]);
        $p = fn (string $n, float $pr, ?int $b) => Product::create(['business_id' => $this->shop->id, 'name' => $n, 'price' => $pr, 'cost' => $b ? 0 : 1.111, 'quantity' => 500, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $b]);
        $this->ring = $p('خاتم لمى', 53.917, $this->lama->id);
        $scarf = $p('وشاح ريم', 61.373, $reem->id);
        $flowers = $p('ورد جوري', 79.129, null);
        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        $elsewhere = Boutique::create(['business_id' => $other->id, 'name' => 'بوتيك المتجر الآخر', 'commission_rate' => 15, 'active' => true]);
        $this->foreign = [
            'ريم' => $scarf, 'المحلّ' => $flowers,
            'متجرٌ آخر' => Product::create(['business_id' => $other->id, 'name' => 'صنف متجر آخر', 'price' => 5, 'cost' => 0, 'quantity' => 9, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $elsewhere->id]),
        ];

        // مالٌ حقيقيّ المسار: بيعٌ بالصندوق، وفاتورةُ عميلٍ محصّلةٌ جزئيًّا، وورقةُ لمى وسدادُها
        $this->actingAs($this->owner);
        $sell = fn (array $lines, string $method = 'نقدي') => $this->postJson('/pos/checkout', ['items' => array_map(fn ($l) => ['id' => $l[0]->id, 'name' => $l[0]->name, 'qty' => $l[1], 'price' => (float) $l[0]->price], $lines), 'payment_method' => $method, 'client_uuid' => uniqid('t', true)])->assertOk();
        $sell([[$this->ring, 13], [$flowers, 7]]);
        $sell([[$this->ring, 4], [$scarf, 2], [$flowers, 1]], 'بطاقة');
        $company = Customer::create(['business_id' => $this->shop->id, 'language' => 'ar', 'name' => 'شركة النور', 'phone' => '90000001', 'customer_type' => 'شركة', 'allow_credit_sales' => true]);
        $invoice = CustomerInvoices::issue(CustomerInvoices::create($this->shop->id, $company, ['branch_id' => $branch->id], [
            ['product_id' => $this->ring->id, 'description' => $this->ring->name, 'quantity' => 11, 'unit_price' => 53.917],
            ['product_id' => $flowers->id, 'description' => $flowers->name, 'quantity' => 4, 'unit_price' => 79.129],
        ]));
        CustomerPayments::record($this->shop->id, $company, 333.457, ['method' => 'نقدي'], [$invoice->id => 333.457], $this->owner->id);
        $sheet = Boutiques::settle($this->shop->fresh(), $this->lama, now()->format('Y-m'), 'مالك');
        $this->post(route('admin.expenses.paid', $sheet->expense_id), ['method' => 'نقدي'])->assertSessionHasNoErrors();
        auth()->logout();
        // ولكلّ صنفٍ مقاسٌ وصورة — بعد البيع، ليُطرق البابُ برقم صنفها ورقمِ ما تحت صنف غيرها
        foreach ([$this->ring, ...array_values($this->foreign)] as $item) {
            DB::table('product_variants')->insert(['business_id' => $item->business_id, 'product_id' => $item->id, 'name' => 'مقاس '.$item->id, 'price' => 1, 'active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('product_images')->insert(['business_id' => $item->business_id, 'product_id' => $item->id, 'path' => 'products/x'.$item->id.'.jpg', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        // أوسعُ حسابٍ يُربط: كلُّ قسمٍ وكلُّ فعل، وصندوقُ واتساب، ودورُ admin بصلاحيّاتٍ يدويّة
        $everything = array_merge(array_keys(Permissions::sectionLabels()), array_keys(Permissions::actionLabels()));
        $this->rich = User::create(['business_id' => $this->shop->id, 'name' => 'لمى', 'email' => 'lama@abaad.om', 'password' => bcrypt('secret-pass-1'), 'role' => 'admin', 'status' => 'نشط', 'permissions' => $everything, 'job_title' => 'بائع']);
        DB::table('users')->where('id', $this->rich->id)->update(['boutique_id' => $this->lama->id, 'whatsapp_inbox' => 'reply']);
        $this->rich = $this->rich->fresh();
        $this->assertFalse(Permissions::isOwner($this->rich), 'الحسابُ الأوسع صار صاحبَ النشاط — لا يُربط');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_open_doors_are_named_here_letter_by_letter(): void
    {
        $named = array_merge(BoutiqueAccount::ACCOUNT_ROUTES, BoutiqueAccount::ROUTES, BoutiqueAccount::PRODUCT_ROUTES);
        sort($named);
        $here = self::OPEN;
        sort($here);
        $this->assertSame($here, $named, 'تغيّرت أبوابُ رفّ البوتيك — راجِع ما يُفتح لحساب صاحب البوتيك ثمّ حدّث هذه القائمة');

        foreach ($named as $name) {
            $this->assertTrue(Router::has($name), "بابٌ في قائمة الرفّ لا مسارَ له: {$name}");
        }
    }

    public function test_no_door_asks_for_login_outside_the_web_group(): void
    {
        foreach (Router::getRoutes() as $route) {
            $mw = $route->gatherMiddleware();
            if ($this->asksForLogin($mw)) {
                $this->assertContains('web', $mw, 'مسارٌ يطلب الدخول خارج مجموعة web فلا يحرسه حارسُ الرفّ: '.$route->uri());
            }
        }
    }

    public function test_every_door_that_asks_for_login_is_shut_unless_it_is_on_its_shelf(): void
    {
        $opened = [];
        $boundFirst = [];
        $knocked = 0;
        $unmatched = [];

        foreach (Router::getRoutes() as $route) {
            if (! $this->asksForLogin($route->gatherMiddleware()) || in_array($route->getName(), self::OPEN, true)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $uri = $this->fill($route);
                $matched = $this->reaches($route, $method, $uri);
                if (! $matched) {
                    $unmatched[] = $method.' '.$route->uri();

                    continue;
                }

                // والحدُّ من الطرق يُفرَّغ قبل كلّ طرقة — فـ٤٢٩ لا تُخفي بابًا مفتوحًا
                Cache::flush();
                $status = $this->actingAs($this->rich)->json($method, $uri)->status();
                $knocked++;
                // و٤٠٤ تُقبل حيث يُحلّ نموذجٌ من الرابط قبل الحارس فلم يُوجد — لم يبلغ المتحكّمَ شيء
                if ($status === 404 && $this->bindsAModel($route)) {
                    $boundFirst[] = $route->uri();

                    continue;
                }
                if ($status !== 403) {
                    $opened[] = $method.' '.$route->uri().' ['.($route->getName() ?? 'بلا اسم').'] → '.$status;
                }
            }
        }

        $this->assertSame([], $unmatched, "مساراتٌ لم يُبنَ لها رابطٌ يبلغها:\n".implode("\n", $unmatched));
        $this->assertGreaterThan(700, $knocked, 'طُرق أقلُّ ممّا في التطبيق — الطوافُ لم يبلغ كلَّ باب');
        $this->assertSame([], $opened, "أبوابٌ لم تُردّ بـ403 لحساب البوتيك:\n".implode("\n", $opened));
        $this->assertLessThan(10, count($boundFirst), 'أبوابٌ كثيرةٌ رُدّت بـ٤٠٤ لا بحارس الرفّ: '.implode(', ', $boundFirst));
    }

    /** أيحلّ المسارُ نموذجًا من وسيطه قبل أن يبلغ الحارس؟ */
    private function bindsAModel(Route $route): bool
    {
        return $route->signatureParameters(['subClass' => Model::class]) !== [];
    }

    public function test_every_door_of_a_product_is_shut_on_what_is_not_hers_whatever_number_rides_under_it(): void
    {
        $before = $this->rows();
        $doors = array_values(array_filter(iterator_to_array(Router::getRoutes()), fn (Route $r) => in_array($r->getName(), BoutiqueAccount::PRODUCT_ROUTES, true)));
        $this->assertCount(count(BoutiqueAccount::PRODUCT_ROUTES), array_unique(array_map(fn ($r) => $r->getName(), $doors)));

        $knocks = 0;
        foreach ($doors as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                // صنفُ غيرها بما تحته، ورقمٌ لا صنفَ له — ٤٠٤ لا ٤٠٣: لا يُقال لها إنّه موجود
                foreach ([...$this->foreign, 'رقمٌ لا صنفَ له' => null] as $whose => $item) {
                    $uri = $this->door($route, $item?->id ?? 987654, $item?->id);
                    $status = $this->actingAs($this->rich)->json($method, $uri, ['name' => 'مسروق', 'price' => 1, 'status' => 'available', 'ids' => []])->status();
                    $this->assertSame(404, $status, "{$method} {$route->uri()} فُتح لها على صنف «{$whose}»");
                    $knocks++;
                }
                // وصنفُها برقم مقاسِ غيرها أو صورتِه — يُسأل في المتحكّم: أهو تحت صنفها؟
                if (array_intersect($route->parameterNames(), ['imageId', 'variant'])) {
                    foreach ($this->foreign as $whose => $item) {
                        $status = $this->actingAs($this->rich)->json($method, $this->door($route, $this->ring->id, $item->id), ['name' => 'مسروق', 'price' => 1])->status();
                        $this->assertSame(404, $status, "{$method} {$route->uri()} بلغ ما تحت صنف «{$whose}» من باب صنفها");
                        $knocks++;
                    }
                }
            }
        }

        // ستّة عشر بابًا × أربعة أصناف، وأربعةُ أبوابٍ بما تحتها × ثلاثة
        $this->assertGreaterThanOrEqual(76, $knocks);
        $this->assertSame($before, $this->rows(), 'تغيّر شيءٌ ليس لها');
    }

    /** رابطُ باب صنف: `{id}` صنفٌ، و`{imageId}`/`{variant}` ما تحت `$under` */
    private function door(Route $route, int $id, ?int $under): string
    {
        $values = ['id' => $id];
        if ($under !== null) {
            $values['imageId'] = (int) DB::table('product_images')->where('product_id', $under)->value('id');
            $values['variant'] = (int) DB::table('product_variants')->where('product_id', $under)->value('id');
        }
        $uri = $route->uri();
        foreach ($route->parameterNames() as $name) {
            $uri = str_replace('{'.$name.'}', (string) ($values[$name] ?? 987654), $uri);
        }

        return '/'.$uri;
    }

    private function rows(): array
    {
        return [
            DB::table('products')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('product_variants')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('product_images')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    public function test_what_its_shelf_shows_carries_no_amount_of_the_shops_money(): void
    {
        $canaries = $this->canaries();
        $this->assertGreaterThan(10, count($canaries), 'لم يُزرع مالٌ يكفي ليُبحث عنه');

        $pages = [
            'قائمة الأصناف' => route('admin.products.index'),
            'بحثٌ في الأصناف' => route('admin.products.index', ['q' => 'خاتم']),
            'صنفٌ جديد' => route('admin.products.create'),
            'عرضُ صنفها' => route('admin.products.show', $this->ring->id),
            'تعديلُ صنفها' => route('admin.products.edit', $this->ring->id),
            'الملفّ الشخصيّ' => route('profile.edit'),
        ];

        foreach ($pages as $page => $url) {
            $body = $this->everythingOf($url);
            foreach ($canaries as $label => $forms) {
                foreach ($forms as $form) {
                    $this->assertStringNotContainsString($form, $body, "«{$page}» حملت مبلغًا من مال المتجر ({$label} = {$form})");
                }
            }
        }
    }

    /**
     * ولا اسمَ ولا هاتفَ لعميل، ولا بندَ مصروف، ولا شيءَ من بوتيكٍ آخر أو أصناف المحلّ.
     *
     * المالُ وحده لا يكفي: اسمُ الشركة ورقمُها، وما صُرف ولمن، وأسماءُ البوتيكات الأخرى
     * وأصنافُها — كلُّها من دفتر المتجر لا من رفّ صاحبة البوتيك. وكلُّ عيّنةٍ تُرى أوّلًا
     * عند صاحب النشاط في صفحتها، فلا يُقال «لم تظهر» عن بحثٍ أعمى.
     */
    public function test_its_shelf_carries_no_customer_no_expense_and_nothing_of_another_boutique_or_the_shop(): void
    {
        $customer = Customer::create(['business_id' => $this->shop->id, 'language' => 'ar', 'name' => 'زبونةٌ لا تُسمّى', 'phone' => '91827364', 'customer_type' => 'فرد']);
        Expense::create(['business_id' => $this->shop->id, 'type' => 'إيجار', 'description' => 'إيجارُ مخزنٍ سرّيّ', 'amount' => 777.111,
            'method' => 'نقدي', 'status' => Expense::PAID, 'spent_at' => '2027-02-05']);

        $canaries = [
            'اسمُ عميلة' => [$customer->name, route('admin.customers.index')],
            'هاتفُ عميلة' => ['91827364', route('admin.customers.index')],
            'اسمُ شركةٍ عميلة' => ['شركة النور', route('admin.customers.index')],
            'هاتفُ الشركة' => ['90000001', route('admin.customers.index')],
            'بندُ مصروف' => ['إيجارُ مخزنٍ سرّيّ', route('admin.expenses.index')],
            'بوتيكٌ آخر' => ['بوتيك ريم', route('admin.products.index')],
            'صنفُ بوتيكٍ آخر' => ['وشاح ريم', route('admin.products.index')],
            'صنفُ المحلّ' => ['ورد جوري', route('admin.products.index')],
            'صنفُ متجرٍ آخر' => ['صنف متجر آخر', null],
        ];

        // ليس أعمى: صاحبُ النشاط يرى كلَّ عيّنةٍ في صفحتها
        foreach ($canaries as $label => [$form, $where]) {
            if ($where !== null) {
                $this->assertStringContainsString($form, $this->ownerSees($where), "صاحبُ النشاط لا يرى «{$label}» في صفحتها — البحثُ أعمى");
            }
        }

        // والبحثُ باسمها أو هاتفها أو باسم صنف غيرها لا يُرجع صفًّا — والكلمةُ نفسُها تُعاد في المرشِّح، فيُعدّ الصفُّ لا النصّ
        foreach (['زبونة', '91827364', 'شركة النور', 'وشاح', 'ورد'] as $q) {
            $this->actingAs($this->rich)->get(route('admin.products.index', ['q' => $q]))->assertOk()
                ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->has('products', 0));
        }

        $pages = [
            'قائمة الأصناف' => route('admin.products.index'),
            'صنفٌ جديد' => route('admin.products.create'),
            'عرضُ صنفها' => route('admin.products.show', $this->ring->id),
            'تعديلُ صنفها' => route('admin.products.edit', $this->ring->id),
            'الملفّ الشخصيّ' => route('profile.edit'),
        ];
        foreach ($pages as $page => $url) {
            $body = $this->everythingOf($url);
            foreach ($canaries as $label => [$form]) {
                $this->assertStringNotContainsString($form, $body, "«{$page}» حملت {$label} ({$form})");
            }
        }
    }

    /** ما يراه صاحبُ النشاط في صفحةٍ — كاملةً، بما يُؤجَّل منها */
    private function ownerSees(string $url): string
    {
        return $this->everythingOf($url, $this->owner);
    }

    /** المبالغُ التي لا تبلغه — كلُّ مبلغٍ في دفاتر المتجر بعد البيع، إلّا سعرَ صنفها */
    private function canaries(): array
    {
        $amounts = [];
        $take = function (string $label, iterable $values) use (&$amounts) {
            foreach ($values as $v) {
                $v = round(abs((float) $v), 3);
                if ($v >= 100 && abs($v - round($v)) > 0.0005) {
                    $amounts[$label.' '.$v] = $v;
                }
            }
        };

        $take('إجماليُّ طلب', DB::table('orders')->where('business_id', $this->shop->id)->pluck('total'));
        $take('إجماليُّ فاتورة', DB::table('customer_invoices')->where('business_id', $this->shop->id)->pluck('total'));
        foreach (['total', 'net', 'gross', 'commission', 'amount', 'due', 'payable', 'sales'] as $col) {
            if (Schema::hasColumn('boutique_settlements', $col)) {
                $take('ورقةُ البوتيك '.$col, DB::table('boutique_settlements')->where('business_id', $this->shop->id)->pluck($col));
            }
        }
        $take('مصروف', DB::table('expenses')->where('business_id', $this->shop->id)->pluck('amount'));
        $take('حركة', DB::table('transactions')->where('business_id', $this->shop->id)->pluck('amount'));
        $take('قيدٌ مدين', DB::table('journal_lines')->whereIn('journal_entry_id', DB::table('journal_entries')->where('business_id', $this->shop->id)->pluck('id'))->pluck('debit'));

        // وسعرُ صنفها ومضاعفاتُه في صفحته شأنُها — لا يُعدّ مالًا للمتجر
        $own = (float) $this->ring->price;
        $amounts = array_filter($amounts, fn ($v) => abs(fmod($v, $own)) > 0.0005 && abs(fmod($v, $own) - $own) > 0.0005);

        $forms = array_map(fn ($v) => array_unique([
            number_format($v, 3, '.', ''), number_format($v, 3, '.', ','),
            rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.'),
        ]), $amounts);

        // وما بيع من صنفها نفسِه: ١٧ قطعة في بيعتين، و١١ في الفاتورة — عددٌ لا يُقال له كالمال
        $sold = (int) DB::table('order_items')->where('product_id', $this->ring->id)->sum('quantity');
        $this->assertSame(17, $sold);
        $forms['قطعٌ بيعت من صنفها'] = [__(':n قطعة', ['n' => $sold]), __(':n قطعة', ['n' => $sold + 11])];

        return $forms;
    }

    /** الصفحةُ كاملة: ما رُسم أوّلًا، وما يُؤجَّل منها ويُطلب بعده */
    private function everythingOf(string $url, ?User $as = null): string
    {
        $who = $as ?? $this->rich;
        $response = $this->actingAs($who)->get($url);
        $response->assertOk();
        $body = $response->getContent();

        if (! preg_match('/<script data-page="app" type="application\/json">(.*?)<\/script>/s', $body, $m)) {
            return $body;
        }
        $page = json_decode($m[1], true) ?? json_decode(html_entity_decode($m[1]), true);
        $this->assertIsArray($page, 'لم تُقرأ بياناتُ الصفحة: '.$url);
        // والنصُّ العربيّ مُهرَّبٌ في الصفحة (\u0642…) — يُفكّ ليُبحث فيه
        $body .= "\n".json_encode($page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ((array) ($page['deferredProps'] ?? []) as $props) {
            $body .= "\n".$this->actingAs($who)->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) ($page['version'] ?? ''),
                'X-Inertia-Partial-Component' => (string) $page['component'],
                'X-Inertia-Partial-Data' => implode(',', (array) $props),
            ])->get($url)->getContent();
            $body .= "\n".json_encode(json_decode(substr($body, strrpos($body, "\n") + 1), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $body;
    }

    private function asksForLogin(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if (is_string($m) && ($m === 'auth' || str_starts_with($m, 'auth:'))) {
                return true;
            }
        }

        return false;
    }

    /** رابطٌ يبلغ المسار: كلُّ وسيطٍ بقيمةٍ تُطابق قيدَه — ورقمُ صنف لمى حيث يُطلب رقم */
    private function fill(Route $route): string
    {
        $uri = $route->uri();
        foreach ($route->parameterNames() as $name) {
            $value = $this->valueFor($name, $route->wheres[$name] ?? null);
            $uri = str_replace(['{'.$name.'}', '{'.$name.'?}'], $value, $uri);
        }

        return '/'.ltrim($uri, '/');
    }

    private function valueFor(string $name, ?string $where): string
    {
        $candidates = [(string) $this->ring->id, '1', 'orders', 'sales', 'products', 'pdf', 'a4', 'thermal', 'ar', 'en', 'x', 'today', 'invoice', 'order', 'all', 'abc123'];
        if ($where === null) {
            return $candidates[0];
        }
        foreach ($candidates as $c) {
            if (preg_match('#^(?:'.$where.')$#u', $c)) {
                return $c;
            }
        }
        // قيدٌ لا يقبل شيئًا ممّا سبق: أوّلُ بديلٍ حرفيٍّ فيه
        $first = explode('|', trim($where, '()'))[0];

        return preg_replace('/[^A-Za-z0-9_\-.]/', '', $first) ?: '1';
    }

    private function reaches(Route $route, string $method, string $uri): bool
    {
        try {
            $found = Router::getRoutes()->match(Request::create($uri, $method));
        } catch (\Throwable) {
            return false;
        }

        return $found->uri() === $route->uri() && in_array($method, $found->methods(), true);
    }
}
