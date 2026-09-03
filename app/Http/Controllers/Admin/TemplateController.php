<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * محرّرُ قوالب الأوراق — ورقةٌ إلى اليمين وإعداداتُها إلى اليسار.
 *
 * وكانت المعاينة صندوقًا مرسومًا بيد الشاشة يشبه الإيصال ولا يقرأ ملفَّ
 * رسمه: يُصلَح سطرٌ في الورقة ولا يتغيّر في المعاينة، فيضبط التاجر قالبه
 * على شكلٍ لا يخرج من الطابعة. وهنا تُرسم المعاينة **بالقالب نفسه** — هو
 * الذي يُطبع، وهو الذي يُرى.
 */
class TemplateController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function edit(string $type): Response
    {
        abort_unless(DocumentTemplates::exists($type), 404);

        return Inertia::render('Admin/Settings/TemplateEditor', [
            'template' => DocumentTemplates::describe($this->bid(), $type),
            'templates' => DocumentTemplates::all(),
        ]);
    }

    public function update(Request $request, string $type)
    {
        abort_unless(DocumentTemplates::exists($type), 404);

        $data = $request->validate(DocumentTemplates::rules($type));

        /*
         * والأعلام تُقرأ من الطلب لا من المصفوفة المصادَقة.
         *
         * `false` يصل الحفظَ سلسلةً فارغة فتُقرأ لاحقًا غيابًا لا إطفاءً،
         * فيعود العلم إلى افتراضيّه ويُطبع ما أخفاه صاحبُه.
         */
        foreach (array_keys($data) as $field) {
            if (str_starts_with($field, 'show_')) {
                $data[$field] = $request->boolean($field);
            }
        }

        DocumentTemplates::save($this->bid(), $type, $data);
        Activity::log('updated', 'عدّل قالب: '.(DocumentTemplates::TYPES[$type]['label'] ?? $type));

        return back()->with('toast', ['msg' => __('حُفظ القالب'), 'type' => 'success']);
    }

    /**
     * الورقة كما ستُطبع بالقيم التي على الشاشة الآن — قبل الحفظ.
     *
     * ولا تُحفظ قيمةٌ هنا: من يجرّب شكلًا ثمّ يتركه لا يجب أن يجد ورقته قد
     * تغيّرت. والمصادقة نفسُها تسبق الرسم: قيمةٌ لا تُقبل في الحفظ لا تُرى
     * في المعاينة، وإلّا عاين شكلًا لا يستطيع اعتماده.
     */
    public function preview(Request $request, string $type)
    {
        abort_unless(DocumentTemplates::exists($type), 404);

        $data = $request->validate(DocumentTemplates::rules($type));

        foreach (array_keys($data) as $field) {
            if (str_starts_with($field, 'show_')) {
                $data[$field] = $request->boolean($field);
            }
        }

        return response()->json([
            'html' => DocumentRenderer::preview($this->bid(), $type, $data),
        ]);
    }
}
