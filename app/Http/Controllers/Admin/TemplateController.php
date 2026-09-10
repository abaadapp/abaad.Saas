<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Document\Branding;
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
            /*
             * والهويّةُ تُرسَل مع كلّ نوع — وهي واحدةٌ لأوراق المتجر كلّها.
             *
             * الشعارُ والغلافُ واللون ليست إعدادَ ورقةٍ بعينها: من ضبطها من
             * محرّر «أمر الشراء» يجدها على فاتورته أيضًا. وشاشةٌ تُوحي بغير
             * ذلك تجعله يضبطها خمس مرّات — أو يظنّ أنّه فعل.
             */
            'brand' => Branding::settings($this->bid()),
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
     * حفظُ هويّة الأوراق — اللونان.
     *
     * وهي مستقلّةٌ عن حفظ القالب: القالبُ إعدادُ ورقةٍ بعينها، والهويّةُ
     * تخصّ المتجر كلَّه. وزرٌّ واحد يحفظ الاثنين يجعل من بدّل لونَه في
     * محرّر «سند التسليم» يظنّ أنّه بدّله لسند التسليم وحده.
     */
    public function brand(Request $request)
    {
        $data = $request->validate([
            'primary' => ['sometimes', 'nullable', 'string', 'max:9'],
            'accent' => ['sometimes', 'nullable', 'string', 'max:9'],
        ]);

        /*
         * ولا تُفحص صيغةُ اللون هنا: `Theme::normalize` تفعل — وهي القاعدةُ
         * التي تُقرأ بها. وقاعدتان لشيءٍ واحد تقبل إحداهما ما تردّه الأخرى.
         */
        Branding::save($this->bid(), $data);
        Activity::log('settings', 'عدّل هوية الأوراق');

        return back()->with('toast', ['msg' => __('حُفظت الهوية'), 'type' => 'success']);
    }

    /**
     * غلافُ الأوراق — صورةٌ تعلو المستند، أو حذفُها.
     *
     * وبابٌ مستقلٌّ عن حفظ النموذج: الملفُّ يُرفع بـ`multipart` وبقيّةُ
     * الحقول بـJSON، وخلطُهما في طلبٍ واحد يجعل المصادقة تقرأ «false»
     * نصًّا فتنكسر — وهو العطبُ نفسُه الذي فُصل لأجله بابُ الشعار.
     */
    public function cover(Request $request)
    {
        $request->validate([
            'cover' => ['nullable', 'image', 'max:4096'],
        ], [
            'cover.image' => __('الغلاف صورة — PNG أو JPG أو WEBP'),
            'cover.max' => __('أقصى حجمٍ للغلاف ٤ ميغابايت'),
        ]);

        if (! Branding::storeCover($this->bid(), $request->file('cover'), $request->boolean('remove'))) {
            return back();
        }

        $has = Branding::coverPath($this->bid()) !== null;
        Activity::log('settings', $has ? 'حدّث غلاف الأوراق' : 'حذف غلاف الأوراق');

        return back()->with('toast', [
            'msg' => $has ? __('حُفظ الغلاف') : __('حُذف الغلاف'),
            'type' => 'success',
        ]);
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
