<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppCampaignTemplate;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppOptOut;
use App\Support\WhatsAppCampaignAudience;
use App\Support\WhatsAppCampaigns;
use App\Support\WhatsAppCampaignsSwitch;
use App\Support\WhatsAppCampaignTemplates;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOptOuts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ‏‏التاجر يكتب عرضًا بقالبٍ معتمَد فيخرج من رقمه إلى عملائه — وما يحرس ذلك.
 *
 * مقفلةٌ حتى تفتحها المنصّة؛ من رقم المتجر وحده؛ بعددٍ رآه وأقرّه وبموافقةٍ أقرّ بها؛ ولا رسالتان لرقم؛
 * ومن طلب الإيقاف لا تصله؛ وما يصيب الكلّ يوقفها ولا يحرق القائمة. وميتا كلُّها مُحاكاة: لا رسالةَ تخرج.
 */
class AShopSendsWhatsAppCampaignsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret-not-a-real-one';

    private Plan $plan;

    private Business $shop;

    private User $owner;

    private WhatsAppConnection $line;

    private int $seq = 0;

    /** @var callable|null */
    private $onSend = null;

    /** @var callable|null */
    private $onCreate = null;

    /** @var list<array<string, mixed>> */
    private array $metaTemplates = [];

    private string $tier = 'TIER_2K';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');
        Cache::flush();
        // ‏‏ظهرًا: لا ساعةَ صمت
        $this->travelTo(now()->setTime(12, 0));

        config(['whatsapp.app_secret' => self::SECRET, 'whatsapp.graph_url' => 'https://graph.example.test', 'whatsapp.api_version' => 'v21.0']);
        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);

        Http::fake(function (HttpRequest $r) {
            $url = $r->url();

            return match (true) {
                str_contains($url, '/message_templates') && $r->method() === 'POST' => $this->onCreate ? ($this->onCreate)($r) : Http::response(['id' => 'TPL-'.(++$this->seq), 'status' => 'PENDING', 'category' => 'MARKETING']),
                str_contains($url, '/message_templates') && $r->method() === 'DELETE' => Http::response(['success' => true]),
                str_contains($url, '/message_templates') => Http::response(['data' => $this->metaTemplates]),
                str_ends_with($url, '/messages') => $this->onSend ? ($this->onSend)($r) : Http::response(['messages' => [['id' => 'wamid.OUT-'.(++$this->seq)]]]),
                str_contains($url, 'whatsapp_business_manager_messaging_limit') => Http::response(['whatsapp_business_manager_messaging_limit' => $this->tier, 'quality_rating' => 'GREEN']),
                default => Http::response(['error' => ['message' => 'unexpected '.$url]], 500),
            };
        });

        $this->plan = Plan::create(['name' => 'الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299, 'capabilities' => ['whatsapp']]);
        $this->shop = $this->shop('محل الورد');
        $this->owner = $this->user($this->shop, 'admin');
        $this->line = $this->own($this->shop, 'PN-1');
    }

    /* ═══════════ أدوات ═══════════ */

    private function shop(string $name): Business
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط', 'plan_id' => $this->plan->id,
            'whatsapp_enabled' => true, 'whatsapp_own_allowed' => true, 'whatsapp_shared_allowed' => false, 'whatsapp_mode' => WhatsAppMode::BUSINESS_OWN,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return $b->fresh();
    }

    private function user(Business $b, string $role): User
    {
        return User::create([
            'business_id' => $b->id, 'name' => 'مستخدم '.$role, 'email' => $role.'-'.$b->id.'@shop.om',
            'password' => bcrypt('password12345'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    private function own(Business $b, string $pn): WhatsAppConnection
    {
        return WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_BUSINESS, 'business_id' => $b->id, 'phone_number_id' => $pn, 'display_phone_number' => '+968 7000 0000',
            'waba_id' => 'WABA-'.$pn, 'access_token' => 'shop-token-'.$pn, 'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);
    }

    private function open(): void
    {
        $this->assertTrue(WhatsAppCampaignsSwitch::set(WhatsAppCampaignsSwitch::ALL)['ok']);
    }

    private function customer(string $name, ?string $phone, array $over = [], ?Business $b = null): Customer
    {
        return Customer::create($over + ['business_id' => ($b ?? $this->shop)->id, 'name' => $name, 'phone' => $phone]);
    }

    private function bought(Customer $c, int $daysAgo, float $total = 10): void
    {
        Order::create([
            'business_id' => $c->business_id, 'number' => 'O-'.$c->id.'-'.$daysAgo, 'customer_id' => $c->id, 'customer_name' => $c->name,
            'status' => 'تم التسليم', 'payment_status' => 'مدفوع', 'subtotal' => $total, 'total' => $total, 'ordered_at' => now()->subDays($daysAgo),
        ]);
    }

    private function template(array $over = [], ?Business $b = null): WhatsAppCampaignTemplate
    {
        $b ??= $this->shop;

        return WhatsAppCampaignTemplate::create($over + [
            'business_id' => $b->id, 'waba_id' => 'WABA-PN-1', 'meta_name' => 'abaad_offer_'.$b->id.'_'.(++$this->seq), 'title' => 'عرض العيد', 'language' => 'ar',
            'body' => 'أهلًا {الاسم}، خصم العيد في {المتجر} حتى الخميس', 'variables' => ['name', 'store'], 'status' => 'APPROVED',
        ]);
    }

    /** @param array<string, mixed> $over */
    private function form(WhatsAppCampaignTemplate $t, int $count, array $over = []): array
    {
        return $over + ['name' => 'حملة العيد', 'template_id' => $t->id, 'confirm_count' => $count, 'consent' => true, 'audience' => ['segment' => 'all', 'quiet_days' => 3]];
    }

    private function launch(WhatsAppCampaignTemplate $t, int $count, array $over = []): WhatsAppCampaign
    {
        $this->actingAs($this->owner)->post(route('admin.marketing.whatsapp.campaigns.store'), $this->form($t, $count, $over))->assertSessionHasNoErrors();

        return WhatsAppCampaign::orderByDesc('id')->firstOrFail();
    }

    private function tick(int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            Artisan::call('whatsapp:run-campaigns');
        }
    }

    /** @return list<array{to: string, name: string, params: list<string>}> */
    private function sent(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages') && $r['type'] === 'template')
            ->map(fn (HttpRequest $r) => [
                'to' => (string) $r['to'], 'name' => (string) $r['template']['name'], 'lang' => (string) $r['template']['language']['code'],
                'params' => array_column($r['template']['components'][0]['parameters'] ?? [], 'text'), 'token' => $r->header('Authorization')[0] ?? '',
            ])->values()->all();
    }

    private function webhook(array $change, string $entry = 'WABA-PN-1'): void
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => $entry, 'changes' => [$change]]]]);

        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body)->assertOk();
    }

    private function delivery(string $wamid, string $status, ?string $code = null, string $pn = 'PN-1'): void
    {
        $this->webhook(['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => $pn], 'statuses' => [array_filter([
            'id' => $wamid, 'status' => $status, 'timestamp' => (string) now()->timestamp, 'errors' => $code ? [['code' => (int) $code, 'title' => 'x']] : null,
        ])]]]);
    }

    private function say(string $wamid, string $from, array $message): void
    {
        $this->webhook(['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'PN-1'], 'messages' => [$message + ['id' => $wamid, 'from' => $from, 'timestamp' => (string) now()->timestamp]]]]);
    }

    /* ═══════════ مفتاحُ المنصّة وصاحبُ الحساب ═══════════ */

    public function test_it_is_closed_until_the_platform_opens_it_and_only_the_owner_writes(): void
    {
        $this->actingAs($this->owner)->get(route('admin.marketing.whatsapp.campaigns'))->assertNotFound();
        $this->get(route('admin.integrations.whatsapp'))->assertInertia(fn ($p) => $p->where('automation.campaigns', false));

        $super = User::create(['business_id' => $this->shop('المنصّة')->id, 'name' => 'مدير', 'email' => 'root@abaadapp.om', 'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط']);
        $this->actingAs($super)->post(route('super-admin.whatsappCampaigns.switch'), ['mode' => 'pilot', 'pilot' => ''])->assertSessionHasErrors('campaigns_pilot');
        $this->post(route('super-admin.whatsappCampaigns.switch'), ['mode' => 'pilot', 'pilot' => (string) $this->shop->id])->assertSessionHasNoErrors();
        $this->assertSame(1, ActivityLog::where('description', 'like', 'حملات واتساب:%لمتاجر التجربة%')->count());

        $this->actingAs($this->owner)->get(route('admin.marketing.whatsapp.campaigns'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Marketing/WhatsappCampaigns')->where('view.obstacle', null)->where('view.may_manage', true));

        $other = $this->shop('محل آخر');
        $this->actingAs($this->user($other, 'admin'))->get(route('admin.marketing.whatsapp.campaigns'))->assertNotFound();
        $this->actingAs($this->user($this->shop, 'cashier'))->post(route('admin.marketing.whatsapp.campaigns.store'), [])->assertForbidden();
    }

    public function test_a_shop_without_its_own_number_is_told_so_and_nothing_leaves_from_abaads(): void
    {
        $this->open();
        $t = $this->template();
        $this->customer('سارة', '91234567');
        $this->line->update(['status' => WhatsAppConnection::INACTIVE]);

        $this->actingAs($this->owner)->get(route('admin.marketing.whatsapp.campaigns'))->assertInertia(fn ($p) => $p->where('view.obstacle', fn ($v) => str_contains($v, 'رقم متجرك')));
        $this->post(route('admin.marketing.whatsapp.campaigns.store'), $this->form($t, 1))->assertSessionHasErrors('name');
        $this->assertSame([0, []], [WhatsAppCampaign::count(), $this->sent()]);
    }

    /* ═══════════ القوالب ═══════════ */

    public function test_a_template_goes_to_meta_with_an_opt_out_button_and_comes_back_approved(): void
    {
        $this->open();

        $this->actingAs($this->owner)->post(route('admin.marketing.whatsapp.campaigns.templates.store'), [
            'title' => 'عرض العيد', 'language' => 'ar', 'body' => "أهلًا {الاسم}،\nخصم 20٪ في {المتجر} حتى الخميس.", 'button' => ['title' => 'اطلب الآن', 'url' => 'https://rose.test'],
        ])->assertSessionHasNoErrors();

        $t = WhatsAppCampaignTemplate::firstOrFail();
        $this->assertSame(['WABA-PN-1', 'PENDING', 'TPL-1', ['name', 'store']], [$t->waba_id, $t->status, $t->meta_id, $t->variables]);

        Http::assertSent(function (HttpRequest $r) use ($t) {
            if ($r->method() !== 'POST' || ! str_contains($r->url(), '/WABA-PN-1/message_templates')) {
                return false;
            }
            $parts = collect($r['components'])->keyBy('type');

            return $r['category'] === 'MARKETING' && $r['language'] === 'ar' && $r['name'] === $t->meta_name && preg_match('/^[a-z0-9_]+$/', $r['name'])
                && $parts['BODY']['text'] === "أهلًا {{1}}،\nخصم 20٪ في {{2}} حتى الخميس." && $parts['BODY']['example']['body_text'] === [['سارة', 'محل الورد']]
                && str_contains($parts['FOOTER']['text'], 'إيقاف الرسائل') && mb_strlen($parts['FOOTER']['text']) <= 60
                && $parts['BUTTONS']['buttons'][0] === ['type' => 'QUICK_REPLY', 'text' => 'إيقاف الرسائل']
                && $parts['BUTTONS']['buttons'][1] === ['type' => 'URL', 'text' => 'اطلب الآن', 'url' => 'https://rose.test'];
        });

        // ‏‏إشعارُ Meta على حساب الأعمال — لحسابٍ آخر أو لغةٍ أخرى لا يمسّه
        $approve = fn (string $waba, string $lang) => $this->webhook(['field' => 'message_template_status_update', 'value' => ['event' => 'APPROVED', 'message_template_name' => $t->meta_name, 'message_template_language' => $lang, 'reason' => 'NONE', 'message_template_category' => 'MARKETING']], $waba);
        $approve('WABA-OTHER', 'ar');
        $approve('WABA-PN-1', 'en-US');
        $this->assertSame('PENDING', $t->fresh()->status);
        $approve('WABA-PN-1', 'ar');
        $this->assertSame('APPROVED', $t->fresh()->status);

        // ‏‏والسؤالُ يقرأ الرفضَ بسببه، وما لم تعد Meta تعرفه لا يُرسل به
        $this->metaTemplates = [['name' => $t->meta_name, 'language' => 'ar', 'status' => 'REJECTED', 'category' => 'MARKETING', 'rejected_reason' => 'INVALID_FORMAT']];
        $this->post(route('admin.marketing.whatsapp.campaigns.templates.sync'))->assertSessionHasNoErrors();
        $this->assertSame(['REJECTED', 'INVALID_FORMAT'], [$t->fresh()->status, $t->fresh()->rejected_reason]);
        $this->metaTemplates = [];
        Artisan::call('whatsapp:run-campaigns', ['--templates' => true]);
        $this->assertSame('REJECTED', $t->fresh()->status, 'ما حُسم لا يُسأل عنه كلَّ ساعة');
        $t->fresh()->update(['status' => 'PENDING']);
        Artisan::call('whatsapp:run-campaigns', ['--templates' => true]);
        $this->assertSame('DELETED', $t->fresh()->status);
    }

    public function test_what_whatsapp_refuses_is_said_before_it_is_sent_and_a_refusal_saves_nothing(): void
    {
        $this->open();
        $store = fn (array $over) => $this->actingAs($this->owner)->post(route('admin.marketing.whatsapp.campaigns.templates.store'), $over + ['title' => 'عرض', 'language' => 'ar', 'body' => 'خصم اليوم لكم']);

        foreach (['{الاسم} أهلًا بك', 'أهلًا بك في {المتجر}.', 'أهلًا {الاسم} {المتجر} اليوم', 'خصم {كود} اليوم', 'خصم {{1}} اليوم', str_repeat('س', 901)] as $body) {
            $store(['body' => $body])->assertSessionHasErrors('body');
        }
        $store(['button' => ['title' => 'افتح', 'url' => 'http://not-secure.test']])->assertSessionHasErrors('body');
        $this->assertSame([], collect(Http::recorded())->all(), 'لا نداءَ إلى Meta بما يُعرف ردُّه');

        $this->onCreate = fn () => Http::response(['error' => ['code' => 100, 'message' => 'Invalid', 'error_user_msg' => 'النص يخالف السياسة']], 400);
        $store([])->assertSessionHasErrors(['body' => 'رفض واتساب القالب: النص يخالف السياسة']);
        $this->assertSame(0, WhatsAppCampaignTemplate::count());
    }

    public function test_a_template_in_use_is_not_deleted_and_one_at_rest_is_removed_at_meta_too(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $t = $this->template();
        $c = $this->launch($t, 1);

        $this->delete(route('admin.marketing.whatsapp.campaigns.templates.destroy', $t->id))->assertSessionHasErrors('templates');
        $this->post(route('admin.marketing.whatsapp.campaigns.cancel', $c->id));
        $this->delete(route('admin.marketing.whatsapp.campaigns.templates.destroy', $t->id))->assertSessionHasNoErrors();

        $this->assertNull(WhatsAppCampaignTemplate::find($t->id));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'name='.$t->meta_name));
        $this->assertNull($c->fresh()->template_id);
        $this->assertSame($t->body, $c->fresh()->message['body'], 'وما خرج يبقى مقروءًا');
    }

    /* ═══════════ لمن ═══════════ */

    public function test_the_audience_is_chosen_by_purchases_and_says_who_is_left_out(): void
    {
        $this->open();
        $recent = $this->customer('قريبة', '91000001');
        $this->bought($recent, 5, 50);
        $away = $this->customer('غائبة', '91000002');
        $this->bought($away, 120, 5);
        $this->customer('لم تشترِ', '91000003');
        $this->customer('بلا رقم', null);
        $this->customer('مكررة', '+968 91000001');
        $this->customer('أوقفت', '91000005');
        $this->customer('إنجليزية', '91000006', ['language' => 'en']);
        $this->customer('متجر آخر', '91999999', [], $this->shop('محل آخر'));
        WhatsAppOptOuts::add((int) $this->shop->id, '96891000005', WhatsAppOptOut::CUSTOMER);

        $ask = fn (array $a) => $this->actingAs($this->owner)->postJson(route('admin.marketing.whatsapp.campaigns.preview'), ['audience' => $a])->assertOk();

        $all = $ask(['segment' => 'all', 'quiet_days' => 0]);
        $this->assertSame([4, ['no_phone' => 1, 'opted_out' => 1, 'recent' => 0, 'duplicate' => 1]], [$all->json('count'), $all->json('skipped')]);
        $this->assertSame(1, $ask(['segment' => 'recent', 'days' => 30])->json('count'));
        $this->assertSame(1, $ask(['segment' => 'away', 'days' => 90])->json('count'));
        // ‏‏والبطاقةُ المكرّرة لم تشترِ باسمها
        $this->assertSame(3, $ask(['segment' => 'never'])->json('count'));
        $this->assertSame(1, $ask(['segment' => 'all', 'min_spent' => 20])->json('count'));
        $this->assertSame(1, $ask(['segment' => 'all', 'language' => 'en'])->json('count'));
        $this->assertSame(3, $ask(['segment' => 'all', 'language' => 'ar'])->json('count'));
        $this->assertSame(1, $ask(['segment' => 'whatever', 'days' => 'x'])->json('count'), 'اختيارٌ لا نعرفه يُردّ إلى الأضيق لا إلى الكلّ');
    }

    /* ═══════════ الإقرار ═══════════ */

    public function test_it_starts_only_with_consent_an_approved_template_and_the_number_that_was_shown(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $this->customer('منى', '92345678');
        $t = $this->template();
        $post = fn (array $over, ?WhatsAppCampaignTemplate $with = null) => $this->actingAs($this->owner)->post(route('admin.marketing.whatsapp.campaigns.store'), $this->form($with ?? $t, 2, $over));

        $post(['consent' => false])->assertSessionHasErrors('consent');
        $post(['confirm_count' => 1])->assertSessionHasErrors('confirm_count');
        $post([], $this->template(['status' => 'PENDING']))->assertSessionHasErrors('template_id');
        $post([], $this->template(['waba_id' => 'WABA-OLD']))->assertSessionHasErrors('template_id');
        $post([], $this->template([], $this->shop('محل آخر')))->assertSessionHasErrors('template_id');
        $post(['scheduled_at' => now()->subHour()->toIso8601String()])->assertSessionHasErrors('scheduled_at');
        $post(['scheduled_at' => now()->addDays(40)->toIso8601String()])->assertSessionHasErrors('scheduled_at');
        $post(['audience' => ['segment' => 'recent', 'days' => 7], 'confirm_count' => 1])->assertSessionHasErrors('audience');
        $this->assertSame([0, 0], [WhatsAppCampaign::count(), WhatsAppCampaignRecipient::count()]);

        $post([])->assertSessionHasNoErrors();
        // ‏‏وضغطتان لا تصيران حملتين
        $post([])->assertSessionHasErrors('name');

        $c = WhatsAppCampaign::firstOrFail();
        $this->assertSame([WhatsAppCampaign::SENDING, 2, $this->owner->id], [$c->status, $c->total, (int) $c->created_by]);
        $this->assertNotNull($c->consent_at);
        $this->assertSame(1, ActivityLog::where('subject_type', WhatsAppCampaign::class)->where('description', 'like', '%أقرّ بموافقة العملاء%')->count());
        $this->assertSame([], $this->sent(), 'لا رسالةَ تخرج من الطلب نفسِه');
    }

    /* ═══════════ الإرسال ═══════════ */

    public function test_messages_leave_in_batches_from_the_shops_number_with_each_ones_name(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $this->customer('  ', '92345678');
        $c = $this->launch($this->template(), 2);

        $this->tick();

        $sent = $this->sent();
        $this->assertSame(['96891234567', '96892345678'], array_column($sent, 'to'));
        $this->assertSame(['سارة', 'محل الورد'], $sent[0]['params']);
        $this->assertSame(['عميلنا العزيز', 'محل الورد'], $sent[1]['params'], 'متغيّرٌ فارغ تردّه ميتا');
        $this->assertSame(['Bearer shop-token-PN-1'], array_values(array_unique(array_column($sent, 'token'))));
        $this->assertSame([WhatsAppCampaign::DONE, 2], [$c->fresh()->status, $c->fresh()->stats['out']]);
        $this->assertSame(0, WhatsAppMessage::count(), 'الحملةُ ليست إشعارَ طلب — فلا تمسّ سجلَّه ولا حالَ الوصلة');

        $this->tick(2);
        $this->assertCount(2, $this->sent());
    }

    public function test_a_batch_is_small_and_the_night_is_quiet_and_a_scheduled_one_waits_its_time(): void
    {
        $this->open();
        foreach (range(1, 30) as $i) {
            $this->customer('عميل '.$i, '9200'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $c = $this->launch($this->template(), 30, ['scheduled_at' => now()->addHours(2)->toIso8601String()]);
        $this->assertSame(WhatsAppCampaign::SCHEDULED, $c->status);
        $this->tick();
        $this->assertSame([], $this->sent());

        $this->travel(121)->minutes();
        $this->tick();
        $this->assertCount(WhatsAppCampaigns::PER_RUN, $this->sent());
        $this->assertSame(WhatsAppCampaign::SENDING, $c->fresh()->status);

        $this->travelTo(now()->setTime(22, 30));
        $this->tick();
        $this->assertCount(WhatsAppCampaigns::PER_RUN, $this->sent(), 'العاشرة مساءً: لا تخرج');

        $this->travelTo(now()->addDay()->setTime(8, 1));
        $this->tick();
        $this->assertCount(30, $this->sent());
        $this->assertSame(WhatsAppCampaign::DONE, $c->fresh()->status);
    }

    public function test_the_hour_the_merchant_chose_is_the_hour_it_starts_wherever_the_clock_was_read(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $at = now()->addDay()->setTime(17, 0);

        // ‏‏المتصفّحُ يرسلها بتوقيت غرينتش (`toISOString`)
        $c = $this->launch($this->template(), 1, ['scheduled_at' => $at->copy()->utc()->format('Y-m-d\TH:i:s.000\Z')]);

        $this->assertSame($at->format('Y-m-d H:i'), $c->fresh()->scheduled_at->format('Y-m-d H:i'));
        $this->travelTo($at->copy()->subMinutes(2));
        $this->tick();
        $this->assertSame([], $this->sent());
        $this->travelTo($at->copy()->addMinute());
        $this->tick();
        $this->assertCount(1, $this->sent());
    }

    public function test_the_scheduler_never_breaks_while_the_tables_are_not_there_yet(): void
    {
        $this->assertFalse(WhatsAppCampaigns::waiting());
        Schema::drop('whatsapp_campaign_recipients');
        Schema::drop('whatsapp_campaigns');
        Schema::drop('whatsapp_campaign_templates');

        $this->assertFalse(WhatsAppCampaigns::waiting());
        $this->assertFalse(WhatsAppCampaigns::templatesWaiting());
    }

    public function test_metas_daily_limit_is_kept_and_the_rest_continues_by_itself(): void
    {
        $this->open();
        $this->tier = 'TIER_50';
        foreach (range(1, 60) as $i) {
            $this->customer('عميل '.$i, '9300'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }
        $c = $this->launch($this->template(), 60);

        $this->tick(5);
        $this->assertCount(45, $this->sent(), 'حدُّ الخمسين بهامش عُشر');
        $this->assertSame([WhatsAppCampaign::SENDING, WhatsAppCampaigns::WAITS_DAILY_LIMIT], [$c->fresh()->status, $c->fresh()->stop_reason]);
        $this->assertStringContainsString('حدّ اليوم', (string) WhatsAppCampaigns::stopWhy($c->fresh()->stop_reason));

        $this->travel(25)->hours();
        $this->tick();
        $this->assertCount(60, $this->sent());
        $this->assertSame([WhatsAppCampaign::DONE, null], [$c->fresh()->status, $c->fresh()->stop_reason]);
    }

    public function test_one_number_is_never_sent_twice_even_when_two_runs_meet_or_one_dies_midway(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $this->customer('منى', '92345678');
        $c = $this->launch($this->template(), 2);

        // ‏‏تشغيلٌ ثانٍ يبدأ وسط نداء الأوّل
        $nested = false;
        $this->onSend = function () use (&$nested) {
            if (! $nested) {
                $nested = true;
                WhatsAppCampaigns::run();
            }

            return Http::response(['messages' => [['id' => 'wamid.N-'.(++$this->seq)]]]);
        };
        $this->tick();
        $this->assertSame(2, count(array_unique(array_column($this->sent(), 'to'))));
        $this->assertCount(2, $this->sent());

        // ‏‏وتشغيلٌ مات بعد الحجز: يُطوى ولا يُعاد
        $row = $c->recipients()->first();
        $row->update(['status' => WhatsAppCampaignRecipient::SENDING, 'claimed_at' => now()->subMinutes(11)]);
        $c->fresh()->update(['status' => WhatsAppCampaign::SENDING, 'finished_at' => null]);
        $this->tick();
        $this->assertCount(2, $this->sent());
        $this->assertSame([WhatsAppCampaignRecipient::FAILED, WhatsAppCampaigns::INTERRUPTED], [$row->fresh()->status, $row->fresh()->error_code]);
    }

    public function test_a_lost_network_is_not_repeated_and_a_speed_limit_is(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $c = $this->launch($this->template(), 1);

        $this->onSend = fn () => Http::response(['error' => ['code' => 130429, 'message' => 'rate']], 400);
        $this->tick();
        $this->assertSame(WhatsAppCampaignRecipient::PENDING, $c->recipients()->first()->status);
        $this->assertSame(WhatsAppCampaign::SENDING, $c->fresh()->status);

        $calls = 0;
        $this->onSend = function () use (&$calls) {
            $calls++;

            throw new ConnectionException('cut');
        };
        $this->tick(2);
        $this->assertSame([WhatsAppCampaignRecipient::FAILED, 'network_error'], [$c->recipients()->first()->status, $c->recipients()->first()->error_code]);
        $this->assertSame(1, $calls, 'الحدُّ أُعيد، والشبكةُ لم تُعد: قد تكون وصلت وتُحتسب');
    }

    public function test_what_hits_everyone_stops_the_campaign_before_it_burns_the_list(): void
    {
        $this->open();
        foreach (range(1, 4) as $i) {
            $this->customer('عميل '.$i, '9400000'.$i);
        }
        $c = $this->launch($this->template(), 4);

        $this->onSend = fn () => Http::response(['error' => ['code' => 131042, 'message' => 'payment']], 400);
        $this->tick(3);
        $this->assertCount(1, $this->sent());
        $this->assertSame([WhatsAppCampaign::PAUSED, '131042', 4], [$c->fresh()->status, $c->fresh()->stop_reason, $c->recipients()->where('status', 'pending')->count()]);
        $this->assertStringContainsString('وسيلة الدفع', (string) WhatsAppCampaigns::stopWhy('131042'));

        $this->onSend = null;
        $this->post(route('admin.marketing.whatsapp.campaigns.resume', $c->id))->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame([WhatsAppCampaign::DONE, 4], [$c->fresh()->status, $c->recipients()->where('status', 'sent')->count()]);
    }

    public function test_when_most_of_them_fail_it_stops_and_asks(): void
    {
        $this->open();
        foreach (range(1, 40) as $i) {
            $this->customer('عميل '.$i, '9500'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }
        $c = $this->launch($this->template(), 40);
        $this->onSend = fn () => Http::response(['error' => ['code' => 131026, 'message' => 'undeliverable']], 400);

        $this->tick(3);

        $this->assertCount(WhatsAppCampaigns::MIN_TO_JUDGE, $this->sent());
        $this->assertSame([WhatsAppCampaign::PAUSED, WhatsAppCampaigns::STOP_MANY_FAILURES], [$c->fresh()->status, $c->fresh()->stop_reason]);
    }

    public function test_the_merchant_pauses_resumes_and_cancels_and_a_closed_switch_stops_it(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $c = $this->launch($this->template(), 1);

        $this->post(route('admin.marketing.whatsapp.campaigns.pause', $c->id))->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame([], $this->sent());
        $this->post(route('admin.marketing.whatsapp.campaigns.pause', $c->id))->assertSessionHasErrors('campaign');

        $this->post(route('admin.marketing.whatsapp.campaigns.resume', $c->id))->assertSessionHasNoErrors();
        WhatsAppCampaignsSwitch::set(WhatsAppCampaignsSwitch::OFF);
        $this->tick();
        $this->assertSame([[], WhatsAppCampaign::PAUSED, WhatsAppCampaigns::STOP_CLOSED], [$this->sent(), $c->fresh()->status, $c->fresh()->stop_reason]);

        $this->open();
        $this->post(route('admin.marketing.whatsapp.campaigns.cancel', $c->id))->assertSessionHasNoErrors();
        $this->tick();
        $this->assertSame([[], WhatsAppCampaign::CANCELLED], [$this->sent(), $c->fresh()->status]);
        $this->post(route('admin.marketing.whatsapp.campaigns.resume', $c->id))->assertSessionHasErrors('campaign');
    }

    public function test_a_number_that_changed_or_stopped_does_not_carry_the_campaign_on(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $c = $this->launch($this->template(), 1);
        $this->line->update(['status' => WhatsAppConnection::INACTIVE]);
        $this->own($this->shop, 'PN-2');

        $this->tick();
        $this->assertSame([[], WhatsAppCampaign::PAUSED], [$this->sent(), $c->fresh()->status]);
    }

    public function test_a_shop_restored_from_a_backup_does_not_send_it_again(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $c = $this->launch($this->template(), 1);
        // ‏‏أرقامُ الحملة لا تُنسخ (`TenantTables::NOT_MINE`) — فتعود الحملةُ بلا أرقام
        $c->recipients()->delete();

        $this->tick();
        $this->assertSame([[], WhatsAppCampaign::CANCELLED, WhatsAppCampaigns::STOP_RESTORED], [$this->sent(), $c->fresh()->status, $c->fresh()->stop_reason]);
    }

    /* ═══════════ ما يصل بعدها ═══════════ */

    public function test_delivery_is_read_from_meta_by_its_id_and_its_line_and_never_goes_back(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $c = $this->launch($this->template(), 1);
        $this->tick();
        $row = $c->recipients()->firstOrFail();
        $other = $this->shop('محل آخر');
        $this->own($other, 'PN-9');

        $this->delivery($row->wamid, 'read', pn: 'PN-9');
        $this->assertSame('sent', $row->fresh()->status, 'وصلةُ متجرٍ آخر لا تعدّل رسالته');

        $this->delivery($row->wamid, 'read');
        $this->delivery($row->wamid, 'delivered');
        $this->assertSame('read', $row->fresh()->status);
        $this->assertNotNull($row->fresh()->delivered_at);

        $this->delivery($row->wamid, 'failed', '131049');
        $this->assertSame(['failed', '131049'], [$row->fresh()->status, $row->fresh()->error_code]);
        $this->actingAs($this->owner)->get(route('admin.marketing.whatsapp.campaigns'))->assertInertia(fn ($p) => $p
            ->where('view.campaigns.0.counts.failed', 1)->where('view.campaigns.0.failures.0.n', 1)->where('view.campaigns.0.failures.0.why', fn ($w) => str_contains($w, 'حدّ واتساب')));
    }

    /* ═══════════ من طلب الإيقاف ═══════════ */

    public function test_the_stop_button_stops_this_shops_offers_says_so_and_start_brings_them_back(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $this->launch($this->template(), 1);
        $this->tick();

        $this->say('wamid.IN-1', '96891234567', ['type' => 'button', 'button' => ['payload' => 'إيقاف الرسائل', 'text' => 'إيقاف الرسائل']]);

        $this->assertSame('customer', WhatsAppOptOut::where('business_id', $this->shop->id)->where('phone', '96891234567')->value('source'));
        $this->assertSame('opted_out', WhatsAppInboundMessage::where('wamid', 'wamid.IN-1')->value('reply_reason'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages') && $r['type'] === 'text' && str_contains($r['text']['body'], 'أوقفنا رسائل العروض'));

        $this->travel(10)->days();
        $this->assertSame(0, $this->actingAs($this->owner)->postJson(route('admin.marketing.whatsapp.campaigns.preview'), ['audience' => ['segment' => 'all']])->json('count'));

        $this->say('wamid.IN-2', '96891234567', ['type' => 'text', 'text' => ['body' => 'اشتراك']]);
        $this->assertFalse(WhatsAppOptOuts::has((int) $this->shop->id, '96891234567'));
    }

    public function test_the_word_stop_from_someone_never_campaigned_is_a_conversation_not_an_opt_out(): void
    {
        $this->open();

        $this->say('wamid.IN-1', '96891234567', ['type' => 'text', 'text' => ['body' => 'إيقاف']]);

        $this->assertSame(0, WhatsAppOptOut::count());
        $this->assertStringNotContainsString('opted', (string) WhatsAppInboundMessage::where('wamid', 'wamid.IN-1')->value('reply_reason'));
    }

    public function test_one_who_stopped_after_the_list_was_made_is_skipped_at_the_door(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $c = $this->launch($this->template(), 1);
        $this->post(route('admin.marketing.whatsapp.campaigns.optouts.store'), ['phone' => '9123 4567'])->assertSessionHasNoErrors();
        $this->post(route('admin.marketing.whatsapp.campaigns.optouts.store'), ['phone' => 'x'])->assertSessionHasErrors('phone');

        $this->tick();
        $this->assertSame([[], 'skipped', WhatsAppCampaign::DONE], [$this->sent(), $c->recipients()->first()->status, $c->fresh()->status]);
    }

    public function test_stopping_offers_inside_whatsapp_is_heard_and_resuming_there_too(): void
    {
        $this->open();
        $pref = fn (string $v) => $this->webhook(['field' => 'user_preferences', 'value' => ['metadata' => ['phone_number_id' => 'PN-1'], 'user_preferences' => [['wa_id' => '96891234567', 'category' => 'marketing_messages', 'value' => $v]]]]);

        $pref('stop');
        $this->assertSame('meta', WhatsAppOptOut::where('phone', '96891234567')->value('source'));
        $pref('resume');
        $this->assertSame(0, WhatsAppOptOut::count());

        // ‏‏ومن أوقفها بزرّ المتجر لا تعيده إعداداتُ واتساب
        WhatsAppOptOuts::add((int) $this->shop->id, '96891234567', WhatsAppOptOut::CUSTOMER);
        $pref('resume');
        $this->assertSame(1, WhatsAppOptOut::count());

        // ‏‏ورفضُ Meta «أوقف العروض» يُكتب كذلك
        WhatsAppOptOut::query()->delete();
        $this->customer('سارة', '91234567');
        $c = $this->launch($this->template(), 1);
        $this->tick();
        $this->delivery((string) $c->recipients()->first()->wamid, 'failed', '131050');
        $this->assertSame('meta', WhatsAppOptOut::where('phone', '96891234567')->value('source'));
    }

    public function test_someone_campaigned_lately_is_left_alone_for_the_days_the_merchant_chose(): void
    {
        $this->open();
        $this->customer('سارة', '91234567');
        $this->launch($this->template(), 1);
        $this->tick();

        $count = fn (int $quiet) => $this->actingAs($this->owner)->postJson(route('admin.marketing.whatsapp.campaigns.preview'), ['audience' => ['segment' => 'all', 'quiet_days' => $quiet]])->json('count');
        $this->assertSame([0, 1], [$count(3), $count(0)]);
        $this->travel(4)->days();
        $this->assertSame(1, $count(3));
        $this->assertSame(WhatsAppCampaignAudience::SEGMENTS, ['all', 'recent', 'away', 'never']);
    }

    public function test_the_text_the_merchant_previews_is_the_text_that_leaves(): void
    {
        $body = 'أهلًا {الاسم}، خصم في {المتجر} اليوم';

        $this->assertNull(WhatsAppCampaignTemplates::problem($body));
        $this->assertSame(['body' => 'أهلًا {{1}}، خصم في {{2}} اليوم', 'variables' => ['name', 'store']], WhatsAppCampaignTemplates::compile($body));
        $this->assertSame('أهلًا سارة، خصم في محل الورد اليوم', WhatsAppCampaignTemplates::preview($this->shop, $body, 'ar', "سارة\n"));
    }
}
