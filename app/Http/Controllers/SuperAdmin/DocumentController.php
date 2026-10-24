<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Support\Activity;
use App\Support\Pagination;
use App\Support\PlatformDocuments;
use App\Support\Search;
use App\Support\SigningCodes;
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
 * الخادم وحدها. انظر `PlatformDocuments`.
 */
class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $state = (string) $request->query('state', '');
        $q = Search::term($request);

        $query = PlatformDocument::query()->latest('id');
        match ($state) {
            PlatformDocument::SIGNED, PlatformDocument::CANCELLED => $query->where('status', $state),
            PlatformDocument::SENT => $query->where('status', PlatformDocument::SENT)
                ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now())),
            'expired' => $query->where('status', PlatformDocument::SENT)->where('expires_at', '<', now()),
            default => null,
        };
        if ($q !== '') {
            $like = Search::like();
            $query->where(fn ($w) => $w->where('number', $like, "%{$q}%")->orWhere('client_label', $like, "%{$q}%")->orWhere('title', $like, "%{$q}%"));
        }
        $page = $query->paginate(20)->withQueryString();

        return Inertia::render('Platform/Documents/Index', [
            'documents' => collect($page->items())->map(fn (PlatformDocument $d) => self::row($d))->all(),
            'pagination' => Pagination::meta($page),
            'filters' => ['state' => $state, 'q' => $q],
            // بلا مزوّدٍ يوصل رمزَ التحقّق يبقى التوقيعُ مغلقًا — والمديرُ يُقال له قبل أن يرسل
            'signing_ready' => SigningCodes::available(),
            'counts' => [
                'sent' => PlatformDocument::where('status', PlatformDocument::SENT)
                    ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now()))->count(),
                'signed' => PlatformDocument::where('status', PlatformDocument::SIGNED)->count(),
            ],
            'templates' => PlatformDocumentTemplate::where('active', true)->orderBy('id')
                ->get(['id', 'name', 'service', 'includes_plan', 'includes_amount']),
            'plans' => Plan::orderBy('monthly_price')->get()->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'prices' => [
                    'monthly' => PlatformDocuments::planSnapshot($p, 'monthly')['price'],
                    'semiannual' => PlatformDocuments::planSnapshot($p, 'semiannual')['price'],
                    'yearly' => PlatformDocuments::planSnapshot($p, 'yearly')['price'],
                ],
            ]),
            'cycles' => collect(PlatformDocuments::CYCLES)->map(fn ($c, $k) => ['value' => $k, 'label' => $c['label']])->values(),
            'businesses' => Business::orderBy('name')->limit(1000)->get(['id', 'name', 'plan_id', 'owner_name', 'phone'])
                ->map(fn (Business $b) => ['id' => $b->id, 'name' => $b->name, 'plan_id' => $b->plan_id, 'owner' => $b->owner_name, 'phone' => $b->phone]),
            'days' => PlatformDocuments::DEFAULT_DAYS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer', Rule::exists('platform_document_templates', 'id')->where('active', true)],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'client' => ['nullable', 'array'],
            'client.*' => ['nullable', 'string', 'max:190'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'cycle' => ['nullable', Rule::in(array_keys(PlatformDocuments::CYCLES))],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'amount_label' => ['nullable', 'string', 'max:190'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'template_id' => __('نوع المستند'),
            'business_id' => __('النشاط'),
            'client' => __('بيانات العميل'),
            'plan_id' => __('الباقة'),
            'cycle' => __('دورة الاشتراك'),
            'amount' => __('المبلغ'),
            'days' => __('صلاحية الرابط'),
        ]);

        try {
            $doc = PlatformDocuments::issue($request->user(), $data);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['document' => $e->getMessage()]);
        }

        return redirect()->route('super-admin.documents.show', $doc->id)
            ->with('toast', ['msg' => __('أُصدر المستند — انسخ الرابط وأرسله للعميل'), 'type' => 'success']);
    }

    public function show(int $id): Response
    {
        $doc = PlatformDocument::with('business:id,name')->findOrFail($id);
        $link = route('documents.sign', $doc->token);
        // الرابطُ إلى الرقم المعتمد نفسه — إليه يصل الرمز
        $phone = (string) $doc->signing_phone;

        return Inertia::render('Platform/Documents/Show', [
            'document' => self::row($doc) + [
                'title_en' => $doc->title_en,
                'terms' => $doc->terms,
                'plan' => $doc->plan,
                'amount' => $doc->amount === null ? null : (string) $doc->amount,
                'amount_label' => $doc->amount_label,
                'client' => $doc->client ?? [],
                'notes' => $doc->notes,
                'created_by' => $doc->created_by_name,
                'viewed_at' => PlatformDocuments::date($doc->viewed_at),
                'cancelled_at' => PlatformDocuments::date($doc->cancelled_at),
                'cancel_reason' => $doc->cancel_reason,
                'signer_ip' => $doc->signer_ip,
                'signer_agent' => $doc->signer_agent,
                'signing_phone' => $doc->signing_phone ? '+'.$doc->signing_phone : null,
                'phone_verified_at' => $doc->phone_verified_at ? PlatformDocuments::date($doc->phone_verified_at) : null,
                'signing_ready' => SigningCodes::available(),
                'content_hash' => $doc->content_hash,
                'intact' => $doc->status === PlatformDocument::SIGNED ? PlatformDocuments::intact($doc) : null,
                'signature' => PlatformDocuments::signatureDataUri($doc),
                'link' => $link,
                // واتساب يُفتح من جهاز المدير برسالةٍ جاهزة — هو من يضغط «إرسال»
                'whatsapp' => 'https://wa.me/'.$phone.'?text='.rawurlencode(__('مرحبًا، هذا رابط :title من أبعاد للمراجعة والتوقيع:', ['title' => $doc->title]).' '.$link),
            ],
            'fields' => PlatformDocuments::FIELDS,
        ]);
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
        // ورمزٌ أُرسل أو إذنٌ في جلسةٍ لا يبقيان بعد الإلغاء
        SigningCodes::revoke($doc);

        Activity::log('updated', 'ألغى رابط المستند: '.$doc->number, ['business_id' => null, 'subject_id' => $doc->id, 'subject_type' => PlatformDocument::class]);

        return back()->with('toast', ['msg' => __('أُلغي المستند — الرابط لم يعد يقبل التوقيع'), 'type' => 'success']);
    }

    public function pdf(int $id)
    {
        return PlatformDocuments::pdf(PlatformDocument::findOrFail($id));
    }

    /** صفُّ المستند في القائمة وفي رأس صفحته */
    public static function row(PlatformDocument $d): array
    {
        $state = $d->state();

        return [
            'id' => $d->id,
            'url' => route('super-admin.documents.show', $d->id),
            'number' => $d->number,
            'title' => $d->title,
            'service' => $d->service,
            'client_name' => $d->client['name'] ?? null,
            'client_biz' => $d->client['biz'] ?? null,
            'business' => $d->client_business_id ? ['id' => $d->client_business_id, 'name' => $d->business?->name] : null,
            'state' => $state,
            'state_label' => PlatformDocument::LABELS[$state] ?? $state,
            'created_at' => PlatformDocuments::date($d->created_at),
            'expires_at' => PlatformDocuments::date($d->expires_at),
            'signed_at' => PlatformDocuments::date($d->signed_at),
        ];
    }
}
