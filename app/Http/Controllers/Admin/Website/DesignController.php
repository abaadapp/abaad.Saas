<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Support\Website\Preview;
use App\Support\Website\Templates;
use App\Support\Website\Theme;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * التصميم — ستّة اختيارات، لا لوحةُ مصمّم.
 *
 * قالبٌ ولونٌ أساسيّ وخلفيةٌ ولونُ نصٍّ وخطٌّ وحوافُّ وشكلُ زر. وما بقي يُشتقّ
 * (انظر `Theme`): لونُ ما يُكتب فوق الأساسيّ، ولونُ البطاقات، ولونُ السطور
 * الخافتة. فلا يقع التاجر في تركيبةٍ سيّئة لأنّه لم يُعطَ سبيلًا إليها.
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
            'templates' => Templates::options(),
            'theme' => $site->theme,
            'options' => Theme::options(),
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

        $theme = ($data['adopt'] ?? false) || $data['template'] !== $site->template
            ? Templates::theme($data['template'])
            : Theme::normalize($data['theme'] ?? [], $site->theme ?? []);

        $site->update(['template' => $data['template'], 'theme' => $theme]);
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
}
