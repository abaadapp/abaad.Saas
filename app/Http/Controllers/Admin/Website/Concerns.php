<?php

namespace App\Http\Controllers\Admin\Website;

use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Support\Website\Blueprints;
use App\Support\Website\Domains;
use App\Support\Website\MerchantData;
use App\Support\Website\Sections;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * ما تشترك فيه شاشات الموقع: أيّ موقعٍ نحن فيه، وهل هو موقعُنا.
 *
 * والعزل هنا لا في كلّ متحكّم: كلّ صفحةٍ وكلّ قسمٍ يُطلب بمعرّف يأتي من
 * المتصفّح، وفحصٌ يُنسى مرّةً واحدة يعني تاجرًا يعدّل صفحةً في موقع جاره.
 * فالمرور من هذه الدوالّ وحدها يجعل النسيان مستحيلًا لا مستبعَدًا.
 */
trait Concerns
{
    /**
     * نشاطُ من يعمل الآن — ولا يُخمَّن.
     *
     * ═══ ولا يُردّ إلى `Demo::bid()` صامتًا ═══
     *
     * تلك تردّ صفرًا لمستخدمٍ مسجَّلٍ بلا نشاط، وتردّ **أوّلَ نشاطٍ في
     * القاعدة** حين تُنادى من الطرفية. والصفرُ يمرّ في كلّ استعلامٍ هنا فلا
     * يجد شيئًا، فيُساق التاجرُ إلى معالج الإنشاء ثمّ يصطدم بـ«غير موجود»
     * بلا أن يفهم لماذا.
     *
     * والأخطرُ أنّ كلَّ استعلامٍ في هذه الشاشات مقيَّدٌ بهذا الرقم — ورقمٌ
     * مخمَّن في موضع معرّف المستأجر بابٌ لا يُترك مفتوحًا على حسن النيّة.
     * فمن لا نشاطَ له يُردّ هنا بكلمةٍ، ولا يُخدَم بنشاطٍ لا يملكه.
     */
    protected function bid(): int
    {
        $bid = (int) (auth()->user()?->business_id ?? 0);

        abort_if($bid <= 0, 403, __('حسابك غير مرتبطٍ بنشاط — راجع مدير النظام'));

        return $bid;
    }

    /** موقع هذا النشاط — أو null إن لم يُنشأ بعد */
    protected function site(): ?Website
    {
        return Website::where('business_id', $this->bid())->first();
    }

    /**
     * موقعُ هذا النشاط — ومن لا موقع له يُردّ إلى شاشة الاختيار لا إلى 404.
     *
     * كلّ شاشات هذا القسم تفترض موقعًا قائمًا، ومن لم ينشئ موقعه بعد قد يصل
     * إلى أيٍّ منها برابطٍ محفوظ أو من قائمةٍ جانبية. و«غير موجود» تقول له
     * إنّ الصفحة معطوبة؛ والصحيح أن يُقال له إنّ موقعه لم يُنشأ — وأن يُفتح
     * له بابُ إنشائه.
     */
    protected function siteOrFail(): Website
    {
        $site = $this->site();

        if (! $site) {
            throw new HttpResponseException(redirect()->route('admin.website.index'));
        }

        return $site;
    }

    /** صفحةٌ في موقعنا — ومن غيره لا تُوجد */
    protected function page(Website $site, int|string $id): WebsitePage
    {
        return WebsitePage::where('business_id', $site->business_id)
            ->where('website_id', $site->id)->findOrFail($id);
    }

    protected function section(Website $site, int|string $id): WebsiteSection
    {
        return WebsiteSection::where('business_id', $site->business_id)
            ->where('website_id', $site->id)->findOrFail($id);
    }

    /**
     * ما تعرضه كلّ شاشات الموقع في ترويستها — الحال والرابط والزرّ.
     *
     * ويُحسب في موضعٍ واحد لأنّه يُعرض في ستّ شاشات: لو حُسب في كلٍّ منها
     * لقالت إحداها «منشور» وقالت أختُها «فيه تغييرات» عن الموقع نفسه.
     *
     * @return array<string, mixed>
     */
    protected function shell(Website $site): array
    {
        return [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'goal' => $site->goal,
                'goal_label' => __(Blueprints::GOALS[$site->goal()]['label'] ?? ''),
                'template' => $site->template,
                'state' => $site->state(),
                'sells' => $site->sells(),
                'maintenance' => $site->maintenance,
                'published_at' => optional($site->published_at)->format('Y-m-d H:i'),
                'saved_at' => optional($site->draft_saved_at)->format('Y-m-d H:i'),
                'changes' => $site->hasUnpublishedChanges(),
                'url' => $this->publicUrl(),
                'tokens' => $site->tokens(),
            ],
        ];
    }

    /**
     * عنوان الموقع على الإنترنت — وهو **ما يُخدَم** لا ما يُملَك.
     *
     * وكان يُقرأ من `Demo::websiteUrl`، فيردّ `متجري.abaadapp.om` لمن نشر
     * متجره. وذلك عنوانٌ لا يُحلّ قبل سجلِّ DNS بالحرف البدل وشهادةٍ مثله —
     * فزرُّ «افتح موقعك» في لوحة التاجر يفتح صفحةَ خطأ في متصفّحه، وهو أوّل
     * ما يجرّبه بعد النشر.
     *
     * و`Domains::canonical` تقرأ الحال والعلَم معًا فتردّ البابَ العامل:
     * نطاقُ التاجر إن خُدم ونشط، ثمّ عنوانُ أبعاد إن ضُبط له نطاقٌ بالحرف
     * البدل، ثمّ البابُ البديل الذي يعمل اليوم.
     */
    protected function publicUrl(): ?string
    {
        return Domains::canonical($this->bid());
    }

    /**
     * حالُ العناوين كما تقرؤها لوحة الموقع.
     *
     * وتُقرأ من `Domains` لا من ثلاثة مواضع: كان الاسمُ المحجوز يُقرأ من
     * عمودٍ في `businesses` والنطاقُ من صفٍّ في `settings`، ولا حالَ لأيٍّ
     * منهما — فتعرض الشاشةُ عنوانًا ولا تعرف أيفتح أم لا.
     *
     * @return array<string, mixed>
     */
    protected function domainState(): array
    {
        return Domains::state($this->bid());
    }

    /** ما يملكه المتجر ممّا تقرؤه الأقسام */
    protected function available(): array
    {
        return MerchantData::available($this->bid());
    }

    /** مكتبة الأقسام كما تُعرض لهذا الموقع */
    protected function library(Website $site): array
    {
        return Sections::library($site->goal(), $this->available());
    }
}
