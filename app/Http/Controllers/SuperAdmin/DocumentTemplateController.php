<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Models\PlatformDocumentTemplateVersion;
use App\Support\Activity;
use App\Support\DocumentForms;
use App\Support\PlatformDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * قوالبُ المستندات — يحرّرها مديرُ المنصّة كلَّها من الشاشة: أسماءها وعناوينها
 * ورسومها، وبنودَها أو نموذجَها بأقسامه وحقوله وقوائمه وجداوله (`DocumentForms`).
 *
 * وتعديلُ القالب لا يمسّ مستندًا صدر منه: المستندُ نسخةٌ ساعةَ إصداره. ولا
 * يُحذف قالب: مستنداتٌ صدرت منه تشير إليه.
 *
 * ═══ الحفظ والإصدارات ═══
 *
 * ما يُحفظ يُصدر منه فورًا — لا اعتمادَ ولا نشرَ ولا تفعيل. فلا يُحفظ قالبٌ فيه
 * خطأ (`PlatformDocumentTemplate::problems`): يُردّ بما هو ليُصحَّح. وتعديلُ ما يصل
 * إلى المستند (نصُّه ورسومُه وقاعدةُ مبلغه) إصدارٌ جديد يُحفظ في سجلّه ببصمته ومن
 * حفظه ومتى. ويُعاين صفحةَ توقيعٍ وورقةً كما يراهما العميل، بلا مستندٍ يُكتب.
 * ولا يُرسل الحفظُ شيئًا لأحد.
 */
class DocumentTemplateController extends Controller
{
    public function index(): Response
    {
        $provider = PlatformDocuments::provider();

        return Inertia::render('Platform/Documents/Templates', [
            'templates' => PlatformDocumentTemplate::withCount('documents')->with('versions')->orderBy('id')->get()
                ->map(fn (PlatformDocumentTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'service' => $t->service,
                    'title' => $t->title,
                    'title_en' => $t->title_en,
                    'terms' => $t->terms ?? [],
                    // النموذجُ كما يُحرَّر — برموزه (`{fee}`، `{provider}`) تُملأ ساعةَ الإصدار
                    'form' => $t->form ?: null,
                    'default_amount' => $t->default_amount === null ? null : (string) $t->default_amount,
                    'default_amount_label' => $t->default_amount_label,
                    'amount_rule' => $t->amount_rule,
                    'includes_plan' => $t->includes_plan,
                    'includes_amount' => $t->includes_amount,
                    'documents_count' => $t->documents_count,
                    'version' => $t->version,
                    'hash' => $t->contentHash(),
                    // ما يمنع الإصدارَ منه — قالبٌ قديمٌ ناقص يُصحَّح من هنا
                    'problems' => $t->problems(),
                    'preview_url' => route('super-admin.documents.templates.preview', $t->id),
                    'preview_pdf_url' => route('super-admin.documents.templates.preview-pdf', $t->id),
                    'versions' => $t->versions->map(fn (PlatformDocumentTemplateVersion $v) => [
                        'version' => $v->version,
                        'hash' => substr($v->content_hash, 0, 12),
                        'saved_at' => PlatformDocuments::date($v->created_at),
                        'saved_by' => $v->saved_by_name,
                    ])->all(),
                ]),
            'provider' => $provider,
            'providerLine' => PlatformDocuments::providerLine($provider),
            'providerMissing' => PlatformDocuments::providerMissing($provider),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // القالبُ الجديد يُصدر منه فور حفظه — وما فيه خطأٌ لا يُحفظ
        $template = new PlatformDocumentTemplate(['version' => 1] + $this->validated($request, $request->input('form') !== null));
        $this->refuseProblems($template);
        $template->save();
        $template->recordVersion($request->user());
        Activity::log('created', 'أضاف قالب مستند: '.$template->name, ['business_id' => null, 'subject_id' => $template->id, 'subject_type' => PlatformDocumentTemplate::class]);

        return back()->with('toast', ['msg' => __('أُضيف القالب — يُصدر منه الآن'), 'type' => 'success']);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $template = PlatformDocumentTemplate::findOrFail($id);
        $data = $this->validated($request, $template->form !== null);
        // نموذجٌ لم يتغيّر بعد التنظيف يبقى كما حُفظ — فلا يصير إصدارًا جديدًا بترتيب مفاتيحه
        if (array_key_exists('form', $data) && DocumentForms::same($template->form, $data['form'])) {
            $data['form'] = $template->form;
        }
        $before = $template->contentHash();

        $template->fill($data);
        $this->refuseProblems($template);
        $changed = $template->contentHash() !== $before;
        if ($changed) {
            // نصٌّ جديد إصدارٌ جديد يُصدر منه فورًا — وما قبله باقٍ في سجلّه وفي مستنداته
            $template->forceFill(['version' => $template->version + 1]);
        }
        $template->save();
        if ($changed) {
            $template->recordVersion($request->user());
        }

        Activity::log('updated', ($changed ? 'عدّل نص قالب مستند — صار الإصدار '.$template->version.': ' : 'عدّل قالب مستند: ').$template->name, [
            'business_id' => null, 'subject_id' => $template->id, 'subject_type' => PlatformDocumentTemplate::class,
        ]);

        return back()->with('toast', [
            'msg' => $changed
                ? __('حُفظ الإصدار :v — يُصدر منه الآن. المستندات التي صدرت لا تتغيّر', ['v' => $template->version])
                : __('حُفظ القالب — المستندات التي صدرت منه لا تتغيّر'),
            'type' => 'success',
        ]);
    }

    /** صفحةُ التوقيع كما يراها العميل — من الإصدار الحاليّ، بلا مستندٍ يُكتب ولا توقيعٍ يُرسل */
    public function preview(int $id): HttpResponse
    {
        $doc = $this->sample(PlatformDocumentTemplate::findOrFail($id));
        $form = $doc->form ?: null;

        return response()->view('public.sign', [
            'doc' => $doc,
            'token' => str_repeat('0', 32),
            'readable' => true,
            'preview' => true,
            'provider' => PlatformDocuments::providerOf($doc),
            'fields' => PlatformDocuments::FIELDS,
            'consent' => PlatformDocuments::CONSENT,
            'signature' => null,
            'form' => $form,
            'team' => [],
            'answers' => [],
            'asksClient' => DocumentForms::asksClient($form),
            'amountInForm' => DocumentForms::showsAmount($form),
        ])->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** ورقةُ PDF كما تُطبع — من الإصدار الحاليّ، موسومةً «معاينة» */
    public function previewPdf(int $id): HttpResponse
    {
        $response = PlatformDocuments::pdf($this->sample(PlatformDocumentTemplate::findOrFail($id)), true);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * مستندٌ في الذاكرة من الإصدار الحاليّ — لا يُحفظ.
     *
     * برسوم القالب ومقدّم الخدمة اليوم كما لو صدر الآن. وما يُكتب عند الإصدار
     * (بيانُ العميل) فارغ، والباقةُ أرخصُ باقةٍ شهريّةً مثالًا على مكانها.
     */
    private function sample(PlatformDocumentTemplate $t): PlatformDocument
    {
        $provider = PlatformDocuments::provider();
        $plan = $t->includes_plan && ($p = Plan::orderBy('monthly_price')->first()) ? PlatformDocuments::planSnapshot($p, 'monthly') : null;
        $start = now()->startOfDay();
        $doc = new PlatformDocument;
        $doc->forceFill([
            'number' => __('معاينة'),
            'service' => $t->service,
            'title' => $t->title,
            'title_en' => $t->title_en,
            'terms' => array_values($t->terms ?? []),
            'form' => DocumentForms::render($t->form ?: null, [
                'fee' => $t->default_amount === null ? null : (string) $t->default_amount,
                'provider' => PlatformDocuments::providerLine($provider),
            ]),
            'plan' => $plan,
            'term_starts_on' => $plan ? $start->toDateString() : null,
            'term_ends_on' => $plan ? PlatformDocuments::termEnd($start, $plan['cycle'])->toDateString() : null,
            'amount' => $t->includes_amount ? $t->default_amount : null,
            'amount_label' => $t->includes_amount ? ($t->default_amount_label ?: $t->name) : null,
            'provider' => $provider,
            'template_version' => $t->version,
            'client' => [],
            'status' => PlatformDocument::SENT,
            'expires_at' => now()->addDays(PlatformDocuments::DEFAULT_DAYS)->endOfDay(),
        ]);
        $doc->created_at = now();

        return $doc;
    }

    /**
     * ما يمنع الإصدارَ من القالب يمنع حفظَه — فكلُّ ما يُحفظ يُصدر منه. ويُردّ بعباراته
     * ليُصحَّح: الرسومُ عند خانتها، والباقي فوق النموذج.
     */
    private function refuseProblems(PlatformDocumentTemplate $template): void
    {
        if ($template->needsFee() && $template->default_amount === null) {
            throw ValidationException::withMessages(['default_amount' => __('اكتب رسوم القالب — نصّه يذكرها.')]);
        }
        if ($problems = $template->problems()) {
            throw ValidationException::withMessages([($template->form ? 'form' : 'terms') => implode("\n", $problems)]);
        }
    }

    private function validated(Request $request, bool $isForm = false): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'service' => ['nullable', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:120'],
            'title_en' => ['nullable', 'string', 'max:120'],
            'terms' => $isForm ? ['nullable', 'array', 'max:40'] : ['required', 'array', 'min:1', 'max:40'],
            'terms.*.h' => ['nullable', 'string', 'max:120'],
            // ولقالب النموذج لا بنود: ما وصل منها يُترك (انظر أسفل) ولا يردّ الحفظ
            'terms.*.b' => $isForm ? ['nullable'] : ['required', 'string', 'max:3000'],
            'form' => $isForm ? ['required', 'array'] : ['nullable', 'prohibited'],
            'includes_plan' => ['boolean'],
            'includes_amount' => ['boolean'],
            'default_amount' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:1000000'],
            'default_amount_label' => ['nullable', 'string', 'max:190'],
            'amount_rule' => ['nullable', Rule::in([PlatformDocumentTemplate::AMOUNT_FIXED, PlatformDocumentTemplate::AMOUNT_MIN])],
        ], [], [
            'name' => __('اسم القالب'),
            'service' => __('الخدمة'),
            'title' => __('عنوان المستند'),
            'terms' => __('البنود'),
            'terms.*.b' => __('نص البند'),
            'form' => __('النموذج'),
            'default_amount' => __('المبلغ المقترح'),
            'amount_rule' => __('قاعدة المبلغ'),
        ]);

        // قاعدةُ مبلغٍ بلا رسوم: وعدٌ بمبلغٍ لا يُعرف
        if (($data['amount_rule'] ?? null) !== null && ($data['default_amount'] ?? null) === null) {
            throw ValidationException::withMessages(['default_amount' => __('اكتب الرسوم — قاعدة المبلغ تحتاجها.')]);
        }

        // النموذجُ بأقسامه وحقوله: يُنظَّف ثمّ يُفحص — ما تقوم عليه صفحةُ التوقيع وورقتُها
        if ($isForm) {
            $data['form'] = DocumentForms::normalize((array) $data['form']);
            if ($problems = DocumentForms::problems($data['form'])) {
                throw ValidationException::withMessages(['form' => implode("\n", $problems)]);
            }
            $data['terms'] = [];

            return $data;
        }
        unset($data['form']);

        $data['terms'] = array_values(array_map(fn ($t) => [
            'h' => trim((string) ($t['h'] ?? '')),
            'b' => trim((string) $t['b']),
        ], $data['terms']));

        return $data;
    }
}
