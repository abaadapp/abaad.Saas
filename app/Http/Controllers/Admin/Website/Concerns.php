<?php

namespace App\Http\Controllers\Admin\Website;

use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Support\Demo;
use App\Support\DomainOptions;
use App\Support\MarketingSettings;
use App\Support\Website\Blueprints;
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
    protected function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /** موقع هذا النشاط — أو null إن لم يُنشأ بعد */
    protected function site(): ?Website
    {
        return Website::where('business_id', $this->bid())->first();
    }

    /**
     * موقعُ هذا النشاط — ومن لا موقع له يُردّ إلى المعالج لا إلى 404.
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
     * عنوان الموقع على الإنترنت — أو null إن لم يُضبط بعد.
     *
     * ولا يُخترع من الاسم الفرعيّ المحجوز: لا شيء على الخادم يقدّمه بعد،
     * ورابطٌ لا يردّ أسوأ من لا رابط. انظر MarketingSettings::site_subdomain.
     */
    protected function publicUrl(): ?string
    {
        return Demo::websiteUrl();
    }

    /** حال النطاق كما تقرؤها لوحة الموقع — بلا تكرار شاشة الدومين */
    protected function domainState(): array
    {
        $site = MarketingSettings::group($this->bid(), 'website');
        $subdomain = trim((string) $site['site_subdomain']);

        return [
            'mode' => DomainOptions::mode($site),
            'domain' => trim((string) $site['site_domain']),
            'subdomain' => $subdomain !== '' ? DomainOptions::host($subdomain) : null,
        ];
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
