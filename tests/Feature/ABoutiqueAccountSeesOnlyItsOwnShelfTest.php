<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\BackupService;
use App\Support\BoutiqueAccess;
use App\Support\Ledger;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * حسابُ صاحب البوتيك مربوطٌ ببوتيكه — لا يرى من المتجر إلا رفَّه، ولا يقرأ مالًا.
 *
 * متجرٌ يُؤوي بوتيكين (لمى وريم) وله صنفٌ وباقة، ومتجرٌ آخر. وحسابُ لمى يُطلب
 * منه كلُّ بابٍ برقم صنفٍ ليس لها: صنفُ ريم، وصنفُ المحلّ، والباقة، وصنفُ المتجر
 * الآخر — بالرابط المباشر وبالطلب البرمجيّ وبتغيير المعرّف. وحسابٌ مربوطٌ
 * مُنح كلَّ صلاحيّات المال لا يقرأ منها شيئًا.
 *
 * والمالكُ يرى البوتيكات كلَّها، وحسابٌ قديمٌ لم يُربط يبقى كما كان — لا
 * يُخمَّن له بوتيكٌ ولا يأخذ شيئًا.
 */
class ABoutiqueAccountSeesOnlyItsOwnShelfTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Business $other;

    private User $owner;

    private Boutique $lama;

    private Boutique $reem;

    private Boutique $elsewhere;

    /** @var array<string, Product> */
    private array $p = [];

    private int $seq = 0;

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
        $this->shop = $this->business('متجر الورد');
        $this->other = $this->business('متجر آخر');
        $this->owner = $this->user($this->shop, 'admin', null);
        $this->lama = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك لمى', 'commission_rate' => 20, 'active' => true]);
        $this->reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك ريم', 'commission_rate' => 10, 'active' => true, 'phone' => '99000001']);
        $this->elsewhere = Boutique::create(['business_id' => $this->other->id, 'name' => 'بوتيك المتجر الآخر', 'commission_rate' => 15, 'active' => true]);
        $this->p = [
            'lama' => $this->product($this->shop, 'خاتم لمى', 12, $this->lama->id),
            'reem' => $this->product($this->shop, 'وشاح ريم', 15, $this->reem->id),
            'shop' => $this->product($this->shop, 'ورد جوري', 9, null),
            'bundle' => $this->product($this->shop, 'باقة', 40, null),
            'elsewhere' => $this->product($this->other, 'صنف متجر آخر', 5, $this->elsewhere->id),
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function business(string $name): Business
    {
        $shop = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط', 'boutiques_enabled' => true]);
        Currency::create(['business_id' => $shop->id, 'code' => 'OMR', 'name' => 'ريال عماني', 'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($shop->id);
        Branch::create(['business_id' => $shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        return $shop;
    }

    private function user(Business $shop, string $role, ?array $permissions, ?Boutique $boutique = null): User
    {
        $mail = 'u'.(++$this->seq);
        $user = User::create(['business_id' => $shop->id, 'name' => $mail, 'email' => $mail.'@abaad.om', 'password' => bcrypt('secret-pass-1'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions, 'job_title' => 'بائع']);
        if ($boutique) {
            // الربطُ بيد التاجر من شاشة الموظف — وهنا مباشرةً لاختبار ما بعده
            DB::table('users')->where('id', $user->id)->update(['boutique_id' => $boutique->id]);
        }

        return $user->fresh();
    }

    private function product(Business $shop, string $name, float $price, ?int $boutique): Product
    {
        return Product::create(['business_id' => $shop->id, 'name' => $name, 'price' => $price, 'cost' => $boutique ? 0 : 1, 'quantity' => 20, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $boutique]);
    }

    /** كلُّ بابٍ برقم صنف — كما يُطلب من رابطٍ مباشر أو طلبٍ برمجيّ */
    private function productDoors(Product $p): array
    {
        return [
            'العرض' => ['get', route('admin.products.show', $p->id)],
            'التعديل' => ['get', route('admin.products.edit', $p->id)],
            'الحفظ' => ['put', route('admin.products.update', $p->id), ['name' => 'مسروق', 'price' => 1]],
            'التعديل السريع' => ['patch', route('admin.products.quick', $p->id), ['price' => 1]],
            'الإيقاف' => ['post', route('admin.products.toggle', $p->id)],
            'الحذف' => ['delete', route('admin.products.destroy', $p->id)],
            'النسخ' => ['post', route('admin.products.duplicate', $p->id)],
            'حال الموقع' => ['patch', route('admin.products.websiteStatus', $p->id), ['status' => 'available']],
            'مقاسٌ جديد' => ['post', route('admin.products.variants.store', $p->id), ['name' => 'كبير', 'price' => 1]],
            'ترتيب الصور' => ['put', route('admin.products.images.order', $p->id), ['order' => []]],
        ];
    }

    private function snapshot(): array
    {
        return DB::table('products')->orderBy('id')->get(['id', 'name', 'price', 'boutique_id', 'active', 'deleted_at'])->map(fn ($r) => (array) $r)->all();
    }

    public function test_the_owner_sees_every_boutique_and_every_product_of_the_shop(): void
    {
        $this->actingAs($this->owner)->get(route('admin.products.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('products', 4)->has('boutiques', 2)
                ->where('auth.boutiqueAccount', null));
        $this->actingAs($this->owner)->get(route('admin.boutiques.index'))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_a_bound_account_lists_its_boutiques_products_alone_and_nothing_of_the_shop(): void
    {
        $lama = $this->user($this->shop, 'sales', ['dashboard', 'products'], $this->lama);

        $this->actingAs($lama)->get(route('admin.products.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('products', 1)->where('products.0.id', $this->p['lama']->id)
                // ولا تُرسل إليه أسماءُ البوتيكات الأخرى
                ->has('boutiques', 1)->where('boutiques.0.value', $this->lama->id)
                ->where('auth.abilities', ['products'])
                ->where('auth.boutiqueAccount.id', $this->lama->id)
                ->where('notifications', null)
                ->where('reportPages', null));

        // والبحثُ في القائمة لا يُخرج صنفَ غيره
        $this->actingAs($lama)->get(route('admin.products.index', ['q' => 'وشاح']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('products', 0));
        $this->actingAs($lama)->get(route('admin.products.index', ['boutique' => $this->reem->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('products', 0));

        // وشاشةُ الإنشاء لا تحمل أصنافَ المتجر بتكلفتها ولا إضافاته
        $this->actingAs($lama)->get(route('admin.products.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('composition.components', [])->where('composition.products', [])
                ->where('composition.stock_items', [])->where('composition.addons', [])
                ->has('boutiques', 1)->where('addonBoutiqueLinks', null));

        // ودخولُه يقوده إلى أصنافه
        auth()->logout();
        $this->post(route('login.attempt'), ['email' => $lama->email, 'password' => 'secret-pass-1'])
            ->assertRedirect(route('admin.products.index'));
    }

    public function test_it_reaches_no_other_product_by_its_number_whatever_door_it_knocks(): void
    {
        $lama = $this->user($this->shop, 'sales', ['dashboard', 'products'], $this->lama);
        $before = $this->snapshot();

        foreach (['reem', 'shop', 'bundle', 'elsewhere'] as $whose) {
            foreach ($this->productDoors($this->p[$whose]) as $door => $spec) {
                $status = $this->actingAs($lama)->json($spec[0], $spec[1], $spec[2] ?? [])->status();
                $this->assertSame(404, $status, "باب «{$door}» فُتح لحساب لمى على صنف «{$whose}»");
            }
        }

        $this->assertSame($before, $this->snapshot(), 'تغيّر صنفٌ ليس للمى');
        $this->assertSame(0, DB::table('product_variants')->count());
    }

    public function test_what_it_creates_is_its_boutiques_and_it_moves_no_product_out_or_in(): void
    {
        $lama = $this->user($this->shop, 'sales', ['dashboard', 'products'], $this->lama);

        $this->actingAs($lama)->post(route('admin.products.store'), [
            'name' => 'سوار لمى', 'price' => 8, 'boutique_id' => $this->reem->id,
            'composition' => ['recipe' => [['component_product_id' => $this->p['shop']->id, 'quantity' => 1]]],
        ])->assertSessionHasNoErrors();
        $made = Product::where('business_id', $this->shop->id)->where('name', 'سوار لمى')->firstOrFail();
        $this->assertSame($this->lama->id, (int) $made->boutique_id, 'نُسب صنفُها إلى بوتيكٍ آخر');
        $this->assertSame(0, DB::table('recipe_items')->where('product_id', $made->id)->count(), 'بنت باقةً من أصناف المتجر');

        foreach ([$this->reem->id, null] as $to) {
            $this->actingAs($lama)->put(route('admin.products.update', $this->p['lama']->id), ['name' => 'خاتم لمى', 'price' => 12, 'boutique_id' => $to])
                ->assertSessionHasNoErrors();
            $this->assertSame($this->lama->id, (int) $this->p['lama']->fresh()->boutique_id, 'نُقل صنفُ لمى من بوتيكها');
        }

        // وعملُها على أصنافها كما منحها التاجر
        $this->actingAs($lama)->patch(route('admin.products.quick', $this->p['lama']->id), ['price' => 13])->assertSessionHasNoErrors();
        $this->assertSame(13.0, (float) $this->p['lama']->fresh()->price);
        $this->actingAs($lama)->get(route('admin.products.edit', $this->p['lama']->id))->assertOk();
    }

    /**
     * ولا بابَ يكتب ينقل ملكيّةَ صنفٍ أو نسبتَه، ولا يربط الحسابَ نفسَه أو يفكّه.
     *
     * الأبوابُ المفتوحة له على أصنافه تُطرق بحمولةٍ مصنوعة: `boutique_id` لبوتيكٍ آخر أو فارغ،
     * ونسبةُ عمولة. والأبوابُ المغلقة التي تكتب الملكيّةَ أو النسبة (التعديلُ الجماعيّ، وإسنادُ
     * البوتيك، وتعديلُ البوتيك، والباقة، وتعديلُ الموظف) تُطرق فتُردّ، ولا يتغيّر صفّ.
     */
    public function test_no_write_door_moves_ownership_or_rate_and_it_never_rebinds_itself(): void
    {
        $lama = $this->user($this->shop, 'sales', ['dashboard', 'products', 'employees', 'settings'], $this->lama);
        $mine = $this->p['lama'];
        $variant = DB::table('product_variants')->insertGetId(['business_id' => $this->shop->id, 'product_id' => $mine->id, 'name' => 'صغير', 'price' => 12, 'active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $before = [
            'products' => DB::table('products')->orderBy('id')->get(['id', 'boutique_id'])->toArray(),
            'rates' => DB::table('boutiques')->orderBy('id')->pluck('commission_rate', 'id')->all(),
            'binding' => DB::table('users')->where('id', $lama->id)->value('boutique_id'),
        ];
        $crafted = ['boutique_id' => $this->reem->id, 'commission_rate' => 1, 'boutique_rate' => 1];

        // الأبوابُ المفتوحة على أصنافها: تُقبل وتُكتب على بوتيكها وحده
        $this->actingAs($lama)->patch(route('admin.products.quick', $mine->id), ['price' => 14] + $crafted);
        $this->actingAs($lama)->post(route('admin.products.variants.store', $mine->id), ['name' => 'كبير', 'price' => 15] + $crafted);
        $this->actingAs($lama)->put(route('admin.products.variants.update', [$mine->id, $variant]), ['name' => 'صغير', 'price' => 11] + $crafted);
        $this->actingAs($lama)->post(route('admin.products.duplicate', $mine->id));
        $copy = Product::where('business_id', $this->shop->id)->where('id', '!=', $mine->id)->where('name', 'like', '%خاتم لمى%')->latest('id')->first();
        $this->assertNotNull($copy, 'لم يُنسخ صنفُها');
        $this->assertSame($this->lama->id, (int) $copy->boutique_id, 'نسخةُ صنفها خرجت من بوتيكها');

        // والأبوابُ التي تكتب الملكيّةَ أو النسبة مغلقة — ولو حمل الحسابُ «الموظفين» و«الإعدادات»
        $shut = [
            'التعديلُ الجماعيّ' => ['post', route('admin.products.bulk'), ['ids' => [$this->p['reem']->id, $this->p['shop']->id, $mine->id], 'action' => 'boutique', 'boutique_id' => $this->lama->id]],
            'تعديلُ البوتيك ونسبته' => ['put', route('admin.boutiques.update', $this->lama->id), ['name' => 'بوتيك لمى', 'commission_rate' => 1]],
            'تعديلُ بوتيكٍ آخر' => ['put', route('admin.boutiques.update', $this->reem->id), ['name' => 'بوتيك ريم', 'commission_rate' => 1]],
            'باقةٌ من صنفها' => ['post', route('admin.products.recipe.store', $mine->id), ['component_product_id' => $this->p['shop']->id, 'quantity' => 1]],
            'تعديلُ حسابها من الموظفين' => ['put', route('admin.employees.update', $lama->id), ['name' => $lama->name, 'job_title' => 'بائع', 'boutique_id' => '']],
        ];
        foreach ($shut as $door => [$method, $url, $body]) {
            $status = $this->actingAs($lama)->json($method, $url, $body)->status();
            $this->assertContains($status, [403, 404], "«{$door}» لم يُردّ ({$status})");
        }

        // والملفُّ الشخصيّ مفتوحٌ له — ولا يحمل الربط
        $this->actingAs($lama)->put(route('profile.update'), ['name' => $lama->name, 'email' => $lama->email, 'boutique_id' => $this->reem->id]);

        $this->assertSame($before['rates'], DB::table('boutiques')->orderBy('id')->pluck('commission_rate', 'id')->all(), 'تغيّرت نسبةُ بوتيك');
        $this->assertSame($before['binding'], DB::table('users')->where('id', $lama->id)->value('boutique_id'), 'تغيّر ربطُ الحساب بيده');
        $now = collect(DB::table('products')->orderBy('id')->get(['id', 'boutique_id'])->toArray())->keyBy('id');
        foreach ($before['products'] as $row) {
            $this->assertSame($row->boutique_id, $now[$row->id]->boutique_id, "انتقلت ملكيّةُ الصنف {$row->id}");
        }
        $this->assertSame(0, DB::table('recipe_items')->where('product_id', $mine->id)->count(), 'بنت باقةً من أصناف المتجر');
        $this->assertSame(0, DB::table('product_variants')->whereNotNull('product_id')->where('product_id', '!=', $mine->id)->where('product_id', '!=', $copy->id)->where('business_id', $this->shop->id)->count(), 'كُتب مقاسٌ على صنف غيرها');
    }

    public function test_no_money_and_no_other_screen_reaches_it_whatever_it_was_granted(): void
    {
        // حسابٌ مربوطٌ أُعطي كلَّ صلاحيّات المال — الربطُ فوقها
        $rich = $this->user($this->shop, 'sales', ['dashboard', 'products', 'orders', 'reports', 'expenses', 'finance', 'customers', 'inventory', 'settings', 'employees', 'pos', Permissions::INVOICE_APPROVE, Permissions::INVOICE_PAY], $this->lama);

        $doors = [
            'لوحة التحكم' => route('admin.dashboard'), 'الطلبات' => route('admin.orders.index'),
            'فواتير العملاء' => route('admin.customerInvoices.index'), 'التقارير' => route('admin.reports.index'),
            'تقرير المبيعات' => route('admin.reports.sales'), 'المصروفات' => route('admin.expenses.index'),
            'المالية' => route('admin.finance.index'), 'المخزون' => route('admin.inventory.index'),
            'العملاء' => route('admin.customers.index'), 'الموظفون' => route('admin.employees.index'),
            'الإعدادات' => route('admin.settings.index'), 'سجل النشاط' => route('admin.activity.index'),
            'البوتيكات' => route('admin.boutiques.index'), 'مبيعات البوتيك' => route('admin.boutiques.sales'),
            'تصدير الطلبات' => route('admin.export.orders', ['expected_business_id' => $this->shop->id]),
            'تصدير الأصناف' => route('admin.export.products', ['expected_business_id' => $this->shop->id]),
            'الجرس' => route('admin.notifications.feed'), 'البحث' => route('admin.search', ['q' => 'ورد']),
            'الدعم' => route('admin.help.index'), 'نقطة البيع' => route('pos.index'),
        ];
        foreach ($doors as $door => $url) {
            $this->assertSame(403, $this->actingAs($rich)->getJson($url)->status(), "باب «{$door}» فُتح لحساب البوتيك");
        }
        foreach ([
            'ربط صنفٍ ببوتيك' => route('admin.boutiques.attach', $this->reem->id),
            'التعديل الجماعيّ' => route('admin.products.bulk'),
            'إضافةٌ للمتجر' => route('admin.products.addons.store'),
        ] as $door => $url) {
            $this->assertSame(403, $this->actingAs($rich)->postJson($url, [])->status(), "باب «{$door}» فُتح لحساب البوتيك");
        }

        $this->assertFalse(BoutiqueAccess::read($rich), 'قرأ مالَ البوتيك');
        $this->assertFalse(BoutiqueAccess::pay($rich));
        $this->assertTrue(BoutiqueAccess::hidesSales($rich, $this->shop));

        // وصفحةُ صنفه بلا مبيعاتٍ ولا ربح
        $this->actingAs($rich)->get(route('admin.products.show', $this->p['lama']->id))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('stats', fn ($stats) => ! collect($stats)->contains(fn ($s) => str_contains((string) ($s['label'] ?? ''), 'المبيعات'))));
    }

    public function test_one_boutique_account_never_reaches_the_other(): void
    {
        $lama = $this->user($this->shop, 'sales', ['products'], $this->lama);
        $reem = $this->user($this->shop, 'sales', ['products'], $this->reem);

        $this->assertSame(404, $this->actingAs($lama)->get(route('admin.products.edit', $this->p['reem']->id))->status());
        $this->assertSame(404, $this->actingAs($reem)->get(route('admin.products.edit', $this->p['lama']->id))->status());
        $this->actingAs($reem)->get(route('admin.products.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('products', 1)->where('products.0.id', $this->p['reem']->id)->has('boutiques', 1)->where('boutiques.0.value', $this->reem->id));
    }

    public function test_the_merchant_binds_and_unbinds_from_the_staff_screen_and_nothing_is_guessed(): void
    {
        $old = $this->user($this->shop, 'sales', ['dashboard', 'products']);
        $this->assertNull(DB::table('users')->where('id', $old->id)->value('boutique_id'), 'حسابٌ قديمٌ رُبط وحده');

        // حسابٌ قديمٌ لم يُربط يبقى كما كان: أصنافُ المتجر كلُّها، بلا شيءٍ زائد
        $this->actingAs($old)->get(route('admin.products.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('products', 4));
        $this->assertSame(403, $this->actingAs($old)->get(route('admin.orders.index'))->status());

        $this->actingAs($this->owner)->get(route('admin.employees.edit', $old->id))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('boutiqueOptions', 2)->where('employee.boutique_id', null));

        $save = fn (User $who, mixed $boutique) => $this->actingAs($who)->put(route('admin.employees.update', $old->id), [
            'name' => $old->name, 'job_title' => 'بائع', 'boutique_id' => $boutique,
        ]);

        $save($this->owner, $this->lama->id)->assertSessionHasNoErrors();
        $this->assertSame($this->lama->id, (int) DB::table('users')->where('id', $old->id)->value('boutique_id'));
        $this->assertSame(['dashboard', 'products'], json_decode((string) DB::table('users')->where('id', $old->id)->value('permissions'), true), 'تغيّرت صلاحيّاته بالربط');
        $this->actingAs($old->fresh())->get(route('admin.products.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('products', 1));

        // بوتيكُ متجرٍ آخر لا يُربط به
        $save($this->owner, $this->elsewhere->id)->assertSessionHasErrors('boutique_id');
        $this->assertSame($this->lama->id, (int) DB::table('users')->where('id', $old->id)->value('boutique_id'));

        // والفكُّ يُعيده إلى صلاحيّاته كما هي
        $save($this->owner, '')->assertSessionHasNoErrors();
        $this->assertNull(DB::table('users')->where('id', $old->id)->value('boutique_id'));

        // وصاحبُ النشاط لا يُربط، ولا يُعرض الحقلُ له ولا في متجرٍ لا يُؤوي بوتيكًا
        $this->actingAs($this->owner)->get(route('admin.employees.edit', $this->owner->id))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('boutiqueOptions', null));
        $plain = Business::create(['name' => 'بلا بوتيك', 'type' => 'عام', 'status' => 'نشط']);
        $plainOwner = $this->user($plain, 'admin', null);
        $plainStaff = $this->user($plain, 'sales', ['products']);
        $this->actingAs($plainOwner)->get(route('admin.employees.edit', $plainStaff->id))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('boutiqueOptions', null));
    }

    public function test_a_boutique_is_not_deleted_from_under_its_bound_account_and_a_vanished_one_closes_the_shelf(): void
    {
        $lama = $this->user($this->shop, 'sales', ['dashboard', 'products', 'orders'], $this->lama);

        $this->actingAs($this->owner)->delete(route('admin.boutiques.destroy', $this->lama->id))->assertSessionHasErrors('boutique');
        $this->assertNotNull(Boutique::find($this->lama->id));

        // وبوتيكٌ غاب من غير الشاشة (نسخةٌ استُعيدت قبله) لا يفكّ الربط: يُغلق الرفُّ ولا يُفتح المتجر
        DB::table('boutiques')->where('id', $this->lama->id)->delete();
        $lama = $lama->fresh();
        $this->assertSame($this->lama->id, (int) $lama->boutique_id, 'فُكّ الربطُ بغياب البوتيك');
        foreach ([
            'قائمة الأصناف' => ['get', route('admin.products.index')],
            'صنفٌ جديد' => ['post', route('admin.products.store'), ['name' => 'شيء', 'price' => 1]],
            'صنفُها' => ['get', route('admin.products.edit', $this->p['lama']->id)],
            'الطلبات' => ['get', route('admin.orders.index')],
            'لوحة التحكم' => ['get', route('admin.dashboard')],
        ] as $door => $spec) {
            $this->assertContains($this->actingAs($lama)->json($spec[0], $spec[1], $spec[2] ?? [])->status(), [403, 404], "باب «{$door}» فُتح لحسابٍ غاب بوتيكُه");
        }
        $this->assertSame(0, Product::where('name', 'شيء')->count());
    }

    public function test_a_restored_backup_keeps_the_account_on_its_shelf(): void
    {
        $lama = $this->user($this->shop, 'sales', ['products'], $this->lama);
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('backup.json', BackupService::json($this->shop->id));

        // الاستعادةُ تمحو البوتيكات وتُدرجها بأرقامها — ولا تُسقطها حساباتٌ مربوطة
        $this->actingAs($this->owner)->post(route('admin.backup.restore'), ['backup' => $file, 'confirm' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull(Boutique::find($this->lama->id));
        $this->assertSame($this->lama->id, (int) DB::table('users')->where('id', $lama->id)->value('boutique_id'));
        $this->actingAs($lama->fresh())->get(route('admin.products.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('products', 1)->where('products.0.name', 'خاتم لمى'));
        $this->assertSame(404, $this->actingAs($lama->fresh())->get(route('admin.products.edit', $this->p['reem']->id))->status());
    }

    public function test_the_binding_is_not_undone_over_bound_accounts(): void
    {
        $this->user($this->shop, 'sales', ['products'], $this->lama);
        $migration = require database_path('migrations/2026_12_06_090000_a_boutique_account_is_bound_to_its_boutique.php');

        try {
            $migration->down();
            $this->fail('نزلت الهجرةُ وفيها حسابٌ مربوط');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('لا يُتراجع', $e->getMessage());
        }
        $this->assertSame(1, DB::table('users')->whereNotNull('boutique_id')->count());
    }

    /**
     * باقةٌ بناها التاجرُ على رفّ البوتيك من أصناف المتجر — لا تحمل لصاحب البوتيك تكلفتَها.
     *
     * شاشةُ التعديل تُرسل التركيبَ بتكلفة كلّ مكوّنٍ وتكلفةِ السطر وهامشِ الباقة،
     * وتلك تكلفةُ أصناف المتجر وربحُه. والمكوّناتُ نفسها تبقى بأسمائها وكمّيّاتها.
     */
    public function test_a_bundle_the_merchant_built_on_its_shelf_brings_it_no_shop_cost(): void
    {
        $rose = Product::create(['business_id' => $this->shop->id, 'name' => 'ورد المتجر للباقة', 'price' => 9, 'cost' => 4.321, 'quantity' => 50, 'alert_qty' => 0, 'active' => true, 'published' => true]);
        $ring = $this->p['lama'];
        \App\Models\ProductVariant::create(['product_id' => $ring->id, 'business_id' => $this->shop->id, 'name' => 'كبير', 'price' => 15, 'active' => true, 'sort_order' => 0]);
        \App\Models\RecipeItem::create(['business_id' => $this->shop->id, 'product_id' => $ring->id, 'component_product_id' => $rose->id, 'quantity' => 2, 'wastage_percent' => 0, 'sort_order' => 0]);

        $shopCost = ['4.321' => 'تكلفة المكوّن', '8.642' => 'تكلفة السطر والباقة', '3.358' => 'هامش الباقة', '6.358' => 'هامش المقاس'];
        $props = fn (User $who) => json_encode($this->actingAs($who)->get(route('admin.products.edit', $ring->id))->assertOk()->viewData('page')['props'], JSON_UNESCAPED_UNICODE);

        // والمالكُ يراها — وإلّا فالاختبارُ لا يرى شيئًا
        $owners = $props($this->owner);
        foreach ($shopCost as $amount => $what) {
            $this->assertStringContainsString($amount, $owners, "المالكُ لم يجد {$what}");
        }

        $lama = $this->user($this->shop, 'sales', ['products'], $this->lama);
        $hers = $props($lama);
        foreach ($shopCost as $amount => $what) {
            $this->assertStringNotContainsString($amount, $hers, "{$what} وصلت حسابَ البوتيك");
        }
        $this->assertStringContainsString('ورد المتجر للباقة', $hers, 'غابت مكوّناتُ الباقة عن صاحبها — يُحجب المالُ لا التركيب');
    }
}
