<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\JobTitle;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\BackupService;
use App\Support\BoutiqueAccess;
use App\Support\BoutiqueAccount;
use App\Support\Ledger;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ربطُ حساب صاحب البوتيك يكتبه التاجرُ من شاشة الموظف وحدها — ولا يُفتح بربطٍ تالف.
 *
 * العمودُ بلا مفتاحٍ أجنبيّ (لتنجو استعادةُ النسخة)، فالحارسُ في الخادم:
 *
 *   - لا بابَ غيرُ شاشة الموظف يكتب الربط: لا إنشاءُ الموظف، ولا الملفُّ
 *     الشخصيّ، ولا شاشةُ المنصّة، ولا الاستعادةُ على حسابٍ قائم.
 *   - وشاشةُ الموظف ترفض بوتيكَ متجرٍ آخر، ورقمًا لا بوتيكَ له، وصاحبَ
 *     النشاط، وصاحبَ الشاشة نفسَه، ومتجرًا لا يُؤوي بوتيكات، وموظّفَ متجرٍ آخر.
 *   - وربطٌ تالف — إلى رقمٍ لا بوتيكَ له، أو بوتيكِ متجرٍ آخر، أو بوتيكٍ
 *     حُذف، أو حسابٍ نُقل إلى متجرٍ آخر — يُغلق كلَّ شيء ولا يرجع الحسابُ
 *     غيرَ مربوطٍ يرى أصنافَ المتجر كلَّها.
 *   - والاستعادةُ تحفظ العزل: نسخةٌ مصنوعةٌ تُسمّي بوتيكَ متجرٍ آخر لا تبلغه.
 */
class ABoutiqueBindingIsWrittenOnlyByTheMerchantTest extends TestCase
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
        $this->reem = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك ريم', 'commission_rate' => 10, 'active' => true]);
        $this->elsewhere = Boutique::create(['business_id' => $this->other->id, 'name' => 'بوتيك المتجر الآخر', 'commission_rate' => 15, 'active' => true]);
        $mk = fn (Business $b, string $n, ?int $boutique) => Product::create(['business_id' => $b->id, 'name' => $n, 'price' => 10, 'cost' => 0, 'quantity' => 20, 'alert_qty' => 0, 'active' => true, 'published' => true, 'boutique_id' => $boutique]);
        $this->p = [
            'lama' => $mk($this->shop, 'خاتم لمى', $this->lama->id),
            'reem' => $mk($this->shop, 'وشاح ريم', $this->reem->id),
            'shop' => $mk($this->shop, 'ورد جوري', null),
            'elsewhere' => $mk($this->other, 'صنف متجر آخر', $this->elsewhere->id),
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
        JobTitle::create(['business_id' => $shop->id, 'name' => 'بائع', 'role' => 'sales']);

        return $shop;
    }

    private function user(Business $shop, string $role, ?array $permissions, ?int $boutique = null): User
    {
        $mail = 'u'.(++$this->seq);
        $user = User::create(['business_id' => $shop->id, 'name' => $mail, 'email' => $mail.'@abaad.om', 'password' => bcrypt('secret-pass-1'), 'role' => $role, 'status' => 'نشط', 'permissions' => $permissions, 'job_title' => 'بائع']);
        if ($boutique !== null) {
            DB::table('users')->where('id', $user->id)->update(['boutique_id' => $boutique]);
        }

        return $user->fresh();
    }

    private function binding(User $user): ?int
    {
        $v = DB::table('users')->where('id', $user->id)->value('boutique_id');

        return $v === null ? null : (int) $v;
    }

    public function test_no_form_but_the_staff_screen_writes_the_binding(): void
    {
        // إنشاءُ الموظف: الحقلُ يُتجاهل — لا يُولد حسابٌ مربوط
        $this->actingAs($this->owner)->post(route('admin.employees.store'), [
            'name' => 'سالم', 'login_username' => 'salim', 'job_title' => 'بائع', 'manual_permissions' => false,
            'boutique_id' => $this->lama->id,
        ])->assertSessionHasNoErrors();
        $salim = User::where('business_id', $this->shop->id)->where('name', 'سالم')->firstOrFail();
        $this->assertNull($this->binding($salim), 'وُلد الحسابُ مربوطًا من شاشة الإنشاء');

        // الملفُّ الشخصيّ: لا يربط صاحبُه نفسَه ولا يفكّها
        $free = $this->user($this->shop, 'sales', ['products']);
        $this->actingAs($free)->put(route('profile.update'), ['name' => $free->name, 'email' => $free->email, 'boutique_id' => $this->lama->id])->assertSessionHasNoErrors();
        $this->assertNull($this->binding($free), 'ربط حسابَه من ملفّه الشخصيّ');
        $lama = $this->user($this->shop, 'sales', ['products'], $this->lama->id);
        $this->actingAs($lama)->put(route('profile.update'), ['name' => $lama->name, 'email' => $lama->email, 'boutique_id' => ''])->assertSessionHasNoErrors();
        $this->actingAs($lama)->put(route('profile.update'), ['name' => $lama->name, 'email' => $lama->email, 'boutique_id' => $this->reem->id])->assertSessionHasNoErrors();
        $this->assertSame($this->lama->id, $this->binding($lama), 'غيّر حسابُ البوتيك ربطَه من ملفّه الشخصيّ');

        // شاشةُ المنصّة: لا تكتب الربط
        $platform = User::create(['business_id' => null, 'name' => 'المنصّة', 'email' => 'platform@abaadapp.om', 'password' => bcrypt('secret-pass-1'), 'role' => 'super_admin', 'status' => 'نشط']);
        $this->actingAs($platform)->put(route('super-admin.users.update', $free->id), [
            'name' => $free->name, 'email' => $free->email, 'role' => 'sales', 'business_id' => $this->shop->id, 'boutique_id' => $this->lama->id,
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->binding($free), 'ربطت شاشةُ المنصّة حسابًا');

        // وشاشةُ الموظف بلا الحقل لا تفكّ الربطَ بصمت
        $this->actingAs($this->owner)->put(route('admin.employees.update', $lama->id), ['name' => $lama->name, 'job_title' => 'بائع'])->assertSessionHasNoErrors();
        $this->assertSame($this->lama->id, $this->binding($lama), 'فُكّ الربطُ بحفظةٍ لم ترسل الحقل');
    }

    public function test_the_staff_screen_refuses_every_binding_that_is_not_the_merchants_to_make(): void
    {
        $staff = $this->user($this->shop, 'sales', ['products']);
        JobTitle::create(['business_id' => $this->shop->id, 'name' => 'مدير', 'role' => 'manager']);
        $manager = $this->user($this->shop, 'manager', null);
        DB::table('users')->where('id', $manager->id)->update(['job_title' => 'مدير']);
        $manager = $manager->fresh();
        // والوظيفةُ كما هي — فلا يُردّ الطلبُ إلا بسبب الربط
        $save = fn (User $who, User $whom, mixed $boutique) => $this->actingAs($who)->put(route('admin.employees.update', $whom->id), [
            'name' => $whom->name, 'job_title' => $whom->fresh()->job_title ?: 'بائع', 'boutique_id' => $boutique,
        ]);

        foreach ([
            'بوتيكُ متجرٍ آخر' => $this->elsewhere->id,
            'رقمٌ لا بوتيكَ له' => 987654,
            'نصٌّ لا رقم' => 'lama',
        ] as $why => $value) {
            $this->assertTrue($this->refused($save($this->owner, $staff, $value)), "{$why}: لم يُرفض");
            $this->assertNull($this->binding($staff), "رُبط الحسابُ إلى {$why}");
        }

        // صاحبُ النشاط لا يُربط، ولا يربط أحدٌ حسابَه بنفسه
        $this->assertContains($save($manager, $this->owner, $this->lama->id)->status(), [302, 403]);
        $this->assertNull($this->binding($this->owner), 'رُبط صاحبُ النشاط من حساب مديره');
        // وشريكٌ ثانٍ بدور admin بلا صلاحيّاتٍ يدويّة صاحبُ نشاطٍ كذلك — لا يربطه الأوّل
        $partner = $this->user($this->shop, 'admin', null);
        $this->assertTrue($this->refused($save($this->owner, $partner, $this->lama->id)), 'رُبط صاحبُ النشاط');
        $this->assertNull($this->binding($partner));
        $this->assertNull($this->binding($this->owner));
        $this->assertTrue($this->refused($save($manager, $manager, $this->lama->id)), 'ربط نفسَه');
        $this->assertNull($this->binding($manager));

        // ومن يدير الموظفين من غير صاحب النشاط يربط — فالربطُ يُضيّق ولا يُوسّع
        $save($manager, $staff, $this->reem->id)->assertSessionHasNoErrors();
        $this->assertSame($this->reem->id, $this->binding($staff));

        // وموظّفُ متجرٍ آخر لا يُبلغ من هذا المتجر
        $theirs = $this->user($this->other, 'sales', ['products']);
        $this->assertSame(404, $save($this->owner, $theirs, $this->lama->id)->status());
        $this->assertNull($this->binding($theirs));

        // ومتجرٌ لا يُؤوي بوتيكًا لا يُربط فيه حساب
        $plain = Business::create(['name' => 'بلا بوتيك', 'type' => 'عام', 'status' => 'نشط']);
        $plainOwner = $this->user($plain, 'admin', null);
        $plainStaff = $this->user($plain, 'sales', ['products']);
        $this->assertTrue($this->refused($save($plainOwner, $plainStaff, $this->lama->id)), 'رُبط حسابٌ في متجرٍ لا يُؤوي بوتيكًا');
        $this->assertNull($this->binding($plainStaff));
    }

    /** ربطٌ تالف: كلُّ بابٍ يُغلق، ولا يرجع الحسابُ غيرَ مربوط */
    public function test_a_broken_binding_closes_every_door_and_never_falls_back_to_the_whole_shop(): void
    {
        $everything = array_merge(array_keys(Permissions::sectionLabels()), array_keys(Permissions::actionLabels()));

        $cases = [
            'رقمٌ لا بوتيكَ له' => fn () => $this->user($this->shop, 'custom', $everything, 987654),
            'بوتيكُ متجرٍ آخر' => fn () => $this->user($this->shop, 'custom', $everything, $this->elsewhere->id),
            'بوتيكٌ حُذف' => function () use ($everything) {
                $gone = Boutique::create(['business_id' => $this->shop->id, 'name' => 'بوتيك زال', 'commission_rate' => 5, 'active' => true]);
                $user = $this->user($this->shop, 'custom', $everything, $gone->id);
                DB::table('boutiques')->where('id', $gone->id)->delete();

                return $user;
            },
            'حسابٌ نُقل إلى متجرٍ آخر' => function () use ($everything) {
                $user = $this->user($this->shop, 'custom', $everything, $this->lama->id);
                DB::table('users')->where('id', $user->id)->update(['business_id' => $this->other->id]);

                return $user->fresh();
            },
        ];

        foreach ($cases as $case => $make) {
            $user = $make();
            $before = DB::table('products')->count();

            $this->assertTrue(BoutiqueAccount::bound($user), "{$case}: رجع الحسابُ غيرَ مربوط");
            $this->assertNull(BoutiqueAccount::boutique($user), "{$case}: قُرئ له بوتيكٌ ليس من متجره");
            $this->assertFalse(BoutiqueAccess::read($user), "{$case}: قرأ مالَ البوتيك");
            $this->assertTrue(BoutiqueAccess::hidesSales($user, $user->business), "{$case}: لم تُحجب المبيعات");
            foreach (['dashboard', 'orders', 'reports', 'finance', 'expenses', 'customers', 'employees', 'settings', 'pos', 'inventory'] as $section) {
                $this->assertFalse($user->allows($section), "{$case}: فُتح له «{$section}»");
            }

            foreach ([
                'قائمة الأصناف' => ['get', route('admin.products.index'), 403],
                'صنفٌ جديد' => ['get', route('admin.products.create'), 403],
                'حفظُ صنف' => ['post', route('admin.products.store'), 403, ['name' => 'شيء', 'price' => 1]],
                'لوحة التحكم' => ['get', route('admin.dashboard'), 403],
                'الطلبات' => ['get', route('admin.orders.index'), 403],
                'البوتيكات' => ['get', route('admin.boutiques.index'), 403],
            ] as $door => $spec) {
                $this->assertSame($spec[2], $this->actingAs($user)->json($spec[0], $spec[1], $spec[3] ?? [])->status(), "{$case}: باب «{$door}»");
            }
            foreach ($this->p as $whose => $product) {
                $this->assertSame(404, $this->actingAs($user)->get(route('admin.products.edit', $product->id))->status(), "{$case}: فُتح صنفُ «{$whose}»");
            }
            $this->assertSame([], BoutiqueAccount::scope(Product::where('business_id', $user->business_id), $user)->get()->filter(fn ($p) => BoutiqueAccount::owns($user, $p->id))->all(), "{$case}: عُدّ له صنف");
            $this->assertSame($before, DB::table('products')->count(), "{$case}: أُنشئ صنف");
        }
    }

    public function test_a_restore_keeps_every_binding_where_the_merchant_left_it_and_writes_none_from_the_file(): void
    {
        $bound = $this->user($this->shop, 'sales', ['products'], $this->lama->id);
        $free = $this->user($this->shop, 'sales', ['products']);
        Storage::fake('local');

        // نسخةٌ تقول عكسَ ما في القاعدة: المربوطُ حرٌّ، والحرُّ مربوطٌ ببوتيك ريم
        $payload = BackupService::payload($this->shop->id);
        foreach ($payload['users'] as &$row) {
            $row['boutique_id'] = match ($row['email']) {
                $bound->email => null,
                $free->email => $this->reem->id,
                default => $row['boutique_id'] ?? null,
            };
        }
        unset($row);
        $this->restore($payload)->assertSessionHasNoErrors();

        $this->assertSame($this->lama->id, $this->binding($bound), 'فكّت الاستعادةُ ربطًا من الملفّ');
        $this->assertNull($this->binding($free), 'ربطت الاستعادةُ حسابًا قائمًا من الملفّ');
    }

    public function test_an_account_a_restore_brings_back_keeps_the_shelf_the_backup_gave_it(): void
    {
        Storage::fake('local');
        $payload = BackupService::payload($this->shop->id);

        // حسابُ صاحب بوتيكٍ في النسخة لا في القاعدة — غُيّر بريدُه بعدها، أو غاب صفُّه
        $payload['users'][] = ['email' => 'lama-old@abaad.om', 'name' => 'لمى', 'role' => 'custom', 'status' => 'نشط',
            'permissions' => ['products'], 'job_title' => 'بائع', 'boutique_id' => $this->lama->id, 'business_id' => $this->shop->id];
        $payload['users'][] = ['email' => 'cross@abaad.om', 'name' => 'عابر', 'role' => 'custom', 'status' => 'نشط',
            'permissions' => ['products'], 'job_title' => 'بائع', 'boutique_id' => $this->elsewhere->id, 'business_id' => $this->shop->id];
        $payload['users'][] = ['email' => 'plain@abaad.om', 'name' => 'حرّ', 'role' => 'custom', 'status' => 'نشط',
            'permissions' => ['products'], 'job_title' => 'بائع', 'boutique_id' => null, 'business_id' => $this->shop->id];
        $this->restore($payload)->assertSessionHasNoErrors();

        // يعود على رفِّه كما في النسخة — لا حسابًا حرًّا يرى أصنافَ المتجر كلَّها
        $lama = User::where('email', 'lama-old@abaad.om')->firstOrFail();
        $this->assertSame($this->lama->id, $this->binding($lama), 'عاد حسابُ البوتيك من النسخة حرًّا');
        $this->assertSame($this->shop->id, (int) $lama->business_id);
        $this->assertSame(404, $this->actingAs($lama)->get(route('admin.products.edit', $this->p['reem']->id))->status());

        // وبوتيكُ متجرٍ آخر في الملفّ لا يُبلغ — يُغلق ولا يُفتح
        $cross = User::where('email', 'cross@abaad.om')->firstOrFail();
        $this->assertTrue(BoutiqueAccount::bound($cross));
        $this->assertNull(BoutiqueAccount::boutique($cross));
        $this->assertSame(403, $this->actingAs($cross)->get(route('admin.products.index'))->status());
        $this->assertSame(404, $this->actingAs($cross)->get(route('admin.products.edit', $this->p['elsewhere']->id))->status());

        // وما لم يُربط في النسخة لا يُربط
        $this->assertNull($this->binding(User::where('email', 'plain@abaad.om')->firstOrFail()));
    }

    public function test_a_crafted_backup_naming_another_shops_boutique_never_reaches_it(): void
    {
        $bound = $this->user($this->shop, 'sales', ['products'], $this->lama->id);
        $elsewhereBefore = DB::table('boutiques')->where('id', $this->elsewhere->id)->first();
        $theirsBefore = DB::table('products')->where('business_id', $this->other->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        Storage::fake('local');

        // نسخةٌ مصنوعة: بوتيكُ لمى يحمل رقمَ بوتيك المتجر الآخر، وأصنافُها معه
        $payload = BackupService::payload($this->shop->id);
        foreach ($payload['boutiques'] as &$row) {
            if ((int) $row['id'] === $this->lama->id) {
                $row['id'] = $this->elsewhere->id;
            }
        }
        unset($row);
        foreach ($payload['products'] as &$row) {
            if ((int) ($row['boutique_id'] ?? 0) === $this->lama->id) {
                $row['boutique_id'] = $this->elsewhere->id;
            }
        }
        unset($row);
        $this->restore($payload);

        // بوتيكُ المتجر الآخر وأصنافُه كما كانت، أيًّا كانت نتيجةُ الاستعادة
        $this->assertEquals($elsewhereBefore, DB::table('boutiques')->where('id', $this->elsewhere->id)->first(), 'مسّت النسخةُ بوتيكَ متجرٍ آخر');
        $this->assertSame($theirsBefore, DB::table('products')->where('business_id', $this->other->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());

        // وحسابُ لمى لا يبلغ من المتجر الآخر شيئًا
        $bound = $bound->fresh();
        $this->assertSame(404, $this->actingAs($bound)->get(route('admin.products.edit', $this->p['elsewhere']->id))->status());
        if (BoutiqueAccount::boutique($bound) === null) {
            $this->assertSame(403, $this->actingAs($bound)->get(route('admin.products.index'))->status());
        } else {
            $this->actingAs($bound)->get(route('admin.products.index'))->assertOk()
                ->assertInertia(fn ($page) => $page->where('products', fn ($rows) => collect($rows)->every(fn ($r) => (int) Product::find($r['id'])->business_id === $this->shop->id)));
        }
    }

    /** رُدّ الطلبُ بخطأٍ على حقل الربط نفسه — لا بسببٍ آخر */
    private function refused(TestResponse $r): bool
    {
        $errors = app('session.store')->get('errors');
        $bag = $errors instanceof ViewErrorBag ? $errors->getBag('default')->toArray() : (array) ($errors['default']['messages'] ?? $errors);

        return array_key_exists('boutique_id', $bag);
    }

    private function restore(array $payload): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('backup.json', json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $this->actingAs($this->owner)->post(route('admin.backup.restore'), ['backup' => $file, 'confirm' => true]);
    }
}
