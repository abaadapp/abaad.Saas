<?php

namespace App\Http\Controllers\Admin\Website;

use App\Models\Business;
use App\Models\StoreSite;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteSection;
use App\Models\WebsiteVersion;
use App\Support\MarketingSettings;
use App\Support\Permissions;
use App\Support\Store\CheckoutFields;
use App\Support\Store\PageEditor;
use App\Support\Store\StoreContent;
use App\Support\Store\StoreNav;
use App\Support\Storefront;
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

    /**
     * ضبطُ الموقع فعلٌ على حدة — لا يرثه كلُّ من فتح القسم.
     *
     * قسمُ `website` يُمنح للموظّف ليتفقّد منتجات موقعه كلّ صباح (انظر
     * `HubController`). وتبديلُ القالب وحذفُ صفحةٍ وتحويلُ النطاق ليست من
     * عمله: نطاقٌ يُحوَّل خطأً يُطفئ المتجر على زبائنه كلِّهم، وصفحةٌ تُحذف
     * تُسقط روابطَ وُزّعت.
     *
     * ويُنادى في أوّل كلّ شاشةِ ضبطٍ وفعلِها — لا في الشريط الجانبيّ وحده:
     * إخفاءُ زرٍّ ليس حراسة، والمسارُ يُكتب في شريط العنوان.
     */
    protected function mayConfigure(): void
    {
        abort_unless(
            (bool) auth()->user()?->may(Permissions::WEBSITE_CONFIGURE),
            403,
            __('ضبط الموقع لصاحب المتجر — راجعه إن احتجت تغييرًا هنا'),
        );
    }

    /** موقع هذا النشاط — أو null إن لم يُنشأ بعد */
    protected function site(): ?Website
    {
        return Website::where('business_id', $this->bid())->first();
    }

    /**
     * الواجهةُ الخاصّة لهذا النشاط — `ribbon` لمن لبسها، وnull لسائر المتاجر.
     *
     * ═══ ومن لبسها لا قوالبَ له ═══
     *
     * الواجهةُ الخاصّة موقعٌ كاملٌ بتصميم صاحبه، يُخدم على عنوانه قبل البانِي
     * والبسيطة (انظر `Storefront::serves`). فقوالبُ البانِي الجاهزة — التي
     * يُعرض عليه اختيارُ واحدٍ منها في أوّل فتحةٍ لـ«الموقع الإلكتروني» —
     * تعرض عليه أن يبني موقعًا ثانيًا لن يراه زبونٌ قطّ، وتقول له إنّ موقعه
     * الحقيقيّ ليس موقعه.
     *
     * فمن لبس واجهةً خاصّة يرى لوحةَ تشغيلها في `‎/website‎`، وله على
     * المسارات نفسِها أربعُ شاشاتٍ من صنعها: «عام» و«صفحة المتجر»
     * و«المتجر والطلبات» و«العنوان والنشر» — بشريط تبويباتٍ كشريط جاره
     * (انظر `SectionTabs::THEME_TABS`).
     *
     * وما بقي من شاشات البانِي يردّه: «التصميم» قالبٌ يُبدَّل ولا قوالبَ
     * له، و«الصفحات» صفحاتٌ تُضاف وصفحتُه واحدة، و«السيو» يكتب في صفٍّ لا
     * يملكه.
     */
    protected function theme(): ?string
    {
        return Business::find($this->bid())?->storefrontTheme();
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

        /*
         * ومن لبس واجهةً خاصّة لا يُفتح له البانِي — ولو بقي له موقعٌ بُني قبلها.
         *
         * والشاشاتُ التي صار له مثلُها لا تبلغ هنا أصلًا: تتفرّع قبلها إلى
         * صنعتها (انظر `SettingsController::general` و`EditorController::show`).
         * فما يصل هذه الدالّة هو ما لا وجود له عنده.
         */
        if (! $site || $this->theme() !== null) {
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
     * ترويسةُ شاشات الواجهة الخاصّة — الاسمُ والحالُ والعنوان.
     *
     * ═══ ولمَ تُحسب هنا لا في كلّ شاشة ═══
     *
     * أربعُ شاشاتٍ تعرضها في رأسها (عام، صفحة المتجر، المتجر والطلبات،
     * العنوان والنشر). ولو حُسبت في كلٍّ منها لقالت إحداها «منشور» وقالت
     * أختُها «غير منشور» عن المتجر نفسه — وهو ما وقع في شاشات البانِي قبل
     * أن تُجمع في `shell`.
     *
     * و«منشور» هنا **ما يُخدم على العنوان فعلًا** لا ما يقوله المفتاح:
     * `Storefront::serves` تقرأ العنوانَ والمفتاحَ والواجهةَ معًا.
     *
     * @return array<string, mixed>
     */
    protected function themeShell(string $theme): array
    {
        $bid = $this->bid();
        $business = Business::findOrFail($bid);
        $published = Storefront::serves($business) === Storefront::SERVES_THEME;

        return [
            'theme' => $theme,
            'site' => [
                'name' => (string) $business->name,
                'published' => $published,
                /*
                 * والعنوانُ لا يُعرض إلّا إن كان يُفتح.
                 *
                 * رابطٌ يُعرض على متجرٍ غير منشورٍ يُضغط فيردّ «غير موجود» —
                 * فيظنّ صاحبُه العطبَ في النظام لا في مفتاحٍ أطفأه بيده.
                 */
                'url' => $published ? Domains::canonical($bid) : null,
                'slug' => $business->site_slug,
                'host' => Storefront::domain(),
            ],
        ];
    }

    /**
     * حالُ متجر الواجهة الخاصّة كما تقرؤه شاشاتُه الستّ — بذرةٌ واحدة.
     *
     * ═══ ولمَ تُرسَل كاملةً إلى كلٍّ منها ═══
     *
     * قسمتُها على الشاشات تعني ستَّ صيغٍ للقراءة نفسِها، تفترق يومَ يُضاف
     * مفتاح: شاشةٌ تقرأ الفراغَ «مطفأ» وأختُها تقرؤه «ما كان». وهي كلُّها
     * صفٌّ واحدٌ من `MarketingSettings::group` — فالقسمةُ لا توفّر استعلامًا
     * وتُكلّف اتّفاقًا.
     *
     * وما يُرسَل ليس ما يُحفظ: كلُّ شاشةٍ تُرسل مفاتيحَها وحدَها
     * (`SCREEN_KEYS` في الواجهة)، والخادمُ يُسقط الغائبَ ولا يمحوه.
     *
     * @return array<string, mixed>
     */
    protected function themeSeed(Business $business): array
    {
        $bid = (int) $business->id;

        /*
         * والشاشاتُ تفتح على **المسوّدة** لا على المنشور — كالمحرّر سواء.
         *
         * `StoreContent::VERSIONED` تُرسل سبعةَ عشرَ مفتاحًا إلى المسوّدة حين
         * يكون النظامُ مفتوحًا، ومنها `store_seo_title` و`store_pages`. فلو
         * قرأت الشاشةُ المنشورَ وحدَه لَكتب صاحبُ المتجر عنوانَ بحثه وحفظ
         * ثمّ حدّث الصفحةَ فوجده قد عاد إلى ما كان — وهو لم يذهب، بل ينتظر
         * النشر. وهي عينُ الحال التي يحذّر منها `PageEditor::values`.
         *
         * ومن لا مسوّدةَ له تردّ `draft` المنشورَ نفسَه، فلا يتبدّل شيءٌ
         * لمتجرٍ لم يُفتح له النظام — وهي حالُ كلّ متجرٍ قبل ترحيله.
         */
        $values = array_merge(
            MarketingSettings::group($bid, 'website'),
            StoreContent::draft($bid),
        );

        return [
            'settings' => $values,
            /*
             * وحقولُ الطلب تصل محسوبةً لا فارغة.
             *
             * `CheckoutFields` تقرأ الفراغَ «ما كان» — فشاشةٌ تعرض فراغًا
             * تقول لصاحبها إنّ حقلًا مطفأٌ وهو يُسأل عنه في متجره.
             */
            'fieldStates' => CheckoutFields::all($bid),
            'fulfilments' => CheckoutFields::fulfilments($bid),
            /*
             * وما أذِن به من الصفحات يصل **محسوبًا** لا خامًا من الإعداد.
             *
             * الفراغُ في العمود يعني «كلُّها» (انظر `StoreNav::allowed`)،
             * فإرسالُه فراغًا يجعل مفتاحَي الصفحتين مطفأين وهما مفتوحتان
             * على زبائنه.
             */
            'pages' => ['allowed' => implode(',', StoreNav::allowedFrom($values['store_pages'] ?? '')) ?: StoreNav::NONE],
            'seo' => [
                'title' => (string) ($values['store_seo_title'] ?? ''),
                'desc' => (string) ($values['store_seo_desc'] ?? ''),
                'index' => ($values['store_seo_index'] ?? '1') === '1',
            ],
            'storeOn' => ($values['store_on'] ?? '0') === '1',
            'slug' => $business->site_slug,
        ];
    }

    /**
     * حالُ نشر الواجهة الخاصّة: أفيه ما لم يُنشر، وما سجلُّ النشرات.
     *
     * ═══ ولمَ في السمة لا في متحكّم ═══
     *
     * تقرؤها شاشتان: «عام» التي فيها زرُّ النشر، والمحرّرُ الذي يُنشر منه.
     * ولو حُسبت في كلٍّ منهما لقالت إحداهما «فيه ثلاثةُ تغييرات» وقالت
     * أختُها «لا تغييرات» عن المتجر نفسه — وهو العطبُ الذي جمع `shell`.
     *
     * و`null` تعني أنّ النظامَ لم يُفتح لهذا المتجر بعد (لا صفَّ له في
     * `store_sites`): يُحفظ فيظهر، فلا يُعرض عليه زرُّ نشرٍ لا يعمل ولا
     * سجلُّ نشراتٍ فارغ. ومقبضٌ موصولٌ بلا شيءٍ أسوأ من غيابه.
     *
     * ولا يُعدّ سجلٌّ لا نهاية له: خمسٌ تكفي من يراجع ما فعل، ومن أراد
     * أبعد منها أراد أرشيفًا لا شاشة.
     *
     * @return array<string, mixed>|null
     */
    protected function themePublishState(int $bid): ?array
    {
        if (! StoreContent::usesDrafts($bid)) {
            return null;
        }

        $site = StoreSite::where('business_id', $bid)->first();
        $changed = StoreContent::changed($bid);

        return [
            'changed' => count($changed),
            /*
             * وأسماءُ ما تغيّر تُقال لا عددُه وحدَه: «٣ تغييرات» تُقلق ولا
             * تُفيد، و«العنوان والنبذة والتذييل» تُراجَع في لحظة.
             */
            'fields' => collect($changed)
                ->map(fn ($key) => __(PageEditor::labelOf($key)))->filter()->values()->all(),
            'revision' => (int) ($site?->draft_revision ?? 0),
            'published_at' => optional($site?->published_at)->format('Y-m-d H:i'),
            /*
             * ووقتُ آخرِ حفظٍ إلى جانبه — وهو ما يُفرّق بين الحالَين.
             *
             * «آخر نشرة» وحدَها لا تقول أَحفِظتَ بعدها أم لا. فحفظٌ أحدثُ من
             * النشرة يعني «عندك ما ينتظر»، ومساواتُهما تعني «كلُّ ما حفظتَ
             * منشور».
             *
             * ═══ و`draft_saved_at` لا `updated_at` ═══
             *
             * `updated_at` ختمٌ تلقائيٌّ يتبدّل عند كلّ كتابةٍ في الصفّ —
             * والنشرُ يكتب فيه (`published_at` و`published_version_id`). فلو
             * قُرئ منه لَقفز «آخر حفظ» إلى لحظة النشر وصاحبُه لم يحفظ شيئًا،
             * فيتساوى السطران أبدًا ولا يبقى في أحدهما خبر.
             *
             * و`draft_saved_at` لا تُكتب إلّا حين تُحفظ المسوّدةُ فعلًا
             * (`ThemePublisher::saveDraft`) — وهي المصدرُ الذي تقرؤه شاشةُ
             * البانِي في `shell()` منذ كُتبت.
             */
            'saved_at' => optional($site?->draft_saved_at)->format('Y-m-d H:i'),
            'versions' => WebsiteVersion::where('business_id', $bid)
                ->where('kind', WebsiteVersion::THEME)
                ->with('creator:id,name')
                ->orderByDesc('number')->limit(5)->get()
                ->map(fn ($v) => [
                    'id' => (int) $v->id,
                    'number' => (int) $v->number,
                    'at' => optional($v->published_at)->format('Y-m-d H:i'),
                    'by' => $v->creator?->name,
                    'note' => $v->note,
                    'current' => (int) $v->id === (int) ($site?->published_version_id ?? 0),
                ])->all(),
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
