<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * رقمُ هاتف العميل: هل يصلح، ولماذا لا — قاعدةٌ واحدة للخادم والشاشة والتنبيه.
 *
 * ═══ الرقمُ العُمانيّ ثماني خانات، بلا مفتاح الدولة ═══
 *
 * يُقبل كما يكتبه الناس: «91234567» و«+968 9123 4567» و«00968-91234567»،
 * وبالأرقام العربيّة والهنديّة. المسافاتُ والشرطاتُ (والأقواسُ والنقطة، كما
 * يقبلها نموذجُ طلب الموقع منذ بُني) تُنظَّف قبل العدّ، و«+» لا تُقبل إلّا
 * في أوّله. وما سوى ذلك (حرفٌ أو رمزٌ آخر) يُردّ ولا يُخمَّن.
 * ولا بادئةَ مفروضة: المطلوبُ الطولُ وحده.
 *
 * ═══ ودولةُ الرقم تُقرأ من مفتاحه ═══
 *
 * لا عمودَ للدولة في صفّ العميل. فالرقمُ بعلامةٍ دوليّة («+971…» أو «00971…»)
 * يُقرأ رقمَ دولته، ولا يُفرض عليه الثماني. وبلا علامة: «968» في أوّله
 * مفتاحُ عُمان، وتسعُ خاناتٍ فأقلّ رقمٌ عُمانيٌّ محلّيّ. وما زاد بلا علامة
 * يُردّ إن كُتب الآن (يُطلب «+» أو «00»)، ويُترك إن كان محفوظًا قبلُ —
 * فلا يمرّ رقمٌ عُمانيٌّ زائدٌ على أنّه دوليّ، ولا يُخطَّأ رقمٌ دوليٌّ قديم
 * بلا يقين. التفصيلُ في `problem`.
 *
 * ═══ ولا يُكتب شيءٌ في صفّ العميل ═══
 *
 * هذا فحصٌ يُسأل ويُعدّ، لا تصحيح. الرقمُ القديم يبقى كما كُتب حتى يصحّحه
 * صاحبُ النشاط بنفسه — ومرآتُه في الشاشة `resources/js/lib/customer-phone.ts`،
 * والحالاتُ المشتركة في `tests/fixtures/customer-phone-cases.json`.
 */
final class CustomerPhone
{
    public const OMAN = '968';

    public const OMAN_DIGITS = 8;

    /** لماذا لا يصلح الرقم */
    public const MISSING = 'missing';

    public const SHORT = 'short';

    public const LONG = 'long';

    public const CHARS = 'chars';

    public const FOREIGN = 'foreign';

    /** «+» أو «00» ثمّ ثماني خاناتٍ وحدها — رقمٌ عُمانيٌّ نُسي مفتاحُه في الغالب */
    public const NO_CODE = 'nocode';

    /** أكثرُ من تسع خاناتٍ بلا «+» ولا «00» ولا «968» — لا يُعرف لأيّ دولة */
    public const UNMARKED = 'unmarked';

    /**
     * مفاتيحُ دولٍ رقمُها الدوليُّ كلُّه ثماني خانات (مفتاحٌ وخمسٌ بعده).
     *
     * جزرٌ صغيرة: سانت هيلينا، فوكلاند، تونغا، جزر سليمان، كوك، كيريباتي،
     * توفالو. وما سواها بثماني خاناتٍ بعد العلامة يُقرأ عُمانيًّا بلا مفتاح.
     */
    private const EIGHT_DIGIT_CODES = ['290', '500', '676', '677', '682', '686', '688'];

    /** حالةُ الصفّ في قائمة العملاء — ثلاثُ كلماتٍ يقرؤها صاحبُ النشاط */
    public const STATUS_MISSING = 'missing';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_INVALID = 'invalid';

    /** علاماتُ الاتّجاه والمسافةُ غيرُ الفاصلة — تلتصق بالرقم حين يُنسخ من شاشةٍ عربيّة */
    private const INVISIBLE = '/[\x{00A0}\x{2000}-\x{200F}\x{202A}-\x{202F}\x{205F}\x{2066}-\x{2069}\x{3000}\x{FEFF}]/u';

    /** مدّةُ عدّاد التنبيه — ويُمحى قبلها كلّما حُفظ عميلٌ في المتجر */
    private const TTL = 600;

    /**
     * ما الخطأ في الرقم — أو `null` إن صلح.
     *
     * والفراغُ `missing`: سببٌ يُعرض في التنبيه، لا رفضٌ عند الإدخال (`refusal`).
     *
     * ═══ وبلا عمود دولة، تُقرأ الدولةُ من علامة الرقم ═══
     *
     * 1. «+» أو «00»: رقمٌ دوليّ. مفتاحُه 968 ⇐ ثماني خاناتٍ بعده بالضبط.
     *    وغيرُه بين تسعٍ وخمس عشرة خانة، لا يبدأ بصفر. وثماني خاناتٍ وحدها
     *    بعد العلامة رقمٌ عُمانيٌّ بلا مفتاحه — إلّا لدولةٍ رقمُها كذلك.
     * 2. «968» في أوّله وأكثرُ من تسع خانات: مفتاحُ عُمان كُتب بلا «+».
     * 3. تسعُ خاناتٍ فأقلّ بلا علامة: رقمٌ عُمانيّ، ثماني خاناتٍ بالضبط.
     * 4. أكثرُ من تسعٍ بلا علامة: لا يُعرف لأيّ دولة.
     *    - `$strict` (ما يُكتب الآن): يُردّ ويُطلب «+» أو «00» — فلا يمرّ
     *      رقمٌ عُمانيٌّ زائدٌ على أنّه دوليّ لطوله.
     *    - بلا `$strict` (ما حُفظ قبلُ): يُقرأ دوليًّا كما يقرؤه
     *      `WhatsAppPhone::normalize` اليوم، ولا يُعدّ خطأً بلا يقين —
     *      إلّا ما يبدأ بصفر: لا يكون دوليًّا ولا عُمانيًّا.
     */
    public static function problem(?string $raw, bool $strict = false): ?string
    {
        $text = trim((string) preg_replace(self::INVISIBLE, ' ', (string) $raw));

        if ($text === '') {
            return self::MISSING;
        }

        $text = (string) Digits::western($text);

        // أرقامٌ، وتنسيقٌ يُنظَّف بأمان (مسافةٌ وشرطةٌ وقوسٌ ونقطة)، و«+» في أوّله وحده
        if (! preg_match('/^\+?\s*[0-9(][0-9\s().-]*$/', $text) || ! preg_match('/[0-9]/', $text)) {
            return self::CHARS;
        }

        $digits = (string) preg_replace('/\D+/', '', $text);

        if (str_starts_with($text, '+') || str_starts_with($digits, '00')) {
            $international = str_starts_with($text, '+') ? $digits : substr($digits, 2);

            return str_starts_with($international, self::OMAN)
                ? self::omani(substr($international, strlen(self::OMAN)))
                : self::foreign($international);
        }

        if (strlen($digits) > 9 && str_starts_with($digits, self::OMAN)) {
            return self::omani(substr($digits, strlen(self::OMAN)));
        }

        if (strlen($digits) > 9) {
            if ($strict) {
                return self::UNMARKED;
            }

            return str_starts_with($digits, '0') ? self::FOREIGN : null;
        }

        // رقمٌ محلّيٌّ لدولة المنصّة — وقاعدةُ الثماني لعُمان وحدها
        if ((string) config('whatsapp.default_country_code', self::OMAN) !== self::OMAN) {
            return WhatsAppPhone::isValid($digits) ? null : self::FOREIGN;
        }

        return self::omani($digits);
    }

    /**
     * سببُ رفض رقمٍ يُكتب الآن — أو `null` إن صلح أو تُرك فارغًا.
     *
     * والفراغُ مقبولٌ كما كان (`Customers::phoneRule`): عابرٌ بلا هاتف عميلٌ صحيح.
     */
    public static function refusal(?string $raw): ?string
    {
        return match (self::problem($raw, strict: true)) {
            null, self::MISSING => null,
            self::SHORT, self::LONG => __('رقم الهاتف العُماني يجب أن يتكون من 8 أرقام.'),
            // والجملةُ الأولى هي رسالةُ القاعدة نفسُها، والثانيةُ تدلّ صاحبَ الرقم الأجنبيّ
            self::UNMARKED => __('رقم الهاتف العُماني يجب أن يتكون من 8 أرقام. وإن كان الرقم لدولةٍ أخرى فاكتب + أو 00 قبل مفتاح دولته، مثل +971501234567.'),
            self::NO_CODE => __('هذا رقمٌ عُمانيٌّ بلا مفتاحه — اكتبه +968 ثمّ الأرقام الثمانية، أو اكتب الرقم الدولي كاملًا بمفتاح دولته.'),
            self::CHARS => __('رقم الهاتف لا يقبل إلّا الأرقام والمسافات والشرطة، و+ في أوّله.'),
            default => __('الرقم الدولي غير صحيح — اكتبه بمفتاح دولته، مثل +971501234567.'),
        };
    }

    /**
     * حالةُ العميل في قائمة المراجعة — أو `null` إن لم يحتج مراجعة.
     *
     * والجهةُ تُعرف بمن يردّ عنها: بلا هاتفٍ ومعها رقمُ تواصل يُقرأ رقمُ
     * التواصل. و«عميل نقدي» (صفُّ العابر في بيانات بعض المتاجر) لا رقمَ له
     * بطبيعته، فلا يُعدّ ناقصًا.
     */
    public static function status(?string $phone, ?string $contactPhone = null, ?string $name = null): ?string
    {
        $raw = filled($phone) ? $phone : $contactPhone;
        $problem = self::problem($raw);

        if ($problem === self::MISSING && trim((string) $name) === FlowerOrder::WALK_IN) {
            return null;
        }

        return match ($problem) {
            null => null,
            self::MISSING => self::STATUS_MISSING,
            self::SHORT => self::STATUS_INCOMPLETE,
            default => self::STATUS_INVALID,
        };
    }

    /**
     * معرّفاتُ عملاء المتجر الذين تحتاج أرقامُهم مراجعة — قراءةٌ فقط.
     *
     * والفحصُ في التطبيق لا في `where`: الأرقامُ العربيّة والمفتاحُ والمسافاتُ
     * قاعدةٌ لا تكتبها SQL بالشكل نفسِه في PostgreSQL وSQLite. لكنّ أكثرَ
     * الأرقام ثماني خاناتٍ لاتينيّةٍ صحيحة، فتُستبعد في القاعدة أوّلًا ولا
     * تُقرأ — ويُقرأ الباقي بالمؤشّر صفًّا صفًّا، بأعمدته الأربعة وحدها.
     *
     * @return list<int>
     */
    public static function reviewIds(int $businessId): array
    {
        $query = DB::table('customers')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->select(['id', 'name', 'phone', 'contact_phone'])
            ->orderBy('id');

        $plain = self::plainEightDigits();
        if ($plain !== null) {
            $query->where(fn ($q) => $q->whereNull('phone')->orWhereRaw('NOT ('.$plain.')'));
        }

        $ids = [];
        foreach ($query->cursor() as $row) {
            if (self::status($row->phone, $row->contact_phone, $row->name) !== null) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }

    /** عددُهم لتنبيه اللوحة — محفوظًا عشر دقائق، ويُمحى بحفظ أيّ عميلٍ في المتجر */
    public static function reviewCount(int $businessId): int
    {
        return (int) Cache::remember(self::key($businessId), self::TTL, fn () => count(self::reviewIds($businessId)));
    }

    public static function forget(int $businessId): void
    {
        Cache::forget(self::key($businessId));
    }

    private static function key(int $businessId): string
    {
        return 'customer-phone-review:'.$businessId;
    }

    /** ثماني خاناتٍ لاتينيّةٍ بلا شيءٍ معها — بلغة القاعدة، أو `null` فيُفحص كلُّ صفّ */
    private static function plainEightDigits(): ?string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "phone ~ '^[0-9]{8}$'",
            'sqlite' => "phone GLOB '".str_repeat('[0-9]', self::OMAN_DIGITS)."'",
            'mysql', 'mariadb' => "phone REGEXP '^[0-9]{8}$'",
            default => null,
        };
    }

    private static function omani(string $local): ?string
    {
        return match (true) {
            strlen($local) < self::OMAN_DIGITS => self::SHORT,
            strlen($local) > self::OMAN_DIGITS => self::LONG,
            default => null,
        };
    }

    /** رقمٌ دوليٌّ بعد علامته: لا صفرَ في أوّله، وبين تسعٍ وخمس عشرة خانة (E.164) */
    private static function foreign(string $digits): ?string
    {
        $n = strlen($digits);

        if (str_starts_with($digits, '0') || $n < 8 || $n > 15) {
            return self::FOREIGN;
        }

        if ($n === 8) {
            return in_array(substr($digits, 0, 3), self::EIGHT_DIGIT_CODES, true) ? null : self::NO_CODE;
        }

        return null;
    }
}
