<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmStageEvent;
use App\Models\CrmTask;
use App\Models\Plan;
use App\Models\User;
use App\Support\Activity;
use App\Support\Crm;
use App\Support\CrmLeads;
use App\Support\Pagination;
use App\Support\Search;
use App\Support\WhatsAppPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * دفترُ مبيعات أبعاد — لوحةُ العملاء المحتملين.
 *
 * ═══ الحدُّ الذي لا يُعبر ═══
 *
 * لا سطرَ في هذا الملفّ يقرأ `customers` ولا `orders` ولا `whatsapp_messages`
 * ولا أيَّ جدولٍ تحت `business_id`. ما يُقرأ هنا دفترُ **أبعاد** مع من يريد
 * أن يشتريَها — لا دفترُ تاجرٍ مع زبائنه. والحارسُ في
 * `CrmStaysOutOfTenantDataTest` يشهد.
 *
 * ═══ والترشيحُ في الخادم ═══
 *
 * البحثُ والتصفيةُ والصفحاتُ استعلامٌ واحد. ولوحةُ المسار تُحمِّل المراحلَ
 * السبعَ بسقفٍ لكلّ عمود: «اسحب الكلَّ إلى المتصفّح ثمّ رتّبه هناك» تعني
 * دفترًا بألفِ عميلٍ يُرسَل كاملًا في كلّ فتحةِ شاشة.
 */
class CrmController extends Controller
{
    private const PER_PAGE = 20;

    /** أقصى ما يُحمَّل في عمودٍ من أعمدة المسار — والباقي يُقال عددًا */
    private const BOARD_LIMIT = 50;

    /* ═══════════════════ الشاشات ═══════════════════ */

    /** لوحةُ CRM — أرقامٌ محسوبةٌ من الدفتر، لا اتّجاهاتٌ مرسومة */
    public function dashboard(Request $request): Response
    {
        $byStage = CrmLead::query()
            ->selectRaw('stage, count(*) as n')->groupBy('stage')
            ->pluck('n', 'stage');

        $won = (int) ($byStage[Crm::WON] ?? 0);
        $lost = (int) ($byStage[Crm::LOST] ?? 0);
        $closed = $won + $lost;

        return Inertia::render('Platform/Crm/Dashboard', [
            'metrics' => [
                'total' => CrmLead::count(),
                'active' => CrmLead::open()->count(),
                'new' => (int) ($byStage[Crm::NEW] ?? 0),
                'interested' => (int) ($byStage[Crm::INTERESTED] ?? 0),
                'qualified' => (int) ($byStage[Crm::QUALIFIED] ?? 0),
                'trial' => (int) ($byStage[Crm::TRIAL] ?? 0),
                'quotation' => (int) ($byStage[Crm::QUOTATION] ?? 0),
                'won' => $won,
                'lost' => $lost,
                /*
                 * ونسبةُ التحويل تُحسب من المحسوم وحدَه.
                 *
                 * قسمتُها على الدفتر كلِّه تُنقصها كلّما أُضيف عميلٌ جديد —
                 * فتبدو المبيعاتُ تسوء كلّما زاد العمل. والمقامُ ما انتهى:
                 * مشتركٌ أو مفقود.
                 */
                'conversionRate' => $closed > 0 ? round($won / $closed * 100, 1) : null,
                'overdueFollowUps' => CrmLead::open()
                    ->whereNotNull('next_follow_up_at')
                    ->where('next_follow_up_at', '<', now())->count(),
                'overdueTasks' => CrmTask::open()->where('due_at', '<', now())->count(),
                'unassigned' => CrmLead::open()->whereNull('assigned_to')->count(),
            ],
            'mine' => $this->myWork($request->user()),
        ]);
    }

    /** قائمةُ العملاء المحتملين */
    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'stage' => (string) $request->query('stage', 'all'),
            'status' => (string) $request->query('status', 'all'),
            'assignment' => (string) $request->query('assignment', 'all'),
            'source' => (string) $request->query('source', 'all'),
        ];

        $q = CrmLead::query()->with(['assignee:id,name', 'plan:id,name', 'business:id,name']);
        $this->filter($q, $filters, $request->user());

        $leads = $q->orderByRaw('case when next_follow_up_at is null then 1 else 0 end')
            ->orderBy('next_follow_up_at')
            ->orderByDesc('last_contact_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Platform/Crm/Leads/Index', [
            'leads' => collect($leads->items())->map(fn (CrmLead $l) => $this->row($l))->all(),
            'pagination' => Pagination::meta($leads),
            'filters' => $filters,
            'counts' => $this->counts($request->user()),
            'stages' => Crm::options(Crm::STAGES, fn ($s) => Crm::stageLabel($s)),
            'sources' => Crm::options(Crm::SOURCES, fn ($s) => Crm::sourceLabel($s)),
            'statuses' => Crm::options(Crm::STATUSES, fn ($s) => Crm::statusLabel($s)),
            'staff' => $this->staff(),
        ]);
    }

    /** لوحةُ المسار — سبعةُ أعمدةٍ حيّة، وسقفٌ لكلٍّ منها */
    public function pipeline(Request $request): Response
    {
        $assignment = (string) $request->query('assignment', 'all');
        $columns = [];

        foreach (Crm::boardStages() as $stage) {
            $q = CrmLead::where('stage', $stage)
                ->with(['assignee:id,name', 'plan:id,name']);

            if ($assignment === 'mine') {
                $q->where('assigned_to', $request->user()->id);
            } elseif ($assignment === 'unassigned') {
                $q->whereNull('assigned_to');
            }

            $total = (clone $q)->count();

            $columns[] = [
                'stage' => $stage,
                'label' => Crm::stageLabel($stage),
                'tone' => Crm::stageTone($stage),
                'total' => $total,
                /* وما زاد عن السقف يُقال عددًا لا يُخفى — انظر `hidden` */
                'hidden' => max(0, $total - self::BOARD_LIMIT),
                'cards' => $q->orderByDesc('last_contact_at')->orderByDesc('id')
                    ->limit(self::BOARD_LIMIT)->get()
                    ->map(fn (CrmLead $l) => $this->card($l))->all(),
            ];
        }

        return Inertia::render('Platform/Crm/Pipeline', [
            'columns' => $columns,
            'assignment' => $assignment,
            'lostReasons' => Crm::options(Crm::LOST_REASONS, fn ($r) => Crm::lostReasonLabel($r)),
        ]);
    }

    /** ملفُّ عميلٍ محتمَل */
    public function show(Request $request, int $id): Response
    {
        $lead = CrmLead::with(['assignee:id,name', 'assigner:id,name', 'plan:id,name', 'business:id,name,status'])
            ->findOrFail($id);

        $merchant = CrmLeads::existingMerchant($lead);

        return Inertia::render('Platform/Crm/Leads/Show', [
            'lead' => $this->detail($lead),
            /*
             * وهل هو تاجرٌ عندنا أصلًا — يُقال ولا يُكتب في الصفّ.
             *
             * من يكلّمنا وهو مشتركٌ ليس عميلًا محتملًا بل صاحبُ دعم. وعرضُ
             * ذلك يمنع موظّفَ المبيعات من أن يبيعه ما اشتراه.
             */
            'existingMerchant' => $merchant ? [
                'name' => $merchant->name,
                'businessId' => $merchant->business_id,
                'businessName' => $merchant->business?->name,
                'url' => $merchant->business_id
                    ? route('super-admin.businesses.show', $merchant->business_id) : null,
            ] : null,
            'notes' => $lead->notes()->orderByDesc('id')->limit(100)->get()
                ->map(fn ($n) => [
                    'id' => $n->id,
                    'author' => $n->user_name,
                    'body' => $n->body,
                    'at' => optional($n->created_at)->format('Y-m-d H:i'),
                ])->all(),
            'tasks' => $lead->tasks()->with('assignee:id,name')
                ->orderByRaw("case when status = 'open' then 0 else 1 end")
                ->orderBy('due_at')->limit(100)->get()
                ->map(fn (CrmTask $t) => $this->taskRow($t))->all(),
            'timeline' => $lead->stageEvents()->orderByDesc('id')->limit(100)->get()
                ->map(fn (CrmStageEvent $e) => [
                    'id' => $e->id,
                    'from' => $e->from_stage ? Crm::stageLabel($e->from_stage) : null,
                    'to' => Crm::stageLabel($e->to_stage),
                    'by' => $e->user_name,
                    'reason' => $e->reason,
                    'at' => optional($e->created_at)->format('Y-m-d H:i'),
                ])->all(),
            'stages' => Crm::options(Crm::boardStages(), fn ($s) => Crm::stageLabel($s)),
            'lostReasons' => Crm::options(Crm::LOST_REASONS, fn ($r) => Crm::lostReasonLabel($r)),
            'sources' => Crm::options(Crm::SOURCES, fn ($s) => Crm::sourceLabel($s)),
            'staff' => $this->staff(),
            'plans' => Plan::orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['value' => (string) $p->id, 'label' => $p->name])->all(),
            'taskPriorities' => Crm::options(Crm::TASK_PRIORITIES, fn ($p) => Crm::taskPriorityLabel($p)),
            /*
             * والمتاجرُ المتاحةُ للربط — الحقيقيّةُ وحدَها وغيرُ المرتبطة.
             *
             * متجرٌ تجريبيٌّ ليس اشتراكًا، ومتجرٌ ارتُبط بعميلٍ آخر يجعل
             * متجرًا واحدًا محسوبًا اشتراكَين في تقرير التحويل.
             */
            'businesses' => $this->linkableBusinesses($lead),
        ]);
    }

    /** المهامُّ والمتابعات — عبرَ الدفتر كلِّه */
    public function tasks(Request $request): Response
    {
        $scope = (string) $request->query('scope', 'open');

        $q = CrmTask::with(['assignee:id,name', 'lead:id,name,business_name,phone,phone_raw,stage']);

        match ($scope) {
            'overdue' => $q->open()->where('due_at', '<', now()),
            'mine' => $q->open()->where('assigned_to', $request->user()->id),
            'done' => $q->where('status', 'done'),
            default => $q->open(),
        };

        $tasks = $q->orderBy('due_at')->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Platform/Crm/Tasks', [
            'tasks' => collect($tasks->items())->map(fn (CrmTask $t) => $this->taskRow($t, true))->all(),
            'pagination' => Pagination::meta($tasks),
            'scope' => $scope,
            'counts' => [
                'open' => CrmTask::open()->count(),
                'overdue' => CrmTask::open()->where('due_at', '<', now())->count(),
                'mine' => CrmTask::open()->where('assigned_to', $request->user()->id)->count(),
                'done' => CrmTask::where('status', 'done')->count(),
            ],
        ]);
    }

    /**
     * تقاريرُ المبيعات — كلُّ رقمٍ من الدفتر، ولا رقمَ مرسوم.
     *
     * ومسارُ التحويل يُقرأ من `crm_stage_events` لا من عمود `stage`: العمودُ
     * يحمل **الآن** وحدَه، فعميلٌ مرّ بـ«مهتمّ» ثمّ صار مشتركًا لا يُعدّ في
     * «مهتمّ» أبدًا — ويقول المسارُ إنّ أحدًا لم يهتمّ قطّ.
     */
    public function reports(): Response
    {
        $sources = CrmLead::query()->selectRaw('source, count(*) as n')
            ->groupBy('source')->orderByDesc('n')->get()
            ->map(fn ($r) => ['key' => $r->source, 'label' => Crm::sourceLabel($r->source), 'n' => (int) $r->n])->all();

        $lost = CrmLead::query()->whereNotNull('lost_reason')
            ->selectRaw('lost_reason, count(*) as n')
            ->groupBy('lost_reason')->orderByDesc('n')->get()
            ->map(fn ($r) => ['key' => $r->lost_reason, 'label' => Crm::lostReasonLabel($r->lost_reason), 'n' => (int) $r->n])->all();

        $reached = CrmStageEvent::query()->selectRaw('to_stage, count(distinct lead_id) as n')
            ->groupBy('to_stage')->pluck('n', 'to_stage');

        $funnel = array_map(fn (string $s) => [
            'key' => $s,
            'label' => Crm::stageLabel($s),
            'n' => (int) ($reached[$s] ?? 0),
        ], [...Crm::boardStages(), Crm::WON]);

        $staff = User::where('role', 'super_admin')->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'active' => CrmLead::open()->where('assigned_to', $u->id)->count(),
                'won' => CrmLead::where('assigned_to', $u->id)->where('stage', Crm::WON)->count(),
                'lost' => CrmLead::where('assigned_to', $u->id)->where('stage', Crm::LOST)->count(),
            ])->all();

        return Inertia::render('Platform/Crm/Reports', [
            'sources' => $sources,
            'lostReasons' => $lost,
            'funnel' => $funnel,
            'staff' => $staff,
            'whatsappLeads' => CrmLead::where('source', Crm::SOURCE_WHATSAPP)->count(),
            'trialToPaid' => [
                'trials' => (int) ($reached[Crm::TRIAL] ?? 0),
                'won' => CrmStageEvent::where('to_stage', Crm::WON)
                    ->whereIn('lead_id', CrmStageEvent::where('to_stage', Crm::TRIAL)->select('lead_id'))
                    ->distinct()->count('lead_id'),
            ],
        ]);
    }

    /* ═══════════════════ الأفعال ═══════════════════ */

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:150'],
            'business_name' => ['nullable', 'string', 'max:150'],
            'wilayat' => ['nullable', 'string', 'max:80'],
            'branches_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'current_system' => ['nullable', 'string', 'max:120'],
            'source' => ['required', Rule::in(Crm::SOURCES)],
        ], [], ['phone' => __('رقم الجوال')]);

        /*
         * والرقمُ يُفحص قبل الكتابة لا بعدها.
         *
         * `findOrCreateByPhone` ترفع استثناءً على رقمٍ لا يُطبَّع، وذلك خطأُ
         * برمجةٍ يصل المستخدمَ صفحةَ خمسمئة. وهنا رسالةٌ يقرؤها ويصلحها.
         */
        if (WhatsAppPhone::normalize($data['phone']) === null) {
            throw ValidationException::withMessages([
                'phone' => __('رقمٌ لا يصلح — اكتبه بصيغة 9XXXXXXX أو 968XXXXXXXX.'),
            ]);
        }

        $result = CrmLeads::findOrCreateByPhone(
            $data['phone'],
            $data['source'],
            $data['name'] ?? null,
            array_filter([
                'business_name' => $data['business_name'] ?? null,
                'wilayat' => $data['wilayat'] ?? null,
                'branches_count' => $data['branches_count'] ?? null,
                'current_system' => $data['current_system'] ?? null,
            ], fn ($v) => $v !== null),
        );

        /*
         * ورقمٌ مكرَّر لا يُنشئ ثانيًا ولا يُخفي ذلك.
         *
         * «تمّ الحفظ» عن صفٍّ لم يُكتب تقريرُ حالٍ كاذب: يظنّ الموظّفُ أنّه
         * أضاف عميلًا، ثمّ لا يجده في القائمة لأنّه كان فيها.
         */
        if (! $result['created']) {
            return redirect()->route('super-admin.crm.leads.show', $result['lead']->id)
                ->with('toast', ['msg' => __('هذا الرقم مسجَّلٌ من قبل — هذا ملفّه.'), 'type' => 'warning']);
        }

        return redirect()->route('super-admin.crm.leads.show', $result['lead']->id)
            ->with('toast', ['msg' => __('أُضيف العميل المحتمل'), 'type' => 'success']);
    }

    /** تعديلُ ما قاله صاحبُه — ولا يُكتب هنا حالٌ ولا مرحلة */
    public function update(Request $request, int $id): RedirectResponse
    {
        $lead = CrmLead::findOrFail($id);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'business_name' => ['nullable', 'string', 'max:150'],
            'wilayat' => ['nullable', 'string', 'max:80'],
            'branches_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'current_system' => ['nullable', 'string', 'max:120'],
            'source' => ['required', Rule::in(Crm::SOURCES)],
            'interested_plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'expected_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'next_follow_up_at' => ['nullable', 'date'],
            'tags' => ['nullable', 'array', 'max:12'],
            'tags.*' => ['string', 'max:30'],
        ], [], [
            'expected_value' => __('القيمة المتوقعة'),
            'next_follow_up_at' => __('المتابعة القادمة'),
        ]);

        $lead->forceFill($data)->save();
        Activity::log('updated', 'عدّل بيانات العميل المحتمل '.$lead->displayName());

        return back()->with('toast', ['msg' => __('حُفظت البيانات'), 'type' => 'success']);
    }

    public function stage(Request $request, int $id): RedirectResponse
    {
        $lead = CrmLead::findOrFail($id);

        $data = $request->validate([
            /*
             * و«مشترك» لا تُقبل هنا: الاشتراك يُربط بمتجرٍ حقيقيّ — `convert`.
             * ولو قُبلت لَصار في التقرير مشتركون بلا متاجر.
             */
            'stage' => ['required', Rule::in(Crm::boardStages())],
        ]);

        CrmLeads::moveStage($lead, $data['stage'], $request->user());

        return back()->with('toast', [
            'msg' => __('نُقل إلى :stage', ['stage' => Crm::stageLabel($data['stage'])]),
            'type' => 'success',
        ]);
    }

    public function lose(Request $request, int $id): RedirectResponse
    {
        $lead = CrmLead::findOrFail($id);

        $data = $request->validate([
            'lost_reason' => ['required', Rule::in(Crm::LOST_REASONS)],
            'lost_note' => ['nullable', 'string', 'max:300'],
        ], [], ['lost_reason' => __('سبب الخسارة')]);

        CrmLeads::lose($lead, $data['lost_reason'], $data['lost_note'] ?? null, $request->user());

        return back()->with('toast', ['msg' => __('سُجّلت الخسارة وسببُها'), 'type' => 'warning']);
    }

    public function reopen(Request $request, int $id): RedirectResponse
    {
        CrmLeads::reopen(CrmLead::findOrFail($id), $request->user());

        return back()->with('toast', ['msg' => __('أُعيد فتح العميل المحتمل'), 'type' => 'success']);
    }

    public function assign(Request $request, int $id): RedirectResponse
    {
        $lead = CrmLead::findOrFail($id);

        $data = $request->validate(['assigned_to' => ['nullable', 'integer', 'exists:users,id']]);

        $assignee = $data['assigned_to'] ? User::find($data['assigned_to']) : null;

        /*
         * ولا يُسنَد إلى موظّف متجر — والفحصُ هنا لا في `exists` وحدها.
         *
         * `exists:users,id` يشمل كلَّ كاشيرٍ في كلّ متجر. ورسالةُ تحقّقٍ
         * تُقرأ خيرٌ من استثناءٍ يرفعه `CrmLeads::assign` فيصير صفحةَ خمسمئة.
         */
        if ($assignee && ! $assignee->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'assigned_to' => __('لا يُسنَد العميل المحتمل إلا إلى موظّف منصّة.'),
            ]);
        }

        CrmLeads::assign($lead, $assignee, $request->user());

        return back()->with('toast', ['msg' => __('حُفظ الإسناد'), 'type' => 'success']);
    }

    public function note(Request $request, int $id): RedirectResponse
    {
        $lead = CrmLead::findOrFail($id);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        CrmLeads::note($lead, $request->user(), $data['body']);

        return back()->with('toast', ['msg' => __('أُضيفت الملاحظة'), 'type' => 'success']);
    }

    public function convert(Request $request, int $id): RedirectResponse
    {
        $lead = CrmLead::findOrFail($id);
        $data = $request->validate(['business_id' => ['required', 'integer', 'exists:businesses,id']]);

        $business = Business::findOrFail($data['business_id']);

        try {
            CrmLeads::convert($lead, $business, $request->user());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['business_id' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('رُبط بالمتجر :name', ['name' => $business->name]),
            'type' => 'success',
        ]);
    }

    /* ═══════════════════ المهامّ ═══════════════════ */

    public function storeTask(Request $request, int $id): RedirectResponse
    {
        $lead = CrmLead::findOrFail($id);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'due_at' => ['required', 'date'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['required', Rule::in(Crm::TASK_PRIORITIES)],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['due_at' => __('موعد الاستحقاق'), 'title' => __('عنوان المهمة')]);

        if ($data['assigned_to'] ?? null) {
            $assignee = User::find($data['assigned_to']);
            if (! $assignee || ! $assignee->isSuperAdmin()) {
                throw ValidationException::withMessages([
                    'assigned_to' => __('لا تُسنَد المهمة إلا إلى موظّف منصّة.'),
                ]);
            }
        }

        CrmTask::create([
            'lead_id' => $lead->id,
            'title' => $data['title'],
            'due_at' => $data['due_at'],
            'assigned_to' => $data['assigned_to'] ?? null,
            'created_by' => $request->user()->id,
            'priority' => $data['priority'],
            'notes' => $data['notes'] ?? null,
            'status' => 'open',
        ]);

        Activity::log('created', 'أضاف مهمّة على العميل المحتمل '.$lead->displayName());

        return back()->with('toast', ['msg' => __('أُضيفت المهمة'), 'type' => 'success']);
    }

    public function updateTask(Request $request, int $taskId): RedirectResponse
    {
        $task = CrmTask::findOrFail($taskId);

        $data = $request->validate(['status' => ['required', Rule::in(Crm::TASK_STATUSES)]]);

        $task->forceFill([
            'status' => $data['status'],
            'completed_at' => $data['status'] === 'done' ? now() : null,
            'completed_by' => $data['status'] === 'done' ? $request->user()->id : null,
        ])->save();

        Activity::log('status', 'غيّر حال مهمّة CRM إلى '.Crm::taskStatusLabel($data['status']));

        return back()->with('toast', ['msg' => __('حُفظت المهمة'), 'type' => 'success']);
    }

    /* ═══════════════════ الأدوات ═══════════════════ */

    /** @param array<string,string> $filters */
    private function filter($q, array $filters, User $user): void
    {
        if ($filters['q'] !== '') {
            $op = Search::like();
            $term = '%'.$filters['q'].'%';
            /* والرقمُ يُبحث به مطبَّعًا وخامًا معًا: من كتب «9123 4567» يجده */
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

        if ($filters['status'] !== 'all' && in_array($filters['status'], Crm::STATUSES, true)) {
            $q->where('status', $filters['status']);
        }

        if ($filters['source'] !== 'all' && in_array($filters['source'], Crm::SOURCES, true)) {
            $q->where('source', $filters['source']);
        }

        match ($filters['assignment']) {
            'mine' => $q->where('assigned_to', $user->id),
            'unassigned' => $q->whereNull('assigned_to'),
            'overdue' => $q->where('status', Crm::ACTIVE)
                ->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<', now()),
            default => null,
        };
    }

    /** @return array<string,int> */
    private function counts(User $user): array
    {
        return [
            'all' => CrmLead::count(),
            'active' => CrmLead::open()->count(),
            'mine' => CrmLead::open()->where('assigned_to', $user->id)->count(),
            'unassigned' => CrmLead::open()->whereNull('assigned_to')->count(),
            'overdue' => CrmLead::open()->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', now())->count(),
        ];
    }

    /** @return array<int, array{value:string,label:string}> */
    private function staff(): array
    {
        return User::where('role', 'super_admin')->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => ['value' => (string) $u->id, 'label' => $u->name])->all();
    }

    /** @return array<int, array{value:string,label:string}> */
    private function linkableBusinesses(CrmLead $lead): array
    {
        $taken = CrmLead::whereNotNull('converted_business_id')
            ->where('id', '!=', $lead->id)->pluck('converted_business_id')->all();

        return Business::real()->whereNotIn('id', $taken ?: [0])
            ->orderBy('name')->limit(500)->get(['id', 'name'])
            ->map(fn (Business $b) => ['value' => (string) $b->id, 'label' => $b->name])->all();
    }

    /** @return array<string, mixed> */
    private function myWork(User $user): array
    {
        return [
            'leads' => CrmLead::open()->where('assigned_to', $user->id)->count(),
            'tasks' => CrmTask::open()->where('assigned_to', $user->id)->count(),
            'overdue' => CrmTask::open()->where('assigned_to', $user->id)
                ->where('due_at', '<', now())->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(CrmLead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->displayName(),
            'businessName' => $lead->business_name,
            'phone' => $lead->phone_raw ?: $lead->phone,
            'stage' => $lead->stage,
            'stageLabel' => Crm::stageLabel($lead->stage),
            'stageTone' => Crm::stageTone($lead->stage),
            'status' => $lead->status,
            'statusLabel' => Crm::statusLabel($lead->status),
            'source' => $lead->source,
            'sourceLabel' => Crm::sourceLabel($lead->source),
            'assignee' => $lead->assignee?->name,
            'plan' => $lead->plan?->name,
            'lastContactAt' => optional($lead->last_contact_at)->format('Y-m-d H:i'),
            'nextFollowUpAt' => optional($lead->next_follow_up_at)->format('Y-m-d H:i'),
            'followUpOverdue' => $lead->followUpOverdue(),
            'preview' => $lead->notes_summary,
            'url' => route('super-admin.crm.leads.show', $lead->id),
        ];
    }

    /** @return array<string, mixed> */
    private function card(CrmLead $lead): array
    {
        return [
            'id' => $lead->id,
            'name' => $lead->displayName(),
            'businessName' => $lead->business_name,
            'source' => Crm::sourceLabel($lead->source),
            'assignee' => $lead->assignee?->name,
            'plan' => $lead->plan?->name,
            'expectedValue' => $lead->expected_value !== null ? (float) $lead->expected_value : null,
            'nextFollowUpAt' => optional($lead->next_follow_up_at)->format('Y-m-d H:i'),
            'followUpOverdue' => $lead->followUpOverdue(),
            'lastContactAt' => optional($lead->last_contact_at)->format('Y-m-d H:i'),
            'url' => route('super-admin.crm.leads.show', $lead->id),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(CrmLead $lead): array
    {
        return [
            ...$this->row($lead),
            'phoneRaw' => $lead->phone_raw,
            'phoneNormalized' => $lead->phone,
            'wilayat' => $lead->wilayat,
            'branchesCount' => $lead->branches_count,
            'currentSystem' => $lead->current_system,
            'planId' => $lead->interested_plan_id ? (string) $lead->interested_plan_id : null,
            'expectedValue' => $lead->expected_value !== null ? (float) $lead->expected_value : null,
            'assigneeId' => $lead->assigned_to ? (string) $lead->assigned_to : null,
            'assignedBy' => $lead->assigner?->name,
            'assignedAt' => optional($lead->assigned_at)->format('Y-m-d H:i'),
            'firstContactAt' => optional($lead->first_contact_at)->format('Y-m-d H:i'),
            'nextFollowUpRaw' => optional($lead->next_follow_up_at)->format('Y-m-d\TH:i'),
            'tags' => $lead->tags ?? [],
            'lostReason' => $lead->lost_reason,
            'lostReasonLabel' => $lead->lost_reason ? Crm::lostReasonLabel($lead->lost_reason) : null,
            'lostNote' => $lead->lost_note,
            'convertedAt' => optional($lead->converted_at)->format('Y-m-d H:i'),
            'business' => $lead->business ? [
                'id' => $lead->business->id,
                'name' => $lead->business->name,
                'url' => route('super-admin.businesses.show', $lead->business->id),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function taskRow(CrmTask $task, bool $withLead = false): array
    {
        $out = [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'statusLabel' => Crm::taskStatusLabel($task->status),
            'priority' => $task->priority,
            'priorityLabel' => Crm::taskPriorityLabel($task->priority),
            'assignee' => $task->assignee?->name,
            'dueAt' => optional($task->due_at)->format('Y-m-d H:i'),
            'overdue' => $task->overdue(),
            'notes' => $task->notes,
        ];

        if ($withLead) {
            $out['lead'] = $task->lead ? [
                'id' => $task->lead->id,
                'name' => $task->lead->displayName(),
                'stage' => Crm::stageLabel($task->lead->stage),
                'url' => route('super-admin.crm.leads.show', $task->lead->id),
            ] : null;
        }

        return $out;
    }
}
