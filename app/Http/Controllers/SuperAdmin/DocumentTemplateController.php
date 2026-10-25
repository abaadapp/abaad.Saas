<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformDocumentTemplate;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * قوالبُ المستندات — قالبٌ لكلّ خدمة، يكتب المديرُ عنوانَه وبنودَه.
 *
 * وتعديلُ القالب لا يمسّ مستندًا صدر منه: المستندُ نسخةٌ ساعةَ إصداره. ولا
 * يُحذف قالب، يُعطَّل: مستنداتٌ صدرت منه تشير إليه.
 *
 * وقالبُ النموذج (اتفاقياتُ المالك، انظر `DocumentForms`) يُعدَّل اسمُه وعنوانُه
 * ومبلغُه وتفعيلُه، ونموذجُه كما صُمِّم — لا تمسّه هذه الشاشة.
 */
class DocumentTemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Platform/Documents/Templates', [
            'templates' => PlatformDocumentTemplate::withCount('documents')->orderBy('id')->get()
                ->map(fn (PlatformDocumentTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'service' => $t->service,
                    'title' => $t->title,
                    'title_en' => $t->title_en,
                    'terms' => $t->terms ?? [],
                    'form' => $t->form,
                    'default_amount' => $t->default_amount === null ? null : (string) $t->default_amount,
                    'default_amount_label' => $t->default_amount_label,
                    'includes_plan' => $t->includes_plan,
                    'includes_amount' => $t->includes_amount,
                    'active' => $t->active,
                    'documents_count' => $t->documents_count,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $template = PlatformDocumentTemplate::create($this->validated($request));
        Activity::log('created', 'أضاف قالب مستند: '.$template->name, ['business_id' => null, 'subject_id' => $template->id, 'subject_type' => PlatformDocumentTemplate::class]);

        return back()->with('toast', ['msg' => __('أُضيف القالب'), 'type' => 'success']);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $template = PlatformDocumentTemplate::findOrFail($id);
        $template->update($this->validated($request, $template->form !== null));
        Activity::log('updated', 'عدّل قالب مستند: '.$template->name, ['business_id' => null, 'subject_id' => $template->id, 'subject_type' => PlatformDocumentTemplate::class]);

        return back()->with('toast', ['msg' => __('حُفظ القالب — المستندات التي صدرت منه لا تتغيّر'), 'type' => 'success']);
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
            'terms.*.b' => ['required', 'string', 'max:3000'],
            'includes_plan' => ['boolean'],
            'includes_amount' => ['boolean'],
            'active' => ['boolean'],
            'default_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'default_amount_label' => ['nullable', 'string', 'max:190'],
        ], [], [
            'name' => __('اسم القالب'),
            'service' => __('الخدمة'),
            'title' => __('عنوان المستند'),
            'terms' => __('البنود'),
            'terms.*.b' => __('نص البند'),
            'default_amount' => __('المبلغ المقترح'),
        ]);

        // نموذجُ القالب كما صُمِّم — بنودُه في أقسامه، فلا تُكتب له بنود
        if ($isForm) {
            unset($data['terms']);

            return $data;
        }

        $data['terms'] = array_values(array_map(fn ($t) => [
            'h' => trim((string) ($t['h'] ?? '')),
            'b' => trim((string) $t['b']),
        ], $data['terms']));

        return $data;
    }
}
