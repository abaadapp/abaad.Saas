<?php

namespace Tests\Feature;

use App\Jobs\AnswerInstagramEvent;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Business;
use App\Models\ChannelAutomation;
use App\Models\ChannelAutomationRun;
use App\Models\InstagramConnection;
use App\Models\InstagramEvent;
use App\Models\Plan;
use App\Models\Product;
use App\Models\User;
use App\Support\ChannelAutomations;
use App\Support\ChannelAutomationsSwitch;
use App\Support\ChannelAutomationTemplates;
use App\Support\InstagramAutomations;
use App\Support\InstagramLink;
use App\Support\InstagramMessenger;
use App\Support\InstagramPlatform;
use App\Support\InstagramWebhooks;
use App\Support\KeywordMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ‏‏زبونٌ يعلّق «بكم؟» فيصله ردٌّ خاصّ ويُكتب تحته، أو يكتب رسالةً فيصله ردُّها — وما يمنع ذلك.
 *
 * مقفلةٌ حتى تُفتح بمفتاحين؛ ولا يُردّ على الحساب نفسِه ولا على ما سبق النشر؛ ولا تُقطع محادثةُ التاجر؛
 * ولا ردّان على تعليقٍ واحد؛ وحدودُ Meta (ساعتُها وبايتاتُها ونافذتُها) محترَمة. وMeta كلُّها مُحاكاة.
 */
class AShopAnswersInstagramCommentsAndMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'ig-secret-not-a-real-one';

    private Business $shop;

    private User $owner;

    private InstagramConnection $link;

    private int $seq = 0;

    /** @var callable|null ما تردّ به Meta على الإرسال — يُبدَّل في الاختبار */
    private $onSend = null;

    /** @var callable|null */
    private $onReply = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        config([
            'services.instagram.graph_url' => 'https://graph.instagram.test',
            'services.instagram.api_version' => 'v27.0',
        ]);

        Http::fake(function (HttpRequest $r) {
            return match (true) {
                str_ends_with($r->url(), '/messages') => $this->onSend ? ($this->onSend)($r) : Http::response(['recipient_id' => 'R', 'message_id' => 'MID-OUT-'.(++$this->seq)]),
                str_ends_with($r->url(), '/replies') => $this->onReply ? ($this->onReply)($r) : Http::response(['id' => 'REPLY-1']),
                str_contains($r->url(), '/media') => Http::response(['data' => [
                    ['id' => '17900000000000001', 'caption' => 'باقة الجوري', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED', 'media_url' => 'https://cdn.test/1.jpg', 'permalink' => 'https://instagram.test/p/1', 'timestamp' => '2026-11-20T10:00:00+0000'],
                    ['id' => '17900000000000002', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'media_url' => 'https://cdn.test/2.mp4', 'thumbnail_url' => 'https://cdn.test/2.jpg'],
                ]]),
                default => Http::response(['error' => ['message' => 'unexpected '.$r->url()]], 500),
            };
        });

        $this->shop = $this->shop('محل الورد');
        $this->owner = $this->user($this->shop, 'admin');
        $this->link = $this->linked($this->shop, '17841400000000001', 'rose.shop');
    }

    /* ═══════════ أدوات ═══════════ */

    private function shop(string $name, array $over = []): Business
    {
        $b = Business::create($over + ['name' => $name, 'type' => 'محل ورود', 'status' => 'نشط']);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return $b;
    }

    private function user(Business $b, string $role): User
    {
        return User::create([
            'business_id' => $b->id, 'name' => 'مستخدم '.$role, 'email' => $role.'-'.$b->id.'@shop.om',
            'password' => bcrypt('password12345'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    private function linked(Business $b, string $id, string $username): InstagramConnection
    {
        return InstagramConnection::create([
            'business_id' => $b->id, 'ig_user_id' => $id, 'ig_scoped_id' => 'APP-'.$id, 'username' => $username,
            'access_token' => 'LIVE-TOKEN-'.$b->id, 'token_expires_at' => now()->addDays(50), 'permissions' => InstagramLink::SCOPES,
            'subscribed_fields' => [...InstagramLink::FIELDS, InstagramLink::ECHOES], 'status' => InstagramConnection::ACTIVE, 'connected_at' => now()->subDays(3),
        ]);
    }

    private function open(): void
    {
        $this->assertTrue(InstagramPlatform::setCredentials('1234567890', self::SECRET)['ok']);
        $this->assertTrue(InstagramPlatform::setMode(InstagramPlatform::ALL)['ok']);
        $this->assertTrue(ChannelAutomationsSwitch::set(ChannelAutomationsSwitch::ALL)['ok']);
    }

    private function automation(array $over = [], ?Business $b = null): ChannelAutomation
    {
        return ChannelAutomation::create($over + [
            'business_id' => ($b ?? $this->shop)->id, 'channel' => ChannelAutomations::INSTAGRAM, 'name' => 'السعر',
            'trigger' => InstagramAutomations::COMMENT, 'match' => KeywordMatch::CONTAINS, 'keywords' => ['بكم', 'السعر'],
            'reply_ar' => 'أهلًا {الاسم}! السعر 12 ر.ع', 'status' => ChannelAutomation::PUBLISHED, 'cooldown_minutes' => 60,
        ]);
    }

    private function webhook(array $entry): void
    {
        $body = json_encode(['object' => 'instagram', 'entry' => [$entry + ['id' => $this->link->ig_user_id, 'time' => time()]]]);

        $this->call('POST', '/webhooks/instagram', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
        ], $body)->assertOk();
    }

    private function comment(string $id, string $text, string $from = 'IGSID-9', string $username = 'sara', array $media = ['id' => '17900000000000001'], array $over = []): void
    {
        $this->webhook(['changes' => [['field' => 'comments', 'value' => $over + ['id' => $id, 'text' => $text, 'from' => ['id' => $from, 'username' => $username], 'media' => $media]]]]);
    }

    private function dm(string $mid, string $text, string $from = 'IGSID-9', array $message = []): void
    {
        $this->webhook(['messaging' => [['sender' => ['id' => $from], 'recipient' => ['id' => $this->link->ig_user_id], 'timestamp' => time(), 'message' => $message + ['mid' => $mid, 'text' => $text]]]]);
    }

    /** ‏‏رسالةٌ خرجت من الحساب — كتبها التاجرُ بيده أو أرسلناها */
    private function echo(string $mid, string $to = 'IGSID-9'): void
    {
        $this->webhook(['messaging' => [['sender' => ['id' => $this->link->ig_user_id], 'recipient' => ['id' => $to], 'timestamp' => time(), 'message' => ['mid' => $mid, 'text' => 'كتبتُه بيدي', 'is_echo' => true]]]]);
    }

    /** @return list<array<string, mixed>> ما خرج إلى Meta: الرسائلُ والردودُ العلنيّة بترتيبها */
    private function sent(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages') || str_ends_with($r->url(), '/replies'))
            ->map(fn (HttpRequest $r) => str_ends_with($r->url(), '/replies')
                ? ['public' => (string) preg_replace('~^.*/([^/]+)/replies$~', '$1', $r->url()), 'text' => $r['message']]
                : ['to' => $r['recipient'], 'message' => $r['message'], 'token' => $r->header('Authorization')[0] ?? ''])
            ->values()->all();
    }

    private function last(): ?ChannelAutomationRun
    {
        return ChannelAutomationRun::orderByDesc('id')->first();
    }

    /* ═══════════ مفتاحان ═══════════ */

    public function test_it_is_closed_until_both_switches_open_for_the_shop(): void
    {
        $this->automation();

        $this->actingAs($this->owner)->get(route('admin.integrations.instagram.automations'))->assertNotFound();

        InstagramPlatform::setCredentials('1234567890', self::SECRET);
        InstagramPlatform::setMode(InstagramPlatform::ALL);
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram.automations'))->assertNotFound();
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram'))->assertInertia(fn ($p) => $p->where('instagram.automations', null));

        $this->comment('C-1', 'بكم؟');
        $this->assertSame([], $this->sent());
        $this->assertNotNull(InstagramEvent::firstOrFail()->processed_at, 'وصل والأتمتةُ مقفلة: يُطوى ولا ينتظر');

        ChannelAutomationsSwitch::set(ChannelAutomationsSwitch::PILOT, (string) $this->shop->id);
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram.automations'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Integrations/InstagramAutomations')->where('view.automations.0.trigger', 'comment')
                ->where('view.welcome', null)->missing('view.variables.last_order')->where('link.username', 'rose.shop'));
        $this->actingAs($this->owner)->get(route('admin.integrations.instagram'))
            ->assertInertia(fn ($p) => $p->where('instagram.automations', ['published' => 1, 'total' => 1]));

        // ‏‏وما طُوي قبل الفتح لا يُردّ عليه بعده
        Artisan::call('instagram:answer-pending');
        $this->assertSame([], $this->sent());
    }

    public function test_a_suspended_shop_or_a_plan_without_integrations_stays_silent(): void
    {
        $this->open();
        $this->automation();

        $this->shop->update(['status' => 'معطل']);
        $this->comment('C-1', 'بكم؟');

        $plan = Plan::create(['name' => 'الأساسية', 'monthly_price' => 1, 'yearly_price' => 10, 'capabilities' => []]);
        $this->shop->update(['status' => 'نشط', 'plan_id' => $plan->id]);
        $this->comment('C-2', 'بكم؟');
        $this->assertSame([], $this->sent());

        $plan->update(['capabilities' => ['integrations']]);
        $this->comment('C-3', 'بكم؟');
        $this->assertCount(1, $this->sent());
    }

    /* ═══════════ التعليق ═══════════ */

    public function test_a_comment_with_the_word_gets_a_private_reply_then_a_public_one(): void
    {
        $this->open();
        $a = $this->automation(['public_replies' => ['أرسلنا لك على الخاص @{الاسم}', 'تفقّد رسائلك {السعر}']]);

        $this->comment('C-1', 'بكم لو سمحت؟');
        $this->comment('C-1', 'بكم لو سمحت؟');

        $sent = $this->sent();
        $this->assertCount(2, $sent, 'التعليقُ الواحد يصل مرّتين فيُردّ مرّة');
        $this->assertSame(['comment_id' => 'C-1'], $sent[0]['to']);
        $this->assertSame(['text' => 'أهلًا sara! السعر 12 ر.ع'], $sent[0]['message']);
        $this->assertSame('Bearer LIVE-TOKEN-'.$this->shop->id, $sent[0]['token']);
        $this->assertSame('C-1', $sent[1]['public'], 'العلنيُّ بعد الخاصّ');
        $this->assertContains($sent[1]['text'], ['أرسلنا لك على الخاص @sara', 'تفقّد رسائلك'], 'الاسمُ وحده في العلن — ولا سعر');

        $run = $this->last();
        $this->assertSame([$a->id, 'instagram', 'ig:c:C-1', 'IGSID-9', ChannelAutomationRun::SENT, null, 'MID-OUT-1'],
            [(int) $run->automation_id, $run->channel, $run->source_key, $run->recipient, $run->status, $run->reason, $run->message_id]);
    }

    public function test_a_failed_private_reply_writes_nothing_in_public_and_a_failed_public_one_does_not_undo_the_private(): void
    {
        $this->open();
        $this->automation(['public_replies' => ['أرسلنا لك على الخاص'], 'cooldown_minutes' => 1]);

        $this->onSend = fn () => Http::response(['error' => ['message' => 'Cannot message', 'code' => 10]], 400);
        $this->comment('C-1', 'بكم؟');
        $this->assertCount(1, $this->sent());
        $this->assertSame([ChannelAutomationRun::FAILED, 'meta_10'], [$this->last()->status, $this->last()->reason]);

        $this->onSend = null;
        $this->onReply = fn () => Http::response(['error' => ['message' => 'Hidden', 'code' => 100]], 400);
        $this->comment('C-2', 'بكم؟', 'IGSID-10', 'mona');
        $this->assertSame([ChannelAutomationRun::SENT, 'public_meta_100'], [$this->last()->status, $this->last()->reason]);
    }

    public function test_the_accounts_own_comments_are_never_answered(): void
    {
        $this->open();
        $this->automation();

        $this->comment('C-1', 'السعر بكم', $this->link->ig_user_id, 'x');
        $this->comment('C-2', 'السعر بكم', 'APP-17841400000000001', 'x');
        $this->comment('C-3', 'السعر بكم', 'IGSID-OTHER', 'ROSE.shop');

        $this->assertSame([], $this->sent(), 'وإلّا ردّت أتمتتُنا على ردّها العلنيّ بلا نهاية');
        $this->assertSame(0, ChannelAutomationRun::count());
    }

    public function test_it_answers_the_question_and_not_letters_inside_another_word(): void
    {
        $this->open();
        $this->automation(['cooldown_minutes' => 1]);

        foreach (['نحبكم 😍', 'السلام عليكم', 'ما شاء الله', '🔥🔥'] as $i => $text) {
            $this->comment('N-'.$i, $text, 'IGSID-N'.$i);
        }
        $this->assertSame([], $this->sent());

        foreach (['بكم؟', 'وبكم الباقة', 'كم بالسعر', 'السّعر', 'سعره كم'] as $i => $text) {
            $this->comment('Y-'.$i, $text, 'IGSID-Y'.$i);
        }
        $this->assertCount(5, $this->sent());

        $this->assertNull(KeywordMatch::first('blink twice', ['link']));
        $this->assertSame('link', KeywordMatch::first('the links please', ['link']));
        $this->assertSame('التوصيل', KeywordMatch::first('وش سالفة عالتوصيل', ['التوصيل']));
    }

    public function test_chosen_posts_win_and_any_comment_answers_only_under_them(): void
    {
        $this->open();
        $this->automation(['name' => 'كل المنشورات', 'reply_ar' => 'العام', 'cooldown_minutes' => 1]);
        $this->automation(['name' => 'منشور', 'match' => KeywordMatch::ANY, 'keywords' => [], 'reply_ar' => 'المنشور', 'posts' => [['id' => '17900000000000002', 'caption' => '']], 'excluded' => ['إلغاء'], 'cooldown_minutes' => 1]);

        $this->comment('C-1', 'بكم؟', 'IGSID-1');
        $this->comment('C-2', '🔥', 'IGSID-2');
        $this->comment('C-3', '🔥', 'IGSID-3', 'a', ['id' => '17900000000000002']);
        $this->comment('C-4', 'بكم؟', 'IGSID-4', 'b', ['id' => '17900000000000002']);
        $this->comment('C-5', 'حلو', 'IGSID-5', 'c', ['id' => '17999999999999999', 'original_media_id' => '17900000000000002']);
        $this->comment('C-6', 'ابي الغاء', 'IGSID-6', 'd', ['id' => '17900000000000002']);

        $this->assertSame(['العام', 'المنشور', 'المنشور', 'المنشور'], array_map(fn ($s) => $s['message']['text'], $this->sent()), 'والإعلانُ بمنشوره الأصليّ');
    }

    public function test_meta_may_send_the_comment_flat_in_the_entry(): void
    {
        $this->open();
        $this->automation();

        $this->webhook(['field' => 'comments', 'value' => ['id' => 'C-1', 'text' => 'بكم', 'from' => ['id' => 'IGSID-9', 'username' => 'sara'], 'media' => ['id' => '1']]]);

        $this->assertSame(['comment_id' => 'C-1'], $this->sent()[0]['to']);
    }

    /* ═══════════ الرسالة ═══════════ */

    public function test_a_message_is_answered_with_its_buttons_and_a_tap_reaches_another_automation(): void
    {
        $this->open();
        $this->automation(['trigger' => InstagramAutomations::MESSAGE, 'keywords' => ['التوصيل'], 'reply_ar' => 'نوصّل كل يوم', 'buttons' => [['type' => 'reply', 'title' => 'السعر']]]);
        $this->automation(['trigger' => InstagramAutomations::MESSAGE, 'keywords' => ['السعر'], 'reply_ar' => 'من 12 ر.ع', 'reply_en' => 'From 12 OMR', 'buttons' => [['type' => 'link', 'title' => 'افتح', 'url' => 'https://shop.test/x']]]);

        $this->dm('M-1', 'عندكم توصيل؟');
        $this->dm('M-2', 'السعر', message: ['quick_reply' => ['payload' => 'auto:1:0']]);
        $this->webhook(['id' => 'IGSID-10', 'messaging' => [['sender' => ['id' => 'IGSID-10'], 'recipient' => ['id' => $this->link->ig_user_id], 'timestamp' => time(), 'postback' => ['mid' => 'P-1', 'title' => 'السعر', 'payload' => 'x']]]]);

        $sent = $this->sent();
        $this->assertSame(['id' => 'IGSID-9'], $sent[0]['to']);
        $this->assertSame('نوصّل كل يوم', $sent[0]['message']['text']);
        $this->assertSame(['text', 'السعر'], [$sent[0]['message']['quick_replies'][0]['content_type'], $sent[0]['message']['quick_replies'][0]['title']]);
        $this->assertSame(['template_type' => 'button', 'text' => 'من 12 ر.ع', 'buttons' => [['type' => 'web_url', 'url' => 'https://shop.test/x', 'title' => 'افتح']]], $sent[1]['message']['attachment']['payload']);
        $this->assertSame(['id' => 'IGSID-10'], $sent[2]['to'], 'ضغطةُ زرٍّ بعنوانه — ومدخلُها باسم من ضغط كما في الوثيقة');
        $this->assertCount(3, $sent);
    }

    public function test_the_reply_follows_the_language_the_customer_wrote(): void
    {
        $this->open();
        $this->automation(['trigger' => InstagramAutomations::MESSAGE, 'keywords' => ['price', 'السعر'], 'reply_ar' => 'من 12', 'reply_en' => 'From 12']);

        $this->dm('M-1', 'price?', 'IGSID-1');
        $this->dm('M-2', 'السعر', 'IGSID-2');

        $this->assertSame(['From 12', 'من 12'], array_map(fn ($s) => $s['message']['text'], $this->sent()));
    }

    public function test_what_is_not_a_customers_message_is_left_alone(): void
    {
        $this->open();
        $this->automation(['trigger' => InstagramAutomations::MESSAGE, 'keywords' => ['السعر']]);
        $this->automation(['trigger' => InstagramAutomations::COMMENT, 'keywords' => ['الدوام']]);

        $this->dm('M-1', 'السعر', message: ['is_self' => true]);
        $this->dm('M-2', 'السعر', message: ['is_deleted' => true]);
        $this->dm('M-3', 'السعر', $this->link->ig_user_id);
        $this->echo('M-4');
        $this->dm('M-5', 'الدوام');
        $this->webhook(['messaging' => [['sender' => ['id' => 'IGSID-9'], 'recipient' => ['id' => $this->link->ig_user_id], 'message' => ['mid' => 'M-6', 'attachments' => [['type' => 'image']]]]]]);

        $this->assertSame([], $this->sent(), 'وأتمتةُ التعليق لا تردّ على رسالة');
        $this->assertSame(0, InstagramEvent::whereNull('processed_at')->count());
    }

    /* ═══════════ لا تُقطع محادثةُ التاجر ═══════════ */

    public function test_a_merchant_talking_from_the_instagram_app_is_not_interrupted(): void
    {
        $this->open();
        $this->automation(['trigger' => InstagramAutomations::MESSAGE, 'keywords' => ['السعر']]);
        $this->automation(['trigger' => InstagramAutomations::MESSAGE, 'keywords' => ['التوصيل'], 'reply_ar' => 'نوصّل']);

        // ‏‏ردُّنا يرتدّ إلينا بمعرّفه — وليس يدَ التاجر
        $this->dm('M-1', 'السعر');
        $this->echo('MID-OUT-1');
        $this->dm('M-2', 'التوصيل');
        $this->assertCount(2, $this->sent());

        // ‏‏ثمّ كتب التاجرُ بيده — فتصمت ساعةً عنه وحده
        $this->travel(5)->minutes();
        $this->echo('HUMAN-1');
        $this->travel(61)->minutes();
        $this->echo('HUMAN-2');
        $this->dm('M-3', 'السعر');
        $this->assertSame([ChannelAutomationRun::SKIPPED, ChannelAutomations::SKIP_STAFF], [$this->last()->status, $this->last()->reason]);
        $this->dm('M-4', 'السعر', 'IGSID-OTHER');
        $this->assertCount(3, $this->sent());

        $this->travel(61)->minutes();
        $this->dm('M-5', 'السعر');
        $this->assertCount(4, $this->sent());
    }

    public function test_the_same_automation_waits_before_it_answers_the_same_person_again(): void
    {
        $this->open();
        $this->automation(['cooldown_minutes' => 60]);

        $this->comment('C-1', 'بكم؟');
        $this->comment('C-2', 'السعر؟');
        $this->comment('C-3', 'بكم؟', 'IGSID-10', 'mona');
        $this->assertCount(2, $this->sent());
        $this->assertSame(1, ChannelAutomationRun::where('reason', ChannelAutomations::SKIP_COOLDOWN)->count());

        $this->travel(61)->minutes();
        $this->comment('C-4', 'بكم؟');
        $this->assertCount(3, $this->sent());
    }

    /* ═══════════ الطابورُ وحدودُ Meta ═══════════ */

    public function test_meta_is_answered_at_once_and_the_reply_leaves_from_the_queue(): void
    {
        Queue::fake();
        $this->open();
        $this->automation();

        $this->comment('C-1', 'بكم؟');
        $this->echo('H-1', 'IGSID-OTHER');

        Queue::assertPushedOn('default', AnswerInstagramEvent::class, fn ($job) => $job->eventId === InstagramEvent::where('field', 'comments')->value('id'));
        Queue::assertPushed(AnswerInstagramEvent::class, 1);
        $this->assertSame([], $this->sent());
        $this->assertNull(InstagramEvent::where('field', 'comments')->value('processed_at'));

        // ‏‏عاملان للحدث نفسِه — يأخذه واحد
        $event = InstagramEvent::where('field', 'comments')->firstOrFail();
        $this->assertSame(InstagramAutomations::SENT, InstagramAutomations::answer($event));
        $this->assertSame(InstagramAutomations::TAKEN, InstagramAutomations::answer($event));
        $this->assertCount(1, $this->sent());
    }

    public function test_what_a_stopped_worker_left_is_swept_within_a_day_and_not_after(): void
    {
        Queue::fake();
        $this->open();
        $this->automation();

        $this->comment('C-OLD', 'بكم؟', 'IGSID-1');
        InstagramEvent::query()->update(['received_at' => now()->subHours(25)]);
        $this->comment('C-WAIT', 'بكم؟', 'IGSID-2');
        InstagramEvent::where('event_key', 'c:comments:C-WAIT')->update(['received_at' => now()->subMinutes(10)]);
        $this->comment('C-NOW', 'بكم؟', 'IGSID-3');

        Artisan::call('instagram:answer-pending');

        $this->assertSame([['comment_id' => 'C-WAIT']], array_column($this->sent(), 'to'), 'ما وصل الآن لمهمّته، وما مضى عليه يومٌ يُطوى');
        $this->assertSame(['c:comments:C-NOW'], InstagramEvent::whereNull('processed_at')->pluck('event_key')->all());
    }

    public function test_a_full_hour_defers_and_does_not_lose_the_comment(): void
    {
        $this->open();
        $a = $this->automation();
        ChannelAutomationRun::insert(array_map(fn ($i) => [
            'business_id' => $this->shop->id, 'automation_id' => $a->id, 'channel' => 'instagram', 'source_key' => 'ig:c:X'.$i,
            'recipient' => 'P'.$i, 'status' => 'sent', 'created_at' => now()->subMinutes(5),
        ], range(1, InstagramAutomations::PRIVATE_REPLIES_PER_HOUR)));

        $this->comment('C-1', 'بكم؟');
        $this->assertSame([], $this->sent());
        $this->assertSame([null, 0], [InstagramEvent::firstOrFail()->processed_at, (int) InstagramEvent::firstOrFail()->attempts]);

        // ‏‏والرسائلُ لا تقف خلف حدّ التعليقات
        $this->automation(['trigger' => InstagramAutomations::MESSAGE, 'keywords' => ['الدوام']]);
        $this->dm('M-1', 'الدوام', 'IGSID-7');
        $this->assertCount(1, $this->sent());

        $this->travel(61)->minutes();
        Artisan::call('instagram:answer-pending');
        $this->assertSame(['comment_id' => 'C-1'], $this->sent()[1]['to']);
    }

    public function test_a_passing_limit_is_tried_again_and_a_lost_network_is_not(): void
    {
        $this->open();
        $this->automation(['trigger' => InstagramAutomations::MESSAGE, 'keywords' => ['السعر'], 'cooldown_minutes' => 1]);

        $this->onSend = fn () => Http::response(['error' => ['message' => 'limit', 'code' => 613]], 400);
        $this->dm('M-1', 'السعر');
        $this->assertSame(0, ChannelAutomationRun::count(), 'يُرفع الحجزُ فلا تمنعه مدّةُ الانتظار');
        $this->assertSame(1, (int) InstagramEvent::firstOrFail()->attempts);

        $this->onSend = null;
        $this->travel(5)->minutes();
        Artisan::call('instagram:answer-pending');
        $this->assertSame(ChannelAutomationRun::SENT, $this->last()->status);

        // ‏‏والخامسةُ آخرُها
        $this->onSend = fn () => Http::response(['error' => ['message' => 'limit', 'code' => 613]], 400);
        $this->dm('M-2', 'السعر', 'IGSID-2');
        for ($i = 0; $i < 6; $i++) {
            $this->travel(5)->minutes();
            Artisan::call('instagram:answer-pending');
        }
        $this->assertSame([ChannelAutomationRun::FAILED, 'meta_613'], [$this->last()->status, $this->last()->reason]);
        $this->assertSame(0, InstagramEvent::whereNull('processed_at')->count());

        // ‏‏وانقطاعُ الشبكة: قد تكون خرجت — فلا تُرسل ثانية
        $this->onSend = fn () => throw new ConnectionException('cut');
        $this->dm('M-3', 'السعر', 'IGSID-3');
        $this->assertSame([ChannelAutomationRun::FAILED, 'network_error'], [$this->last()->status, $this->last()->reason]);
        $this->assertSame(0, InstagramEvent::whereNull('processed_at')->count());
    }

    public function test_a_dead_token_asks_to_relink_and_stops(): void
    {
        $this->open();
        $this->automation(['cooldown_minutes' => 1]);
        $this->onSend = fn () => Http::response(['error' => ['message' => 'Error validating access token', 'code' => 190, 'type' => 'OAuthException']], 400);

        $this->comment('C-1', 'بكم؟');
        $this->assertSame(InstagramConnection::NEEDS_RELINK, $this->link->fresh()->status);
        $this->comment('C-2', 'بكم؟', 'IGSID-2');
        $this->assertCount(1, $this->sent());
    }

    public function test_the_text_is_cut_by_bytes_the_way_instagram_counts(): void
    {
        $cut = InstagramMessenger::cut(str_repeat('ورد ', 400));

        $this->assertLessThanOrEqual(1000, strlen($cut));
        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'));
        $this->assertGreaterThan(900, strlen($cut));
    }

    /* ═══════════ ما يكتبه التاجر ═══════════ */

    /** @return array<string, mixed> */
    private function form(array $over = []): array
    {
        return $over + [
            'name' => 'تعليق بالسعر', 'trigger' => 'comment', 'match' => 'contains', 'keywords' => ['بكم'], 'reply_ar' => 'السعر 12',
            'cooldown_minutes' => 60, 'status' => 'published', 'posts' => [['id' => '17900000000000001', 'caption' => 'باقة الجوري']],
            'public_replies' => ['أرسلنا لك على الخاص', '', 'أرسلنا لك على الخاص'], 'buttons' => [['type' => 'link', 'title' => 'افتح', 'url' => 'https://x.test']],
        ];
    }

    public function test_the_owner_writes_it_publishes_it_and_the_screen_reads_it_back(): void
    {
        $this->open();

        $this->actingAs($this->owner)->post(route('admin.integrations.instagram.automations.store'), $this->form())->assertSessionHasNoErrors();

        $a = ChannelAutomation::firstOrFail();
        $this->assertSame(['instagram', 'comment', 'published'], [$a->channel, $a->trigger, $a->status]);
        $this->assertSame([['id' => '17900000000000001', 'caption' => 'باقة الجوري']], $a->posts);
        $this->assertSame(['أرسلنا لك على الخاص'], $a->public_replies);
        $this->assertNull($a->buttons, 'الردُّ الخاصّ على التعليق نصٌّ لا غير');
        $this->assertSame(1, ActivityLog::where('description', 'like', '%إنستقرام — أتمتة «تعليق بالسعر»: نُشرت%')->count());

        // ‏‏ورسالةٌ: لها أزرار، ولا منشورات ولا ردود علنيّة
        $this->put(route('admin.integrations.instagram.automations.update', $a->id), $this->form(['trigger' => 'message']))->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertSame(['message', null, null, 'link'], [$a->trigger, $a->posts, $a->public_replies, $a->buttons[0]['type']]);

        $this->post(route('admin.integrations.instagram.automations.status', $a->id), ['status' => 'paused'])->assertSessionHasNoErrors();
        $this->assertSame('paused', $a->fresh()->status);

        // ‏‏وصفحةُ واتساب لا تراها، ولا تُعدّ فيها، ولا يعدّلها بابُها
        $this->get(route('admin.integrations.whatsapp.automations'))->assertInertia(fn ($p) => $p->where('view.automations', []));
        $this->put(route('admin.integrations.whatsapp.automations.update', $a->id), ['name' => 'x'])->assertNotFound();

        $this->delete(route('admin.integrations.instagram.automations.destroy', $a->id))->assertSessionHasNoErrors();
        $this->assertSame(0, ChannelAutomation::count());
    }

    public function test_what_instagram_cannot_do_is_refused_before_it_is_published(): void
    {
        $this->open();
        $store = fn (array $over) => $this->actingAs($this->owner)->post(route('admin.integrations.instagram.automations.store'), $this->form($over));

        $store(['match' => 'any', 'keywords' => [], 'posts' => []])->assertSessionHasErrors('status');
        $store(['keywords' => []])->assertSessionHasErrors('status');
        $store(['reply_ar' => str_repeat('س', 501)])->assertSessionHasErrors('status');
        $store(['trigger' => 'message', 'reply_ar' => str_repeat('a', 641)])->assertSessionHasErrors('status');
        $store(['reply_ar' => '{آخر_طلب}'])->assertSessionHasErrors('status');
        $store(['public_replies' => ['تواصل [اكتب رقمك]']])->assertSessionHasErrors('status');
        $store(['posts' => [['id' => 'not-a-post']]])->assertSessionHasErrors('posts.0.id');
        $store(['trigger' => 'message', 'match' => 'any'])->assertSessionHasErrors('match');
        $store(['trigger' => 'keyword'])->assertSessionHasErrors('trigger');
        $this->assertSame(0, ChannelAutomation::count());

        $store(['match' => 'any', 'keywords' => []])->assertSessionHasNoErrors();
        $store(['reply_ar' => str_repeat('س', 500)])->assertSessionHasNoErrors();
        $store(['reply_ar' => '', 'status' => 'draft'])->assertSessionHasNoErrors();
        $this->assertSame(3, ChannelAutomation::count());

        // ‏‏والكاشير لا يبلغها أصلًا
        $cashier = $this->user($this->shop, 'cashier');
        $this->actingAs($cashier)->post(route('admin.integrations.instagram.automations.store'), $this->form())->assertForbidden();
    }

    public function test_every_template_opens_in_the_wizard_and_publishes_once_its_blanks_are_filled(): void
    {
        $this->open();
        $product = Product::create(['business_id' => $this->shop->id, 'name' => 'باقة الجوري', 'price' => 12.5, 'active' => true]);

        foreach (ChannelAutomationTemplates::instagram() as $tpl) {
            $fill = fn (string $t) => (string) preg_replace(ChannelAutomations::PLACEHOLDER, 'مسقط', $t);

            $this->actingAs($this->owner)->post(route('admin.integrations.instagram.automations.store'), [
                'name' => $tpl['name'], 'template' => $tpl['key'], 'trigger' => $tpl['trigger'], 'match' => $tpl['match'], 'keywords' => $tpl['keywords'],
                'reply_ar' => $fill($tpl['reply_ar']), 'reply_en' => $fill($tpl['reply_en']), 'buttons' => $tpl['buttons'], 'public_replies' => $tpl['public_replies'],
                'posts' => $tpl['match'] === 'any' ? [['id' => '17900000000000001']] : [], 'product_id' => $tpl['needs_product'] ? $product->id : null,
                'cooldown_minutes' => 60, 'status' => 'published',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(count(ChannelAutomationTemplates::instagram()), ChannelAutomation::where('status', 'published')->count());

        $this->comment('C-1', 'بكم؟', media: ['id' => '17900000000000009']);
        $this->assertStringContainsString('أهلًا sara! باقة الجوري بسعر 12.500', $this->sent()[0]['message']['text']);
    }

    public function test_the_post_picker_reads_the_accounts_posts_for_the_owner_alone(): void
    {
        $this->open();

        $res = $this->actingAs($this->owner)->get(route('admin.integrations.instagram.automations.posts'))->assertOk();
        $this->assertSame(['17900000000000001', '17900000000000002'], array_column($res->json('posts'), 'id'));
        $this->assertSame(['https://cdn.test/1.jpg', 'https://cdn.test/2.jpg'], array_column($res->json('posts'), 'image'), 'والريلز بغلافه لا برابط الفيديو');
        $this->assertStringNotContainsString('LIVE-TOKEN', $res->getContent());

        $this->get(route('admin.integrations.instagram.automations.posts'))->assertOk();
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/media')), 'دقيقتان بلا نداءٍ ثانٍ');

        $this->link->forceFill(['status' => InstagramConnection::NEEDS_RELINK])->save();
        $this->get(route('admin.integrations.instagram.automations.posts'))->assertJson(['ok' => false]);
        $this->link->delete();
        $this->get(route('admin.integrations.instagram.automations.posts'))->assertJson(['ok' => false]);
    }

    /* ═══════════ متجران ═══════════ */

    public function test_one_shops_automation_never_answers_from_another_shops_account(): void
    {
        $this->open();
        $other = $this->shop('محل آخر');
        $theirs = $this->linked($other, '17841400000000002', 'other.shop');
        $this->automation(['reply_ar' => 'ردُّ المتجر الآخر'], $other);

        $this->comment('C-1', 'بكم؟');
        $this->assertSame([], $this->sent());

        $this->webhook(['id' => $theirs->ig_user_id, 'changes' => [['field' => 'comments', 'value' => ['id' => 'C-2', 'text' => 'بكم', 'from' => ['id' => 'IGSID-9', 'username' => 'sara'], 'media' => ['id' => '1']]]]]);
        $this->assertSame(['Bearer LIVE-TOKEN-'.$other->id], array_column($this->sent(), 'token'));
    }

    public function test_deleting_the_accounts_data_removes_who_wrote_to_it_and_keeps_what_the_merchant_wrote(): void
    {
        $this->open();
        $this->automation();
        $this->comment('C-1', 'بكم؟');
        $this->assertSame(1, ChannelAutomationRun::count());

        InstagramWebhooks::delete($this->link->ig_user_id);

        $this->assertSame([0, 0, 1], [ChannelAutomationRun::count(), InstagramEvent::count(), ChannelAutomation::count()]);
    }
}
