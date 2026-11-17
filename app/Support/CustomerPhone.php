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
 * مفتاحُ عُمان، وثماني خاناتٍ فأقلّ رقمٌ عُمانيٌّ محلّيّ. وما زاد على
 * الثماني بلا علامة لا تُعرف دولتُه (`UNMARKED`): يُردّ إن كُتب الآن ويُطلب
 * «+» أو «00»، ويُعرض في المراجعة إن كان محفوظًا قبلُ بحالةٍ مستقلّة —
 * «رقم يحتاج تأكيد الدولة» — لا خطأً. التفصيلُ في `problem`.
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

    /** أكثرُ من ثماني خاناتٍ بلا «+» ولا «00» ولا «968» — لا تُعرف دولتُه */
    public const UNMARKED = 'unmarked';

    /**
     * مفاتيحُ دولٍ رقمُها الدوليُّ كلُّه ثماني خانات (مفتاحٌ وخمسٌ بعده).
     *
     * جزرٌ صغيرة: سانت هيلينا، فوكلاند، تونغا، جزر سليمان، كوك، كيريباتي،
     * توفالو. وما سواها بثماني خاناتٍ بعد العلامة يُقرأ عُمانيًّا بلا مفتاح.
     */
    private const EIGHT_DIGIT_CODES = ['290', '500', '676', '677', '682', '686', '688'];

    /** حالةُ الصفّ في قائمة العملاء — أربعُ عباراتٍ يقرؤها صاحبُ النشاط */
    public const STATUS_MISSING = 'missing';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_INVALID = 'invalid';

    /** «رقم يحتاج تأكيد الدولة» — ليس خطأً، بل رقمٌ لا تُعرف دولتُه بيقين */
    public const STATUS_COUNTRY = 'country';

    /** علاماتُ الاتّجاه والمسافةُ غيرُ الفاصلة — تلتصق بالرقم حين يُنسخ من شاشةٍ عربيّة */
    private const INVISIBLE = '/[\x{00A0}\x{2000}-\x{200F}\x{202A}-\x{202F}\x{205F}\x{2066}-\x{2069}\x{3000}\x{FEFF}]/u';

    /** مدّةُ حفظ قائمة المراجعة — وتُمحى قبلها كلّما حُفظ عميلٌ في المتجر */
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
     * 3. ثماني خاناتٍ فأقلّ بلا علامة: رقمٌ عُمانيّ — ودون الثماني ناقص.
     * 4. أكثرُ من ثماني خاناتٍ بلا علامة: لا تُعرف دولتُه (`UNMARKED`). قد
     *    يكون رقمًا عُمانيًّا زائدًا، أو دوليًّا كُتب بلا «+» — ولا سبيلَ إلى
     *    التمييز بلا حقل دولة. فلا يُقبل إذا كُتب الآن (`refusal`)، ولا
     *    يُعدّ خطأً إن كان محفوظًا قبلُ (`status` ⇐ «يحتاج تأكيد الدولة»).
     */
    public static function problem(?string $raw): ?string
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

        // رقمٌ محلّيٌّ لدولة المنصّة — وقاعدةُ الثماني لعُمان وحدها
        if ((string) config('whatsapp.default_country_code', self::OMAN) !== self::OMAN) {
            return WhatsAppPhone::isValid($digits) ? null : self::FOREIGN;
        }

        if (strlen($digits) > self::OMAN_DIGITS) {
            return self::UNMARKED;
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
        return match (self::problem($raw)) {
            null, self::MISSING => null,
            self::SHORT, self::LONG => __('رقم الهاتف العُماني يجب أن يتكون من 8 أرقام.'),
            /*
             * والجملةُ الأولى رسالةُ القاعدة نفسُها، والثانيةُ تقول كيف يُكتب
             * الرقمُ الدوليّ في النظام: بـ«+» أو «00» ثمّ مفتاح الدولة — فمن
             * كتب رقمًا إماراتيًّا بلا علامة يعرف ما يصحّحه.
             */
            self::UNMARKED => __('رقم الهاتف العُماني يجب أن يتكون من 8 أرقام. أمّا الرقم الدولي فيُكتب بـ + أو 00 ثمّ مفتاح الدولة، مثل +971501234567 أو 00971501234567.'),
            self::NO_CODE => __('هذا رقمٌ عُمانيٌّ بلا مفتاحه — اكتبه +968 ثمّ الأرقام الثمانية، أو اكتب الرقم الدولي كاملًا بمفتاح دولته.'),
            self::CHARS => __('رقم الهاتف لا يقبل إلّا الأرقام والمسافات والشرطة، و+ في أوّله.'),
            default => __('الرقم الدولي غير صحيح — بعد + أو 00 يُكتب مفتاح الدولة ثمّ الرقم، من 9 إلى 15 خانة، مثل +971501234567.'),
        };
    }

    /**
     * حالةُ العميل في قائمة المراجعة — أو `null` إن لم يحتج مراجعة.
     *
     * والجهةُ تُعرف بمن يردّ عنها: بلا هاتفٍ ومعها رقمُ تواصل يُقرأ رقمُ
     * التواصل. و«عميل نقدي» (صفُّ العابر في بيانات بعض المتاجر) لا رقمَ له
     * بطبيعته، فلا يُعدّ ناقصًا.
     *
     * ورقمٌ لا تُعرف دولتُه (`UNMARKED`) حالتُه «يحتاج تأكيد الدولة» لا
     * «غير صحيح»: لا يمنع بيعًا ولا فاتورةً ولا تعديلَ العميل، ويُصحَّح
     * بثماني خاناتٍ أو بمفتاح دولته.
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
            self::UNMARKED => self::STATUS_COUNTRY,
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
     * والقائمةُ محفوظةٌ عشر دقائق (`TTL`)، يقرؤها عدّادُ اللوحة ومرشِّحُ القائمة
     * وكلُّ صفحةٍ منه — فلا يُعاد الفحصُ مع كلّ فتحٍ أو انتقال صفحة. وتُمحى
     * بحفظ أيّ عميلٍ في المتجر (`Customer::booted`)، فالتصحيحُ يظهر لحظتَه.
     *
     * @return list<int>
     */
    public static function reviewIds(int $businessId): array
    {
        return Cache::remember(self::key($businessId), self::TTL, fn () => self::scan($businessId));
    }

    /** عددُهم لتنبيه اللوحة — من القائمة المحفوظة نفسِها */
    public static function reviewCount(int $businessId): int
    {
        return count(self::reviewIds($businessId));
    }

    /** @return list<int> */
    private static function scan(int $businessId): array
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

    public static function forget(int $businessId): void
    {
        Cache::forget(self::key($businessId));
    }

    private static function key(int $businessId): string
    {
        return 'customer-phone-review-ids:'.$businessId;
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
