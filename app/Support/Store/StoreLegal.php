<?php

namespace App\Support\Store;

use App\Models\Business;
use App\Models\StoreLegalVersion;
use App\Models\StoreTermsVersion;
use App\Support\MarketingSettings;
use App\Support\Seo;
use App\Support\Website\Commerce;
use App\Support\Website\Domains;
use App\Support\Website\MerchantData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * الصفحاتُ القانونيّة لكلّ متجر — سياسةُ الخصوصيّة، وتعليماتُ حذف البيانات، والشروط.
 *
 * ═══ لكلّ متجرٍ بلا عملٍ من أحد ═══
 *
 * لا تُنشأ صفحةٌ لتاجرٍ بيد مدير أبعاد، ولا يُكتب صفٌّ يوم يُفتح متجر: القالبُ
 * (`LegalTemplates`) يُبنى حين تُفتح الشاشة من بيانات المتجر نفسِه — اسمِه،
 * واسمِه القانونيّ، وتواصلِه، وعنوانِ موقعه، وما يعمل فيه فعلًا. فالمتجرُ
 * الأوّلُ والعاشرُ ألفًا سواء، ولا منطقَ هنا باسم متجرٍ بعينه.
 *
 * ═══ ولا يُنشر إلّا ما راجعه صاحبُه ═══
 *
 * الصفحةُ العامّة «غير موجودة» حتّى يضغط صاحبُ المتجر «نشر». والنشرُ يُردّ ما
 * دامت بياناتٌ لازمةٌ ناقصة (الاسمُ القانونيّ ووسيلةُ تواصل)، أو بقي في النصّ
 * ما بين `[[ ]]`، أو فرغت إحدى اللغتين. فلا يُعرض نصٌّ افتراضيٌّ على أنّه
 * سياسةُ متجرٍ نهائيّة.
 *
 * ═══ والمنشورُ نسخٌ لا تُعدَّل ═══
 *
 * المسوّدةُ صفٌّ واحدٌ (الإصدار صفر)، والنشرُ نسخةٌ برقمٍ أعلى بنصٍّ مملوءٍ
 * ثابت. والرجوعُ إلى ما قبلها نشرٌ جديد بنصّها (`restore`) — لا يُمحى شيء.
 *
 * ═══ والشروطُ تُقرأ من مصدرها ═══
 *
 * لها نسخُها وموافقاتُ الزبائن عليها منذ #136 (`StoreTerms`). فلا تُكرَّر هنا:
 * المسوّدةُ وحدها في هذا الجدول، والنشرُ يكتب نسخةَ شروطٍ جديدة. ومن كتب
 * شروطَه من قبل تُعرض صفحتُه منها كما هي، ولا يُكتب فوقها قالب.
 */
final class StoreLegal
{
    public const PRIVACY = 'privacy';

    public const DELETION = 'deletion';

    public const TERMS = 'terms';

    public const KINDS = [self::PRIVACY, self::DELETION, self::TERMS];

    /** الرابطُ العامّ لكلّ نوع — ثابتٌ لا يتبدّل بتعديل النصّ */
    public const PATHS = [
        self::PRIVACY => 'privacy-policy',
        self::DELETION => 'data-deletion',
        self::TERMS => 'terms',
    ];

    /** بياناتُه القانونيّة في إعدادات الموقع */
    public const LEGAL_NAME = 'store_legal_name';

    public const LEGAL_EMAIL = 'store_legal_email';

    /** أنُشرت الشروطُ صفحةً عامّة من هذه الشاشة — وإن لم تُشترط الموافقةُ عليها */
    public const TERMS_PUBLIC = 'store_terms_public';

    public const MAX = 30000;

    /** نوعُ الصفحة من رابطها — أو null لما ليس منها */
    public static function kindAt(?string $path): ?string
    {
        $found = array_search(trim((string) $path, '/'), self::PATHS, true);

        return $found === false ? null : $found;
    }

    public static function title(string $kind, string $lang): string
    {
        $en = $lang === 'en';

        return match ($kind) {
            self::PRIVACY => $en ? 'Privacy Policy' : 'سياسة الخصوصية',
            self::DELETION => $en ? 'Data Deletion Instructions' : 'تعليمات حذف البيانات',
            self::TERMS => $en ? 'Terms of Service' : 'شروط الاستخدام',
        };
    }

    /* ═══════════ بياناتُ المتجر ═══════════ */

    /**
     * ما يُملأ به القالب — من مواضعه الأصليّة لا من نسخةٍ هنا.
     *
     * @return array{store_name: string, legal_name: string, email: string, phone: string, whatsapp: string, website: string}
     */
    public static function facts(int $bid): array
    {
        $identity = MerchantData::identity($bid);
        $site = MarketingSettings::group($bid, 'website');
        $whatsapp = trim((string) ($site['store_whatsapp'] ?? ''));

        return [
            'store_name' => trim((string) $identity['name']),
            'legal_name' => trim((string) ($site[self::LEGAL_NAME] ?? '')),
            'email' => trim((string) ($site[self::LEGAL_EMAIL] ?? '')) ?: trim((string) $identity['email']),
            'phone' => trim((string) (Business::whereKey($bid)->value('phone') ?? '')),
            'whatsapp' => $whatsapp,
            'website' => (string) (Domains::canonical($bid) ?? ''),
        ];
    }

    /**
     * ما يعمل في المتجر فعلًا — فيُذكر في النصّ ما يقع، ولا يُذكر ما لا يقع.
     *
     * @return array{orders: bool, card: bool, whatsapp: bool, analytics: bool}
     */
    public static function features(int $bid): array
    {
        $identity = MerchantData::identity($bid);

        return [
            'orders' => Commerce::checkout($bid),
            'card' => Paymob::enabled($bid),
            'whatsapp' => trim((string) $identity['whatsapp']) !== '',
            'analytics' => Seo::tagFor($bid) !== null,
        ];
    }

    /**
     * البياناتُ اللازمة للنشر وما ينقص منها.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function missing(int $bid, ?array $facts = null): array
    {
        $facts ??= self::facts($bid);
        $out = [];

        if ($facts['legal_name'] === '') {
            $out[] = ['key' => 'legal_name', 'label' => __('الاسم القانوني للنشاط (كما في السجل التجاري)')];
        }

        if ($facts['email'] === '' && $facts['phone'] === '' && $facts['whatsapp'] === '') {
            $out[] = ['key' => 'contact', 'label' => __('بريد إلكتروني أو رقم للتواصل')];
        }

        if ($facts['website'] === '') {
            $out[] = ['key' => 'website', 'label' => __('عنوان الموقع — اختر اسم موقعك من «الدومين»')];
        }

        if ($facts['store_name'] === '') {
            $out[] = ['key' => 'store_name', 'label' => __('اسم المتجر')];
        }

        return $out;
    }

    /* ═══════════ النصّ ═══════════ */

    public static function template(int $bid, string $kind, string $lang): string
    {
        return LegalTemplates::for($kind, $lang, self::features($bid));
    }

    /** النصُّ كما يُحفظ: أطرافٌ مقصوصة وأسطرٌ بلا `\r` */
    public static function clean(?string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
    }

    /**
     * يملأ رموزَ القالب من بيانات المتجر.
     *
     * وما ينقص يُترك علامةً ظاهرة («[ناقص: …]») في المعاينة — والنشرُ لا
     * يصل إليها: يُردّ قبلها (`publish`).
     */
    public static function resolve(string $text, int $bid, string $lang, ?array $facts = null): string
    {
        $facts ??= self::facts($bid);
        $en = $lang === 'en';
        $base = rtrim($facts['website'], '/');
        $gap = fn (string $ar, string $enLabel) => '['.($en ? 'missing: '.$enLabel : 'ناقص: '.$ar).']';

        $contact = array_values(array_filter([
            $facts['email'] !== '' ? ($en ? 'Email: ' : 'البريد الإلكتروني: ').$facts['email'] : null,
            $facts['whatsapp'] !== '' ? ($en ? 'WhatsApp: ' : 'واتساب: ').$facts['whatsapp'] : null,
            $facts['phone'] !== '' && $facts['phone'] !== $facts['whatsapp'] ? ($en ? 'Phone: ' : 'الهاتف: ').$facts['phone'] : null,
        ]));

        $values = [
            'store_name' => $facts['store_name'] ?: $gap('اسم المتجر', 'store name'),
            'legal_name' => $facts['legal_name'] ?: $gap('الاسم القانوني', 'legal name'),
            'website' => $base ?: $gap('عنوان الموقع', 'website address'),
            'contact' => $contact !== [] ? implode("\n", $contact) : $gap('وسيلة التواصل', 'contact details'),
            'privacy_url' => $base !== '' ? $base.'/'.self::PATHS[self::PRIVACY] : $gap('عنوان الموقع', 'website address'),
            'deletion_url' => $base !== '' ? $base.'/'.self::PATHS[self::DELETION] : $gap('عنوان الموقع', 'website address'),
        ];

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => $values[$m[1]] ?? $m[0], $text);
    }

    /**
     * النصُّ أجزاءً يرسمها القالب: عنوانٌ فرعيّ (`## `)، وقائمة (`- `)، وفقرة.
     *
     * @return list<array{type: string, text?: string, items?: list<string>}>
     */
    public static function blocks(string $text): array
    {
        $out = [];
        $para = [];
        $list = [];
        $flush = function () use (&$out, &$para, &$list) {
            if ($para !== []) {
                $out[] = ['type' => 'p', 'text' => implode("\n", $para)];
                $para = [];
            }
            if ($list !== []) {
                $out[] = ['type' => 'ul', 'items' => $list];
                $list = [];
            }
        };

        foreach (explode("\n", self::clean($text)) as $line) {
            $line = trim($line);

            if ($line === '') {
                $flush();
            } elseif (str_starts_with($line, '## ')) {
                $flush();
                $out[] = ['type' => 'h', 'text' => trim(substr($line, 3))];
            } elseif (str_starts_with($line, '- ')) {
                if ($para !== []) {
                    $out[] = ['type' => 'p', 'text' => implode("\n", $para)];
                    $para = [];
                }
                $list[] = trim(substr($line, 2));
            } else {
                if ($list !== []) {
                    $out[] = ['type' => 'ul', 'items' => $list];
                    $list = [];
                }
                $para[] = $line;
            }
        }

        $flush();

        return $out;
    }

    /** سطرٌ مُهرَّب، وروابطُه وبريدُه قابلةٌ للضغط — ولا وسمَ يكتبه صاحبُ النصّ */
    public static function inline(string $text): HtmlString
    {
        $safe = e($text);
        $safe = preg_replace('~https?://[^\s<]+[^\s<.,،)]~u', '<a href="$0" rel="noopener">$0</a>', $safe);
        $safe = preg_replace('~(?<![\w./@-])([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})~', '<a href="mailto:$1">$1</a>', $safe);

        return new HtmlString(nl2br($safe, false));
    }

    /* ═══════════ المنشور ═══════════ */

    /**
     * النصُّ المنشور لنوعٍ — أو null حيث لا صفحةَ عامّة.
     *
     * @return array{ar: string, en: string, version: int, at: ?Carbon}|null
     */
    public static function published(int $bid, string $kind): ?array
    {
        return $kind === self::TERMS ? self::publishedTerms($bid) : self::publishedLegal($bid, $kind);
    }

    private static function publishedLegal(int $bid, string $kind): ?array
    {
        $v = self::latest($bid, $kind);

        return $v === null ? null : [
            'ar' => (string) $v->body_ar, 'en' => (string) $v->body_en,
            'version' => (int) $v->version, 'at' => $v->created_at,
        ];
    }

    /**
     * الشروطُ صفحةً عامّة: نسخةٌ مكتوبة، وصاحبُها نشرها من هذه الشاشة.
     *
     * واشتراطُ الموافقة عليها في الإتمام (`store_terms_on`) لا يفتحها صفحةً وحده.
     * كان يفتحها، فظهرت شروطُ متجرٍ قائمٍ في رابطٍ عامٍّ وفي تذييله يومَ النشر،
     * قبل أن يراجعها صاحبُها هنا — وقرارُ صاحب المنتج أنّ كلَّ صفحةٍ قانونيّةٍ تبقى
     * مسوّدةً حتّى ينشرها التاجرُ بنفسه. والإتمامُ يعرضها ويشترطها كما كان.
     */
    private static function publishedTerms(int $bid): ?array
    {
        // والإعداداتُ أوّلًا — محفوظةٌ في ذاكرة الطلب، فمن لم يفتح شروطَه لا يُسأل عنه سؤالٌ آخر
        $site = MarketingSettings::group($bid, 'website');

        if (($site[self::TERMS_PUBLIC] ?? '0') !== '1') {
            return null;
        }

        $v = StoreTerms::current($bid);

        if ($v === null || (trim((string) $v->body_ar) === '' && trim((string) $v->body_en) === '')) {
            return null;
        }

        return ['ar' => (string) $v->body_ar, 'en' => (string) $v->body_en, 'version' => (int) $v->version, 'at' => $v->created_at];
    }

    /** النصُّ بلغة الصفحة — وإن فرغت فباللغة الأخرى، ولا صفحةَ بيضاء */
    public static function bodyFor(array $published, string $lang): string
    {
        $mine = $lang === 'en' ? $published['en'] : $published['ar'];
        $other = $lang === 'en' ? $published['ar'] : $published['en'];

        return trim($mine) !== '' ? $mine : $other;
    }

    /**
     * روابطُ التذييل — للمنشور وحده، بلغة الصفحة.
     *
     * وتُرسم في كلّ صفحةٍ من صفحات المتجر، فثمنُها ثابت: سؤالٌ واحدٌ عن الأنواع
     * المنشورة، والشروطُ من الإعدادات المحفوظة — لا يكبر بكِبَر المحلّ ولا بعدد الأنواع.
     *
     * @return list<array{key: string, href: string, label: string}>
     */
    public static function links(int $bid, string $base, string $lang): array
    {
        $kinds = StoreLegalVersion::where('business_id', $bid)
            ->where('version', '>', StoreLegalVersion::DRAFT)->distinct()->pluck('kind')->all();

        if (self::publishedTerms($bid) !== null) {
            $kinds[] = self::TERMS;
        }

        $out = [];

        foreach (self::KINDS as $kind) {
            if (in_array($kind, $kinds, true)) {
                $out[] = ['key' => $kind, 'href' => rtrim($base, '/').'/'.self::PATHS[$kind], 'label' => self::title($kind, $lang)];
            }
        }

        return $out;
    }

    private static function latest(int $bid, string $kind): ?StoreLegalVersion
    {
        return StoreLegalVersion::where('business_id', $bid)->where('kind', $kind)
            ->where('version', '>', StoreLegalVersion::DRAFT)->orderByDesc('version')->first();
    }

    private static function draftRow(int $bid, string $kind): ?StoreLegalVersion
    {
        return StoreLegalVersion::where('business_id', $bid)->where('kind', $kind)
            ->where('version', StoreLegalVersion::DRAFT)->first();
    }

    /* ═══════════ الشاشة ═══════════ */

    /**
     * ما يُعرض في المحرّر: المسوّدة، وإلّا المنشور، وإلّا القالب.
     *
     * @return array{ar: string, en: string, from: string}  `from`: draft | published | existing | template
     */
    public static function source(int $bid, string $kind): array
    {
        if (($d = self::draftRow($bid, $kind)) !== null) {
            return ['ar' => (string) $d->body_ar, 'en' => (string) $d->body_en, 'from' => 'draft'];
        }

        // والشروطُ المكتوبة تُفتح كما كُتبت — ولو لم تُنشر صفحةً بعد
        $existing = $kind === self::TERMS ? StoreTerms::current($bid) : self::latest($bid, $kind);

        if ($existing !== null && (trim((string) $existing->body_ar) !== '' || trim((string) $existing->body_en) !== '')) {
            // وشروطٌ مكتوبةٌ لم تُنشر صفحةً بعد «قائمةٌ» لا «منشورة» — تُراجَع ثمّ تُنشر
            $from = $kind === self::TERMS && self::publishedTerms($bid) === null ? 'existing' : 'published';

            return ['ar' => (string) $existing->body_ar, 'en' => (string) $existing->body_en, 'from' => $from];
        }

        return ['ar' => self::template($bid, $kind, 'ar'), 'en' => self::template($bid, $kind, 'en'), 'from' => 'template'];
    }

    /** أله نسخةٌ سابقةٌ يُرجع إليها؟ */
    public static function previous(int $bid, string $kind): ?object
    {
        if ($kind === self::TERMS) {
            $current = StoreTerms::current($bid);

            return $current === null ? null : StoreTermsVersion::where('business_id', $bid)
                ->where('version', '<', $current->version)->orderByDesc('version')->first();
        }

        $current = self::latest($bid, $kind);

        return $current === null ? null : StoreLegalVersion::where('business_id', $bid)->where('kind', $kind)
            ->where('version', '>', StoreLegalVersion::DRAFT)->where('version', '<', $current->version)
            ->orderByDesc('version')->first();
    }

    /** يحفظ المسوّدة — ولا يتبدّل شيءٌ في الصفحة العامّة */
    public static function saveDraft(int $bid, string $kind, ?string $ar, ?string $en): void
    {
        self::check($kind, $ar, $en);

        StoreLegalVersion::updateOrCreate(
            ['business_id' => $bid, 'kind' => $kind, 'version' => StoreLegalVersion::DRAFT],
            ['body_ar' => self::clean($ar), 'body_en' => self::clean($en), 'created_by' => auth()->id()],
        );

    }

    /**
     * ينشر النصّ نسخةً جديدةً مملوءةً ببيانات المتجر — أو يردّ ما يمنعه.
     *
     * @throws ValidationException
     */
    public static function publish(int $bid, string $kind, ?string $ar, ?string $en, ?int $userId): int
    {
        self::check($kind, $ar, $en);
        $ar = self::clean($ar);
        $en = self::clean($en);
        $facts = self::facts($bid);

        $errors = [];

        if ($ar === '' || $en === '') {
            $errors['body'] = __('اكتب النص بالعربية والإنجليزية قبل النشر — زبونك يقرأ إحداهما.');
        }

        if (str_contains($ar.$en, '[[') || str_contains($ar.$en, ']]')) {
            $errors['body'] = __('أكمل ما بين [[ ]] بما يخص متجرك قبل النشر.');
        }

        if (($gaps = self::missing($bid, $facts)) !== []) {
            $errors['facts'] = __('أكمل البيانات الناقصة أولًا: :list', ['list' => implode('، ', array_column($gaps, 'label'))]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $ar = self::resolve($ar, $bid, 'ar', $facts);
        $en = self::resolve($en, $bid, 'en', $facts);

        $version = DB::transaction(function () use ($bid, $kind, $ar, $en, $userId) {
            Business::whereKey($bid)->lockForUpdate()->first();

            if ($kind === self::TERMS) {
                $v = StoreTerms::revise($bid, $ar, $en, $userId);
                MarketingSettings::save($bid, 'website', [self::TERMS_PUBLIC => '1']);
                $number = (int) $v->version;
            } else {
                $current = self::latest($bid, $kind);
                $number = (int) ($current?->version ?? 0) + 1;

                if ($current === null || $current->body_ar !== $ar || $current->body_en !== $en) {
                    StoreLegalVersion::create([
                        'business_id' => $bid, 'kind' => $kind, 'version' => $number,
                        'body_ar' => $ar, 'body_en' => $en, 'created_by' => $userId,
                    ]);
                } else {
                    $number = (int) $current->version;
                }
            }

            self::draftRow($bid, $kind)?->delete();

            return $number;
        });

        MarketingSettings::forget($bid);

        return $version;
    }

    /** يُرجع النصَّ السابق — نشرًا جديدًا بنصّه، لا محوًا لما بعده */
    public static function restore(int $bid, string $kind, ?int $userId): ?int
    {
        $prev = self::previous($bid, $kind);

        if ($prev === null) {
            return null;
        }

        return DB::transaction(function () use ($bid, $kind, $prev, $userId) {
            Business::whereKey($bid)->lockForUpdate()->first();

            if ($kind === self::TERMS) {
                $number = (int) StoreTerms::revise($bid, (string) $prev->body_ar, (string) $prev->body_en, $userId)->version;
            } else {
                $number = (int) self::latest($bid, $kind)->version + 1;
                StoreLegalVersion::create([
                    'business_id' => $bid, 'kind' => $kind, 'version' => $number,
                    'body_ar' => (string) $prev->body_ar, 'body_en' => (string) $prev->body_en, 'created_by' => $userId,
                ]);
            }

            self::draftRow($bid, $kind)?->delete();

            return $number;
        });
    }

    private static function check(string $kind, ?string $ar, ?string $en): void
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        foreach (['body_ar' => $ar, 'body_en' => $en] as $field => $text) {
            if (mb_strlen(self::clean($text)) > self::MAX) {
                throw ValidationException::withMessages([$field => __('النص أطول من الحد المسموح.')]);
            }
        }
    }
}
