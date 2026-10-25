<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Support\Activity;
use App\Support\Billing;
use App\Support\DocumentForms;
use App\Support\Pagination;
use App\Support\PlatformDocuments;
use App\Support\Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * قسم المستندات — يُصدر مديرُ المنصّة لكلّ عميلٍ مستنداتِ خدمته، ويرسل رابطها.
 *
 * والرابطُ يُرسله المديرُ بنفسه (نسخٌ أو واتساب من جهازه): لا رسالةَ تخرج من
 * الخادم وحدها، ولا مزوّدَ رسائلَ مدفوع. والشاشتان تستطلعان الخادمَ ما دام فيهما
 * ما ينتظر التوقيع، فيظهر التوقيعُ عند المدير دون أن يعيد تحميل شيء.
 * انظر `PlatformDocuments`.
 */
class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $state = (string) $request->query('state', '');
        $q = Search::term($request);
        // مستنداتُ شركةٍ واحدة (?business=) — من ملفّها؛ ونافذةُ الإصدار تبدأ بها
        $company = $request->filled('business') ? Business::find((int) $request->query('business'), ['id', 'name']) : null;

        $query = PlatformDocument::query()->with('business:id,name')->latest('id');
        if ($company) {
            $query->where('client_business_id', $company->id);
        }
        match ($state) {
            PlatformDocument::SIGNED, PlatformDocument::CANCELLED => $query->where('status', $state),
            PlatformDocument::SENT => $query->where('status', PlatformDocument::SENT)
                ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now())),
            'expired' => $query->where('status', PlatformDocument::SENT)->where('expires_at', '<', now()),
            'renewal' => $query->whereNotNull('renews_document_id'),
            default => null,
        };
        if ($q !== '') {
            $like = Search::like();
            $query->where(fn ($w) => $w->where('number', $like, "%{$q}%")->orWhere('client_label', $like, "%{$q}%")->orWhere('title', $like, "%{$q}%"));
        }
        $page = $query->paginate(20)->withQueryString();

        // عقدُ تجديدٍ يُفتح من عقدٍ موقَّع (?renew=) — نافذةُ الإصدار تبدأ ببيانه
        $renew = $request->filled('renew')
            ? PlatformDocument::whereKey((int) $request->query('renew'))->where('status', PlatformDocument::SIGNED)->first()
            : null;
        // ما يُصدر منه وحده: مفعّلٌ ونصُّه هو المعتمد — والنموذجُ كما سيُكتب في المستند
        $line = PlatformDocuments::providerLine(PlatformDocuments::provider());
        $all = PlatformDocumentTemplate::orderBy('id')->get();
        // وما لا يُصدر منه بعدُ يُعرض باسمه وسببه — لا يختفي فيُظنّ مفقودًا
        $waiting = $all->reject(fn (PlatformDocumentTemplate $t) => $t->issuable())
            ->map(fn (PlatformDocumentTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'service' => $t->service,
                'reason' => $t->approved() ? __('معتمد — غير مفعّل') : __('بانتظار الاعتماد'),
            ])->values();
        $templates = $all->filter(fn (PlatformDocumentTemplate $t) => $t->issuable())
            ->map(fn (PlatformDocumentTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'service' => $t->service,
                'title' => $t->title,
                'terms' => $t->terms ?? [],
                'form' => DocumentForms::render($t->form, ['fee' => $t->default_amount, 'provider' => $line]),
                'includes_plan' => $t->includes_plan,
                'includes_amount' => $t->includes_amount,
                'default_amount' => $t->default_amount === null ? null : (string) $t->default_amount,
                'default_amount_label' => $t->default_amount_label,
                'amount_rule' => $t->amount_rule,
            ])->values();

        return Inertia::render('Platform/Documents/Index', [
            'documents' => collect($page->items())->map(fn (PlatformDocument $d) => self::row($d))->all(),
            'pagination' => Pagination::meta($page),
            'filters' => ['state' => $state, 'q' => $q] + ($company ? ['business' => (string) $company->id] : []),
            'company' => $company ? ['id' => $company->id, 'name' => $company->name, 'url' => route('super-admin.businesses.show', $company->id)] : null,
            'opening' => $company !== null && $request->boolean('new'),
            'counts' => [
                'sent' => PlatformDocument::where('status', PlatformDocument::SENT)
                    ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now()))->count(),
                'signed' => PlatformDocument::where('status', PlatformDocument::SIGNED)->count(),
            ],
            'templates' => $templates,
            'waiting' => $waiting,
            'plans' => Plan::orderBy('monthly_price')->get()->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'prices' => [
                    'monthly' => PlatformDocuments::planSnapshot($p, 'monthly')['price'],
                    'semiannual' => PlatformDocuments::planSnapshot($p, 'semiannual')['price'],
                    'yearly' => PlatformDocuments::planSnapshot($p, 'yearly')['price'],
                ],
            ]),
            'cycles' => collect(PlatformDocuments::CYCLES)
                ->map(fn ($c, $k) => ['value' => $k, 'label' => $c['label'], 'months' => $c['months'], 'duration' => $c['duration']])->values(),
            'businesses' => Business::orderBy('name')->limit(1000)->get(['id', 'name', 'plan_id', 'owner_name', 'phone', 'ends_at'])
                ->map(fn (Business $b) => [
                    'id' => $b->id, 'name' => $b->name, 'plan_id' => $b->plan_id, 'owner' => $b->owner_name, 'phone' => $b->phone,
                    // بدايةُ مدّةٍ جديدة كما يبدؤها سدادُ التجديد
                    'starts_on' => PlatformDocuments::defaultStart($b)->toDateString(),
                ]),
            'today' => now()->toDateString(),
            'days' => PlatformDocuments::DEFAULT_DAYS,
            'renew' => $renew ? [
                'id' => $renew->id,
                'number' => $renew->number,
                'client' => $renew->client_label,
                'phone' => $renew->client['phone'] ?? '',
                'template_id' => $templates->firstWhere('id', $renew->template_id)['id'] ?? $templates->firstWhere('includes_plan', true)['id'] ?? null,
                'plan_id' => $renew->plan['plan_id'] ?? null,
                'cycle' => $renew->plan['cycle'] ?? 'monthly',
                'starts_on' => $renew->term_ends_on?->toDateString(),
            ] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer', Rule::exists('platform_document_templates', 'id')->where('active', true)],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'renews_document_id' => ['nullable', 'integer', 'exists:platform_documents,id'],
            'client' => ['nullable', 'array'],
            'client.*' => ['nullable', 'string', 'max:190'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'cycle' => ['nullable', Rule::in(array_keys(PlatformDocuments::CYCLES))],
            'term_starts_on' => ['nullable', 'date'],
            'term_ends_on' => ['nullable', 'date', 'after:term_starts_on'],
            'amount' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:1000000'],
            'amount_label' => ['nullable', 'string', 'max:190'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'team' => ['nullable', 'array'],
        ], [], [
            'template_id' => __('نوع المستند'),
            'business_id' => __('النشاط'),
            'renews_document_id' => __('العقد المجدَّد'),
            'client' => __('بيانات العميل'),
            'plan_id' => __('الباقة'),
            'cycle' => __('دورة الاشتراك'),
            'term_starts_on' => __('بداية الاشتراك'),
            'term_ends_on' => __('نهاية الاشتراك'),
            'amount' => __('المبلغ'),
            'days' => __('صلاحية الرابط'),
            'team' => __('حقول فريق أبعاد'),
        ]);

        try {
            $doc = PlatformDocuments::issue($request->user(), $data);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['document' => $e->getMessage()]);
        }

        return redirect()->route('super-admin.documents.show', $doc->id)
            ->with('toast', ['msg' => __('أُصدر المستند — انسخ الرابط أو أرسله عبر واتساب'), 'type' => 'success']);
    }

    public function show(int $id): Response
    {
        $doc = PlatformDocument::with(['business:id,name,plan_id,starts_at,ends_at,status', 'business.plan:id,name', 'renews:id,number', 'renewals'])
            ->findOrFail($id);
        $link = route('documents.sign', $doc->token);
        $business = $doc->business;
        $pending = $business ? Billing::pendingRenewal($business) : null;
        $openRenewal = $doc->renewals->first(fn (PlatformDocument $r) => $r->status === PlatformDocument::SIGNED || $r->signable());

        return Inertia::render('Platform/Documents/Show', [
            'document' => self::row($doc) + [
                'title_en' => $doc->title_en,
                'template_version' => $doc->template_version,
                'terms' => $doc->terms,
                'form' => $doc->form,
                'team' => $doc->team ?? [],
                'answers' => $doc->answers ?? [],
                'plan' => $doc->plan,
                'term_starts_on' => PlatformDocuments::day($doc->term_starts_on),
                'term_ends_on' => PlatformDocuments::day($doc->term_ends_on),
                'amount' => $doc->amount === null ? null : (string) $doc->amount,
                'amount_label' => $doc->amount_label,
                'client' => $doc->client ?? [],
                'client_phone' => $doc->client_phone ? '+'.$doc->client_phone : null,
                'notes' => $doc->notes,
                'created_by' => $doc->created_by_name,
                'viewed_at' => PlatformDocuments::date($doc->viewed_at),
                'cancelled_at' => PlatformDocuments::date($doc->cancelled_at),
                'cancel_reason' => $doc->cancel_reason,
                'signer_ip' => $doc->signer_ip,
                'signer_agent' => $doc->signer_agent,
                'consent_text' => $doc->consent_text,
                'agreed_at' => $doc->agreed_at ? PlatformDocuments::date($doc->agreed_at) : null,
                'content_hash' => $doc->content_hash,
                'pdf_hash' => $doc->pdf_hash,
                'intact' => $doc->status === PlatformDocument::SIGNED ? PlatformDocuments::intact($doc) : null,
                'signature' => PlatformDocuments::signatureDataUri($doc),
                'link' => $link,
                // واتساب يُفتح من جهاز المدير برسالةٍ جاهزة — هو من يضغط «إرسال»
                'whatsapp' => 'https://wa.me/'.$doc->client_phone.'?text='.rawurlencode(PlatformDocuments::whatsappText($doc, $link)),
                'renews' => $doc->renews ? ['id' => $doc->renews->id, 'number' => $doc->renews->number, 'url' => route('super-admin.documents.show', $doc->renews->id)] : null,
                'renewals' => $doc->renewals->map(fn (PlatformDocument $r) => [
                    'id' => $r->id, 'number' => $r->number, 'url' => route('super-admin.documents.show', $r->id),
                    'state_label' => PlatformDocument::LABELS[$r->state()] ?? $r->state(),
                ])->values(),
                'can_renew' => $doc->status === PlatformDocument::SIGNED && $doc->plan !== null && ! $openRenewal,
                // الاشتراكُ كما هو في المنصّة — العقدُ يقرؤه ولا يكتبه
                'subscription' => $business ? [
                    'plan' => $business->plan?->name,
                    'starts_at' => PlatformDocuments::day($business->starts_at),
                    'ends_at' => PlatformDocuments::day($business->ends_at),
                    'status' => $business->status,
                    'pending_invoice' => $pending?->invoice?->number,
                    'pending_amount' => $pending ? number_format((float) $pending->amount, 3, '.', '') : null,
                    'pending_payment' => $pending?->payment_status,
                ] : null,
            ],
            'fields' => PlatformDocuments::FIELDS,
        ]);
    }

    /** المديرُ نسخ الرابطَ أو فتح به واتساب — يُسجَّل فعلُه، لا وصولُ الرسالة */
    public function shared(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['via' => ['required', Rule::in(array_keys(PlatformDocument::SHARED))]], [], ['via' => __('طريقة المشاركة')]);
        PlatformDocuments::share(PlatformDocument::findOrFail($id), $data['via']);

        return back();
    }

    public function cancel(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:190']], [], ['reason' => __('السبب')]);
        $doc = PlatformDocument::findOrFail($id);

        // الموقَّعُ لا يُلغى من هنا: هو سجلٌّ لما اتُّفق عليه
        $changed = PlatformDocument::whereKey($doc->id)->where('status', PlatformDocument::SENT)->update([
            'status' => PlatformDocument::CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => trim((string) ($data['reason'] ?? '')) ?: null,
            'updated_at' => now(),
        ]);
        if (! $changed) {
            return back()->withErrors(['document' => __('لا يُلغى إلا مستندٌ لم يُوقَّع بعد.')]);
        }

        Activity::log('updated', 'ألغى رابط المستند: '.$doc->number, ['business_id' => null, 'subject_id' => $doc->id, 'subject_type' => PlatformDocument::class]);

        return back()->with('toast', ['msg' => __('أُلغي المستند — الرابط لم يعد يقبل التوقيع'), 'type' => 'success']);
    }

    /** الورقة — المحفوظةُ للموقَّع، والمرسومةُ الآن لما لم يُوقَّع */
    public function pdf(int $id)
    {
        return PlatformDocuments::paper(PlatformDocument::findOrFail($id));
    }

    /** صفُّ المستند في القائمة وفي رأس صفحته */
    public static function row(PlatformDocument $d): array
    {
        $state = $d->state();

        return [
            'id' => $d->id,
            'url' => route('super-admin.documents.show', $d->id),
            'pdf_url' => route('super-admin.documents.pdf', $d->id),
            'number' => $d->number,
            'title' => $d->title,
            'service' => $d->service,
            'client_name' => $d->client['name'] ?? null,
            'client_biz' => $d->client['biz'] ?? null,
            'business' => $d->client_business_id ? ['id' => $d->client_business_id, 'name' => $d->business?->name] : null,
            'plan_name' => $d->plan['name'] ?? null,
            'cycle_label' => $d->plan['cycle_label'] ?? null,
            // قيمةُ العقد: سعرُ دورة الباقة، أو مبلغُ الخدمة، أو كلاهما
            'value' => $d->plan ? $d->plan['price'] : ($d->amount === null ? null : number_format((float) $d->amount, 3, '.', '')),
            'value_unit' => $d->plan['unit'] ?? null,
            'renewal' => $d->renews_document_id !== null,
            'shared' => $d->shared_via ? (PlatformDocument::SHARED[$d->shared_via] ?? $d->shared_via) : null,
            'shared_at' => $d->shared_at ? PlatformDocuments::date($d->shared_at) : null,
            'state' => $state,
            'state_label' => PlatformDocument::LABELS[$state] ?? $state,
            'created_at' => PlatformDocuments::date($d->created_at),
            'expires_at' => PlatformDocuments::date($d->expires_at),
            'signed_at' => PlatformDocuments::date($d->signed_at),
        ];
    }
}
