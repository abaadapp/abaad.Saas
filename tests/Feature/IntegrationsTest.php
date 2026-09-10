<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\User;
use App\Support\GoogleReviews;
use App\Support\Integrations;
use App\Support\MarketingSettings;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * التطبيقات التكاملية — لوحةٌ تقول بمَ رُبط المتجر، وبمَ يستطيع أن يُربط.
 *
 * وأهمُّ ما فيها أنّها لا تكذب: بطاقةٌ تقول «مربوط» ومفتاحُها ناقص تجعل
 * التاجر ينتظر تقييماتٍ لا تأتي ولا يعرف لماذا. فالحالُ تُقاس ممّا يقرؤه
 * العاملُ نفسه لا ممّا يظنّه أحد.
 */
class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->business = Business::create(['name' => 'ورود مسقط', 'type' => 'محل ورود', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** بطاقاتُ اللوحة كما تصل الشاشة، مفهرسةً بمفتاح الأداة */
    private function cards(): array
    {
        $props = $this->actingAs($this->owner)->get(route('admin.integrations.index'))
            ->assertOk()->viewData('page')['props'];

        return collect($props['apps'])->keyBy('key')->all();
    }

    /* ------------------------------- اللوحة ------------------------------- */

    public function test_the_hub_lists_every_tool_in_the_catalogue(): void
    {
        $cards = $this->cards();

        $this->assertSame(
            [Integrations::GOOGLE, Integrations::WHATSAPP, Integrations::AMWALPAY],
            array_keys($cards),
            'الترتيب ترتيبُ الدليل — لا ترتيبٌ يتبدّل بحال المتجر',
        );
    }

    /**
     * والأدواتُ تُعرض كلُّها لا المربوطةُ وحدها.
     *
     * لوحةٌ لا تعرض إلا ما رُبط تكون فارغةً عند كلّ متجرٍ جديد، فلا تقول
     * لصاحبها ما الذي يستطيع ربطه — وهو السؤال الذي فُتحت من أجله.
     */
    public function test_a_merchant_who_connected_nothing_still_sees_what_he_could_connect(): void
    {
        $cards = $this->cards();

        $this->assertCount(3, $cards);
        $this->assertSame('off', $cards[Integrations::GOOGLE]['status']['state']);
    }

    /* ------------------------------ حالُ الأداة ------------------------------ */

    /**
     * «مربوط» تعني أنّ الاثنين حاضران: المحلُّ والمفتاح.
     *
     * ومفتاحٌ بلا محلٍّ لا يقرأ شيئًا، ومحلٌّ بلا مفتاحٍ لا يُقرأ. فأيُّهما
     * وحده «لم يكتمل» — ولو قيلت «مربوط» لَانتظر التاجر تقييماتٍ لا تأتي.
     */
    public function test_google_is_ready_only_when_both_the_place_and_the_key_are_there(): void
    {
        $this->assertSame('off', $this->cards()[Integrations::GOOGLE]['status']['state']);

        // محلٌّ بلا مفتاح — بدأ ولم يكتمل
        MarketingSettings::save($this->business->id, 'google', [
            'google_place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
        ]);
        $this->assertSame('partial', $this->cards()[Integrations::GOOGLE]['status']['state']);

        // ومع المفتاح يتمّ الشرطان
        GoogleReviews::storeKey($this->business->id, 'AIza-merchant-key');
        $this->assertSame('ready', $this->cards()[Integrations::GOOGLE]['status']['state']);
    }

    /** وضغطُ «ربط» وحده يُخرج الأداة من «غير مربوط» ولا يبلغ بها «مربوط» */
    public function test_pressing_connect_moves_google_out_of_off_but_not_into_ready(): void
    {
        MarketingSettings::save($this->business->id, 'connect', ['google_setup_started' => '1']);

        $this->assertSame('partial', $this->cards()[Integrations::GOOGLE]['status']['state']);
    }

    /**
     * واللوحةُ لا تسأل Google لترسم بطاقة.
     *
     * نداءٌ شبكيّ في كلّ فتحةٍ يجعل صفحةً تُفتح كلَّ يومٍ تنتظر خادمًا لا
     * نملكه — فإن تأخّر تأخّرت اللوحة كلُّها. والحالُ تُقرأ من القاعدة.
     */
    public function test_the_hub_never_calls_google_to_draw_a_card(): void
    {
        MarketingSettings::save($this->business->id, 'google', [
            'google_place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
        ]);
        GoogleReviews::storeKey($this->business->id, 'AIza-merchant-key');

        \Illuminate\Support\Facades\Http::preventStrayRequests();

        $this->assertSame('ready', $this->cards()[Integrations::GOOGLE]['status']['state']);
    }

    /* ------------------------------ ما لم يُبنَ ------------------------------ */

    /**
     * AmwalPay في الدليل ولمّا تُبنَ — وتُقال كما هي.
     *
     * وبطاقةٌ تعد بالربط ثمّ تفتح شاشةً فارغة أسوأ من بطاقةٍ تقول «لم
     * تُهيّأ بعد»: الأولى يجرّبها التاجر مرّتين ثمّ يظنّ العطب في متصفّحه.
     */
    public function test_a_tool_that_was_never_built_says_so_and_offers_no_door(): void
    {
        $card = $this->cards()[Integrations::AMWALPAY];

        $this->assertFalse($card['built']);
        $this->assertNull($card['route'], 'ما لم يُبنَ لا بابَ له — ولا يُخترع له باب');
        $this->assertSame('unbuilt', $card['status']['state']);
    }

    /** ولا مسارَ في النظام باسمها: البطاقةُ لا تخفي بابًا مفتوحًا */
    public function test_no_route_answers_for_the_unbuilt_tool(): void
    {
        $this->assertFalse(
            app('router')->has('admin.integrations.amwalpay'),
            'وُجد مسارٌ لأداةٍ الدليلُ يقول إنّها لم تُبنَ',
        );
    }

    /* ------------------------------- الباقة ------------------------------- */

    /**
     * والباقةُ تسبق الحال.
     *
     * أداةٌ خارج الباقة لا يُقال عنها «غير مربوطة» — فيذهب صاحبها يربطها
     * ويُردّ. ولا تُخفى أيضًا: من لم يشترِها يفيده أن يعرف أنّها موجودة.
     */
    public function test_a_tool_outside_the_plan_is_shown_and_marked_so(): void
    {
        $plan = Plan::create(['name' => 'الأساسية', 'monthly_price' => 10, 'yearly_price' => 100, 'capabilities' => []]);
        $this->business->update(['plan_id' => $plan->id]);

        $card = $this->cards()[Integrations::WHATSAPP];

        $this->assertFalse($card['licensed']);
        $this->assertTrue($card['built'], 'خارج الباقة لا يعني غير مبنيّ');
    }

    /* ------------------------------ الصلاحية ------------------------------ */

    /**
     * ربطُ الأدوات قسمٌ مستقلّ — لا يُمنح مع «أدوات التسويق».
     *
     * وهذا هو الفرق كلُّه: من يكتب كوبونًا أو يردّ على تقييم كان يُمنح معه
     * مفتاحَ Places — وهو مفتاحٌ يُنفِق على حساب المتجر — ووصلةَ واتساب
     * التي تخاطب زبائنه باسمه.
     */
    public function test_marketing_alone_no_longer_opens_the_connection_screens(): void
    {
        $marketer = User::create([
            'business_id' => $this->business->id, 'name' => 'مسوّق', 'email' => 'm@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['dashboard', 'marketing'],
        ]);

        foreach (['admin.integrations.index', 'admin.integrations.google', 'admin.integrations.whatsapp'] as $screen) {
            $this->actingAs($marketer)->get(route($screen))->assertForbidden();
        }

        // وبابُه الذي مُنحه يبقى مفتوحًا: الكوبونات تسويقٌ لا ربط
        $this->actingAs($marketer)->get(route('admin.marketing.coupons'))->assertOk();
    }

    /** ومن مُنح التكاملات وحدها يفتحها ولا يفتح التسويق */
    public function test_the_section_opens_for_whoever_was_granted_it_alone(): void
    {
        $connector = User::create([
            'business_id' => $this->business->id, 'name' => 'مهيّئ', 'email' => 'i@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'sales', 'status' => 'نشط',
            'permissions' => ['dashboard', 'integrations'],
        ]);

        $this->actingAs($connector)->get(route('admin.integrations.index'))->assertOk();
        $this->actingAs($connector)->get(route('admin.marketing.coupons'))->assertForbidden();
    }

    /** والقسمُ في قائمة الأقسام ومعه بابُه واسمُه — وإلّا سقط في كلّ شاشة */
    public function test_the_section_is_declared_with_a_door_and_a_name(): void
    {
        $this->assertContains('integrations', Permissions::SECTIONS);
        $this->assertSame('admin.integrations.index', Permissions::ROUTES['integrations']);
        $this->assertSame('التطبيقات التكاملية', Permissions::sectionLabels()['integrations']);
    }

    /* --------------------- الربط هنا والاستعمال في قسمه --------------------- */

    /**
     * شاشةُ «إشعارات واتساب» لم يعد فيها رمزٌ ولا معرّفُ وصلة.
     *
     * ومن يريد إطفاء رسالةٍ واحدة كان يمرّ على رمز تفعيلٍ من ميتا لا شأن
     * له به. فبقي هناك ما يُفعَل بالأداة، وانتقل إلى هنا ما يصلها.
     */
    public function test_the_notifications_screen_keeps_the_switches_and_drops_the_wiring(): void
    {
        $props = $this->actingAs($this->owner)->get(route('admin.marketing.whatsapp'))
            ->assertOk()->viewData('page')['props'];

        $this->assertArrayHasKey('events', $props['automation']);
        $this->assertNotEmpty($props['settings'], 'مقابضُ الأحداث تبقى في شاشتها');
    }

    /** وشاشةُ الربط تحمل الوصلة ولا تحمل مقبضَ حدثٍ واحد */
    public function test_the_connection_screen_carries_the_wiring(): void
    {
        $props = $this->actingAs($this->owner)->get(route('admin.integrations.whatsapp'))
            ->assertOk()->viewData('page')['props'];

        $this->assertArrayHasKey('readiness', $props['automation']);
        $this->assertArrayNotHasKey('settings', $props, 'مقابضُ الأحداث ليست من الربط');
    }
}
