<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Models\User;
use App\Support\Activity;
use App\Support\Support;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * مركزُ المحادثات — لوحةُ دعم أبعاد.
 *
 * ═══ الحدُّ الذي لا يُعبر ═══
 *
 * ما يُقرأ هنا محادثاتُ **أبعاد ↔ أصحاب المتاجر** وحدَها. ولا سطرَ في هذا
 * الملفّ يمسّ `whatsapp_messages` ولا `orders` ولا شيئًا ممّا يدور بين
 * تاجرٍ وزبائنه: من يفتح لوحةَ المنصّة لا يقرأ ما كتبته زبونةٌ لمحلّ ورودٍ
 * عن هديّةٍ لزوجها. والحارسُ في `SupportPrivacyTest` يشهد.
 *
 * ═══ والترشيحُ في الخادم ═══
 *
 * البحثُ والتصفيةُ والصفحات كلُّها استعلامٌ واحد. وسحبُ المحادثات كلِّها
 * إلى المتصفّح ثمّ ترشيحُها هناك يعني أنّ منصّةً بألف محادثةٍ ترسل ألفًا
 * في كلّ فتحةِ شاشة — ومعها نصوصُ الرسائل التي لا يحقّ لصاحب الشاشة
 * قراءتُها كلِّها دفعةً واحدة.
 */
class ConversationController extends Controller
{
    /** عددُ المحادثات في الصفحة — كأخواتها في لوحة المنصّة */
    private const PER_PAGE = 15;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', 'all'),
            'assignment' => (string) $request->query('assignment', 'all'),
        ];

        $q = SupportConversation::query()
            ->with(['business:id,name,logo,status', 'assignee:id,name', 'opener:id,name']);

        $this->filter($q, $filters, $user);

        $conversations = $q->orderByDesc('last_message_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /*
         * والخلاصةُ تُقرأ للصفحة كلِّها قبل رسم صفوفها.
         *
         * `through` تُنادى صفًّا صفًّا، فسؤالُ القاعدة داخلها يعني استعلامًا
         * لكلّ محادثة. والصفحةُ تُفتح في كلّ ضغطةِ تبويبٍ وكلّ حرفِ بحث.
         */
        $digest = Support::digest(
            collect($conversations->items())->pluck('id')->all(),
            $user,
            true,
        );

        $conversations->through(fn (SupportConversation $c) => $this->row($c, $digest[$c->id] ?? []));

        $selected = $request->query('conversation');
        $open = $selected
            ? SupportConversation::with(['business', 'assignee:id,name', 'opener:id,name,email'])->find($selected)
            : null;

        if ($open) {
            Support::markRead($open, $user);
        }

        return Inertia::render('Platform/Conversations/Index', [
            'conversations' => $conversations,
            'filters' => $filters,
            'counts' => $this->counts($user),
            'active' => $open ? $this->detail($open) : null,
            'messages' => $open ? $this->thread($open) : [],
            'staff' => User::where('role', 'super_admin')
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
            'statuses' => array_map(fn (string $s) => [
                'value' => $s, 'label' => Support::statusLabel($s),
            ], Support::STATUSES),
            'priorities' => array_map(fn (string $p) => [
                'value' => $p, 'label' => Support::priorityLabel($p),
            ], Support::PRIORITIES),
            /* والقنواتُ المرسومة هي العاملةُ وحدَها — لا بابَ يُعرض ولا يُفتح */
            'channels' => array_map(fn (string $c) => [
                'value' => $c, 'label' => Support::channelLabel($c),
            ], Support::WORKING_CHANNELS),
            'maxFiles' => Support::MAX_FILES,
            'maxKb' => Support::MAX_KB,
            'extensions' => Support::EXTENSIONS,
        ]);
    }

    /* ═══════════════════ الأفعال ═══════════════════ */

    /** ردٌّ يراه التاجر، أو ملاحظةٌ لا تبلغه */
    public function reply(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();
        $conversation = SupportConversation::findOrFail($id);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'internal' => ['boolean'],
        ] + Support::fileRules('files'));

        $internal = (bool) ($data['internal'] ?? false);

        DB::transaction(function () use ($conversation, $user, $data, $internal, $request) {
            $message = Support::say($conversation, $user, 'platform', $data['body'], $internal);

            foreach ($request->file('files', []) as $file) {
                Support::attach($message, $file);
            }

            /*
             * وملاحظةُ الفريق لا تُحرّك الحالة.
             *
             * «بانتظار العميل» تعني أنّ الكرة عند التاجر. وملاحظةٌ يكتبها
             * زميلٌ لزميله لا تنقلها إليه — ولو نقلتها لصار الدعمُ ينتظر
             * ردًّا على كلامٍ لم يصل صاحبَه.
             */
            if (! $internal && in_array($conversation->status, ['new', 'open', 'waiting_abaad'], true)) {
                $conversation->forceFill(['status' => 'waiting_customer'])->save();
            }
        });

        if ($internal) {
            Activity::log('support', 'ملاحظة داخلية على المحادثة: '.$conversation->reference);
        }

        return back();
    }

    /** تعيينُ المحادثة — ولا تُعيَّن إلّا إلى فريق المنصّة */
    public function assign(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();
        $conversation = SupportConversation::findOrFail($id);

        $data = $request->validate(['user_id' => ['nullable', 'integer']]);

        $assignee = null;

        if (filled($data['user_id'])) {
            /*
             * ولا يُعيَّن موظّفُ متجرٍ إلى محادثةِ دعم.
             *
             * يفتح البابَ فيقرأ محادثاتِ المتاجر كلِّها. والفحصُ على الدور
             * لا على وجود الصفّ: رقمٌ يُبدَّل في الطلب يُعيّن أيَّ مستخدمٍ
             * في القاعدة.
             */
            $assignee = User::where('role', 'super_admin')->find($data['user_id']);

            if (! $assignee) {
                return back()->withErrors(['user_id' => __('لا يُعيَّن إلا أحد فريق أبعاد.')]);
            }
        }

        DB::transaction(function () use ($conversation, $assignee, $user) {
            $conversation->forceFill([
                'assigned_to' => $assignee?->id,
                'assigned_by' => $assignee ? $user->id : null,
                'assigned_at' => $assignee ? now() : null,
                'status' => $conversation->status === 'new' ? 'open' : $conversation->status,
            ])->save();

            Support::say($conversation, null, 'system', null, true, 'assigned', [
                'to' => $assignee?->name,
                'by' => $user->name,
            ]);
        });

        Activity::log('support', $assignee
            ? 'عُيّنت المحادثة '.$conversation->reference.' إلى '.$assignee->name
            : 'رُفع التعيين عن المحادثة '.$conversation->reference);

        return back();
    }

    /** الحالة — والتواريخُ تتبعها */
    public function status(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();
        $conversation = SupportConversation::findOrFail($id);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', Support::STATUSES)],
        ]);

        $was = $conversation->status;

        DB::transaction(function () use ($conversation, $data, $user, $was) {
            $conversation->forceFill([
                'status' => $data['status'],
                'resolved_at' => $data['status'] === 'resolved' ? now() : null,
                'closed_at' => $data['status'] === 'closed' ? now() : null,
            ])->save();

            /*
             * وثلاثةُ أحداثٍ يراها التاجر، وما عداها شأنُ الفريق.
             *
             * «تم الحل» و«أُغلقت» و«أُعيد فتحها» تخصّه: هو ينتظر جوابًا.
             * و«صارت بانتظار فريق أبعاد» ترتيبٌ داخليّ لا يعنيه.
             */
            $shown = in_array($data['status'], ['resolved', 'closed'], true)
                || in_array($was, ['resolved', 'closed'], true);

            Support::say($conversation, null, 'system', null, ! $shown, match (true) {
                $data['status'] === 'resolved' => 'resolved',
                $data['status'] === 'closed' => 'closed',
                in_array($was, ['resolved', 'closed'], true) => 'reopened',
                default => 'status',
            }, ['from' => $was, 'to' => $data['status'], 'by' => $user->name]);
        });

        Activity::log('support', 'حالة المحادثة '.$conversation->reference.': '
            .Support::statusLabel($was).' ← '.Support::statusLabel($data['status']));

        return back();
    }

    /** الأولويّة — شأنُ المنصّة، ولا تُعرض للتاجر ولا تُشعره */
    public function priority(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();
        $conversation = SupportConversation::findOrFail($id);

        $data = $request->validate([
            'priority' => ['required', 'string', 'in:'.implode(',', Support::PRIORITIES)],
        ]);

        $was = $conversation->priority;
        $conversation->forceFill(['priority' => $data['priority']])->save();

        Support::say($conversation, null, 'system', null, true, 'priority', [
            'from' => $was, 'to' => $data['priority'], 'by' => $user->name,
        ]);

        Activity::log('support', 'أولوية المحادثة '.$conversation->reference.': '
            .Support::priorityLabel($was).' ← '.Support::priorityLabel($data['priority']));

        return back();
    }

    /* ═══════════════════ البناء ═══════════════════ */

    private function filter($q, array $f, User $user): void
    {
        if ($f['q'] !== '') {
            $term = '%'.$f['q'].'%';
            $q->where(function ($w) use ($term) {
                $w->where('reference', 'like', $term)
                    ->orWhere('subject', 'like', $term)
                    ->orWhereHas('business', fn ($b) => $b->where('name', 'like', $term))
                    /*
                     * ونصُّ الرسائل يُبحث فيه — والملاحظاتُ الداخليّة منه.
                     *
                     * القارئُ هنا فريقُ أبعاد وحدَه، وهي ملاحظاتُه هو. ولو
                     * استُثنيت لصار البحثُ عن كلمةٍ كتبها زميلٌ لا يجدها.
                     */
                    ->orWhereHas('messages', fn ($m) => $m->where('body', 'like', $term));
            });
        }

        match ($f['status']) {
            'all' => null,
            'unread' => $q->whereExists(fn ($s) => $s->selectRaw(1)
                ->from('support_messages')
                ->whereColumn('support_messages.conversation_id', 'support_conversations.id')
                ->where('support_messages.sender_scope', 'business')
                ->whereRaw(
                    'support_messages.id > coalesce((select last_read_message_id from support_reads
                     where support_reads.conversation_id = support_conversations.id
                       and support_reads.user_id = ?), 0)',
                    [$user->id],
                )),
            default => in_array($f['status'], Support::STATUSES, true)
                ? $q->where('status', $f['status'])
                : null,
        };

        match ($f['assignment']) {
            'mine' => $q->where('assigned_to', $user->id),
            'unassigned' => $q->whereNull('assigned_to'),
            default => null,
        };
    }

    /** أعدادُ التبويبات — من القاعدة لا من الشاشة */
    private function counts(User $user): array
    {
        $byStatus = SupportConversation::selectRaw('status, count(*) as n')
            ->groupBy('status')->pluck('n', 'status');

        return [
            'all' => (int) $byStatus->sum(),
            'unread' => Support::platformBadge($user),
            'open' => (int) ($byStatus['open'] ?? 0) + (int) ($byStatus['new'] ?? 0),
            'waiting_customer' => (int) ($byStatus['waiting_customer'] ?? 0),
            'waiting_abaad' => (int) ($byStatus['waiting_abaad'] ?? 0),
            'resolved' => (int) ($byStatus['resolved'] ?? 0),
            'closed' => (int) ($byStatus['closed'] ?? 0),
        ];
    }

    /** @param  array{preview?: string, unread?: int}  $digest */
    private function row(SupportConversation $c, array $digest): array
    {
        return [
            'id' => $c->id,
            'reference' => $c->reference,
            'business' => $c->business?->name ?? '—',
            'businessLogo' => $c->business?->logo,
            'opener' => $c->opener?->name,
            'subject' => $c->subject,
            'preview' => $digest['preview'] ?? '',
            'channel' => $c->channel,
            'channelLabel' => Support::channelLabel($c->channel),
            'status' => $c->status,
            'statusLabel' => Support::statusLabel($c->status),
            'priority' => $c->priority,
            'priorityLabel' => Support::priorityLabel($c->priority),
            'assignee' => $c->assignee?->name,
            'lastMessageAt' => optional($c->last_message_at)->toIso8601String(),
            'unread' => $digest['unread'] ?? 0,
        ];
    }

    /**
     * بطاقةُ النشاط — ما يحتاجه الدعمُ ليفهم، لا كلَّ ما في الصفّ.
     *
     * لا رقمَ ضريبيّ ولا سجلَّ تجاريّ ولا عنوان: من يردّ على سؤالٍ عن
     * الفواتير لا يحتاجها، وعرضُها يجعل كلَّ محادثةٍ نافذةً على بيانات
     * المتجر كلِّها.
     */
    private function detail(SupportConversation $c): array
    {
        $b = $c->business;

        return [
            'id' => $c->id,
            'reference' => $c->reference,
            'subject' => $c->subject,
            'category' => __($c->category),
            'status' => $c->status,
            'statusLabel' => Support::statusLabel($c->status),
            'priority' => $c->priority,
            'channel' => $c->channel,
            'channelLabel' => Support::channelLabel($c->channel),
            'assigneeId' => $c->assigned_to,
            'assignee' => $c->assignee?->name,
            'lastMessageAt' => optional($c->last_message_at)->toIso8601String(),
            'business' => [
                'id' => $b?->id,
                'name' => $b?->name ?? '—',
                'logo' => $b?->logo,
                'status' => $b?->status,
                'owner' => $c->opener?->name,
                'email' => $c->opener?->email,
                'phone' => $b?->phone,
                'url' => $b ? route('super-admin.businesses.show', $b->id) : null,
            ],
        ];
    }

    /** الخيطُ كاملًا — والفريقُ يرى الداخليَّ لأنّه له */
    private function thread(SupportConversation $c): array
    {
        return $c->messages()->with(['sender:id,name', 'attachments'])->orderBy('id')->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'scope' => $m->sender_scope,
                'internal' => $m->is_internal,
                'body' => $m->body,
                'event' => $m->event,
                'eventText' => $this->eventText($m->event, $m->event_meta),
                'sender' => $m->sender?->name ?? '',
                'at' => optional($m->created_at)->toIso8601String(),
                'files' => $m->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'size' => $a->size,
                    'isImage' => str_starts_with($a->mime, 'image/'),
                    'url' => route('super-admin.conversations.attachment', [$c->id, $a->id]),
                ])->all(),
            ])->all();
    }

    private function eventText(?string $event, ?array $meta): ?string
    {
        return match ($event) {
            'assigned' => filled($meta['to'] ?? null)
                ? __('عُيّنت المحادثة إلى :name', ['name' => $meta['to']])
                : __('رُفع التعيين عن المحادثة'),
            'status' => __('تغيّرت الحالة إلى :to', ['to' => Support::statusLabel($meta['to'] ?? '')]),
            'priority' => __('تغيّرت الأولوية إلى :to', ['to' => Support::priorityLabel($meta['to'] ?? '')]),
            'resolved' => __('تم حل المحادثة'),
            'closed' => __('أُغلقت المحادثة'),
            'reopened' => __('أُعيد فتح المحادثة'),
            default => null,
        };
    }
}
