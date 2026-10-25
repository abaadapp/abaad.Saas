<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformDocumentTemplate;
use App\Support\Activity;
use App\Support\DocumentForms;
use App\Support\PlatformDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * قوالبُ المستندات — قالبٌ لكلّ خدمة، يكتب المديرُ عنوانَه وبنودَه.
 *
 * وتعديلُ القالب لا يمسّ مستندًا صدر منه: المستندُ نسخةٌ ساعةَ إصداره. ولا
 * يُحذف قالب، يُعطَّل: مستنداتٌ صدرت منه تشير إليه.
 *
 * وقالبُ النموذج (اتفاقياتُ المالك، انظر `DocumentForms`) يُعدَّل اسمُه وعنوانُه
 * ورسومُه وتفعيلُه، ونموذجُه وقاعدةُ مبلغه كما صُمِّما — لا تمسّهما هذه الشاشة.
 *
 * ═══ الاعتماد ═══
 *
 * القالبُ الجديد مسوّدةٌ معطَّلة. ويعتمده مديرُ المنصّة بكلمة مروره على بصمة ما
 * رآه (`hash`): فإن تغيّر القالبُ بين فتحه وضغطه رُدّ الاعتماد. ولا يُفعَّل قبل
 * اعتماده. وبياناتُ مقدّم الخدمة (السجلّ والعنوان) لا تمنع الاعتماد: ما لم يُكتب منها
 * لا يُطبع، والقرارُ للمالك. وتعديلُ ما يُعتمد (نصُّه
 * ورسومُه وقاعدةُ مبلغه) إصدارٌ جديدٌ يُعطَّل حتى يُعتمد.
 */
class DocumentTemplateController extends Controller
{
    public function index(): Response
    {
        $provider = PlatformDocuments::provider();

        return Inertia::render('Platform/Documents/Templates', [
            'templates' => PlatformDocumentTemplate::withCount('documents')->orderBy('id')->get()
                ->map(fn (PlatformDocumentTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'service' => $t->service,
                    'title' => $t->title,
                    'title_en' => $t->title_en,
                    'terms' => $t->terms ?? [],
                    // النموذجُ كما سيُكتب في المستند لو صدر اليوم: برسومه ومقدّم خدمته
                    'form' => DocumentForms::render($t->form, ['fee' => $t->default_amount, 'provider' => PlatformDocuments::providerLine($provider)]),
                    'default_amount' => $t->default_amount === null ? null : (string) $t->default_amount,
                    'default_amount_label' => $t->default_amount_label,
                    'amount_rule' => $t->amount_rule,
                    'includes_plan' => $t->includes_plan,
                    'includes_amount' => $t->includes_amount,
                    'active' => $t->active,
                    'documents_count' => $t->documents_count,
                    'version' => $t->version,
                    'approval' => $t->approvalState(),
                    'approved_at' => $t->approved_at ? PlatformDocuments::date($t->approved_at) : null,
                    'approved_by' => $t->approved_by_name,
                    'hash' => $t->contentHash(),
                ]),
            'provider' => $provider,
            'providerMissing' => PlatformDocuments::providerMissing($provider),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // القالبُ الجديد مسوّدة: لا يُفعَّل قبل أن يُعتمد
        $template = PlatformDocumentTemplate::create(['active' => false, 'version' => 1] + $this->validated($request));
        Activity::log('created', 'أضاف قالب مستند (مسوّدة): '.$template->name, ['business_id' => null, 'subject_id' => $template->id, 'subject_type' => PlatformDocumentTemplate::class]);

        return back()->with('toast', ['msg' => __('أُضيف القالب مسوّدةً — اعتمده ثمّ فعّله ليُصدر منه'), 'type' => 'success']);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $template = PlatformDocumentTemplate::findOrFail($id);
        $data = $this->validated($request, $template->form !== null);
        $before = $template->contentHash();
        $wasApproved = $template->approved();

        $template->fill($data);
        $changed = $template->contentHash() !== $before;
        if ($changed) {
            // نصٌّ جديد إصدارٌ جديد: لا اعتمادَ له ولا يُصدر منه حتى يُعتمد
            $template->forceFill([
                'version' => $template->version + 1,
                'approved_hash' => null, 'approval_basis' => null, 'approved_at' => null, 'approved_by' => null, 'approved_by_name' => null,
                'active' => false,
            ]);
        } elseif ($template->active && ! $template->approved()) {
            throw ValidationException::withMessages(['active' => __('اعتمد القالب قبل تفعيله.')]);
        }
        $template->save();

        Activity::log('updated', ($changed && $wasApproved ? 'عدّل نص قالب معتمد — صار الإصدار '.$template->version.' بانتظار الاعتماد: ' : 'عدّل قالب مستند: ').$template->name, [
            'business_id' => null, 'subject_id' => $template->id, 'subject_type' => PlatformDocumentTemplate::class,
        ]);

        return back()->with('toast', [
            'msg' => $changed
                ? __('حُفظ إصدارًا جديدًا معطّلًا — اعتمده ثمّ فعّله. المستندات التي صدرت منه لا تتغيّر')
                : __('حُفظ القالب — المستندات التي صدرت منه لا تتغيّر'),
            'type' => 'success',
        ]);
    }

    /**
     * اعتمادُ القالب — بكلمة مرور المدير، على بصمة ما رآه.
     *
     * الاعتمادُ لا يفعّله: التفعيلُ قرارٌ بعده.
     */
    public function approve(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'hash' => ['required', 'string', 'size:64'],
            'password' => ['required', 'current_password'],
        ], [], ['hash' => __('بصمة القالب'), 'password' => __('كلمة المرور')]);

        return DB::transaction(function () use ($request, $id) {
            $template = PlatformDocumentTemplate::whereKey($id)->lockForUpdate()->firstOrFail();
            $hash = $template->contentHash();
            if (! hash_equals($hash, (string) $request->input('hash'))) {
                return back()->withErrors(['approve' => __('تغيّر القالب منذ فتحته — راجعه من جديد ثمّ اعتمده.')]);
            }
            // نصٌّ يذكر رسومه، أو قاعدةُ مبلغٍ تحتاجها، بلا رقم: وعدٌ بمبلغٍ لا يُعرف
            $needsFee = ($template->includes_amount && $template->amount_rule !== null)
                || array_intersect(['fee', 'fee_short'], DocumentForms::tokens($template->form)) !== [];
            if ($needsFee && $template->default_amount === null) {
                return back()->withErrors(['approve' => __('اكتب رسوم القالب قبل اعتماده — نصّه يذكرها.')]);
            }

            $template->forceFill([
                'approved_hash' => $hash,
                'approval_basis' => PlatformDocumentTemplate::BY_OWNER,
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
                'approved_by_name' => $request->user()->name,
            ])->save();

            Activity::log('updated', 'اعتمد قالب مستند (الإصدار '.$template->version.'، البصمة '.substr($hash, 0, 12).'): '.$template->name, [
                'business_id' => null, 'subject_id' => $template->id, 'subject_type' => PlatformDocumentTemplate::class,
            ]);

            return back()->with('toast', ['msg' => __('اعتُمد القالب — فعّله ليُصدر منه'), 'type' => 'success']);
        });
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
            'includes_plan' => ['boolean'],
            'includes_amount' => ['boolean'],
            'active' => ['boolean'],
            'default_amount' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:1000000'],
            'default_amount_label' => ['nullable', 'string', 'max:190'],
            'amount_rule' => ['nullable', Rule::in([PlatformDocumentTemplate::AMOUNT_FIXED, PlatformDocumentTemplate::AMOUNT_MIN])],
        ], [], [
            'name' => __('اسم القالب'),
            'service' => __('الخدمة'),
            'title' => __('عنوان المستند'),
            'terms' => __('البنود'),
            'terms.*.b' => __('نص البند'),
            'default_amount' => __('المبلغ المقترح'),
            'amount_rule' => __('قاعدة المبلغ'),
        ]);

        // نموذجُ القالب كما صُمِّم — بنودُه في أقسامه، وقاعدةُ مبلغه من نصّه (رسومٌ ثابتة أو «تبدأ من»)
        if ($isForm) {
            unset($data['terms'], $data['amount_rule']);

            return $data;
        }

        // قاعدةُ مبلغٍ بلا رسوم: وعدٌ بمبلغٍ لا يُعرف
        if (($data['amount_rule'] ?? null) !== null && ($data['default_amount'] ?? null) === null) {
            throw ValidationException::withMessages(['default_amount' => __('اكتب الرسوم — قاعدة المبلغ تحتاجها.')]);
        }

        $data['terms'] = array_values(array_map(fn ($t) => [
            'h' => trim((string) ($t['h'] ?? '')),
            'b' => trim((string) $t['b']),
        ], $data['terms']));

        return $data;
    }
}
