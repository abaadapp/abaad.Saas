<?php

namespace App\Support\Website;

use App\Events\Website\DomainActivated;
use App\Events\Website\DomainConnected;
use App\Events\Website\DomainFailed;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteDomain;
use App\Support\MarketingSettings;
use App\Support\Storefront;
use App\Support\Website\Domain\CustomDomainProvider;
use Illuminate\Support\Facades\DB;

/**
 * العناوين التي تفتح موقع التاجر — قراءتُها وكتابتُها من موضعٍ واحد.
 *
 * وهذا هو الفرق بين «حقلٌ نصّيّ» و«طبقةُ نطاقات»: الحقل يُكتب ويُقرأ، والطبقة
 * تعرف أنّ العنوان يُربط ويُوجَّه ويُتحقَّق منه ويُصدَر له أمانٌ ثمّ يعمل —
 * وأنّ لكلّ خطوةٍ من هذه ما يُقال للتاجر فيها.
 *
 * ═══ وعنوانان لكلّ متجر، ولكلٍّ منهما مصدرٌ واحد ═══
 *
 * **عنوانُ أبعاد** (`متجري.abaadapp.om`) يُشتقّ من `businesses.site_slug`
 * ولا يُخزَّن ثانيةً هنا. وذلك عمدًا: الاسم عمودٌ فريدٌ في جدوله، يفحص
 * تفرّدَه الحفظُ ويقرؤه نصفُ النظام، وحالُه واحدةٌ لا تتغيّر — نطاقُ أبعاد
 * يخدمه خادمُ أبعاد، بلا توجيهٍ ينتظره ولا شهادةٍ تُصدَر له. فصفٌّ يحكيه
 * صفٌّ يفترق عنه يومًا، ولا يشتري بذلك الافتراقِ شيئًا.
 *
 * **ونطاقُ التاجر** (`myshop.om`) يسكن `website_domains` — وهو الذي كان
 * حقلًا نصّيًّا في جدول المفاتيح: بلا تفرّدٍ في القاعدة، وبلا حالِ ربط،
 * وبلا موضعٍ لمزوّدٍ يتولّاه. وهو وحده ما يحتاج جدولًا، لأنّه وحده ما فيه
 * ما يُتتبَّع.
 *
 * ═══ وقارئٌ واحد ═══
 *
 * `resolve` هي كيف يُعرف صاحبُ عنوانٍ وصل في `Host`. ولا يُخدَم النطاقُ
 * الخاصّ إلّا **نشطًا**: عنوانٌ ما زال «بانتظار التوجيه» لا يفتح موقعًا —
 * ولو فتح لَبطل معنى التحقّق، ولَأمكن أن يدّعي أحدٌ نطاقًا ليس له فيُخدَم
 * عليه موقعُه قبل أن يثبت أنّه يملكه.
 *
 * ═══ وكاتبٌ واحد ═══
 *
 * `settings.site_domain` يبقى مقروءًا لمن يقرؤه (زرُّ «الموقع» في الشريط،
 * والسيو، وشاشةُ الإعدادات)، لكنّ من يكتبه واحدٌ هو هذا الملفّ — يكتب الصفَّ
 * والمرآةَ في معاملةٍ واحدة. ومرآةٌ لا يكتبها إلّا صاحبُ الأصل لا تفترق عنه.
 */
final class Domains
{
    /** عنوان أبعاد الفرعيّ: متجري.abaadapp.om */
    public const PLATFORM = 'platform';

    /** نطاقٌ يملكه التاجر */
    public const CUSTOM = 'custom';

    /** رُبط ولم يُوجَّه سجلُّه بعد */
    public const PENDING = 'pending';

    /** وُجّه ونحن نتحقّق أو ننتظر انتشاره */
    public const VERIFYING = 'verifying';

    /** يفتح الموقع فعلًا */
    public const ACTIVE = 'active';

    /** لا يشير إلينا — ومعه سببُه */
    public const FAILED = 'failed';

    /* ─────────────────────────────── قراءة ─────────────────────────────── */

    /**
     * صاحبُ هذا العنوان — أو null.
     *
     * @return int|null معرّف النشاط
     */
    public static function resolve(string $host): ?int
    {
        $host = self::normalize($host);

        if ($host === null) {
            return null;
        }

        /*
         * ═══ ولا تُشترط حالُ «متصل» هنا ═══
         *
         * الحالُ تقريرٌ عمّا رأيناه آخرَ مرّة، لا إذنٌ بالخدمة. ومن وصل طلبُه
         * على هذا المضيف فقد أثبت بالفعل أنّ توجيهه إلينا — أوثقُ من فحصٍ
         * أجريناه أمس. فاشتراطُ الحال يعني متجرًا يعمل توجيهُه ولا يُفتح
         * لأنّ فحصَنا الأخير تعثّر في شبكةٍ عندنا.
         *
         * والحارسُ الحقيقيّ هو التفرّد: صفٌّ واحد لكلّ عنوان، ومن سبق. فمن
         * ادّعى نطاقًا لا يملكه لم يأخذ شيئًا — لا يستطيع أن يوجّهه إلينا —
         * وإنّما مُنع صاحبُه من تسجيله، وذاك ما يحرسه الفهرس الفريد.
         */
        $id = WebsiteDomain::where('normalized_hostname', $host)->value('business_id');

        return $id !== null ? (int) $id : self::platformOwner($host);
    }

    /**
     * صاحبُ عنوانٍ تحت نطاق أبعاد — من اسمه المحجوز.
     *
     * ولا يُقرأ موقعٌ بمعرّفه: عدّادٌ بسيط يمرّ على مواقع المتاجر كلّها.
     * والاسمُ المحجوز نصٌّ اختاره صاحبُه — لا يُخمَّن بالعدّ.
     */
    private static function platformOwner(string $host): ?int
    {
        $suffix = '.'.mb_strtolower(Storefront::domain());

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = mb_substr($host, 0, -mb_strlen($suffix));

        // اسمٌ من جزءٍ واحد لا غير: `a.b.abaadapp.om` ليس نطاقًا فرعيًّا محجوزًا
        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        $id = Business::whereRaw('LOWER(site_slug) = ?', [$label])->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** عنوانُ أبعاد لهذا النشاط — أو null إن لم يحجز اسمًا */
    public static function platformHost(?string $slug): ?string
    {
        $slug = mb_strtolower(trim((string) $slug));

        return $slug !== '' ? $slug.'.'.Storefront::domain() : null;
    }

    public static function custom(int $businessId): ?WebsiteDomain
    {
        return WebsiteDomain::where('business_id', $businessId)
            ->where('type', self::CUSTOM)->first();
    }

    /* ─────────────────────────────── كتابة ─────────────────────────────── */

    /**
     * يُوافَق الجدولُ حالَ النشاط.
     *
     * ولا يُكتب فيه عنوانُ أبعاد — ذاك مشتقٌّ من `site_slug` ولا صفَّ له.
     * وإنّما يُلحق النطاقُ الخاصّ بموقعٍ بُني بعد أن رُبط، ويُعاد انتخابُ
     * الأصل. وهي مُتساوقة: نداؤها مرّتين كنداؤها مرّة.
     */
    public static function sync(Business $business): void
    {
        $websiteId = Website::where('business_id', $business->id)->value('id');

        WebsiteDomain::where('business_id', $business->id)
            ->whereNull('website_id')->update(['website_id' => $websiteId]);

        self::electPrimary((int) $business->id);
    }

    /**
     * يربط التاجر نطاقَه — أو يفكّه بقيمةٍ فارغة.
     *
     * @return array{ok: bool, error?: string, domain?: WebsiteDomain}
     */
    public static function attach(Business $business, ?string $raw): array
    {
        $host = self::normalize($raw);

        if ($host === null) {
            self::detach($business);

            return ['ok' => true];
        }

        /*
         * ولا يُربط عنوانُ أبعاد نفسه نطاقًا «خاصًّا».
         *
         * تاجرٌ يكتب `متجر-جاري.abaadapp.om` في خانة «نطاقي الخاصّ» كان
         * يأخذ عنوانًا ليس له — الفحصُ الوحيد أنّه غيرُ مسجَّل، وعناوينُ
         * أبعاد لم تكن مسجَّلةً في مكانٍ يُفحص. والآن هي صفوفٌ في الجدول
         * نفسه، فالتفرّدُ يمنعه؛ وهذا الشرط يجعل الرسالة مفهومة.
         */
        if (str_ends_with($host, '.'.mb_strtolower(Storefront::domain()))) {
            return ['ok' => false, 'error' => __('هذا عنوانٌ من عناوين أبعاد — اكتب نطاقك الذي تملكه، أو احجز اسمك من «عنوان متجرك».')];
        }

        $taken = WebsiteDomain::where('normalized_hostname', $host)
            ->where('business_id', '!=', $business->id)->exists()
            /*
             * والمرآةُ القديمة تُستشار أيضًا.
             *
             * الهجرةُ نقلت كلَّ ما في `settings.site_domain` إلى الجدول، إلّا
             * ما تكرّر منه — صفّان بعنوانٍ واحد بقيا من قبل أن يُفحص التفرّد،
             * فأُخذ الأسبقُ وبقي الثاني بلا صفّ. وسؤالُ المرآة هنا يمنع أن
             * يُسجَّل ثالثٌ عنوانًا يُخدَم عليه غيرُه، ويمنع أن يضيع الفحصُ
             * إن كتب أحدٌ المفتاحَ يومًا من خارج هذه الطبقة.
             */
            || \App\Models\Setting::whereNotNull('business_id')
                ->where('business_id', '!=', $business->id)
                ->where('key', 'site_domain')
                ->whereRaw('LOWER(value) = ?', [$host])->exists();

        if ($taken) {
            return ['ok' => false, 'error' => __('هذا النطاق مسجَّلٌ لمتجرٍ آخر — إن كان لك فراسِلنا.')];
        }

        $provider = app(CustomDomainProvider::class);
        $websiteId = Website::where('business_id', $business->id)->value('id');

        $domain = DB::transaction(function () use ($business, $host, $raw, $websiteId, $provider) {
            $existing = self::custom((int) $business->id);

            if ($existing && $existing->normalized_hostname !== $host) {
                $provider->forget($existing);
                $existing->delete();
                $existing = null;
            }

            $domain = $existing ?? new WebsiteDomain(['normalized_hostname' => $host]);

            $domain->fill([
                'business_id' => $business->id,
                'website_id' => $websiteId,
                'hostname' => trim((string) $raw),
                'type' => self::CUSTOM,
                'provider' => $provider->name(),
            ]);

            if (! $domain->exists) {
                $domain->status = self::PENDING;
                $domain->verification_token = bin2hex(random_bytes(16));
            }

            $domain->save();

            $providerId = $provider->register($domain);

            if ($providerId !== null) {
                $domain->update(['provider_id' => $providerId]);
            }

            /*
             * والمرآةُ تُكتب في المعاملة نفسها.
             *
             * `settings.site_domain` يقرؤه زرُّ «الموقع» والسيو وشاشةُ
             * الإعدادات. وكتابتُه هنا — لا في المتحكّم — هي ما يجعل مصدرَين
             * لا يفترقان: كاتبٌ واحد لا كاتبان.
             */
            MarketingSettings::save((int) $business->id, 'website', ['site_domain' => $host]);

            self::electPrimary((int) $business->id);

            return $domain;
        });

        DomainConnected::dispatch($domain->refresh());

        return ['ok' => true, 'domain' => $domain];
    }

    /** يفكّ التاجر نطاقَه — ويبقى له عنوانُ أبعاد */
    public static function detach(Business $business): void
    {
        $domain = self::custom((int) $business->id);

        DB::transaction(function () use ($business, $domain) {
            if ($domain) {
                app(CustomDomainProvider::class)->forget($domain);
                $domain->delete();
            }

            MarketingSettings::save((int) $business->id, 'website', ['site_domain' => '']);

            self::electPrimary((int) $business->id);
        });
    }

    /**
     * أوصل التوجيه؟ — السؤال الوحيد الذي يخرج إلى الشبكة.
     *
     * ولا يُسأل عند فتح شاشة: صفحةٌ تنتظر جوابَ DNS تفتح في ثوانٍ أو لا
     * تفتح. فيُسأل بزرٍّ يضغطه التاجر، أو بأمرٍ مجدول.
     */
    public static function check(WebsiteDomain $domain): WebsiteDomain
    {
        if ($domain->isPlatform()) {
            return $domain;
        }

        $result = app(CustomDomainProvider::class)->check($domain);
        $was = $domain->status;

        $domain->update([
            'status' => $result->status,
            'failure_reason' => $result->reason,
            'last_checked_at' => now(),
            'verified_at' => $result->status === self::ACTIVE ? ($domain->verified_at ?? now()) : null,
        ]);

        // والأصلُ يُعاد انتخابُه: ما صار يُخدَم يصلح أن يُكتب في `canonical`
        self::electPrimary((int) $domain->business_id);

        if ($result->status === self::ACTIVE && $was !== self::ACTIVE) {
            DomainActivated::dispatch($domain->refresh());
        }

        if ($result->status === self::FAILED) {
            DomainFailed::dispatch($domain, (string) $result->reason);
        }

        return $domain;
    }

    /* ─────────────────────────────── الأصل ─────────────────────────────── */

    /**
     * أهذا النطاقُ الخاصّ هو الأصل؟ — إن كان يفتح فعلًا.
     */
    private static function electPrimary(int $businessId): void
    {
        $custom = self::custom($businessId);

        if (! $custom) {
            return;
        }

        $primary = $custom->status === self::ACTIVE && (bool) config('storefront.custom_domains');

        if ($custom->is_primary !== $primary) {
            $custom->update(['is_primary' => $primary]);
        }
    }

    /**
     * العنوانُ الذي يُكتب في `canonical` — وهو ما يُخدَم لا ما يُملَك.
     *
     * وهذه أدقُّ ما في هذا الملفّ. `canonical` يقول لمحرّك البحث: «الأصلُ
     * هنا فافهرسه». فإن أشار إلى عنوانٍ لا يُحلّ — نطاقٌ رُبط ولم يُوجَّه
     * بعد، أو نطاقٌ فرعيٌّ لا سجلَّ بدلٍ له على الخادم — دلَّ المحرّكَ على
     * بابٍ مغلق وترك الصفحةَ الحيّة بلا فهرسة. فيضرّ حيث يُراد به النفع.
     *
     * فيُقرأ الحال والعلَم معًا: العنوانُ يصلح أصلًا إن كان **نشطًا**
     * و**يخدمه الخادم**. وما دون ذلك فالأصلُ هو البابُ الذي يعمل اليوم.
     */
    public static function canonical(int $businessId, ?string $slug = null): ?string
    {
        $custom = self::custom($businessId);

        if ($custom && $custom->isActive() && config('storefront.custom_domains')) {
            return $custom->url();
        }

        if ($slug === null) {
            $slug = Business::whereKey($businessId)->value('site_slug');
        }

        $slug = is_string($slug) ? trim($slug) : '';

        if ($slug === '') {
            return null;
        }

        return config('storefront.subdomains')
            ? 'https://'.self::platformHost($slug)
            : Storefront::fallbackUrl($slug);
    }

    /* ────────────────────────────── العنوان ────────────────────────────── */

    /**
     * العنوانُ كما يُطابَق: صغيرًا بلا بادئةٍ ولا مسارٍ ولا منفذ.
     *
     * والعربيُّ يُحوَّل إلى `xn--`: هو ما يصل في `Host` وما يُحفظ في
     * الشهادة. وحفظُه بحروفه العربية يجعل ما يُطابَق غيرَ ما يصل.
     */
    public static function normalize(?string $raw): ?string
    {
        $host = mb_strtolower(trim((string) $raw));
        $host = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];
        $host = explode('?', $host)[0];
        $host = explode('#', $host)[0];
        // المنفذ يسقط — و`[::1]` ليس نطاقًا يُربط أصلًا
        $host = explode(':', $host)[0];
        $host = trim($host, '.');

        if ($host === '') {
            return null;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '') {
                $host = mb_strtolower($ascii);
            }
        }

        if (mb_strlen($host) > 253) {
            return null;
        }

        // تسميتان على الأقلّ، وكلُّ تسميةٍ تبدأ وتنتهي بحرفٍ أو رقم
        $label = '[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?';

        return preg_match('/^'.$label.'(?:\.'.$label.')+$/', $host) === 1 ? $host : null;
    }

    /** العنوان الذي تُوجَّه إليه نطاقات التجّار */
    public static function connectHost(): string
    {
        return (string) config('storefront.connect');
    }

    /**
     * حالُ عناوين هذا النشاط كما تُعرض — بلا كلمةٍ من كلمات النظام.
     *
     * @return array<string, mixed>
     */
    public static function state(int $businessId): array
    {
        $slug = Business::whereKey($businessId)->value('site_slug');
        $host = self::platformHost(is_string($slug) ? $slug : null);
        $custom = self::custom($businessId);

        return [
            'platform' => $host !== null ? ['host' => $host, 'url' => 'https://'.$host] : null,
            'custom' => $custom ? [
                'id' => $custom->id,
                'host' => $custom->hostname,
                'url' => $custom->url(),
                'status' => $custom->status,
                'label' => $custom->label(),
                'reason' => $custom->failure_reason,
                'checked_at' => optional($custom->last_checked_at)->format('Y-m-d H:i'),
                /*
                 * والسجلّ لا يُعرض إلّا لمن يحتاجه.
                 *
                 * من رُبط نطاقُه لا يعنيه أيُّ سجلٍّ أُضيف — عرضُه له يجعل
                 * الشاشة تبدو ناقصةَ عملٍ وهي تامّة.
                 */
                'records' => $custom->isActive() ? [] : app(CustomDomainProvider::class)->instructions($custom),
            ] : null,
            'primary' => self::canonical($businessId, is_string($slug) ? $slug : null),
        ];
    }
}
