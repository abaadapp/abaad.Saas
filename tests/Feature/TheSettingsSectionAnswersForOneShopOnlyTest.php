<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\CustomAlert;
use App\Models\Customer;
use App\Models\CustomOrderTemplate;
use App\Models\Expense;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\PosDevice;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use App\Support\MarketingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * قسمُ الإعدادات يجيب لصاحبه وحده — ولا يترك على القرص ما قال إنّه محاه.
 *
 * ═══ ما يمشي عليه هذا الملفّ ═══
 *
 * أربعَ عشرةَ بطاقةً كما يقرؤها `SettingsNav`، على متجرين لكلٍّ مالكُه
 * وفروعُه وإعداداتُه. ولا يُكتفى بفتح الصفحة: يُقرأ، ويُحفظ، ويُعاد الفتحُ
 * بعد الحفظ، وتُدفع إليه مدخلاتٌ لا تصلح، وتُجرَّب الأدوارُ والباقات.
 *
 * ═══ والعزلُ يُقاس على الأبواب لا على الشاشة ═══
 *
 * سبعةَ عشرَ بابَ كتابةٍ في القسم تأخذ معرّفًا: تنبيهٌ وفرعٌ وجهازٌ ووظيفةٌ
 * وحسابٌ ونموذجٌ وموظّف. ولكلٍّ منها صفٌّ في المتجر الآخر، ومالكُ الأوّل
 * يطرقه بمعرّفه. وشاشةٌ لا تعرض الزرَّ ليست حارسًا: من يعرف المعرّف يرسل
 * الطلبَ بيده.
 *
 * ═══ والعطبُ الذي وجده ═══
 *
 * شعارُ المتجر كان يُستبدل فيتراكم القديمُ على القرص، ويُحذف فيبقى مخدومًا
 * برابطه على القرص **العامّ** بعد أن يقرأ صاحبُه «حُذف الشعار». وأخوه
 * `Document\Branding::storeCover` يمحو القديمَ منذ كُتب — وهما العمليّة
 * نفسُها في القسم نفسِه. فقُيس الاثنان معًا هنا: أحدُهما يشهد على الآخر.
 */
class TheSettingsSectionAnswersForOneShopOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** البطاقاتُ الأربع عشرة كما يقرؤها `SettingsNav` */
    private const CARDS = [
        'business', 'website', 'finance', 'chart', 'templates', 'customers',
        'permissions', 'notifications', 'branches', 'employees', 'devices',
        'activity', 'trash', 'backup',
    ];

    private Business $a;

    private Business $b;

    private User $ownerA;

    private User $ownerB;

    private User $cashierA;

    private User $accountantA;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');

        [$this->a, $this->ownerA, $this->cashierA, $this->accountantA] = $this->shop('ورد مسقط', 'a');
        [$this->b, $this->ownerB] = $this->shop('هدايا صلالة', 'b');
    }

    private function shop(string $name, string $tag): array
    {
        $biz = Business::create(['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $biz->id, 'name' => 'الرئيسي']);
        Currency::create(['business_id' => $biz->id, 'code' => 'OMR', 'name' => 'ريال',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true]);
        Ledger::seedChart($biz->id);

        $mk = fn (string $role, string $slot) => User::create([
            'business_id' => $biz->id, 'name' => $role, 'email' => "$slot.$tag@abaad.om",
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
        ]);

        return [$biz, $mk('admin', 'owner'), $mk('cashier', 'cash'), $mk('accountant', 'acc')];
    }

    public function test_every_card_opens_for_its_owner(): void
    {
        $broken = [];

        foreach (self::CARDS as $card) {
            $r = $this->actingAs($this->ownerA)->get('/admin/settings?section='.$card);

            if ($r->status() !== 200) {
                $broken[] = $card.' → '.$r->status();
            }
        }

        $this->assertSame([], $broken, "أقسامٌ لا تُفتح:\n".implode("\n", $broken));
    }

    public function test_the_page_carries_its_own_business(): void
    {
        $props = $this->actingAs($this->ownerA)->get('/admin/settings')
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('ورد مسقط', $props['business']['name']);

        $propsB = $this->actingAs($this->ownerB)->get('/admin/settings')
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('هدايا صلالة', $propsB['business']['name']);
    }

    public function test_the_sub_pages_open(): void
    {
        $broken = [];
        $pages = [
            '/admin/settings/trash',
            '/admin/website/settings',
        ];

        foreach (['sale', 'customer_invoice', 'delivery', 'purchase', 'grn',
            'supplier_invoice', 'credit_note', 'customer_receipt'] as $type) {
            $pages[] = '/admin/settings/templates/'.$type;
        }

        foreach ($pages as $url) {
            $r = $this->actingAs($this->ownerA)->get($url);
            if (! in_array($r->status(), [200, 302], true)) {
                $broken[] = $url.' → '.$r->status();
            }
        }

        $this->assertSame([], $broken, implode("\n", $broken));
    }

    public function test_a_cashier_is_refused(): void
    {
        $seen = [];

        foreach (['/admin/settings', '/admin/settings?section=finance', '/admin/settings/trash'] as $url) {
            $seen[$url] = $this->actingAs($this->cashierA)->get($url)->status();
        }

        $this->assertSame([], array_filter($seen, fn ($s) => $s === 200), json_encode($seen));
    }

    public function test_an_accountant_is_refused_settings(): void
    {
        $this->assertSame(403, $this->actingAs($this->accountantA)->get('/admin/settings')->status());
    }

    /** قيمةٌ صالحةٌ لكلّ مفتاحٍ تكتبه الشاشة */
    private function payload(): array
    {
        return [
            'shop_name' => 'ورد مسقط المعدَّل',
            'shop_name_en' => 'Muscat Roses',
            'cr_number' => 'CR-778899',
            'email' => 'shop@ward.om',
            'phone' => '96899112233',
            'address' => 'القرم، مسقط',
            'vat_enabled' => '1',
            'vat_rate' => '7.5',
            'vat_number' => 'OM100200300',
            'tax_mode' => 'inclusive',
            'currency' => 'AED',
            'decimals' => '2',
            'symbol_pos' => 'before',
            'pay_cash' => '1',
            'pay_card' => '0',
            'pay_transfer' => '1',
            'pay_credit' => '0',
            'inv_prefix' => 'WRD-',
            'inv_start' => '500',
            'staff_sees_performance' => '1',
            'notify_new_order' => '1',
            'notify_smart_alerts' => '0',
            'notify_daily_summary' => '1',
            'notify_dormant_customers' => '0',
        ];
    }

    public function test_every_field_saves_and_persists(): void
    {
        $sent = $this->payload();

        $this->actingAs($this->ownerA)->post('/admin/settings', $sent)
            ->assertSessionHasNoErrors();

        $props = $this->actingAs($this->ownerA)->get('/admin/settings')
            ->assertOk()->viewData('page')['props'];

        $read = $props['settings'];
        $biz = $props['business'];

        $wrong = [];

        foreach (['shop_name' => 'name', 'shop_name_en' => 'name_en', 'email' => 'email',
            'phone' => 'phone', 'address' => 'address'] as $field => $col) {
            if ((string) ($biz[$col] ?? '') !== $sent[$field]) {
                $wrong[] = "business.$col = ".json_encode($biz[$col] ?? null).' ≠ '.$sent[$field];
            }
        }

        foreach ($sent as $k => $v) {
            if (in_array($k, ['shop_name', 'shop_name_en', 'email', 'phone', 'address'], true)) {
                continue;
            }
            $got = $read[$k] ?? '(غائب)';
            if ((string) $got !== (string) $v) {
                $wrong[] = "$k = ".json_encode($got).' ≠ '.$v;
            }
        }

        $this->assertSame([], $wrong, "لم يستمرّ:\n".implode("\n", $wrong));
    }

    public function test_saving_one_shop_leaves_the_other_alone(): void
    {
        $before = Setting::where('business_id', $this->b->id)->pluck('value', 'key')->all();
        $platform = Setting::whereNull('business_id')->pluck('value', 'key')->all();
        $nameB = $this->b->fresh()->name;

        $this->actingAs($this->ownerA)->post('/admin/settings', $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame($before, Setting::where('business_id', $this->b->id)->pluck('value', 'key')->all(),
            'تبدّلت إعداداتُ متجرٍ آخر');
        $this->assertSame($platform, Setting::whereNull('business_id')->pluck('value', 'key')->all(),
            'تبدّلت إعداداتُ المنصّة');
        $this->assertSame($nameB, $this->b->fresh()->name, 'تبدّل اسمُ متجرٍ آخر');
    }

    public function test_a_payload_cannot_name_another_business(): void
    {
        $nameB = $this->b->fresh()->name;

        $this->actingAs($this->ownerA)->post('/admin/settings',
            $this->payload() + ['business_id' => $this->b->id, 'bid' => $this->b->id]);

        $this->assertSame($nameB, $this->b->fresh()->name, 'حمولةٌ حملت معرّفَ متجرٍ آخر فكُتب فيه');
        $this->assertSame('ورد مسقط المعدَّل', $this->a->fresh()->name);
    }

    public function test_bad_input_is_refused_with_a_message(): void
    {
        $bad = [
            'vat_rate' => ['200', 'نسبةٌ فوق المئة'],
            'currency' => ['OMRX', 'رمزُ عملةٍ بأربعة أحرف'],
            'email' => ['ليس بريدًا', 'بريدٌ غير صالح'],
            'inv_prefix' => ['A%B', 'بادئةٌ فيها %'],
            'decimals' => ['9', 'خاناتٌ عشريّةٌ تسع'],
            'shop_name' => ['', 'اسمٌ فارغ'],
        ];

        $passed = [];

        foreach ($bad as $key => [$value, $why]) {
            $r = $this->actingAs($this->ownerA)->post('/admin/settings', [$key => $value]);
            if (! $r->getSession()->has('errors')) {
                $passed[] = "$key = ".json_encode($value)." ($why) مرّ بلا اعتراض";
            }
        }

        $this->assertSame([], $passed, implode("\n", $passed));
    }

    /**
     * صفوفُ متجر (ب) — ثمّ يحاول مالكُ (أ) أن يمسّها بمعرّفها.
     *
     * @return array<string, array{0:string,1:string,2:array<string,mixed>,3:callable}>
     */
    private function foreignRows(): array
    {
        $bid = $this->b->id;

        $alert = CustomAlert::create(['business_id' => $bid, 'type' => 'reminder',
            'section' => 'dashboard', 'message' => 'تنبيهُ صلالة', 'active' => true]);

        $branch = Branch::create(['business_id' => $bid, 'name' => 'فرعُ صلالة الثاني']);

        $device = PosDevice::create(['business_id' => $bid, 'branch_id' => $branch->id,
            'name' => 'جهازُ صلالة', 'token_hash' => hash('sha256', 'x'), 'status' => 'نشط']);

        $title = JobTitle::create(['business_id' => $bid, 'name' => 'بائعُ صلالة', 'role' => 'cashier']);

        $account = Account::create(['business_id' => $bid, 'code' => '9911',
            'name' => 'حسابُ صلالة', 'type' => 'أصل', 'normal_side' => 'debit', 'active' => true]);

        $template = CustomOrderTemplate::create(['business_id' => $bid,
            'name' => 'نموذجُ صلالة', 'modes' => ['pickup'], 'default_mode' => 'pickup',
            'base_label' => 'الأساس', 'active' => true, 'sort_order' => 1]);

        $staff = User::create(['business_id' => $bid, 'name' => 'موظّفُ صلالة',
            'email' => 'staff.b@abaad.om', 'password' => bcrypt('password'),
            'role' => 'cashier', 'status' => 'نشط']);

        return [
            'تنبيه — تعديل' => ['put', '/admin/alerts/'.$alert->id, ['message' => 'اختُرق', 'type' => 'reminder', 'section' => 'dashboard', 'due_at' => now()->addWeek()->toDateString()], fn () => $alert->fresh()?->message],
            'تنبيه — حذف' => ['delete', '/admin/alerts/'.$alert->id, [], fn () => $alert->fresh()?->message],
            'فرع — تعديل' => ['put', '/admin/branches/'.$branch->id, ['name' => 'اختُرق'], fn () => $branch->fresh()?->name],
            'فرع — حذف' => ['delete', '/admin/branches/'.$branch->id, [], fn () => $branch->fresh()?->name],
            'جهاز — تعديل' => ['put', '/admin/devices/'.$device->id, ['name' => 'اختُرق'], fn () => $device->fresh()?->name],
            'جهاز — سحب' => ['delete', '/admin/devices/'.$device->id, [], fn () => $device->fresh()?->status],
            'جهاز — محو' => ['delete', '/admin/devices/'.$device->id.'/record', [], fn () => $device->fresh()?->name],
            'وظيفة — تعديل' => ['put', '/admin/job-titles/'.$title->id, ['name' => 'اختُرق', 'role' => 'admin'], fn () => $title->fresh()?->name],
            'وظيفة — حذف' => ['delete', '/admin/job-titles/'.$title->id, [], fn () => $title->fresh()?->name],
            'حساب — تعديل' => ['put', '/admin/finance/chart/'.$account->id, ['name' => 'اختُرق', 'code' => '9911', 'type' => 'أصل', 'normal_side' => 'debit'], fn () => $account->fresh()?->name],
            'حساب — إطفاء' => ['post', '/admin/finance/chart/'.$account->id.'/toggle', [], fn () => (string) (int) ($account->fresh()?->active ?? 1)],
            'حساب — حذف' => ['delete', '/admin/finance/chart/'.$account->id, [], fn () => $account->fresh()?->name],
            'نموذج — تعديل' => ['put', '/admin/custom-order-templates/'.$template->id, ['name' => 'اختُرق', 'modes' => ['pickup'], 'default_mode' => 'pickup', 'base_label' => 'الأساس'], fn () => $template->fresh()?->name],
            'نموذج — حذف' => ['delete', '/admin/custom-order-templates/'.$template->id, [], fn () => $template->fresh()?->name],
            'موظّف — تعديل' => ['put', '/admin/employees/'.$staff->id, ['name' => 'اختُرق', 'email' => 'staff.b@abaad.om', 'role' => 'admin'], fn () => $staff->fresh()?->name],
            'موظّف — إيقاف' => ['post', '/admin/employees/'.$staff->id.'/toggle', [], fn () => $staff->fresh()?->status],
            'موظّف — كلمة مرور' => ['post', '/admin/employees/'.$staff->id.'/reset-password', [], fn () => $staff->fresh()?->password],
        ];
    }

    public function test_no_owner_may_touch_another_shops_row(): void
    {
        $breached = [];

        foreach ($this->foreignRows() as $what => [$verb, $url, $body, $read]) {
            $before = $read();

            $status = $this->actingAs($this->ownerA)->{$verb}($url, $body)->status();

            $after = $read();

            if ($before !== $after) {
                $breached[] = $what.' → '.$verb.' '.$url.' ردّ '.$status
                    .' وتبدّل: '.json_encode($before, JSON_UNESCAPED_UNICODE)
                    .' ← '.json_encode($after, JSON_UNESCAPED_UNICODE);
            }
        }

        $this->assertSame([], $breached, "اختراقُ عزلٍ:\n".implode("\n", $breached));
    }

    /** العمودُ الخام — و`Business::logo` accessor يردّ رابطًا لا مسارًا */
    private function rawLogo(): ?string
    {
        return DB::table('businesses')
            ->where('id', $this->a->id)->value('logo');
    }

    public function test_a_replaced_logo_does_not_pile_up(): void
    {
        $up = fn () => $this->actingAs($this->ownerA)->post('/admin/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 40, 40),
        ])->assertSessionHasNoErrors();

        $up();
        $first = $this->rawLogo();
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        $up();
        $second = $this->rawLogo();
        $this->assertNotSame($first, $second);

        Storage::disk('public')->assertMissing($first);
        $this->assertSame([$second], Storage::disk('public')->allFiles('logos'));
    }

    public function test_a_deleted_logo_is_gone_from_the_disk(): void
    {
        $this->actingAs($this->ownerA)->post('/admin/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 40, 40),
        ])->assertSessionHasNoErrors();

        $path = $this->rawLogo();
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->ownerA)->post('/admin/settings/logo', ['remove' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->rawLogo(), 'العمودُ لم يُفرَّغ');
        Storage::disk('public')->assertMissing($path);
    }

    /**
     * وشعارُ الجار لا يُمسّ — لا حين يُستبدل هذا ولا حين يُحذف.
     *
     * الحارسان فوقه يقيسان القرصَ بمتجرٍ واحدٍ عليه، فيبقيان أخضرين لو
     * محا المحوُ مجلّدَ `logos` كلَّه. والمحوُ يمشي على القرص **العامّ**
     * المشترك بين المتاجر جميعًا: مسارٌ يُقرأ من غير صاحبه — أو محوٌ
     * بالنمط لا بالمسار — يُطفئ شعارَ تاجرٍ لم يفتح شاشتَه أصلًا.
     */
    public function test_a_neighbours_logo_survives_this_shops_delete(): void
    {
        $this->actingAs($this->ownerB)->post('/admin/settings/logo', [
            'logo' => UploadedFile::fake()->image('jar.png', 30, 30),
        ])->assertSessionHasNoErrors();

        $his = DB::table('businesses')->where('id', $this->b->id)->value('logo');
        $this->assertNotNull($his);

        $this->actingAs($this->ownerA)->post('/admin/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 40, 40),
        ])->assertSessionHasNoErrors();

        // استبدالٌ ثمّ حذف — المخرجان اللذان يمحوان
        $this->actingAs($this->ownerA)->post('/admin/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo2.png', 40, 40),
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->ownerA)->post('/admin/settings/logo', ['remove' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->rawLogo(), 'لم يُفرَّغ عمودُ صاحب الشاشة');
        Storage::disk('public')->assertExists($his);
        $this->assertSame(
            $his,
            DB::table('businesses')->where('id', $this->b->id)->value('logo'),
            'تبدّل عمودُ الجار',
        );
        $this->assertSame([$his], Storage::disk('public')->allFiles('logos'), 'بقي على القرص ما لا صاحبَ له');
    }

    /** والغلافُ أخوه — يُقاس به ليُعرف أنّ القاعدة قائمةٌ في أحدهما */
    public function test_a_replaced_cover_does_not_pile_up(): void
    {
        $up = fn () => $this->actingAs($this->ownerA)->post('/admin/settings/documents/cover', [
            'cover' => UploadedFile::fake()->image('cover.png', 60, 20),
        ])->assertSessionHasNoErrors();

        $up();
        $first = Setting::where('business_id', $this->a->id)->where('key', 'like', '%cover%')->value('value');
        $this->assertNotEmpty($first);

        $up();
        $this->assertSame(1, count(Storage::disk('public')->allFiles('covers')), 'تراكمت الأغلفة');
    }

    /** المحذوفاتُ والاستعادة — ولا يستعيد أحدٌ محذوفَ غيره */
    public function test_no_owner_restores_another_shops_deleted_row(): void
    {
        $customerB = Customer::create([
            'business_id' => $this->b->id, 'name' => 'زبونُ صلالة', 'phone' => '95111222',
        ]);
        $customerB->delete();

        $expenseB = Expense::create([
            'business_id' => $this->b->id, 'type' => 'إيجار', 'amount' => 30,
            'spent_at' => now(), 'status' => 'مدفوع',
        ]);
        $expenseB->delete();

        $branchB = Branch::create(['business_id' => $this->b->id, 'name' => 'فرعٌ محذوف']);
        $branchB->delete();

        $tries = [
            'عميل — استعادة' => ['post', '/admin/customers/'.$customerB->id.'/restore'],
            'عميل — محو' => ['delete', '/admin/customers/'.$customerB->id.'/purge'],
            'مصروف — استعادة' => ['post', '/admin/expenses/'.$expenseB->id.'/restore'],
            'مصروف — محو' => ['delete', '/admin/expenses/'.$expenseB->id.'/purge'],
            'فرع — استعادة' => ['post', '/admin/branches/'.$branchB->id.'/restore'],
        ];

        $breached = [];

        foreach ($tries as $what => [$verb, $url]) {
            $this->actingAs($this->ownerA)->{$verb}($url);
        }

        if (Customer::find($customerB->id) !== null) {
            $breached[] = 'استُعيد عميلُ صلالة';
        }
        if (Customer::withTrashed()->find($customerB->id) === null) {
            $breached[] = 'مُحي عميلُ صلالة';
        }
        if (Expense::find($expenseB->id) !== null) {
            $breached[] = 'استُعيد مصروفُ صلالة';
        }
        if (Expense::withTrashed()->find($expenseB->id) === null) {
            $breached[] = 'مُحي مصروفُ صلالة';
        }
        if (Branch::find($branchB->id) !== null) {
            $breached[] = 'استُعيد فرعُ صلالة';
        }

        $this->assertSame([], $breached, implode("\n", $breached));
    }

    /** وصفحةُ المحذوفات تعرض محذوفَ صاحبها وحده */
    public function test_the_trash_page_shows_only_its_own(): void
    {
        $mine = Customer::create(['business_id' => $this->a->id, 'name' => 'زبونُ مسقط', 'phone' => '9911']);
        $mine->delete();
        $theirs = Customer::create(['business_id' => $this->b->id, 'name' => 'زبونُ صلالة', 'phone' => '9922']);
        $theirs->delete();

        $props = $this->actingAs($this->ownerA)->get('/admin/settings/trash')
            ->assertOk()->viewData('page')['props'];

        $seen = json_encode($props, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('زبونُ مسقط', $seen, 'لم تُعرض محذوفاتُ صاحبها');
        $this->assertStringNotContainsString('زبونُ صلالة', $seen, 'صفحةُ المحذوفات أرت محذوفَ متجرٍ آخر');
    }

    /** ونسخةُ النشاط تُنزَّل لصاحبها ولا تحمل بيانات غيره */
    public function test_the_backup_carries_only_its_own_shop(): void
    {
        Customer::create(['business_id' => $this->a->id, 'name' => 'زبونُ مسقط', 'phone' => '9911']);
        Customer::create(['business_id' => $this->b->id, 'name' => 'زبونُ صلالة', 'phone' => '9922']);

        $r = $this->actingAs($this->ownerA)->get('/admin/backup/download');

        $this->assertContains($r->status(), [200, 302], 'تنزيلُ النسخة ردّ '.$r->status());

        if ($r->status() === 200) {
            $body = $r->baseResponse instanceof StreamedResponse
                ? $r->streamedContent()
                : $r->getContent();
            $this->assertStringContainsString('زبونُ مسقط', $body);
            $this->assertStringNotContainsString('زبونُ صلالة', $body, 'النسخةُ حملت بيانات متجرٍ آخر');
        }
    }

    private function plan(string $name, array $capabilities): Plan
    {
        return Plan::create([
            'name' => $name, 'monthly_price' => 10, 'yearly_price' => 100, 'capabilities' => $capabilities,
        ]);
    }

    /** باقةٌ لا تفتح الصلاحيات المخصّصة تردّ الكتابة — لا تُخفي الزرَّ وحده */
    public function test_a_plan_without_custom_permissions_refuses_the_write(): void
    {
        $this->a->update(['plan_id' => $this->plan('بسيطة', ['loyalty'])->id]);

        $title = JobTitle::create(['business_id' => $this->a->id,
            'name' => 'بائع', 'role' => 'cashier']);

        $staff = User::create(['business_id' => $this->a->id, 'name' => 'موظّف',
            'email' => 'st.a@abaad.om', 'password' => bcrypt('password'),
            'role' => 'cashier', 'job_title' => $title->name, 'status' => 'نشط']);

        $r = $this->actingAs($this->ownerA)->put('/admin/employees/'.$staff->id, [
            'name' => 'موظّف', 'email' => 'st.a@abaad.om', 'job_title' => $title->name,
            'manual_permissions' => '1', 'permissions' => ['finance', 'reports'],
        ]);

        $r->assertSessionHasErrors('permissions');
        $this->assertSame([], array_values($staff->fresh()->permissions ?? []), 'كُتبت صلاحياتٌ لا تفتحها الباقة');
    }

    /** وباقةٌ تفتحها لا تُمنع — الحقُّ المستحقُّ لا يُحجب */
    public function test_a_plan_with_custom_permissions_is_not_blocked(): void
    {
        $this->a->update(['plan_id' => $this->plan('ذهبية', ['custom_permissions', 'loyalty'])->id]);

        $title = JobTitle::create(['business_id' => $this->a->id,
            'name' => 'بائع', 'role' => 'cashier']);

        $staff = User::create(['business_id' => $this->a->id, 'name' => 'موظّف',
            'email' => 'st2.a@abaad.om', 'password' => bcrypt('password'),
            'role' => 'cashier', 'job_title' => $title->name, 'status' => 'نشط']);

        $this->actingAs($this->ownerA)->put('/admin/employees/'.$staff->id, [
            'name' => 'موظّف', 'email' => 'st2.a@abaad.om', 'job_title' => $title->name,
            'manual_permissions' => '1', 'permissions' => ['finance', 'reports'],
        ])->assertSessionHasNoErrors();

        $got = $staff->fresh()->permissions ?? [];
        sort($got);
        $this->assertSame(['finance', 'reports'], $got, 'مُنعت ميزةٌ تفتحها الباقة');
    }

    /** والشاشةُ تقول ما تفتحه الباقة — لا تعرض بابًا لا يُفتح */
    public function test_the_screen_reads_the_plan_not_a_guess(): void
    {
        $this->a->update(['plan_id' => $this->plan('بسيطة', ['loyalty'])->id]);

        $props = $this->actingAs($this->ownerA)->get('/admin/employees')
            ->assertOk()->viewData('page')['props'];

        $flat = json_encode($props, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('custom_permissions', $flat, 'الشاشةُ لا تقرأ قدرات الباقة أصلًا');
    }

    /** حفظتان متطابقتان لا تُنشئان صفّين ولا تُفسدان قيمة */
    public function test_saving_twice_is_the_same_as_once(): void
    {
        $send = fn () => $this->actingAs($this->ownerA)->post('/admin/settings', $this->payload());

        $send();
        $rows = Setting::where('business_id', $this->a->id)->count();
        $send();

        $this->assertSame($rows, Setting::where('business_id', $this->a->id)->count(), 'تضاعفت صفوفُ الإعدادات');
        $this->assertSame('7.5', Setting::where('business_id', $this->a->id)->where('key', 'vat_rate')->value('value'));
    }

    /** والحفظُ الجزئيّ لا يمحو ما لم يُرسَل */
    public function test_saving_one_section_keeps_the_others(): void
    {
        $this->actingAs($this->ownerA)->post('/admin/settings', $this->payload())
            ->assertSessionHasNoErrors();

        // قسمٌ واحد فقط — كما ترسله الشاشة حين يُفتح قسمٌ بعينه
        $this->actingAs($this->ownerA)->post('/admin/settings', ['inv_prefix' => 'ZZZ-', 'inv_start' => '900'])
            ->assertSessionHasNoErrors();

        $read = Setting::where('business_id', $this->a->id)->pluck('value', 'key');

        $this->assertSame('ZZZ-', $read['inv_prefix']);
        $this->assertSame('7.5', $read['vat_rate'] ?? '(مُحي)', 'محا حفظُ قسمٍ قيمةَ قسمٍ آخر');
        $this->assertSame('OM100200300', $read['vat_number'] ?? '(مُحي)');
    }

    /** ومفاتيحُ المتجر في الموقع تُحفظ لصاحبها ولا تتسرّب إلى جار */
    public function test_store_switches_save_and_stay_home(): void
    {
        MarketingSettings::save($this->a->id, 'website', ['site_on' => '1']);
        MarketingSettings::save($this->b->id, 'website', ['site_on' => '1', 'store_show_prices' => '1']);
        MarketingSettings::forget($this->a->id);
        MarketingSettings::forget($this->b->id);

        $beforeB = MarketingSettings::group($this->b->id, 'website');

        $r = $this->actingAs($this->ownerA)->post('/admin/website/store', [
            'show_prices' => false, 'allow_orders' => false,
        ]);

        $this->assertContains($r->status(), [200, 302, 404], 'ردّ غيرُ متوقّع: '.$r->status());

        MarketingSettings::forget($this->a->id);
        MarketingSettings::forget($this->b->id);

        $this->assertSame($beforeB, MarketingSettings::group($this->b->id, 'website'),
            'تسرّب حفظُ الموقع إلى متجرٍ آخر');
    }

    /** وتنبيهٌ مخصّصٌ يُكتب ويُقرأ لصاحبه وحده */
    public function test_a_custom_alert_belongs_to_its_shop(): void
    {
        $this->actingAs($this->ownerA)->post('/admin/alerts', [
            'type' => 'reminder', 'section' => 'dashboard',
            'message' => 'انتبه للمخزون', 'color' => 'warning',
            'due_at' => now()->addWeek()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, CustomAlert::where('business_id', $this->a->id)->count());
        $this->assertSame(0, CustomAlert::where('business_id', $this->b->id)->count());
    }
}
