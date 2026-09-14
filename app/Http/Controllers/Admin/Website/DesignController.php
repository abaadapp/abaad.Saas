<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Support\Website\Layout;
use App\Support\Website\Preview;
use App\Support\Website\Templates;
use App\Support\Website\Theme;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * التصميم — قالبٌ وبضعةُ اختيارات، لا لوحةُ مصمّم.
 *
 * قالبٌ ولونٌ أساسيّ وخلفيةٌ ولونُ نصٍّ وخطٌّ وحوافُّ وشكلُ زر. وما بقي يُشتقّ
 * (انظر `Theme`): لونُ ما يُكتب فوق الأساسيّ، ولونُ البطاقات، ولونُ السطور
 * الخافتة. فلا يقع التاجر في تركيبةٍ سيّئة لأنّه لم يُعطَ سبيلًا إليها.
 *
 * ومعها رموزُ البنية (`Layout`): ثلاثةٌ منها تُعرض — شكلُ الموقع وشكلُ
 * المنتجات ونسبةُ صورها — وتسعةٌ تحت «خيارات إضافية». والقالب يحدّدها كلَّها،
 * فمن لم يفتح شيئًا خرج بموقعٍ متناسق. وعرضُ ثلاثة عشر مقبضًا دفعةً واحدة
 * يحوّل شاشةَ تصميمٍ إلى استمارةِ إعدادات، ويُخرج التاجر منها بلا تعديل.
 *
 * وتبديل القالب لا يمسّ المحتوى: الصفحات والأقسام وما كُتب فيها تبقى، ويتبدّل
 * ما يُشتقّ منها في العرض. وهذا لا يصحّ لو كان القالب صفحاتٍ وكودًا — وهو
 * سببُ أن يكون إعدادًا.
 */
class DesignController extends Controller
{
    use Concerns;

    public function index(): Response
    {
        $site = $this->siteOrFail();

        return Inertia::render('Admin/Website/Design', $this->shell($site) + [
            // والقديمةُ تُعرض لمن هو عليها وحده — لا تُقترح على أحد
            'templates' => Templates::options(Templates::isLegacy($site->template)),
            'theme' => $site->theme,
            'layout' => Layout::normalize($site->layout ?? [], Templates::layout($site->template)),
            'options' => Theme::options(),
            'layout_options' => Layout::options(),
            'document' => Preview::document($site),
        ]);
    }

    public function update(Request $request)
    {
        $site = $this->siteOrFail();

        $data = $request->validate([
            'template' => ['required', Rule::in(array_keys(Templates::CATALOGUE))],
            'theme' => ['nullable', 'array'],
            // الألوان تُفحص في `Theme::normalize` لا هنا: قاعدةٌ واحدة للون
            'theme.primary' => ['nullable', 'string', 'max:20'],
            'theme.background' => ['nullable', 'string', 'max:20'],
            'theme.text' => ['nullable', 'string', 'max:20'],
            'theme.font' => ['nullable', 'string', 'max:40'],
            'theme.radius' => ['nullable', 'string', 'max:20'],
            'theme.button' => ['nullable', 'string', 'max:20'],
            /*
             * «تبديل القالب» يعني ألوانه لا ألوانَ التاجر.
             *
             * من يختار «فاخر» يريد ذهبيَّه على فحميّه، لا لونه الأزرق على
             * خلفيةٍ فحميّة لم يخترها. فالعلَم يقول: هذا تبديلُ قالبٍ لا ضبطُ
             * لون — فتُؤخذ رموزُه كاملة.
             */
            'adopt' => ['nullable', 'boolean'],
        ]);

        $adopt = ($data['adopt'] ?? false) || $data['template'] !== $site->template;

        $theme = $adopt
            ? Templates::theme($data['template'])
            : Theme::normalize($data['theme'] ?? [], $site->theme ?? []);

        /*
         * وبنيةُ القالب تُؤخذ معه — أو لا يكون تبديلَ قالب.
         *
         * من اختار «سوق» يريد ترويستَه التجارية وشبكتَه الكثيفة، لا ألوانَه
         * على بنيةٍ تحريرية اختارها أمس. والقالب صار بنيةً قبل أن يكون لونًا.
         */
        $layout = $adopt
            ? Templates::layout($data['template'])
            : Layout::normalize($site->layout ?? [], Templates::layout($site->template));

        $site->update(['template' => $data['template'], 'theme' => $theme, 'layout' => $layout]);
        $site->touchDraft();

        return back()->with('toast', ['msg' => __('حُفظ التصميم'), 'type' => 'success']);
    }

    /**
     * ضبط لونٍ بعينه — بلا تبديل قالب.
     *
     * ومسارٌ مستقلٌّ عن الأعلى لأنّ الشاشة تحفظ عند كلّ تحريكٍ للمنتقي:
     * إرسالُ القالب معه كان سيُعيد ألوانه في كلّ مرّة، فيقفز اللون إلى ما
     * كان كلّما حرّكه التاجر.
     */
    public function palette(Request $request)
    {
        $site = $this->siteOrFail();

        $site->update([
            'theme' => Theme::normalize((array) $request->input('theme', []), $site->theme ?? []),
        ]);
        $site->touchDraft();

        return back(303);
    }

    /**
     * ضبط رمزٍ من رموز البنية — بلا تبديل قالب.
     *
     * ومسارٌ ثالثٌ لا فرعٌ في الثاني: اللون يُحفظ عند كلّ تحريكٍ للمنتقي
     * والبنية عند كلّ ضغطة، وخلطُهما في طلبٍ واحد يجعل كلَّ حفظِ لونٍ يُعيد
     * كتابة البنية وبالعكس — فيسبق أحدُهما الآخر ويُضيّعه.
     */
    public function structure(Request $request)
    {
        $site = $this->siteOrFail();

        $site->update([
            'layout' => Layout::normalize(
                (array) $request->input('layout', []),
                $site->layout ?? Templates::layout($site->template),
            ),
        ]);
        $site->touchDraft();

        return back(303);
    }
}
