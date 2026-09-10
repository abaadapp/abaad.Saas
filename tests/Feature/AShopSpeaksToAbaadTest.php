<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\SupportAttachment;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Support\Support;
use App\Support\TenantTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * مركزُ المحادثات — ما يُقال، ولمن، وما لا يُقال أبدًا.
 *
 * وأثقلُ ما هنا حارسٌ واحد: **الملاحظةُ الداخليّة لا تبلغ صاحبَ المتجر**.
 * ما دونه عطبٌ يُصلَح، وهو فضيحةٌ لا تُستدرك — كلامُ الفريق عن عميلٍ يصل
 * العميلَ نفسَه.
 */
class AShopSpeaksToAbaadTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->shop('محل الورد');
        $this->owner = $this->person($this->shop->id, 'admin', 'صاحب المحل');
        $this->staff = $this->person(null, 'super_admin', 'دعم أبعاد');
    }

    /** متجرٌ بأقلّ ما يقوم به صفُّه */
    private function shop(string $name): Business
    {
        return Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط',
            'city' => 'مسقط', 'phone' => '96871141624',
        ]);
    }

    /** ومستخدمٌ ببريدٍ لا يتكرّر — الفهرسُ عليه فريد */
    private function person(?int $businessId, string $role, string $name = 'مستخدم'): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'business_id' => $businessId, 'name' => $name, 'email' => "u{$n}@abaad.om",
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
        ]);
    }

    /** محادثةٌ يفتحها التاجر — والنصُّ كما كتبه */
    private function conversation(?User $by = null): SupportConversation
    {
        return Support::open($by ?? $this->owner, 'الفاتورة لا تُطبع', 'مشكلة تقنية', 'أضغط طباعة فلا يحدث شيء.');
    }

    /* ═══════════════ التاجر يفتح ويكتب ═══════════════ */

    public function test_a_shop_owner_opens_a_conversation(): void
    {
        $this->actingAs($this->owner)->post(route('admin.help.store'), [
            'subject' => 'الفاتورة لا تُطبع',
            'category' => 'مشكلة تقنية',
            'body' => 'أضغط طباعة فلا يحدث شيء.',
        ])->assertRedirect();

        $c = SupportConversation::firstOrFail();

        $this->assertSame($this->shop->id, $c->business_id);
        $this->assertSame($this->owner->id, $c->opened_by);
        $this->assertSame('new', $c->status);
        $this->assertSame('in_app', $c->channel);
        $this->assertSame('أضغط طباعة فلا يحدث شيء.', $c->messages()->first()->body);
    }

    /**
     * ولا يُسأل عن متجره — ولا يُقبل ما يقوله عنه.
     *
     * حقلٌ يُرسَل من المتصفّح يعني أنّ من يبدّل رقمًا يفتح محادثةً باسم جاره.
     */
    public function test_a_shop_cannot_open_a_conversation_in_another_shops_name(): void
    {
        $other = $this->shop('متجر آخر');

        $this->actingAs($this->owner)->post(route('admin.help.store'), [
            'business_id' => $other->id,
            'opened_by' => 9999,
            'subject' => 'محاولة',
            'category' => 'أخرى',
            'body' => 'نص',
        ])->assertRedirect();

        $c = SupportConversation::firstOrFail();

        $this->assertSame($this->shop->id, $c->business_id, 'المتجر يُقرأ من الجلسة لا من الطلب');
        $this->assertSame($this->owner->id, $c->opened_by);
    }

    public function test_a_subject_and_a_body_are_required(): void
    {
        $this->actingAs($this->owner)
            ->from(route('admin.help.index'))
            ->post(route('admin.help.store'), ['category' => 'أخرى'])
            ->assertSessionHasErrors(['subject', 'body']);

        $this->assertSame(0, SupportConversation::count());
    }

    public function test_an_invented_category_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->from(route('admin.help.index'))
            ->post(route('admin.help.store'), [
                'subject' => 'س', 'category' => 'تصنيف مخترع', 'body' => 'ن',
            ])
            ->assertSessionHasErrors('category');
    }

    /* ═══════════════ العزلُ بين المتاجر ═══════════════ */

    public function test_a_shop_cannot_read_another_shops_conversation(): void
    {
        $mine = $this->conversation();

        $stranger = $this->person($this->shop('متجر آخر')->id, 'admin');

        $this->actingAs($stranger)->get(route('admin.help.show', $mine->id))->assertNotFound();
    }

    public function test_a_shop_cannot_reply_into_another_shops_conversation(): void
    {
        $mine = $this->conversation();

        $stranger = $this->person($this->shop('متجر آخر')->id, 'admin');

        $this->actingAs($stranger)
            ->post(route('admin.help.reply', $mine->id), ['body' => 'أقحم نفسي'])
            ->assertNotFound();

        $this->assertSame(1, $mine->messages()->count());
    }

    public function test_a_shop_sees_only_its_own_conversations_in_the_list(): void
    {
        $this->conversation();

        $otherShop = $this->shop('متجر آخر');
        $otherOwner = $this->person($otherShop->id, 'admin');
        Support::open($otherOwner, 'شأن آخر', 'أخرى', 'نص الجار');

        $page = $this->actingAs($this->owner)->get(route('admin.help.index'));

        $rows = $page->viewData('page')['props']['conversations']['data'];

        $this->assertCount(1, $rows);
        $this->assertSame('الفاتورة لا تُطبع', $rows[0]['subject']);
    }

    /* ═══════════════ لوحة المنصّة ═══════════════ */

    public function test_the_platform_opens_the_conversation_center(): void
    {
        $this->conversation();

        $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index'))
            ->assertOk();
    }

    public function test_a_shop_user_may_not_open_the_conversation_center(): void
    {
        $this->actingAs($this->owner)
            ->get(route('super-admin.conversations.index'))
            ->assertForbidden();
    }

    public function test_the_platform_replies_and_the_shop_reads_it(): void
    {
        $c = $this->conversation();

        $this->actingAs($this->staff)
            ->post(route('super-admin.conversations.reply', $c->id), ['body' => 'جرّب تحديث الصفحة'])
            ->assertRedirect();

        $page = $this->actingAs($this->owner)->get(route('admin.help.show', $c->id));
        $bodies = collect($page->viewData('page')['props']['messages'])->pluck('body');

        $this->assertContains('جرّب تحديث الصفحة', $bodies);
    }

    /* ═══════════════ الملاحظةُ الداخليّة ═══════════════ */

    /**
     * وهذا أثقلُ حارسٍ في الملفّ.
     *
     * ملاحظةٌ يكتبها موظّفُ دعمٍ لزميله عن تاجرٍ — إن وصلت التاجرَ فلا
     * إصلاحَ بعدها. فتُفحص في الشاشة وفي بابِ المرفق وفي الإشعار.
     */
    public function test_an_internal_note_never_reaches_the_shop(): void
    {
        $c = $this->conversation();

        $this->actingAs($this->staff)->post(route('super-admin.conversations.reply', $c->id), [
            'body' => 'هذا التاجر يتأخر في السداد — لا تَعِده بشيء',
            'internal' => true,
        ])->assertRedirect();

        $page = $this->actingAs($this->owner)->get(route('admin.help.show', $c->id));
        $bodies = collect($page->viewData('page')['props']['messages'])->pluck('body');

        $this->assertNotContains('هذا التاجر يتأخر في السداد — لا تَعِده بشيء', $bodies);

        /* ولا في نصّ الاستجابة الخام — لا في prop ولا في HTML */
        $page->assertDontSee('يتأخر في السداد', false);
    }

    public function test_the_platform_does_see_its_own_internal_note(): void
    {
        $c = $this->conversation();

        $this->actingAs($this->staff)->post(route('super-admin.conversations.reply', $c->id), [
            'body' => 'ملاحظة للفريق', 'internal' => true,
        ]);

        $page = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index', ['conversation' => $c->id]));

        $bodies = collect($page->viewData('page')['props']['messages'])->pluck('body');

        $this->assertContains('ملاحظة للفريق', $bodies);
    }

    public function test_an_internal_note_does_not_move_the_conversation_to_the_customer(): void
    {
        $c = $this->conversation();

        $this->actingAs($this->staff)->post(route('super-admin.conversations.reply', $c->id), [
            'body' => 'ملاحظة', 'internal' => true,
        ]);

        $this->assertSame('new', $c->fresh()->status, 'ملاحظةُ الفريق ليست ردًّا فلا تنقل الكرة');
    }

    public function test_an_internal_note_does_not_notify_the_shop(): void
    {
        $c = $this->conversation();

        $this->actingAs($this->staff)->post(route('super-admin.conversations.reply', $c->id), [
            'body' => 'ملاحظة', 'internal' => true,
        ]);

        $this->assertSame(0, Support::businessBadge($this->owner->fresh()));
    }

    /* ═══════════════ التعيين ═══════════════ */

    public function test_the_platform_assigns_a_conversation_to_itself(): void
    {
        $c = $this->conversation();

        $this->actingAs($this->staff)
            ->post(route('super-admin.conversations.assign', $c->id), ['user_id' => $this->staff->id])
            ->assertRedirect();

        $this->assertSame($this->staff->id, $c->fresh()->assigned_to);
        $this->assertSame('open', $c->fresh()->status, 'التعيين يُخرجها من «جديدة»');
    }

    /**
     * ولا يُعيَّن موظّفُ متجرٍ إلى محادثةِ دعم.
     *
     * يفتح البابَ فيقرأ محادثاتِ المتاجر كلِّها.
     */
    public function test_a_shop_user_cannot_be_made_the_assignee(): void
    {
        $c = $this->conversation();

        $this->actingAs($this->staff)
            ->from(route('super-admin.conversations.index'))
            ->post(route('super-admin.conversations.assign', $c->id), ['user_id' => $this->owner->id])
            ->assertSessionHasErrors('user_id');

        $this->assertNull($c->fresh()->assigned_to);
    }

    /* ═══════════════ الحالات ═══════════════ */

    public function test_a_platform_reply_puts_the_ball_in_the_shops_court(): void
    {
        $c = $this->conversation();

        $this->actingAs($this->staff)
            ->post(route('super-admin.conversations.reply', $c->id), ['body' => 'ما رقم الفاتورة؟']);

        $this->assertSame('waiting_customer', $c->fresh()->status);
    }

    public function test_a_shop_reply_brings_it_back_to_abaad(): void
    {
        $c = $this->conversation();
        $c->forceFill(['status' => 'waiting_customer'])->save();

        $this->actingAs($this->owner)
            ->post(route('admin.help.reply', $c->id), ['body' => 'رقمها ١٢٣'])
            ->assertRedirect();

        $this->assertSame('waiting_abaad', $c->fresh()->status);
    }

    public function test_a_resolved_conversation_reopens_when_the_shop_writes_again(): void
    {
        $c = $this->conversation();

        $this->actingAs($this->staff)
            ->post(route('super-admin.conversations.status', $c->id), ['status' => 'resolved']);

        $this->assertSame('resolved', $c->fresh()->status);
        $this->assertNotNull($c->fresh()->resolved_at);

        $this->actingAs($this->owner)->post(route('admin.help.reply', $c->id), ['body' => 'عاد العطب']);

        $c->refresh();
        $this->assertSame('waiting_abaad', $c->status);
        $this->assertNull($c->resolved_at, 'وتاريخُ الحلّ يُمحى — محادثةٌ عادت ليست محلولة');
    }

    public function test_a_closed_conversation_reopens_when_the_shop_writes_again(): void
    {
        $c = $this->conversation();

        $this->actingAs($this->staff)
            ->post(route('super-admin.conversations.status', $c->id), ['status' => 'closed']);

        $this->actingAs($this->owner)->post(route('admin.help.reply', $c->id), ['body' => 'ما زال قائمًا']);

        $c->refresh();
        $this->assertSame('waiting_abaad', $c->status);
        $this->assertNull($c->closed_at);
    }

    /** وتاريخُ الحالات يبقى — من يقرأ المحادثة يرى كيف سارت */
    public function test_the_status_history_is_kept_in_the_thread(): void
    {
        $c = $this->conversation();

        foreach (['open', 'waiting_customer', 'resolved'] as $status) {
            $this->actingAs($this->staff)
                ->post(route('super-admin.conversations.status', $c->id), ['status' => $status]);
        }

        $events = $c->messages()->whereNotNull('event')->pluck('event_meta');

        $this->assertGreaterThanOrEqual(3, $events->count());
        $this->assertSame('new', $events->first()['from']);
    }

    public function test_an_invented_status_is_refused(): void
    {
        $c = $this->conversation();

        $this->actingAs($this->staff)
            ->from(route('super-admin.conversations.index'))
            ->post(route('super-admin.conversations.status', $c->id), ['status' => 'مخترعة'])
            ->assertSessionHasErrors('status');

        $this->assertSame('new', $c->fresh()->status);
    }

    /* ═══════════════ الأولويّة ═══════════════ */

    public function test_priority_is_platform_business_and_never_reaches_the_shop(): void
    {
        $c = $this->conversation();

        $this->actingAs($this->staff)
            ->post(route('super-admin.conversations.priority', $c->id), ['priority' => 'urgent']);

        $this->assertSame('urgent', $c->fresh()->priority);

        $props = $this->actingAs($this->owner)
            ->get(route('admin.help.show', $c->id))
            ->viewData('page')['props'];

        $this->assertArrayNotHasKey('priority', $props['conversation']);
        $this->assertSame(0, collect($props['messages'])->where('event', 'priority')->count());
    }

    /* ═══════════════ القراءةُ والشارات ═══════════════ */

    public function test_the_platform_badge_counts_what_it_has_not_read(): void
    {
        $this->conversation();

        $this->assertSame(1, Support::platformBadge($this->staff));

        $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index', ['conversation' => SupportConversation::first()->id]));

        $this->assertSame(0, Support::platformBadge($this->staff->fresh()), 'وفتحُها يُطفئها');
    }

    /**
     * وقراءةُ زميلٍ لا تُطفئ شارةَ زميله.
     *
     * عمودٌ واحد على المحادثة كان يعني أنّ فتحَ أحدهم يخفيها عن الجميع.
     */
    public function test_one_staff_reading_does_not_clear_another_staffs_badge(): void
    {
        $second = $this->person(null, 'super_admin');
        $c = $this->conversation();

        $this->actingAs($this->staff)->get(route('super-admin.conversations.index', ['conversation' => $c->id]));

        $this->assertSame(0, Support::platformBadge($this->staff->fresh()));
        $this->assertSame(1, Support::platformBadge($second->fresh()));
    }

    public function test_the_shop_badge_counts_only_replies_it_has_not_read(): void
    {
        $c = $this->conversation();

        $this->assertSame(0, Support::businessBadge($this->owner), 'ورسالتُه هو ليست ردًّا عليه');

        $this->actingAs($this->staff)
            ->post(route('super-admin.conversations.reply', $c->id), ['body' => 'وصلنا']);

        $this->assertSame(1, Support::businessBadge($this->owner->fresh()));

        $this->actingAs($this->owner)->get(route('admin.help.show', $c->id));

        $this->assertSame(0, Support::businessBadge($this->owner->fresh()));
    }

    /* ═══════════════ المرفقات ═══════════════ */

    public function test_an_attachment_is_stored_privately_and_read_by_its_owner(): void
    {
        Storage::fake('local');

        $this->actingAs($this->owner)->post(route('admin.help.store'), [
            'subject' => 'صورة العطب',
            'category' => 'مشكلة تقنية',
            'body' => 'هذه الشاشة',
            'files' => [UploadedFile::fake()->image('shot.png')],
        ])->assertRedirect();

        $file = SupportAttachment::firstOrFail();
        $c = SupportConversation::firstOrFail();

        $this->assertSame('local', $file->disk);
        $this->assertSame('shot.png', $file->name, 'والاسمُ الأصليّ يبقى معروضًا');
        $this->assertStringNotContainsString('shot.png', $file->path, 'والمخزَّنُ عشوائيٌّ لا يُخمَّن');
        $this->assertStringStartsWith('support/', $file->path);

        Storage::disk('local')->assertExists($file->path);
        /* ولا نسخةَ على القرص العامّ */
        Storage::disk('public')->assertMissing($file->path);

        $this->actingAs($this->owner)
            ->get(route('admin.help.attachment', [$c->id, $file->id]))
            ->assertOk();
    }

    public function test_another_shop_cannot_download_the_attachment(): void
    {
        Storage::fake('local');

        $this->actingAs($this->owner)->post(route('admin.help.store'), [
            'subject' => 'س', 'category' => 'أخرى', 'body' => 'ن',
            'files' => [UploadedFile::fake()->image('secret.png')],
        ]);

        $c = SupportConversation::firstOrFail();
        $file = SupportAttachment::firstOrFail();

        $stranger = $this->person($this->shop('متجر آخر')->id, 'admin');

        $this->actingAs($stranger)
            ->get(route('admin.help.attachment', [$c->id, $file->id]))
            ->assertNotFound();
    }

    /**
     * ومرفقُ محادثةٍ لا يُفتح برقمِ محادثةٍ أخرى.
     *
     * وهو عطبُ IDOR بعينه: يكفي أن يملك المهاجم محادثةً واحدة ثمّ يبدّل
     * الرقمَ الثاني في العنوان.
     */
    public function test_an_attachment_cannot_be_fetched_through_a_different_conversation(): void
    {
        Storage::fake('local');

        $victim = $this->conversation();
        $file = Support::attach($victim->messages()->first(), UploadedFile::fake()->image('theirs.png'));

        $attacker = $this->person($this->shop('متجر آخر')->id, 'admin');
        $mine = Support::open($attacker, 'محادثتي', 'أخرى', 'نص');

        $this->actingAs($attacker)
            ->get(route('admin.help.attachment', [$mine->id, $file->id]))
            ->assertNotFound();
    }

    /** ومرفقُ الملاحظة الداخليّة لا يبلغ التاجر ولو كان في محادثته */
    public function test_the_shop_cannot_download_an_internal_notes_attachment(): void
    {
        Storage::fake('local');

        $c = $this->conversation();
        $note = Support::say($c, $this->staff, 'platform', 'ملاحظة', true);
        $file = Support::attach($note, UploadedFile::fake()->create('internal.pdf', 10, 'application/pdf'));

        $this->actingAs($this->owner)
            ->get(route('admin.help.attachment', [$c->id, $file->id]))
            ->assertNotFound();

        $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.attachment', [$c->id, $file->id]))
            ->assertOk();
    }

    public function test_an_executable_is_refused(): void
    {
        Storage::fake('local');

        $this->actingAs($this->owner)
            ->from(route('admin.help.index'))
            ->post(route('admin.help.store'), [
                'subject' => 'س', 'category' => 'أخرى', 'body' => 'ن',
                'files' => [UploadedFile::fake()->create('payload.php', 4, 'application/x-php')],
            ])
            ->assertSessionHasErrors('files.0');

        $this->assertSame(0, SupportAttachment::count());
    }

    public function test_an_oversized_file_is_refused(): void
    {
        Storage::fake('local');

        $this->actingAs($this->owner)
            ->from(route('admin.help.index'))
            ->post(route('admin.help.store'), [
                'subject' => 'س', 'category' => 'أخرى', 'body' => 'ن',
                'files' => [UploadedFile::fake()->create('huge.pdf', Support::MAX_KB + 1, 'application/pdf')],
            ])
            ->assertSessionHasErrors('files.0');

        $this->assertSame(0, SupportAttachment::count());
    }

    public function test_more_files_than_allowed_are_refused(): void
    {
        Storage::fake('local');

        $files = array_map(
            fn (int $i) => UploadedFile::fake()->image("s{$i}.png"),
            range(1, Support::MAX_FILES + 1),
        );

        $this->actingAs($this->owner)
            ->from(route('admin.help.index'))
            ->post(route('admin.help.store'), [
                'subject' => 'س', 'category' => 'أخرى', 'body' => 'ن', 'files' => $files,
            ])
            ->assertSessionHasErrors('files');
    }

    /* ═══════════════ الرقم ═══════════════ */

    public function test_each_conversation_carries_a_reference_that_never_repeats(): void
    {
        $a = $this->conversation();
        $b = $this->conversation();

        $this->assertMatchesRegularExpression('/^SUP-\d{4}-\d{6}$/', $a->reference);
        $this->assertNotSame($a->reference, $b->reference);
        $this->assertSame(
            (int) substr($a->reference, -6) + 1,
            (int) substr($b->reference, -6),
            'والتسلسلُ يتقدّم واحدًا لا يقفز',
        );
    }

    /** ورقمٌ قُطع لا يتبدّل — ولو تبدّلت الحالةُ والمسؤول */
    public function test_a_reference_is_never_renumbered(): void
    {
        $c = $this->conversation();
        $was = $c->reference;

        $this->actingAs($this->staff)
            ->post(route('super-admin.conversations.status', $c->id), ['status' => 'closed']);
        $this->actingAs($this->staff)
            ->post(route('super-admin.conversations.assign', $c->id), ['user_id' => $this->staff->id]);

        $this->assertSame($was, $c->fresh()->reference);
    }

    /* ═══════════════ البحثُ والصفحات ═══════════════ */

    public function test_the_platform_searches_by_business_subject_and_reference(): void
    {
        $c = $this->conversation();

        $otherShop = $this->shop('مشتل الخليج');
        $otherOwner = $this->person($otherShop->id, 'admin');
        Support::open($otherOwner, 'شأن الاشتراك', 'الحساب والاشتراك', 'متى ينتهي؟');

        foreach ([
            'محل الورد' => 'الفاتورة لا تُطبع',
            'الاشتراك' => 'شأن الاشتراك',
            $c->reference => 'الفاتورة لا تُطبع',
        ] as $term => $expected) {
            $rows = $this->actingAs($this->staff)
                ->get(route('super-admin.conversations.index', ['q' => $term]))
                ->viewData('page')['props']['conversations']['data'];

            $this->assertCount(1, $rows, "البحث عن «{$term}»");
            $this->assertSame($expected, $rows[0]['subject']);
        }
    }

    public function test_the_platform_filters_by_status_and_by_assignment(): void
    {
        $mine = $this->conversation();
        $this->actingAs($this->staff)
            ->post(route('super-admin.conversations.assign', $mine->id), ['user_id' => $this->staff->id]);

        $this->conversation();

        $assigned = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index', ['assignment' => 'mine']))
            ->viewData('page')['props']['conversations']['data'];
        $this->assertCount(1, $assigned);

        $free = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index', ['assignment' => 'unassigned']))
            ->viewData('page')['props']['conversations']['data'];
        $this->assertCount(1, $free);

        $closed = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index', ['status' => 'closed']))
            ->viewData('page')['props']['conversations']['data'];
        $this->assertCount(0, $closed);
    }

    /**
     * والصفحاتُ في الخادم — لا يُسحب الجدولُ كلُّه إلى المتصفّح.
     *
     * منصّةٌ بألف محادثةٍ ترسل ألفًا في كلّ فتحةِ شاشة، ومعها نصوصُها.
     */
    public function test_the_list_is_paginated_on_the_server(): void
    {
        for ($i = 0; $i < 18; $i++) {
            $this->conversation();
        }

        $props = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index'))
            ->viewData('page')['props'];

        $this->assertCount(15, $props['conversations']['data']);
        $this->assertSame(18, $props['conversations']['total']);
    }

    /** والأحدثُ حديثًا في الرأس */
    public function test_the_newest_conversation_leads_the_list(): void
    {
        $old = $this->conversation();
        $old->forceFill(['last_message_at' => now()->subDays(3)])->save();

        $fresh = $this->conversation();

        $rows = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index'))
            ->viewData('page')['props']['conversations']['data'];

        $this->assertSame($fresh->reference, $rows[0]['reference']);
    }

    /* ═══════════════ القنوات ═══════════════ */

    /**
     * ولا تُعرض قناةٌ لا تعمل.
     *
     * مُرشِّحٌ لواتسابَ لم يُوصَل يقول إنّه موصول، ومن يضغطه يجد صفرًا
     * فيظنّ أنّ لا أحد راسله عليه.
     */
    public function test_only_working_channels_are_offered(): void
    {
        $channels = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index'))
            ->viewData('page')['props']['channels'];

        $this->assertSame(['in_app'], array_column($channels, 'value'));
        $this->assertNotContains('whatsapp', array_column($channels, 'value'));
    }

    /* ═══════════════ حدثُ النظام ═══════════════ */

    public function test_a_system_event_does_not_raise_the_conversation_in_the_list(): void
    {
        $c = $this->conversation();
        $was = $c->fresh()->last_message_at;

        $this->travel(2)->minutes();

        $this->actingAs($this->staff)
            ->post(route('super-admin.conversations.priority', $c->id), ['priority' => 'high']);

        $this->assertEquals(
            $was->timestamp,
            $c->fresh()->last_message_at->timestamp,
            'وفعلُ الدعم بنفسه لا يقول «وصل جديد»',
        );
    }

    /* ═══════════════ الترجمة ═══════════════ */

    public function test_every_status_priority_and_channel_has_an_english_word(): void
    {
        $en = json_decode(file_get_contents(base_path('lang/en.json')), true);

        $keys = array_merge(
            ['جديدة', 'مفتوحة', 'بانتظار العميل', 'بانتظار فريق أبعاد', 'تم الحل', 'مغلقة'],
            ['منخفضة', 'عادية', 'عالية', 'عاجلة'],
            ['داخل أبعاد'],
            Support::CATEGORIES,
            ['مركز المحادثات', 'المساعدة والدعم', 'رد للعميل', 'ملاحظة داخلية',
                'غير معيّن', 'فتح صفحة النشاط', 'إرفاق ملف', 'إرسال',
                'لا توجد محادثات حاليًا', 'لا توجد رسائل بعد', 'اختر محادثة لعرض الرسائل'],
        );

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $en, "لا ترجمة إنجليزية لـ«{$key}»");
            $this->assertNotSame('', trim((string) $en[$key]), "ترجمةٌ فارغة لـ«{$key}»");
        }
    }

    /* ═══════════════ الحدُّ الذي لا يُعبر ═══════════════ */

    /**
     * ورسائلُ المتجر مع زبائنه لا تدخل لوحةَ المنصّة.
     *
     * وهذا أهمُّ حارسٍ في الملفّ بعد الملاحظة الداخليّة: من يفتح مركزَ
     * المحادثات لا يقرأ ما كتبته زبونةٌ لمحلّ ورودٍ عن هديّةٍ لزوجها.
     *
     * ويُقاس بالجدولين معًا: لا صفَّ دعمٍ يُخلَق من رسالة واتساب، ولا نصَّ
     * منها يظهر في الشاشة.
     */
    public function test_a_shops_chat_with_its_own_customers_never_enters_the_platform_inbox(): void
    {
        WhatsAppMessage::create([
            'business_id' => $this->shop->id,
            'direction' => 'outbound',
            'recipient_phone' => '96891234567',
            'source_mode' => 'shared',
            'event_type' => 'order_ready',
            'dedupe_key' => 'test-'.uniqid(),
            'template_name' => 'order_ready',
            'status' => 'sent',
            'metadata' => ['note' => 'وردة حمراء لزوجي في عيد زواجنا'],
        ]);

        $this->conversation();

        $props = $this->actingAs($this->staff)
            ->get(route('super-admin.conversations.index'))
            ->viewData('page')['props'];

        $this->assertCount(1, $props['conversations']['data'], 'محادثةُ الدعم وحدَها');
        $this->assertSame(1, SupportConversation::count());

        $shown = json_encode($props, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('زوجي', $shown);
        $this->assertStringNotContainsString('96891234567', $shown);
    }

    /** ولا سطرَ في مركز المحادثات يقرأ جدولَ رسائل المتاجر */
    public function test_the_platform_inbox_never_reads_the_tenant_message_table(): void
    {
        $files = [
            app_path('Http/Controllers/SuperAdmin/ConversationController.php'),
            app_path('Support/Support.php'),
        ];

        foreach ($files as $file) {
            /*
             * والتعليقاتُ تُنزَع قبل الفحص.
             *
             * هذا الملفُّ نفسُه يشرح في ترويسته أنّه **لا** يمسّ
             * `whatsapp_messages` — فحارسٌ يقرأ النصّ الخام يسقط على شرحِ
             * البراءة ويحسبه جُرمًا. والمقروءُ هو ما يُنفَّذ لا ما يُكتب
             * لقارئٍ بشريّ.
             */
            $code = '';
            foreach (token_get_all(file_get_contents($file)) as $token) {
                if (! is_array($token)) {
                    $code .= $token;

                    continue;
                }

                if (! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= $token[1];
                }
            }

            foreach (['WhatsAppMessage', 'whatsapp_messages', 'Order::', 'Customer::'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    basename($file).' يمسّ '.$forbidden.' — والحدُّ بين اللوحتين لا يُعبر',
                );
            }
        }
    }

    /* ═══════════════ الكلفة ═══════════════ */

    /**
     * ولا استعلامَ لكلّ صفّ.
     *
     * كانت الشاشةُ تسأل عن آخر رسالةٍ وعن غير المقروء لكلّ محادثة: خمسةَ
     * عشرَ صفًّا تُطلق أربعةً وخمسين استعلامًا، وهي تُفتح في كلّ ضغطةِ
     * تبويبٍ وكلّ حرفِ بحث.
     *
     * والحارسُ يقيس **الفرقَ** لا الرقمَ المطلق: صفحةٌ بخمسةَ عشرَ صفًّا
     * لا تكلّف أكثرَ من صفحةٍ بثلاثة إلّا قليلًا. ورقمٌ مطلقٌ يُشدّ كلَّما
     * أُضيف عمودٌ مشترك، فيُرفَع حتّى لا يعني شيئًا.
     */
    public function test_a_longer_page_does_not_cost_a_query_per_row(): void
    {
        foreach (range(1, 3) as $i) {
            $this->conversation();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->staff)->get(route('super-admin.conversations.index'))->assertOk();
        $few = count(DB::getQueryLog());

        foreach (range(1, 12) as $i) {
            $this->conversation();
        }

        DB::flushQueryLog();
        $this->actingAs($this->staff)->get(route('super-admin.conversations.index'))->assertOk();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            $few + 2,
            $many,
            "٣ صفوف كلّفت {$few} استعلامًا و١٥ صفًّا كلّفت {$many} — الكلفةُ تنمو بعدد الصفوف",
        );
    }

    /**
     * وكلُّ ما يُبحث فيه أو يُرشَّح به مفهرَس.
     *
     * والمفتاحُ الأجنبيّ ليس فهرسًا: PostgreSQL تُنشئ قيدًا لا فهرسًا،
     * فجدولٌ يُقرأ بـ`where message_id in (…)` يُمسح كاملًا في كلّ فتحة.
     * ويعمل صامتًا حتّى يكبر.
     */
    public function test_the_columns_that_are_searched_are_indexed(): void
    {
        $expected = [
            'support_conversations' => ['status', 'business_id', 'assigned_to', 'channel', 'reference'],
            'support_messages' => ['conversation_id'],
            'support_attachments' => ['message_id'],
            'support_reads' => ['conversation_id'],
        ];

        foreach ($expected as $table => $columns) {
            $indexed = collect(\Illuminate\Support\Facades\Schema::getIndexes($table))
                ->pluck('columns')->flatten()->unique()->all();

            foreach ($columns as $column) {
                $this->assertContains(
                    $column,
                    $indexed,
                    "{$table}.{$column} يُرشَّح به ولا فهرسَ عليه",
                );
            }
        }
    }

    /* ═══════════════ النسخةُ الاحتياطيّة ═══════════════ */

    /**
     * ومحادثاتُ الدعم لا تخرج في ملفٍّ يُنزّله التاجر.
     *
     * فيها `is_internal`: ملاحظاتُ فريق أبعاد عنه هو. وملفٌّ ينزل على جهازه
     * ويحملها ليس نسخةً احتياطيّة بل تسريب.
     */
    public function test_support_tables_are_excluded_from_a_shops_backup(): void
    {
        foreach (['support_conversations', 'support_messages', 'support_attachments', 'support_reads'] as $table) {
            $this->assertNotContains(
                $table,
                TenantTables::ORDER,
                "{$table} يخرج في نسخة التاجر",
            );
            $this->assertArrayHasKey(
                $table,
                TenantTables::NOT_MINE,
                "{$table} غيرُ مصنَّفٍ — لا يُنسخ ولا يُستثنى بسبب",
            );
        }
    }

    /* ═══════════════ ما لم يُبنَ ═══════════════ */

    /**
     * ولا زرَّ «تذكرة تقنية» يُعرض قبل أن يُبنى نظامُها.
     *
     * مقبضٌ لا يُدير شيئًا أسوأ من غياب المقبض: من يضغطه ينتظر تذكرةً لا
     * تُخلق، ويظنّ بلاغَه مسجَّلًا وقد ضاع.
     */
    public function test_no_ticket_button_is_shown_because_no_ticket_system_exists(): void
    {
        $screen = file_get_contents(resource_path('js/Pages/Platform/Conversations/Index.tsx'));

        $this->assertStringNotContainsString('تذكرة', $screen);
        $this->assertFalse(
            Schema::hasTable('support_tickets'),
            'إن بُني جدولُ التذاكر فليُوصَل الزرُّ — لا أن يُترك هذا الحارس',
        );
    }

    /** والخيطُ يبقى كاملًا: حذفُ المحادثة يحذف رسائلها ومرفقاتها ولا شيء غيرها */
    public function test_deleting_a_conversation_takes_its_messages_with_it(): void
    {
        Storage::fake('local');

        $c = $this->conversation();
        Support::attach($c->messages()->first(), UploadedFile::fake()->image('a.png'));

        $this->assertSame(1, SupportMessage::count());
        $this->assertSame(1, SupportAttachment::count());

        $c->delete();

        $this->assertSame(0, SupportMessage::count());
        $this->assertSame(0, SupportAttachment::count());
        $this->assertDatabaseHas('businesses', ['id' => $this->shop->id]);
    }
}
