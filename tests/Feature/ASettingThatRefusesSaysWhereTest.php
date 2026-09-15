<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingController;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\WebsiteVersion;
use App\Support\Demo;
use App\Support\Money;
use App\Support\Storefront;
use App\Support\Website\Blueprints;
use App\Support\Website\Builder;
use App\Support\Website\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * إعداداتُ صاحب النشاط — ثلاثةُ أشياء كانت تُقال ولا تقع.
 *
 * ١) **زرُّ حفظٍ يصمت.** الشاشة نموذجٌ واحد يرسل حقولَه كلَّها من أيّ قسم.
 *    فصفٌّ قديمٌ في القاعدة لا تقبله قواعدُ اليوم — عملةٌ مكتوبة «ريال عماني»
 *    من أيّام الحفظ الحرّ — يردّ كلَّ حفظةٍ من كلّ قسم، ووسمُ الخطأ يقع على
 *    حقلٍ في قسمٍ آخر لا يراه الواقف. فيضغط «حفظ» فلا يقع شيء: لا سطر أحمر،
 *    ولا تنبيه، ولا حفظ — ولا شيء يقول لماذا.
 *
 * ٢) **مقبضُ عملةٍ لا يُدير المال.** القراءة تسأل جدول `currencies` أوّلًا،
 *    ولا تهبط إلى الإعداد إلّا حين لا صفَّ هناك. ولمتجرٍ مزروعٍ أو مستعادٍ
 *    من نسخة صفٌّ موجود — فيبدّل صاحبُه العملة، وتقول الشاشة «تم الحفظ»،
 *    وتعرضها بعد إعادة التحميل، وكلُّ مبلغٍ في النظام يبقى بالقديمة.
 *
 * ٣) **شارةٌ تقول «غير منشور» عن متجرٍ مفتوح.** العنوان واحدٌ لطريقين،
 *    والمبنيُّ يتقدّم. فمن نشر موقعه ثمّ أطفأ «نشر المتجر» في الإعدادات
 *    قرأ أنّ متجره مغلق — وهو مفتوحٌ يستقبل الطلبات.
 */
class ASettingThatRefusesSaysWhereTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($this->owner);
    }

    /** الحمولة كما يرسلها نموذج الشاشة: كلُّ الحقول، من أيّ قسمٍ حُفظ */
    private function screenPayload(array $over = []): array
    {
        $s = Setting::where('business_id', $this->business->id)->pluck('value', 'key')->all();
        $b = $this->business->fresh();

        return array_merge([
            'shop_name' => $b->name,
            'phone' => (string) $b->phone,
            'email' => (string) $b->email,
            'address' => (string) $b->address,
            'vat_enabled' => ($s['vat_enabled'] ?? '1') === '1',
            'vat_rate' => $s['vat_rate'] ?? '5',
            'vat_number' => $s['vat_number'] ?? '',
            'vat_filed_through' => $s['vat_filed_through'] ?? '',
            'tax_mode' => $s['tax_mode'] ?? 'exclusive',
            'currency' => $s['currency'] ?? 'OMR',
            'decimals' => $s['decimals'] ?? '3',
            'symbol_pos' => $s['symbol_pos'] ?? 'after',
            'pay_cash' => ($s['pay_cash'] ?? '1') === '1',
            'pay_card' => ($s['pay_card'] ?? '1') === '1',
            'pay_transfer' => ($s['pay_transfer'] ?? '1') === '1',
            'inv_prefix' => $s['inv_prefix'] ?? 'INV-',
            'inv_start' => $s['inv_start'] ?? '1',
            'staff_sees_performance' => ($s['staff_sees_performance'] ?? '1') === '1',
            'notify_new_order' => ($s['notify_new_order'] ?? '1') === '1',
            'notify_smart_alerts' => ($s['notify_smart_alerts'] ?? '1') === '1',
            'notify_daily_summary' => ($s['notify_daily_summary'] ?? '1') === '1',
        ], $over);
    }

    private function setting(string $key, string $value): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->business->id, 'key' => $key],
            ['value' => $value],
        );
    }

    /** ثابتٌ خاصٌّ في المتحكّم — يُقرأ بالانعكاس كما يقرؤه حارسُ الإغلاق */
    private function keys(): array
    {
        return (new \ReflectionClass(SettingController::class))->getConstant('KEYS');
    }

    /** مفاتيحُ أقسام الإعدادات وأسماؤها كما تكتبها بطاقاتُ الشاشة */
    private function navSections(): array
    {
        $nav = file_get_contents(resource_path('js/Pages/Admin/Settings/partials/SettingsNav.tsx'));

        // المفتاحُ واسمُه قد يفترقان بأسطرٍ وتعليقٍ بينهما في القائمة
        preg_match_all(
            "/\bkey: '([a-z0-9-]+)',\s*(?:\/\*.*?\*\/\s*)?label: '([^']+)'/su",
            $nav, $m, PREG_SET_ORDER
        );

        return collect($m)->mapWithKeys(fn ($x) => [$x[1] => $x[2]])->all();
    }

    /* ═══════════════ ١ — الحفظُ الذي كان يصمت ═══════════════ */

    public function test_a_stale_value_in_another_section_does_not_refuse_in_silence(): void
    {
        // صفٌّ من أيّام الحفظ الحرّ: عملةٌ مكتوبةٌ بالعربية لا يقبلها الحقل
        $this->setting('currency', 'ريال عماني');

        $this->post(route('admin.settings.update'), $this->screenPayload([
            'shop_name' => 'الاسم الجديد',
        ]))->assertSessionHasErrors('currency');

        // ولا شيء حُفظ — فالطلبُ يُردّ كلُّه
        $this->assertSame('متجري', $this->business->fresh()->name, 'حُفظ بعضُ الطلب بعد ردِّه');

        $toast = session('toast');

        $this->assertIsArray($toast, 'رُدَّ الحفظ بلا تنبيه — زرٌّ لا يفعل شيئًا ولا يقول لماذا');
        $this->assertSame('danger', $toast['type']);
        $this->assertStringContainsString('لم يُحفظ شيء', $toast['msg']);
        // واسمُ القسم فيه: الحقلُ في «المالية» والواقفُ في «بيانات النشاط»
        $this->assertStringContainsString('الضرائب والعملة والدفع', $toast['msg']);
    }

    public function test_the_refusal_names_the_field_in_arabic(): void
    {
        // رقمٌ ضريبيّ أطولُ ممّا يُقبل — رسالتُه رسالةُ القاعدة الافتراضية،
        // وفيها اسمُ الحقل. وكان يخرج «حقل vat_number» في جملةٍ عربية.
        $this->post(route('admin.settings.update'), $this->screenPayload([
            'vat_number' => str_repeat('9', 40),
        ]))->assertSessionHasErrors('vat_number');

        $msg = session('toast')['msg'];

        $this->assertStringContainsString('الرقم الضريبي', $msg);
        $this->assertStringNotContainsString(
            'vat_number',
            $msg,
            'رسالةٌ عربية تحمل اسم الحقل كما يكتبه المبرمج',
        );
    }

    public function test_a_good_save_still_says_it_saved(): void
    {
        $this->post(route('admin.settings.update'), $this->screenPayload([
            'shop_name' => 'الاسم الجديد',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('الاسم الجديد', $this->business->fresh()->name);
        $this->assertSame('success', session('toast')['type']);
    }

    public function test_every_key_declares_where_it_lives_and_what_it_is_called(): void
    {
        $sections = $this->navSections();

        $this->assertNotEmpty($sections, 'لم تُقرأ أقسام الإعدادات من بطاقاتها');

        foreach ($this->keys() as $key => $meta) {
            $this->assertArrayHasKey('rules', $meta, "المفتاح {$key} بلا قاعدة");
            $this->assertArrayHasKey('label', $meta, "المفتاح {$key} بلا اسمٍ عربيّ");
            $this->assertArrayHasKey('section', $meta, "المفتاح {$key} لا يقول في أيّ قسمٍ يُصلَح");
            $this->assertNotSame('', trim($meta['label']), "اسمٌ فارغ للمفتاح {$key}");
            $this->assertArrayHasKey(
                $meta['section'],
                $sections,
                "المفتاح {$key} يُحيل إلى قسمٍ لا بطاقةَ له: {$meta['section']}",
            );
        }
    }

    public function test_the_section_names_match_the_cards_the_merchant_sees(): void
    {
        $nav = $this->navSections();
        $mine = (new \ReflectionClass(SettingController::class))->getConstant('SECTIONS');

        foreach ($mine as $key => $label) {
            $this->assertArrayHasKey($key, $nav, "قسمٌ في المتحكّم لا بطاقةَ له: {$key}");
            $this->assertSame(
                $nav[$key],
                $label,
                "اسمُ القسم في المتحكّم غير اسمِه على بطاقته: {$key}",
            );
        }

        // وكلُّ قسمٍ يسكنه مفتاحٌ له اسمٌ هنا — وإلّا قيل للتاجر «في قسم «»»
        foreach ($this->keys() as $key => $meta) {
            $this->assertArrayHasKey($meta['section'], $mine, "قسمُ المفتاح {$key} بلا اسم");
        }
    }

    /* ═══════════════ ٢ — العملة تُدير المال ═══════════════ */

    public function test_changing_the_currency_changes_what_the_money_says(): void
    {
        Currency::create([
            'business_id' => $this->business->id, 'name' => 'ريال عماني', 'code' => 'OMR',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);

        $this->post(route('admin.settings.update'), $this->screenPayload(['currency' => 'AED']))
            ->assertSessionHasNoErrors();

        Demo::flushCurrency();

        $this->assertStringContainsString(
            'د.إ',
            Money::format(12.5, Demo::baseCurrency()),
            'بُدِّلت العملةُ في الشاشة وبقي المالُ يُكتب بالقديمة',
        );
        $this->assertSame('AED', Currency::where('business_id', $this->business->id)->where('is_base', true)->value('code'));
    }

    public function test_a_shop_without_a_currency_row_gets_none_invented(): void
    {
        $this->post(route('admin.settings.update'), $this->screenPayload(['currency' => 'AED']))
            ->assertSessionHasNoErrors();

        Demo::flushCurrency();

        // الإعدادُ وحده يكفيه — ولا يُخترع له صفٌّ في جدولٍ لا شاشةَ تملؤه
        $this->assertSame(0, Currency::where('business_id', $this->business->id)->count());
        $this->assertStringContainsString('د.إ', Money::format(12.5, Demo::baseCurrency()));
    }

    public function test_the_neighbours_currency_is_not_touched(): void
    {
        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Currency::create([
            'business_id' => $other->id, 'name' => 'ريال عماني', 'code' => 'OMR',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Currency::create([
            'business_id' => $this->business->id, 'name' => 'ريال عماني', 'code' => 'OMR',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);

        $this->post(route('admin.settings.update'), $this->screenPayload(['currency' => 'SAR']))
            ->assertSessionHasNoErrors();

        $this->assertSame('OMR', Currency::where('business_id', $other->id)->value('code'));
    }

    /* ═══════════════ ٣ — الشارةُ تقول أيّ صفحةٍ تُفتح ═══════════════ */

    private function shop(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة ورد', 'price' => 12.5,
            'active' => true, 'published' => true, 'quantity' => 5,
        ]);

        $this->post(route('admin.marketing.store.save'), [
            'site_slug' => 'wardi', 'store_on' => true, 'store_theme' => 'rose',
            'store_show_prices' => true, 'store_pay_cod' => true,
        ])->assertSessionHasNoErrors();
    }

    private function publishBuilt(): void
    {
        $site = Builder::create($this->business, Blueprints::STORE, 'modern', $this->owner->id);
        Publisher::publish($site, $this->owner->id);
    }

    private function serves(): string
    {
        return $this->get(route('admin.settings.index'))->viewData('page')['props']['store']['serves'];
    }

    public function test_the_screen_says_simple_when_only_the_simple_page_is_published(): void
    {
        $this->shop();

        $this->assertSame(Storefront::SERVES_SIMPLE, $this->serves());
    }

    public function test_the_screen_says_nothing_is_served_before_publishing(): void
    {
        $this->assertSame(Storefront::SERVES_NONE, $this->serves());
    }

    public function test_the_built_site_wins_when_both_are_published(): void
    {
        $this->shop();
        $this->publishBuilt();

        // مفتاحُ البسيطة مرفوعٌ والمبنيُّ منشور — والعنوانُ واحد. فالمبنيّ
        // هو ما يُفتح (انظر `StorefrontController::show`)، والشاشةُ تقوله.
        $this->assertSame(Storefront::SERVES_BUILT, Storefront::serves($this->business->fresh()));
        $this->assertSame(Storefront::SERVES_BUILT, $this->serves());
    }

    public function test_turning_off_the_simple_switch_does_not_close_a_built_site(): void
    {
        $this->shop();
        $this->publishBuilt();

        // يُطفئ «نشر المتجر» من الإعدادات — والعنوانُ يبقى يفتح الموقع المبنيّ
        $this->post(route('admin.marketing.store.save'), [
            'site_slug' => 'wardi', 'store_on' => false, 'store_theme' => 'rose',
            'store_show_prices' => true, 'store_pay_cod' => true,
        ])->assertSessionHasNoErrors();

        $this->get('/s/wardi')->assertOk();

        // فالشاشة تقول الحقيقة: المفتوحُ هو المبنيّ لا البسيطة
        $this->assertSame(Storefront::SERVES_BUILT, $this->serves());
    }

    public function test_a_locked_shop_serves_nothing_whatever_its_switches_say(): void
    {
        $this->shop();
        $this->publishBuilt();

        Business::whereKey($this->business->id)->update(['status' => 'موقوف']);

        $this->assertSame(Storefront::SERVES_NONE, Storefront::serves($this->business->fresh()));
    }

    public function test_the_visitor_and_the_screen_read_the_same_answer(): void
    {
        $this->shop();

        foreach ([false, true] as $built) {
            if ($built) {
                $this->publishBuilt();
            }

            $serves = Storefront::serves($this->business->fresh());
            $page = $this->get('/s/wardi');

            $this->assertSame(
                $serves !== Storefront::SERVES_NONE,
                $page->status() === 200,
                'الشاشةُ تقول شيئًا والعنوانُ يقول غيرَه',
            );
        }
    }

    /* ═══════════════ ٤ — الاستعادةُ تُسأل قبل أن تمحو ═══════════════ */

    public function test_an_unconfirmed_restore_wipes_nothing(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'صنفٌ قائم', 'price' => 3, 'active' => true,
        ]);

        $dump = $this->get(route('admin.backup.download'))->getContent();
        $file = UploadedFile::fake()->createWithContent('backup.json', $dump);

        $this->post(route('admin.backup.restore'), ['backup' => $file])
            ->assertSessionHasErrors('confirm');

        // ولا يُمسّ شيء: الردُّ قبل المحو لا بعده
        $this->assertSame(1, Product::where('business_id', $this->business->id)->count());
    }

    public function test_a_confirmed_restore_goes_through(): void
    {
        Product::create([
            'business_id' => $this->business->id, 'name' => 'صنفٌ قبل النسخة', 'price' => 3, 'active' => true,
        ]);

        $dump = $this->get(route('admin.backup.download'))->getContent();

        Product::create([
            'business_id' => $this->business->id, 'name' => 'صنفٌ بعد النسخة', 'price' => 4, 'active' => true,
        ]);

        $file = UploadedFile::fake()->createWithContent('backup.json', $dump);

        $this->post(route('admin.backup.restore'), ['backup' => $file, 'confirm' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['صنفٌ قبل النسخة'],
            Product::where('business_id', $this->business->id)->pluck('name')->all(),
        );
    }

    public function test_a_pointer_to_a_version_that_is_gone_is_not_a_published_site(): void
    {
        $this->shop();
        $this->publishBuilt();

        // النسخةُ تختفي ويبقى مؤشّرُها — فحصٌ يقرأ العمودَ وحده يقول «منشور»
        WebsiteVersion::query()->delete();

        $this->assertSame(
            Storefront::SERVES_SIMPLE,
            Storefront::serves($this->business->fresh()),
            'مؤشّرٌ إلى نسخةٍ ذهبت يُقرأ موقعًا منشورًا',
        );

        // والزائرُ يرى البسيطة — فالشاشةُ لا تقول له غيرَ ما يرى
        $this->get('/s/wardi')->assertOk();
    }

    public function test_the_badge_on_the_screen_reads_that_answer(): void
    {
        $tsx = file_get_contents(resource_path('js/Pages/Admin/Settings/Index.tsx'));

        $at = strpos($tsx, 'label="منشور — موقعك المبنيّ"');

        $this->assertNotFalse($at, 'شارةُ المتجر لا تذكر الموقعَ المبنيّ');
        $this->assertStringContainsString(
            "store.serves === 'built'",
            substr($tsx, max(0, $at - 600), 600),
            'الشارةُ لا تُبنى على ما يُخدم فعلًا',
        );
    }

    /* ═════ ٥ — كلُّ قسمٍ يحفظ حقولَه وحدها ═════ */

    public function test_the_screen_is_told_which_field_belongs_to_which_section(): void
    {
        $fields = $this->get(route('admin.settings.index'))->viewData('page')['props']['settingsFields'];

        $flat = collect($fields)->flatten()->all();

        // لا مفتاحَ يسقط من القائمة ولا يُخترع فيها مفتاحٌ لا يُحفظ
        sort($flat);
        $keys = array_keys($this->keys());
        sort($keys);

        $this->assertSame($keys, $flat, 'قائمةُ الحقول تفترق عن قائمة ما يُحفظ');

        foreach ($fields as $section => $names) {
            foreach ($names as $name) {
                $this->assertSame($section, $this->keys()[$name]['section']);
            }
        }
    }

    public function test_saving_one_section_leaves_the_others_untouched(): void
    {
        $this->setting('vat_rate', '9');

        // حمولةُ قسم «بيانات النشاط» وحده — كما ترسلها الشاشة بعد التحويل
        $this->post(route('admin.settings.update'), [
            'shop_name' => 'الاسم الجديد', 'phone' => '', 'email' => '', 'address' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame('الاسم الجديد', $this->business->fresh()->name);
        $this->assertSame(
            '9',
            Setting::where('business_id', $this->business->id)->where('key', 'vat_rate')->value('value'),
            'حفظُ قسمٍ نسخ القديمَ فوق قسمٍ آخر',
        );
    }

    public function test_the_form_sends_only_the_open_section(): void
    {
        $tsx = file_get_contents(resource_path('js/Pages/Admin/Settings/Index.tsx'));

        $at = strpos($tsx, 'const submit = (e: React.FormEvent)');

        $this->assertNotFalse($at, 'لم يُعثر على حفظ النموذج');
        $this->assertStringContainsString(
            'settingsFields[tab',
            substr($tsx, $at, 700),
            'النموذج يرسل حقولَه كلَّها من أيّ قسم — فيُعيد ما لم يُعدَّل',
        );
    }
}
