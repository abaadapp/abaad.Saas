<?php

namespace Tests\Feature;

use App\Models\ActivityLog as ActivityRow;
use App\Models\Branch;
use App\Models\Business;
use App\Models\ChannelAutomation;
use App\Models\ChannelAutomationRun;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Support\ChannelAutomations;
use App\Support\ChannelAutomationsSwitch;
use App\Support\KeywordMatch;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ‏‏التاجر يكتب كلماتٍ وردًّا مرّةً — فيخرج الردّ وحده حين يكتب زبونٌ إحداها على واتساب.
 *
 * مقفلةٌ حتى تفتحها المنصّة؛ والطلبُ الآليّ قبلها، والترحيبُ بعدها لما لم يطابق كلمة؛ وحرّاسُ
 * الترحيب كلُّها تمنعها؛ وأتمتةُ متجرٍ لا تردّ من رقم غيره. وميتا كلُّها مُحاكاة: لا رسالةَ تخرج.
 */
class AShopAnswersKeywordsOnWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-not-a-real-one';

    private static int $seq = 0;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        config([
            'whatsapp.app_secret' => self::SECRET,
            'whatsapp.graph_url' => 'https://graph.example.test',
            'whatsapp.api_version' => 'v21.0',
        ]);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.OUT-'.(++self::$seq)]]], 200));

        $this->plan = Plan::create(['name' => 'الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299, 'capabilities' => ['whatsapp']]);
    }

    /* ═══════════ أدوات ═══════════ */

    private function shop(string $name): Business
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط', 'plan_id' => $this->plan->id,
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true, 'whatsapp_shared_allowed' => false,
            'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return $b->fresh();
    }

    private function own(Business $b, string $pn, array $over = []): WhatsAppConnection
    {
        return WhatsAppConnection::create($over + [
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $b->id,
            'phone_number_id' => $pn, 'display_phone_number' => '+968 7000 000'.substr($pn, -1),
            'waba_id' => 'WABA-'.$pn, 'access_token' => 'shop-token-value-'.$pn,
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
    }

    private function owner(Business $b, string $role = 'admin'): User
    {
        return User::create([
            'business_id' => $b->id, 'name' => 'مستخدم '.$role, 'email' => $role.'-'.$b->id.'@shop.om',
            'password' => bcrypt('password12345'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    private function automation(Business $b, array $over = []): ChannelAutomation
    {
        return ChannelAutomation::create($over + [
            'business_id' => $b->id, 'channel' => ChannelAutomations::WHATSAPP, 'name' => 'السعر',
            'trigger' => ChannelAutomations::TRIGGER_KEYWORD, 'match' => KeywordMatch::CONTAINS,
            'keywords' => ['سعر'], 'reply_ar' => 'سعرنا اليوم ممتاز', 'status' => ChannelAutomation::PUBLISHED,
            'cooldown_minutes' => 60,
        ]);
    }

    private function webhook(array $value): void
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => $value]],
        ]]]);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body)->assertOk();
    }

    private function say(string $pn, string $wamid, string $text, string $from = '96891234567'): void
    {
        $this->webhook(['metadata' => ['phone_number_id' => $pn], 'messages' => [[
            'id' => $wamid, 'from' => $from, 'type' => 'text', 'timestamp' => (string) now()->timestamp, 'text' => ['body' => $text],
        ]]]);
    }

    private function press(string $pn, string $wamid, string $id, string $title, string $from = '96891234567'): void
    {
        $this->webhook(['metadata' => ['phone_number_id' => $pn], 'messages' => [[
            'id' => $wamid, 'from' => $from, 'type' => 'interactive', 'timestamp' => (string) now()->timestamp,
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]],
        ]]]);
    }

    /** @return list<array{pn:string, to:string, type:string, body:string, raw:array}> */
    private function sent(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_contains($r->url(), '/messages'))
            ->map(fn (HttpRequest $r) => [
                'pn' => (string) preg_replace('~^.*/([^/]+)/messages$~', '$1', $r->url()),
                'to' => (string) $r['to'], 'type' => (string) $r['type'],
                'body' => (string) ($r['text']['body'] ?? $r['interactive']['body']['text'] ?? ''),
                'raw' => $r->data(),
            ])->values()->all();
    }

    private function open(Business ...$shops): void
    {
        $this->assertTrue(ChannelAutomationsSwitch::set(ChannelAutomationsSwitch::PILOT, array_map(fn ($s) => $s->id, $shops))['ok']);
    }

    /* ═══════════ الكلمةُ تأخذ ردّها ═══════════ */

    public function test_a_keyword_gets_the_shops_reply_with_its_product_and_price_instead_of_the_welcome(): void
    {
        $shop = $this->shop('محل الورد');
        $this->own($shop, 'PN-1');
        $product = Product::create(['business_id' => $shop->id, 'name' => 'باقة الجوري', 'price' => 12.5, 'cost' => 4, 'quantity' => 5]);
        $a = $this->automation($shop, ['reply_ar' => "{المنتج} بسعر {السعر}\n{رابط_المنتج}", 'product_id' => $product->id]);
        $this->open($shop);

        $this->say('PN-1', 'wamid.IN-1', 'كم السّعر؟');

        $sent = $this->sent();
        $this->assertCount(1, $sent, 'ردٌّ واحد — لا ترحيبٌ معه');
        $this->assertStringContainsString('باقة الجوري بسعر', $sent[0]['body']);
        $this->assertStringContainsString('12.500', $sent[0]['body']);
        $this->assertStringNotContainsString('{', $sent[0]['body'], 'متغيّرٌ بلا قيمة لا يخرج بقوسيه');

        $row = WhatsAppInboundMessage::where('wamid', 'wamid.IN-1')->first();
        $this->assertSame(WhatsAppInboundMessage::SENT, $row->reply_status);
        $this->assertSame('automation:'.$a->id, $row->reply_reason);
        $this->assertSame(ChannelAutomationRun::SENT, ChannelAutomationRun::where('automation_id', $a->id)->value('status'));

        // ‏‏والردُّ في المحادثة باسم الأتمتة — يراه الموظّف
        $this->assertSame(1, WhatsAppChatMessage::where('business_id', $shop->id)->where('direction', WhatsAppChatMessage::OUT)
            ->where('sender_name', 'like', '%السعر%')->whereNull('sender_user_id')->count());

        // ‏‏وزبونٌ آخر كتب ما لا يطابق — يصله الترحيب كما كان
        $this->say('PN-1', 'wamid.IN-2', 'مرحبا', '96899999999');
        $this->assertCount(2, $this->sent());
        $this->assertStringContainsString('أهلًا بك في محل الورد', $this->sent()[1]['body']);
    }

    public function test_nothing_changes_for_a_shop_until_the_platform_opens_it(): void
    {
        $shop = $this->shop('محل الورد');
        $other = $this->shop('محل آخر');
        $this->own($shop, 'PN-1');
        $this->automation($shop);
        $owner = $this->owner($shop);

        // ‏‏مقفلةٌ افتراضًا: الترحيبُ كما كان، ولا سطرَ تشغيل، ولا صفحة
        $this->assertSame(ChannelAutomationsSwitch::OFF, ChannelAutomationsSwitch::mode());
        $this->say('PN-1', 'wamid.IN-1', 'سعر');
        $this->assertStringContainsString('أهلًا بك في محل الورد', $this->sent()[0]['body']);
        $this->assertSame(0, ChannelAutomationRun::count());
        $this->actingAs($owner)->get(route('admin.integrations.whatsapp.automations'))->assertNotFound();

        // ‏‏ومفتوحةٌ لمتجرٍ آخر وحده — وهذا على حاله
        $this->open($other);
        $this->say('PN-1', 'wamid.IN-2', 'سعر', '96899999999');
        $this->assertStringNotContainsString('سعرنا اليوم', $this->sent()[1]['body']);
        $this->assertFalse(ChannelAutomations::available($shop));

        // ‏‏وللجميع — فتعمل
        ChannelAutomationsSwitch::set(ChannelAutomationsSwitch::ALL);
        $this->say('PN-1', 'wamid.IN-3', 'سعر', '96898888888');
        $this->assertSame('سعرنا اليوم ممتاز', $this->sent()[2]['body']);
    }

    public function test_arabic_is_matched_the_way_customers_write_it(): void
    {
        $this->assertSame('سعر', KeywordMatch::normalize('السّعر؟'));
        $this->assertSame('كم سعره', KeywordMatch::normalize('كم سعرة!!'));
        $this->assertSame('رقم 15', KeywordMatch::normalize('رقم ١٥'));
        $this->assertSame('price', KeywordMatch::normalize('PRICE'));
        $this->assertSame('الا', KeywordMatch::normalize('إلا'), '«ال» لا تُنزع من كلمةٍ قصيرة');

        $this->assertSame('السعر', KeywordMatch::first('كم سعرها لو سمحت', ['السعر']));
        $this->assertSame('حالة الطلب', KeywordMatch::first('ابي اعرف حالة طلبي', ['طلب', 'حالة الطلب']), 'الأطولُ أوّلًا');
        $this->assertNull(KeywordMatch::first('كم السعر', ['سعر'], KeywordMatch::EXACT));
        $this->assertSame('سعر', KeywordMatch::first('السعر', ['سعر'], KeywordMatch::EXACT));
        $this->assertNull(KeywordMatch::first('', ['سعر']));
    }

    public function test_an_exact_match_wins_and_excluded_words_step_aside_and_drafts_stay_silent(): void
    {
        $shop = $this->shop('محل الورد');
        $this->own($shop, 'PN-1');
        $this->open($shop);
        $this->automation($shop, ['name' => 'عامة', 'keywords' => ['طلب'], 'excluded' => ['إلغاء'], 'reply_ar' => 'الرد العام']);
        $this->automation($shop, ['name' => 'تامة', 'keywords' => ['طلبي'], 'match' => KeywordMatch::EXACT, 'reply_ar' => 'الرد التام']);
        $this->automation($shop, ['name' => 'مسودة', 'keywords' => ['هدية'], 'reply_ar' => 'لا يخرج', 'status' => ChannelAutomation::DRAFT]);
        $this->automation($shop, ['name' => 'موقوفة', 'keywords' => ['ورد'], 'reply_ar' => 'لا يخرج أيضًا', 'status' => ChannelAutomation::PAUSED]);

        $this->say('PN-1', 'wamid.IN-1', 'طلبي', '96891000001');
        $this->say('PN-1', 'wamid.IN-2', 'عندي طلب', '96891000002');
        $this->say('PN-1', 'wamid.IN-3', 'ابي الغاء الطلب', '96891000003');
        $this->say('PN-1', 'wamid.IN-4', 'هدية ورد', '96891000004');

        $bodies = array_column($this->sent(), 'body');
        $this->assertSame('الرد التام', $bodies[0]);
        $this->assertSame('الرد العام', $bodies[1]);
        $this->assertStringContainsString('أهلًا بك', $bodies[2], 'كلمةٌ مستثناة: لا أتمتة، فالترحيب');
        $this->assertStringContainsString('أهلًا بك', $bodies[3], 'المسوّدةُ والموقوفةُ لا تردّان');
    }

    public function test_the_same_customer_is_not_answered_twice_within_the_wait(): void
    {
        $shop = $this->shop('محل الورد');
        $this->own($shop, 'PN-1');
        $this->open($shop);
        $a = $this->automation($shop, ['cooldown_minutes' => 30]);

        $this->say('PN-1', 'wamid.IN-1', 'سعر');
        $this->say('PN-1', 'wamid.IN-2', 'السعر كم؟');

        $this->assertCount(1, $this->sent(), 'لا ردٌّ ثانٍ ولا ترحيبٌ بعده');
        $this->assertSame('automation_cooldown', WhatsAppInboundMessage::where('wamid', 'wamid.IN-2')->value('reply_reason'));

        $this->travel(31)->minutes();
        $this->say('PN-1', 'wamid.IN-3', 'سعر');
        $this->assertCount(2, $this->sent());
        $this->assertSame(2, ChannelAutomationRun::where('automation_id', $a->id)->where('status', ChannelAutomationRun::SENT)->count());

        // ‏‏والإشعارُ المكرَّر نفسُه لا يُعالج مرّتين
        $this->say('PN-1', 'wamid.IN-3', 'سعر');
        $this->assertCount(2, $this->sent());
    }

    public function test_it_stays_quiet_while_a_staff_member_is_talking_to_that_customer(): void
    {
        $shop = $this->shop('محل الورد');
        $this->own($shop, 'PN-1');
        $this->open($shop);
        $this->automation($shop);
        $staff = $this->owner($shop);

        $this->say('PN-1', 'wamid.IN-1', 'مرحبا');
        $chat = WhatsAppChat::where('business_id', $shop->id)->firstOrFail();
        WhatsAppChatMessage::create([
            'business_id' => $shop->id, 'chat_id' => $chat->id, 'direction' => WhatsAppChatMessage::OUT, 'body' => 'أهلًا، كيف أخدمك؟',
            'phone_number_id' => 'PN-1', 'sender_user_id' => $staff->id, 'sender_name' => 'منى', 'status' => WhatsAppStatus::SENT,
        ]);

        $this->say('PN-1', 'wamid.IN-2', 'كم السعر');
        $this->assertCount(1, $this->sent(), 'الترحيبُ وحده — والموظّفةُ تكمل');
        $this->assertSame('automation_staff_replied', WhatsAppInboundMessage::where('wamid', 'wamid.IN-2')->value('reply_reason'));
    }

    public function test_the_welcomes_guards_hold_for_keywords_too(): void
    {
        $shop = $this->shop('محل الورد');
        $this->own($shop, 'PN-1', ['status' => WhatsAppConnection::ERROR]);
        $this->open($shop);
        $a = $this->automation($shop);

        $this->say('PN-1', 'wamid.IN-1', 'سعر');

        $this->assertSame([], $this->sent());
        $this->assertSame('connection_unusable', ChannelAutomationRun::where('automation_id', $a->id)->value('reason'));
        $this->assertSame(ChannelAutomationRun::SKIPPED, ChannelAutomationRun::where('automation_id', $a->id)->value('status'));
    }

    public function test_buttons_go_out_as_whatsapp_buttons_and_a_pressed_button_answers_with_its_own_automation(): void
    {
        $shop = $this->shop('محل الورد');
        $this->own($shop, 'PN-1');
        $this->open($shop);
        $menu = $this->automation($shop, ['name' => 'القائمة', 'keywords' => ['قائمة'], 'reply_ar' => 'اختر', 'buttons' => [
            ['type' => 'reply', 'title' => 'التوصيل'], ['type' => 'reply', 'title' => 'السعر'],
        ]]);
        $this->automation($shop, ['name' => 'التوصيل', 'keywords' => ['التوصيل'], 'reply_ar' => 'نوصّل لكل مسقط']);
        $this->automation($shop, ['name' => 'رابط', 'keywords' => ['رابط'], 'reply_ar' => 'تفضّل', 'buttons' => [
            ['type' => 'link', 'title' => 'افتح', 'url' => 'https://rose.example.om/catalog'],
        ]]);

        $this->say('PN-1', 'wamid.IN-1', 'القائمة');
        $first = $this->sent()[0]['raw'];
        $this->assertSame('interactive', $first['type']);
        $this->assertSame('button', $first['interactive']['type']);
        $this->assertSame(['auto:'.$menu->id.':0', 'auto:'.$menu->id.':1'], array_column(array_column($first['interactive']['action']['buttons'], 'reply'), 'id'));

        $this->press('PN-1', 'wamid.IN-2', 'auto:'.$menu->id.':0', 'التوصيل');
        $this->assertSame('نوصّل لكل مسقط', $this->sent()[1]['body']);

        $this->say('PN-1', 'wamid.IN-3', 'ابي الرابط', '96899999999');
        $cta = $this->sent()[2]['raw']['interactive'];
        $this->assertSame('cta_url', $cta['type']);
        $this->assertSame('https://rose.example.om/catalog', $cta['action']['parameters']['url']);
    }

    public function test_one_shop_never_answers_with_another_shops_automation(): void
    {
        $a = $this->shop('محل أ');
        $b = $this->shop('محل ب');
        $this->own($a, 'PN-1');
        $this->own($b, 'PN-2');
        $this->open($a, $b);
        $mine = $this->automation($a, ['reply_ar' => 'سعر محل أ']);

        $this->say('PN-2', 'wamid.IN-1', 'سعر');
        $this->assertCount(1, $this->sent());
        $this->assertSame('PN-2', $this->sent()[0]['pn']);
        $this->assertStringNotContainsString('محل أ', $this->sent()[0]['body']);
        $this->assertSame(0, ChannelAutomationRun::where('business_id', $b->id)->count());

        $ownerB = $this->owner($b);
        $this->actingAs($ownerB)->post(route('admin.integrations.whatsapp.automations.status', $mine->id), ['status' => 'paused'])->assertNotFound();
        $this->actingAs($ownerB)->delete(route('admin.integrations.whatsapp.automations.destroy', $mine->id))->assertNotFound();
        $this->assertSame(ChannelAutomation::PUBLISHED, $mine->fresh()->status);
    }

    public function test_the_owner_writes_publishes_pauses_and_deletes_and_nobody_else_can(): void
    {
        $shop = $this->shop('محل الورد');
        $other = $this->shop('محل آخر');
        $this->open($shop);
        $owner = $this->owner($shop);
        $cashier = $this->owner($shop, 'cashier');
        $foreign = Product::create(['business_id' => $other->id, 'name' => 'ليس لك', 'price' => 1, 'cost' => 1, 'quantity' => 1]);

        $form = [
            'name' => 'السعر', 'match' => 'contains', 'keywords' => ['السعر', 'السّعر', 'بكم'], 'excluded' => [],
            'reply_ar' => 'سعرنا ممتاز', 'reply_en' => '', 'buttons' => [], 'cooldown_minutes' => 60, 'status' => 'published',
        ];

        $this->actingAs($owner)->get(route('admin.integrations.whatsapp.automations'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Integrations/WhatsappAutomations')->where('view.may_manage', true)->has('view.templates', 6));

        // ‏‏والكاشير لا يبلغ الصفحة أصلًا: قسمُ التكاملات ليس له
        $this->actingAs($cashier)->post(route('admin.integrations.whatsapp.automations.store'), $form)->assertForbidden();
        $this->assertSame(0, ChannelAutomation::count());

        $this->actingAs($owner)->post(route('admin.integrations.whatsapp.automations.store'), ['keywords' => []] + $form)->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('admin.integrations.whatsapp.automations.store'), ['reply_ar' => 'دوامنا [اكتب ساعاتك]'] + $form)->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('admin.integrations.whatsapp.automations.store'), ['product_id' => $foreign->id] + $form)->assertSessionHasErrors('product_id');
        $this->actingAs($owner)->post(route('admin.integrations.whatsapp.automations.store'), ['buttons' => [['type' => 'link', 'title' => 'افتح', 'url' => 'javascript:alert(1)']]] + $form)
            ->assertSessionHasErrors('buttons.0.url');
        $this->assertSame(0, ChannelAutomation::count());

        // ‏‏والمسوّدةُ تُحفظ بنصٍّ ينتظر — والنشرُ لا
        $this->actingAs($owner)->post(route('admin.integrations.whatsapp.automations.store'), ['reply_ar' => 'دوامنا [اكتب ساعاتك]', 'status' => 'draft'] + $form)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('admin.integrations.whatsapp.automations.store'), $form)->assertSessionHasNoErrors();

        $saved = ChannelAutomation::where('status', ChannelAutomation::PUBLISHED)->firstOrFail();
        $this->assertSame(['السعر', 'بكم'], $saved->keywords, '«السعر» و«السّعر» كلمةٌ واحدة');
        $this->assertSame($shop->id, (int) $saved->business_id);
        $this->assertSame($owner->id, (int) $saved->created_by);

        $draft = ChannelAutomation::where('status', ChannelAutomation::DRAFT)->firstOrFail();
        $this->actingAs($owner)->post(route('admin.integrations.whatsapp.automations.status', $draft->id), ['status' => 'published'])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('admin.integrations.whatsapp.automations.status', $saved->id), ['status' => 'paused'])->assertSessionHasNoErrors();
        $this->assertSame(ChannelAutomation::PAUSED, $saved->fresh()->status);

        $this->actingAs($owner)->put(route('admin.integrations.whatsapp.automations.update', $saved->id), ['name' => 'الأسعار'] + $form)->assertSessionHasNoErrors();
        $this->assertSame(['الأسعار', ChannelAutomation::PUBLISHED], [$saved->fresh()->name, $saved->fresh()->status]);

        $this->actingAs($owner)->delete(route('admin.integrations.whatsapp.automations.destroy', $saved->id))->assertSessionHasNoErrors();
        $this->assertNull($saved->fresh());

        $this->assertGreaterThanOrEqual(4, ActivityRow::where('subject_type', ChannelAutomation::class)->count(), 'كلُّ تغييرٍ في السجلّ');
    }

    public function test_order_status_reads_the_last_order_of_this_number_in_this_shop(): void
    {
        $shop = $this->shop('محل الورد');
        $this->own($shop, 'PN-1');
        $this->open($shop);
        $this->automation($shop, ['name' => 'حالة الطلب', 'keywords' => ['طلبي'], 'reply_ar' => '{آخر_طلب}']);

        $customer = Customer::create(['business_id' => $shop->id, 'name' => 'سارة', 'phone' => '+968 9123 4567']);
        foreach (['INV-6' => 'مكتمل', 'INV-7' => 'قيد التجهيز'] as $number => $status) {
            DB::table('orders')->insert([
                'business_id' => $shop->id, 'customer_id' => $customer->id, 'number' => $number, 'status' => $status,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->say('PN-1', 'wamid.IN-1', 'وين طلبي');
        $this->assertSame('طلبك رقم INV-7: قيد التجهيز', $this->sent()[0]['body']);

        $this->say('PN-1', 'wamid.IN-2', 'طلبي', '96899999999');
        $this->assertStringContainsString('لم نجد طلبًا مسجّلًا برقمك', $this->sent()[1]['body']);
    }

    public function test_the_platform_opens_it_for_pilot_shops_and_says_so_in_the_log(): void
    {
        $shop = $this->shop('محل الورد');
        $platform = User::create([
            'business_id' => null, 'name' => 'مدير المنصّة', 'email' => 'p@abaad.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->actingAs($platform)->post(route('super-admin.automations.switch'), ['mode' => 'pilot', 'pilot' => ''])->assertSessionHasErrors('pilot');
        $this->assertSame(ChannelAutomationsSwitch::OFF, ChannelAutomationsSwitch::mode());

        $this->actingAs($platform)->post(route('super-admin.automations.switch'), ['mode' => 'pilot', 'pilot' => $shop->id.'، '.$shop->id])->assertSessionHasNoErrors();
        $this->assertSame([ChannelAutomationsSwitch::PILOT, [$shop->id]], [ChannelAutomationsSwitch::mode(), ChannelAutomationsSwitch::pilot()]);
        $this->assertTrue(ChannelAutomations::available($shop));
        $this->assertSame(1, ActivityRow::where('subject_type', Setting::class)->where('description', 'like', '%أتمتة القنوات%')->count());

        $this->actingAs($this->owner($shop))->post(route('super-admin.automations.switch'), ['mode' => 'all'])->assertForbidden();
        $this->assertSame(ChannelAutomationsSwitch::PILOT, ChannelAutomationsSwitch::mode());
    }
}
