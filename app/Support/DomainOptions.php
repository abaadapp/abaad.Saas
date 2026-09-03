<?php

namespace App\Support;

use App\Models\Setting;

/**
 * الطرق الثلاث إلى عنوانٍ على الإنترنت — وتكلفةُ كلٍّ منها.
 *
 * كانت شاشة الدومين حقلًا واحدًا: «اكتب نطاقك». وهي تفترض أنّ للتاجر نطاقًا
 * أصلًا، وأنّه يعرف ما النطاق ومن أين يُشترى وبكم. ومن لا يملك واحدًا يقف
 * أمام حقلٍ فارغ لا يقول له ماذا يفعل، فيتركه فارغًا ويبقى متجره بلا عنوان.
 *
 * فصارت ثلاثًا تُعرض قبل أيّ حقل، ولكلٍّ تكلفتُها مكتوبةً قبل المتابعة لا
 * بعدها: من يملك نطاقًا يربطه، ومن لا يملكه يطلب من أبعاد تجهيزه، ومن لا
 * يريد أن يشتري شيئًا يحجز نطاقًا فرعيًّا.
 *
 * والأسعار من إعدادات المنصّة لا من هذا الملفّ: رقمٌ مكتوبٌ هنا يعني نشرةً
 * كاملة لتغيير سعر، ويعني أن ما يراه التاجر قد لا يكون ما يبيعه المشغّل.
 */
class DomainOptions
{
    /** نطاقٌ يملكه التاجر ويربطه بنفسه */
    public const OWN = 'own';

    /** نطاقٌ فرعيٌّ تابع لأبعاد — يُحجز الاسم، والاستضافة قيد التجهيز */
    public const SUBDOMAIN = 'subdomain';

    /** نطاقٌ جديد تشتريه أبعاد وتجهّزه — طلبُ خدمة يتابعه المشغّل */
    public const SERVICE = 'new';

    public const MODES = [self::OWN, self::SUBDOMAIN, self::SERVICE];

    /**
     * لاحقة النطاقات الفرعية حين لا يضبطها المشغّل.
     *
     * `abaadapp.om` هو نطاق المنصّة الذي تعمل عليه اليوم. وهي إعدادٌ لا ثابتٌ
     * مدفون لأنّ النطاق يُبدَّل مرّةً في عمر المنصّة، وحين يُبدَّل يجب ألّا
     * يكون تبديلُه نشرةَ كود.
     */
    public const DEFAULT_SUFFIX = 'abaadapp.om';

    /**
     * أسماءٌ لا تُحجز.
     *
     * `app` هو عنوان اللوحة نفسها (app.abaadapp.om)، و`mail` و`www` وأخواتُها
     * أسماءٌ تحتاجها البنية التحتية. وتاجرٌ يحجز أحدها لا يحصل على متجرٍ بل
     * يُسقِط خدمةً قائمة يوم تُوصَل النطاقات الفرعية فعلًا.
     */
    public const RESERVED = [
        'app', 'www', 'admin', 'api', 'mail', 'smtp', 'imap', 'pop', 'ftp',
        'ns1', 'ns2', 'dns', 'cdn', 'static', 'assets', 'pos', 'demo',
        'test', 'staging', 'dev', 'support', 'help', 'status', 'abaad', 'abaadapp',
    ];

    /** مفاتيح التسعير في إعدادات المنصّة — تُقرأ هنا وتُكتب في SettingController */
    public const PRICE_KEYS = [
        'domain_subdomain_price',
        'domain_setup_price',
    ];

    /** مفتاح اللاحقة — إعدادُ بنيةٍ لا سعر، فلا يُقرأ مع الأسعار */
    public const SUFFIX_KEY = 'domain_subdomain_suffix';

    /**
     * ما تعرضه الشاشة من إعدادات المنصّة — باستعلامٍ واحد.
     *
     * `pricing()` و`suffix()` تقرآن الجدول نفسه، ونداؤهما معًا في كلّ فتحةٍ
     * لصفحة الإعدادات استعلامان لصفوفٍ متجاورة. والشاشة تحتاجهما معًا دائمًا.
     *
     * @return array{pricing: array<string, float|null>, suffix: string}
     */
    public static function view(): array
    {
        $saved = Setting::whereNull('business_id')
            ->whereIn('key', [...self::PRICE_KEYS, self::SUFFIX_KEY])
            ->pluck('value', 'key')
            ->all();

        return [
            'pricing' => self::pricingFrom($saved),
            'suffix' => self::suffixFrom($saved),
        ];
    }

    /**
     * ما يدفعه التاجر لأبعاد مقابل كلّ خيار — وnull تعني «لم يُسعَّر بعد».
     *
     * والفرق بين `0` و`null` فرقٌ يراه التاجر: الصفر «مجّاني» يطمئنه فيمضي،
     * والغياب «يُحدَّد بالتواصل» يقول له إنّ عليه أن يسأل. وخلطُهما يعني إمّا
     * وعدًا بالمجّان لم يقطعه أحد، أو تخويفًا بسعرٍ لا وجود له.
     */
    public static function pricing(): array
    {
        return self::pricingFrom(
            Setting::whereNull('business_id')->whereIn('key', self::PRICE_KEYS)->pluck('value', 'key')->all()
        );
    }

    /** @param  array<string, string|null>  $saved */
    private static function pricingFrom(array $saved): array
    {
        return [
            /*
             * النطاق الذي يملكه التاجر لا تأخذ أبعاد عليه شيئًا — والربط
             * سطرٌ في حقل. وتجديدُه السنويّ يدفعه لمزوّده هو، وهذا مكتوبٌ
             * في البطاقة كي لا يظنّ أنّ «مجّاني» تعني ألّا يدفع لأحد.
             */
            'own' => 0.0,
            'subdomain' => self::price($saved['domain_subdomain_price'] ?? null),
            'setup' => self::price($saved['domain_setup_price'] ?? null),
        ];
    }

    /** لاحقة النطاقات الفرعية كما ضبطها المشغّل */
    public static function suffix(): string
    {
        return self::suffixFrom([
            self::SUFFIX_KEY => Setting::whereNull('business_id')->where('key', self::SUFFIX_KEY)->value('value'),
        ]);
    }

    /** @param  array<string, string|null>  $saved */
    private static function suffixFrom(array $saved): string
    {
        $value = trim((string) ($saved[self::SUFFIX_KEY] ?? ''));

        return $value !== '' ? $value : self::DEFAULT_SUFFIX;
    }

    /** العنوان الكامل للنطاق الفرعي — الاسم واللاحقة */
    public static function host(string $label): string
    {
        return $label.'.'.self::suffix();
    }

    /**
     * الخيار الذي عليه هذا المتجر — أو '' إن لم يختر بعد.
     *
     * ومن ضبط نطاقه قبل هذه النسخة صاحبُ خيارٍ لا صاحبُ فراغ: الهجرة تكتب
     * له `own`، وهذا السطر يمسك ما فاتها. وبدونه تُفتح شاشةُ الاختيار في وجه
     * تاجرٍ نطاقُه يعمل منذ شهور فيظنّ أنّ إعداده ضاع.
     */
    public static function mode(array $site): string
    {
        $mode = trim((string) ($site['site_domain_mode'] ?? ''));

        if (in_array($mode, self::MODES, true)) {
            return $mode;
        }

        return trim((string) ($site['site_domain'] ?? '')) !== '' ? self::OWN : '';
    }

    /** هل حجز متجرٌ آخر هذا الاسم؟ الاسم الواحد لا يشير إلى متجرين */
    public static function subdomainTaken(string $label, int $exceptBusinessId): bool
    {
        return Setting::where('key', 'site_subdomain')
            ->where('business_id', '!=', $exceptBusinessId)
            ->whereRaw('LOWER(value) = ?', [mb_strtolower($label)])
            ->exists();
    }

    /** نصٌّ فارغ أو غير رقميّ = بلا سعر */
    private static function price(?string $raw): ?float
    {
        $raw = trim((string) $raw);

        return $raw !== '' && is_numeric($raw) ? (float) $raw : null;
    }
}
