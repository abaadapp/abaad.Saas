<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Support\Support;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «المساعدة والدعم» — بابُ التاجر إلى أبعاد.
 *
 * ═══ ولا يُسأل عن متجره ═══
 *
 * لا حقلَ «اسم النشاط» ولا «رقم المتجر»: من يكتب يُعرَف من جلسته. وحقلٌ
 * يُملأ باليد يعني أنّ من يبدّل رقمًا في الطلب يفتح محادثةً باسم جاره،
 * ويعني — وهو أهون — أنّ تاجرًا يكتب اسمَه خطأً فتصل شكواه إلى غيره.
 *
 * ═══ وما لا يصله ═══
 *
 * الملاحظاتُ الداخليّة لفريق أبعاد لا تُقرأ من هنا بحال: كلُّ استعلامٍ في
 * هذا الملفّ يمرّ بـ`businessMessages` لا `messages`.
 */
class HelpController extends Controller
{
    /** قائمةُ محادثات المتجر — الأحدثُ حديثًا أوّلًا */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $conversations = SupportConversation::where('business_id', $user->business_id)
            ->with('assignee:id,name')
            ->orderByDesc('last_message_at')
            ->paginate(10)
            ->withQueryString();

        /* والخلاصةُ للصفحة كلِّها — لا استعلامَ لكلّ صفّ */
        $digest = Support::digest(
            collect($conversations->items())->pluck('id')->all(),
            $user,
            false,
        );

        $conversations->through(fn (SupportConversation $c) => [
            'id' => $c->id,
            'reference' => $c->reference,
            'subject' => $c->subject,
            'category' => __($c->category),
            'status' => $c->status,
            'statusLabel' => Support::statusLabel($c->status),
            'lastMessageAt' => optional($c->last_message_at)->toIso8601String(),
            'unread' => $digest[$c->id]['unread'] ?? 0,
        ]);

        return Inertia::render('Admin/Help/Index', [
            'conversations' => $conversations,
            'categories' => array_map(fn (string $c) => ['value' => $c, 'label' => __($c)], Support::CATEGORIES),
            'maxFiles' => Support::MAX_FILES,
            'maxKb' => Support::MAX_KB,
            'extensions' => Support::EXTENSIONS,
        ]);
    }

    /** محادثةٌ واحدة — وفتحُها يجعلها مقروءة */
    public function show(Request $request, int $id): Response
    {
        $user = $request->user();
        $conversation = $this->mine($request, $id);

        Support::markRead($conversation, $user);

        return Inertia::render('Admin/Help/Show', [
            'conversation' => [
                'id' => $conversation->id,
                'reference' => $conversation->reference,
                'subject' => $conversation->subject,
                'category' => __($conversation->category),
                'status' => $conversation->status,
                'statusLabel' => Support::statusLabel($conversation->status),
                'channelLabel' => Support::channelLabel($conversation->channel),
                'openedAt' => optional($conversation->created_at)->toIso8601String(),
            ],
            'messages' => $conversation->businessMessages()
                ->with(['sender:id,name', 'attachments'])
                ->orderBy('id')
                ->get()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'scope' => $m->sender_scope,
                    'body' => $m->body,
                    'event' => $m->event,
                    'eventText' => $this->eventText($m->event, $m->event_meta),
                    /* واسمُ من ردّ من أبعاد لا يُقال — «دعم أبعاد» جهةٌ لا شخص */
                    'sender' => $m->sender_scope === 'business' ? ($m->sender?->name ?? '') : __('دعم أبعاد'),
                    'at' => optional($m->created_at)->toIso8601String(),
                    'files' => $m->attachments->map(fn ($a) => [
                        'id' => $a->id,
                        'name' => $a->name,
                        'size' => $a->size,
                        'isImage' => str_starts_with($a->mime, 'image/'),
                        'url' => route('admin.help.attachment', [$conversation->id, $a->id]),
                    ])->all(),
                ])->all(),
            'maxFiles' => Support::MAX_FILES,
            'maxKb' => Support::MAX_KB,
            'extensions' => Support::EXTENSIONS,
        ]);
    }

    /** فتحُ محادثةٍ جديدة */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'in:'.implode(',', Support::CATEGORIES)],
            'body' => ['required', 'string', 'max:5000'],
        ] + Support::fileRules('files'));

        $conversation = DB::transaction(function () use ($user, $data, $request) {
            $conversation = Support::open($user, $data['subject'], $data['category'], $data['body']);

            $first = $conversation->messages()->orderBy('id')->first();
            foreach ($request->file('files', []) as $file) {
                Support::attach($first, $file);
            }

            return $conversation;
        });

        return redirect()
            ->route('admin.help.show', $conversation->id)
            ->with('toast', __('وصلت رسالتك — رقم المحادثة :ref', ['ref' => $conversation->reference]));
    }

    /** ردُّ التاجر — ويُعيد المحادثةَ إلى ملعب أبعاد */
    public function reply(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();
        $conversation = $this->mine($request, $id);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ] + Support::fileRules('files'));

        DB::transaction(function () use ($conversation, $user, $data, $request) {
            $message = Support::businessReplied($conversation, $user, $data['body']);

            foreach ($request->file('files', []) as $file) {
                Support::attach($message, $file);
            }
        });

        return back()->with('toast', __('أُرسلت'));
    }

    /**
     * محادثةُ هذا المتجر — أو لا شيء.
     *
     * `where` قبل `find` لا بعده: البحثُ بالمعرّف ثمّ فحصُ المتجر يُسرّب
     * وجودَ الصفّ من فرق زمن الردّ، والأهمُّ أنّه سطرٌ يُنسى.
     */
    private function mine(Request $request, int $id): SupportConversation
    {
        return SupportConversation::where('business_id', $request->user()->business_id)
            ->findOrFail($id);
    }

    /** حدثُ النظام نصًّا — وما لا يخصّ التاجر لا يُترجم له */
    private function eventText(?string $event, ?array $meta): ?string
    {
        return match ($event) {
            'reopened' => __('أُعيد فتح المحادثة'),
            'resolved' => __('تم حل المحادثة'),
            'closed' => __('أُغلقت المحادثة'),
            default => null,
        };
    }
}
