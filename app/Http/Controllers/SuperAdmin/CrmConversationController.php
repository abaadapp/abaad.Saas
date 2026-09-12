<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\User;
use App\Support\Activity;
use App\Support\Crm;
use App\Support\CrmLeads;
use App\Support\CrmWhatsApp;
use App\Support\Pagination;
use App\Support\Search;
use App\Support\WhatsAppPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * محادثاتُ العملاء المحتملين — واتسابُ مبيعات أبعاد.
 *
 * ═══ وليست مركزَ المحادثات ═══
 *
 * ذاك دفترُ **الدعم**: أبعاد ↔ تاجرٌ قائم. وهذا دفترُ **البيع**: أبعاد ↔ من
 * يريد أن يشتريها. ولا سطرَ هنا يقرأ `support_conversations` ولا
 * `whatsapp_messages` ولا جدولًا تحت `business_id`.
 *
 * ═══ والترشيحُ في الخادم ═══
 *
 * القائمةُ تُرقَّم، والخيطُ يُقرأ بسقف. وسحبُ المحادثات كلِّها إلى المتصفّح
 * يعني منصّةً بألف عميلٍ ترسل ألفًا في كلّ فتحةِ شاشة.
 */
class CrmConversationController extends Controller
{
    private const PER_PAGE = 20;

    /** أقصى ما يُقرأ من خيطٍ واحد — والأقدمُ يُقال عددًا لا يُخفى */
    private const THREAD_LIMIT = 200;

    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'stage' => (string) $request->query('stage', 'all'),
            'assignment' => (string) $request->query('assignment', 'all'),
        ];

        /*
         * والقائمةُ من كتب إلينا وحدَهم.
         *
         * عميلٌ أُدخل باليد ولم يُراسلنا قطّ ليس «محادثة»: صفٌّ في القائمة
         * بلا سطرٍ واحدٍ فيه يُفتح فيُوجد فارغًا. وموضعُه شاشةُ العملاء
         * المحتملين، وهناك يُفتح ملفُّه كاملًا.
         */
        $q = CrmLead::query()
            ->whereHas('messages')
            ->with(['assignee:id,name']);

        $this->filter($q, $filters, $request->user());

        $leads = $q->orderByDesc('last_contact_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $rows = collect($leads->items());
        $digest = $this->digest($rows->pluck('id')->all());

        $selected = $request->query('lead');
        $open = $selected
            ? CrmLead::with(['assignee:id,name', 'plan:id,name', 'business:id,name'])->find($selected)
            : null;

        return Inertia::render('Platform/Crm/Conversations', [
            'conversations' => $rows->map(fn (CrmLead $l) => [
                'id' => $l->id,
                'name' => $l->displayName(),
                'businessName' => $l->business_name,
                'phone' => $l->phone_raw ?: $l->phone,
                'stage' => $l->stage,
                'stageLabel' => Crm::stageLabel($l->stage),
                'stageTone' => Crm::stageTone($l->stage),
                'assignee' => $l->assignee?->name,
                'preview' => $digest[$l->id]['preview'] ?? '',
                'at' => optional($l->last_contact_at)->format('Y-m-d H:i'),
                /* ونافذةُ ميتا تُقرأ في القائمة: من ضاق وقتُه يُردّ عليه أوّلًا */
                'windowOpen' => CrmWhatsApp::windowOpen($l),
            ])->all(),
            'pagination' => Pagination::meta($leads),
            'filters' => $filters,
            'counts' => $this->counts($request->user()),
            'stages' => Crm::options(Crm::STAGES, fn ($s) => Crm::stageLabel($s)),
            'staff' => User::where('role', 'super_admin')->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => (string) $u->id, 'label' => $u->name])->all(),
            'active' => $open ? $this->detail($open) : null,
            'messages' => $open ? $this->thread($open) : [],
            /* وحالُ الخطّ يُقال: شاشةٌ صامتةٌ عن رقمٍ غير موصول تُفسَّر عطبًا */
            'line' => [
                'connected' => CrmWhatsApp::connected(),
                'number' => optional(CrmWhatsApp::line())->display_phone_number,
            ],
        ]);
    }

    /**
     * ردٌّ يخرج إلى واتساب — أو يُمنع ويُقال لماذا.
     *
     * ولا يُدّعى النجاح: `CrmWhatsApp::send` تقيس ما جرى وتكتبه في الصفّ،
     * والتوستُ يقرأ ما كُتب لا ما أُريد.
     */
    public function reply(Request $request, int $id): RedirectResponse
    {
        $lead = CrmLead::findOrFail($id);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $message = CrmWhatsApp::send($lead, $request->user(), $data['body']);

        Activity::log('updated', 'ردّ على العميل المحتمل '.$lead->displayName().' عبر واتساب');

        return back()->with('toast', $message->delivery === 'sent'
            ? ['msg' => __('أُرسلت'), 'type' => 'success']
            : ['msg' => (string) $message->delivery_error, 'type' => 'error']);
    }

    /**
     * بدءُ محادثةٍ برقمٍ يُكتب باليد — وهو ما لا يفتح نافذةَ ميتا.
     *
     * فيُنشأ العميلُ ويُفتح ملفُّه، ولا يُدّعى أنّ رسالةً خرجت: واتساب لا
     * يبدأ محادثةً بنصٍّ حرّ، ولا قوالبَ معتمَدةً في هذه النسخة. والباب هنا
     * للتسجيل لا للإرسال.
     */
    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:150'],
        ], [], ['phone' => __('رقم الجوال')]);

        if (WhatsAppPhone::normalize($data['phone']) === null) {
            throw ValidationException::withMessages([
                'phone' => __('رقمٌ لا يصلح — اكتبه بصيغة 9XXXXXXX أو 968XXXXXXXX.'),
            ]);
        }

        $result = CrmLeads::findOrCreateByPhone(
            $data['phone'], Crm::SOURCE_MANUAL, $data['name'] ?? null,
        );

        return redirect()->route('super-admin.crm.leads.show', $result['lead']->id)
            ->with('toast', [
                'msg' => __('لا يبدأ واتساب محادثةً بنصٍّ حرّ — سُجّل العميل، وحين يكتب إلينا تُفتح النافذة.'),
                'type' => 'warning',
            ]);
    }

    /* ═══════════════════ الأدوات ═══════════════════ */

    private function filter($q, array $filters, User $user): void
    {
        if ($filters['q'] !== '') {
            $op = Search::like();
            $term = '%'.$filters['q'].'%';
            $digits = WhatsAppPhone::normalize($filters['q']);

            $q->where(function ($w) use ($op, $term, $digits) {
                $w->where('name', $op, $term)
                    ->orWhere('business_name', $op, $term)
                    ->orWhere('phone_raw', $op, $term)
                    ->orWhere('phone', $op, $term);

                if ($digits !== null) {
                    $w->orWhere('phone', $digits);
                }
            });
        }

        if ($filters['stage'] !== 'all' && in_array($filters['stage'], Crm::STAGES, true)) {
            $q->where('stage', $filters['stage']);
        }

        match ($filters['assignment']) {
            'mine' => $q->where('assigned_to', $user->id),
            'unassigned' => $q->whereNull('assigned_to'),
            /* «مفتوحة النافذة» ليست ترشيحَ إسناد، لكنّها ما يُبحث عنه فعلًا */
            'window' => $q->where('whatsapp_window_at', '>=', now()->subHours(CrmWhatsApp::WINDOW_HOURS)),
            default => null,
        };
    }

    /** @return array<string,int> */
    private function counts(User $user): array
    {
        $base = fn () => CrmLead::query()->whereHas('messages');

        return [
            'all' => $base()->count(),
            'mine' => $base()->where('assigned_to', $user->id)->count(),
            'unassigned' => $base()->whereNull('assigned_to')->count(),
            'window' => $base()->where('whatsapp_window_at', '>=', now()->subHours(CrmWhatsApp::WINDOW_HOURS))->count(),
        ];
    }

    /**
     * آخرُ سطرٍ في كلّ خيط — باستعلامٍ واحدٍ لا بواحدٍ لكلّ صفّ.
     *
     * صفحةٌ بعشرين خيطًا تسأل القاعدةَ عشرين مرّةً لو كُتب السؤال داخل
     * الحلقة. وهي تُفتح في كلّ ضغطةِ تبويبٍ وكلّ حرفٍ في البحث.
     *
     * @param  list<int>  $ids
     * @return array<int, array{preview:string}>
     */
    private function digest(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $previews = CrmMessage::whereIn('lead_id', $ids)
            ->orderByDesc('id')->get(['lead_id', 'body'])
            ->groupBy('lead_id')
            ->map(fn ($rows) => mb_substr((string) ($rows->first()->body ?? ''), 0, 80));

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['preview' => $previews[$id] ?? ''];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function detail(CrmLead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->displayName(),
            'businessName' => $lead->business_name,
            'phone' => $lead->phone_raw ?: $lead->phone,
            'phoneNormalized' => $lead->phone,
            'wilayat' => $lead->wilayat,
            'branchesCount' => $lead->branches_count,
            'currentSystem' => $lead->current_system,
            'stage' => $lead->stage,
            'stageLabel' => Crm::stageLabel($lead->stage),
            'stageTone' => Crm::stageTone($lead->stage),
            'sourceLabel' => Crm::sourceLabel($lead->source),
            'assignee' => $lead->assignee?->name,
            'assigneeId' => $lead->assigned_to ? (string) $lead->assigned_to : null,
            'plan' => $lead->plan?->name,
            'firstContactAt' => optional($lead->first_contact_at)->format('Y-m-d H:i'),
            'lastContactAt' => optional($lead->last_contact_at)->format('Y-m-d H:i'),
            'nextFollowUpAt' => optional($lead->next_follow_up_at)->format('Y-m-d H:i'),
            'notesSummary' => $lead->notes_summary,
            'url' => route('super-admin.crm.leads.show', $lead->id),
            /* والمتجرُ إن صار مشتركًا — ورابطُه، فمن يكلّمنا قد يكون عميلَنا */
            'business' => $lead->business ? [
                'name' => $lead->business->name,
                'url' => route('super-admin.businesses.show', $lead->business->id),
            ] : null,
            /*
             * ونافذةُ ميتا: مفتوحةٌ أو لا، ومتى تُغلق، ولمَ لا يخرج شيء.
             *
             * تُقرأ **قبل** الكتابة لا بعد المنع: من يكتب ردًّا طويلًا ثمّ
             * يُردّ «النافذة مغلقة» يكون قد كتب على لا شيء.
             */
            'windowOpen' => CrmWhatsApp::windowOpen($lead),
            'windowEndsAt' => CrmWhatsApp::windowEndsAt($lead),
            'blockedReason' => CrmWhatsApp::blockedReason($lead),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function thread(CrmLead $lead): array
    {
        return CrmMessage::where('lead_id', $lead->id)
            ->orderByDesc('id')->limit(self::THREAD_LIMIT)->get()
            ->reverse()->values()
            ->map(fn (CrmMessage $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'body' => $m->body,
                'sender' => $m->sender_name,
                'at' => optional($m->created_at)->format('Y-m-d H:i'),
                'delivery' => $m->delivery,
                'deliveryLabel' => CrmWhatsApp::deliveryLabel($m->delivery),
                'deliveryError' => $m->delivery_error,
                'mediaType' => $m->media_type,
            ])->all();
    }
}
