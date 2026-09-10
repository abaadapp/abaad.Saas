<?php

namespace App\Support\Document;

use App\Support\InvoiceBranding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;

/**
 * هويّةُ أوراق المتجر — لونُه وغلافُه، فوق شعارِه واسمِه.
 *
 * ═══ ولمَ لم تُدمَج في `InvoiceBranding` ═══
 *
 * تلك تملك ما كان: الاسمَ المعروض، ولغةَ الطبع، وملفَّ الشعار — وثلاثتُها
 * مقروءةٌ من فاتورة العميل وشاشتِها ومن `Paper::brand`، ولها اختبارٌ يحرس
 * أنّ العنوان لا يُطبع (`TheInvoiceCarriesTheShopsIdentityTest`). وإقحامُ
 * اللون والغلاف فيها يخلط ما يُقرأ في كلّ ورقةٍ بما لا تقرؤه إلّا القوالبُ
 * الجديدة.
 *
 * وهذه تملك ما استُجدّ. **والشعارُ يبقى هناك**: مالكٌ واحدٌ لكتابة العمود
 * (`InvoiceBranding::storeLogo`)، وقارئان يكتبان في `businesses.logo`
 * يفترقان عند أوّل تعديلٍ في صيغة المسار. فهذه تُنادي تلك ولا تنسخها.
 *
 * ═══ والغلافُ صورةٌ في الإعدادات لا عمودٌ في `businesses` ═══
 *
 * `businesses` جدولُ المنصّة: فيه ما تعرفه أبعادُ عن المتجر — اسمُه
 * ومدينتُه واشتراكُه. والغلافُ زينةُ ورقةٍ يبدّلها التاجر متى شاء، ومكانُه
 * حيث تعيش بقيّةُ تفضيلاته.
 */
class Branding
{
    /** لونُ الهوية على الورق */
    public const PRIMARY = 'document_primary';

    /** لمسةٌ ثانية — تُترك فارغةً فتُشتقّ من الأوّل */
    public const ACCENT = 'document_accent';

    /** مسارُ صورة الغلاف على القرص العامّ */
    public const COVER = 'document_cover';

    /**
     * ما يُضمَّن في الورقة من الغلاف — وما فوقه يُوصَل برابط.
     *
     * والغلافُ أكبرُ من الشعار بطبعه: صورةٌ بعرض الورقة. والحدُّ هنا بقدر
     * ما يُقبل رفعُه، لا أضيقُ منه: حدٌّ أضيق يعني صورةً تُقبل في الرفع
     * وتغيب عن الطبع — تُرى في الشاشة ولا تخرج على الورق، وهو أسوأ من
     * ردّها عند الرفع.
     */
    private const INLINE_MAX = 4194304;

    /**
     * اختياراتُ الهوية كما اختارها — لا كما تُشتقّ.
     *
     * @return array<string, string>
     */
    public static function brand(int $businessId): array
    {
        $rows = Setting::where('business_id', $businessId)
            ->whereIn('key', [self::PRIMARY, self::ACCENT])
            ->pluck('value', 'key');

        return Theme::normalize([
            'primary' => $rows[self::PRIMARY] ?? null,
            'accent' => $rows[self::ACCENT] ?? null,
        ]);
    }

    /**
     * الرموز جاهزةً للرسم — الهويّةُ محلولةً بمعامل الخطّ.
     *
     * @return array<string, string|float>
     */
    public static function tokens(int $businessId, float $scale = 1.0): array
    {
        return Theme::tokens(self::brand($businessId), $scale);
    }

    /** حفظُ اللونين — بالقاعدة نفسها التي تُقرأ بها */
    public static function save(int $businessId, array $data): void
    {
        $clean = Theme::normalize($data, self::brand($businessId));

        foreach ([self::PRIMARY => 'primary', self::ACCENT => 'accent'] as $key => $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            Setting::updateOrCreate(
                ['business_id' => $businessId, 'key' => $key],
                ['value' => $clean[$field]],
            );
        }
    }

    /* ------------------------------ الصور ------------------------------ */

    /** مسارُ الشعار الخام — من مالكه الوحيد، عمودِ `businesses` */
    public static function logoPath(int $businessId): ?string
    {
        $raw = DB::table('businesses')->where('id', $businessId)->value('logo');
        $raw = is_string($raw) ? trim($raw) : '';

        return $raw === '' ? null : $raw;
    }

    /** الشعارُ صالحًا للرسم في المحرّكين — من `InvoiceBranding` لا نسخةً عنه */
    public static function logo(int $businessId): ?string
    {
        return InvoiceBranding::logo($businessId);
    }

    /** مسارُ الغلاف الخام */
    public static function coverPath(int $businessId): ?string
    {
        $raw = (string) Setting::where('business_id', $businessId)
            ->where('key', self::COVER)->value('value');

        return trim($raw) === '' ? null : trim($raw);
    }

    /**
     * الغلافُ صالحًا للرسم — مضمَّنًا إن أمكن.
     *
     * والتضمينُ لأنّ الورقةَ تُقرأ في موضعين لا يشتركان في أصل: إطارٌ معزول
     * (`sandbox=""`) في الشاشة، وmpdf الذي لا جلسةَ له ولا كعكة. ورابطٌ
     * نسبيٌّ يعمل في أحدهما ويسقط في الآخر بلا صوت.
     */
    public static function cover(int $businessId, ?string $path = null): ?string
    {
        $raw = $path ?? self::coverPath($businessId);

        if ($raw === null || $raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'http') || str_starts_with($raw, 'data:')) {
            return $raw;
        }

        $disk = Storage::disk('public');

        try {
            if ($disk->exists($raw) && $disk->size($raw) <= self::INLINE_MAX) {
                return 'data:'.($disk->mimeType($raw) ?: 'image/jpeg')
                    .';base64,'.base64_encode($disk->get($raw));
            }
        } catch (\Throwable) {
            /* قرصٌ لا يُقرأ: يُوصَل برابطٍ ولا تسقط الورقة كلُّها لأجل غلاف */
        }

        return url($disk->url($raw));
    }

    /** الغلافُ رابطًا — للشاشة وحدها، كما في `InvoiceBranding::logoUrl` */
    public static function coverUrl(int $businessId): ?string
    {
        $raw = self::coverPath($businessId);

        if ($raw === null) {
            return null;
        }

        return str_starts_with($raw, 'http') || str_starts_with($raw, 'data:')
            ? $raw
            : Storage::disk('public')->url($raw);
    }

    /**
     * حفظُ الغلاف أو حذفُه — والقديمُ يُمحى من القرص.
     *
     * والشعارُ لا يُمحى مثلَه: صفٌّ في `businesses` قد تقرؤه ورقةٌ قديمة
     * بلقطتها (انظر `Version`). أمّا الغلافُ فزينةٌ في الترويسة — وورقةٌ
     * قديمةٌ تفقد غلافَها تبقى ورقةً صحيحة، بينما قرصٌ يمتلئ بأغلفةٍ متروكة
     * عطبٌ يُكتشف حين لا يبقى مكان.
     */
    public static function storeCover(int $businessId, ?UploadedFile $file, bool $remove = false): bool
    {
        $old = self::coverPath($businessId);

        if ($file !== null) {
            $path = $file->store('covers', 'public');
        } elseif ($remove) {
            $path = '';
        } else {
            return false;
        }

        Setting::updateOrCreate(
            ['business_id' => $businessId, 'key' => self::COVER],
            ['value' => $path],
        );

        if ($old !== null && $old !== $path) {
            try {
                Storage::disk('public')->delete($old);
            } catch (\Throwable) {
                /* ملفٌّ لا يُمحى لا يُسقط الحفظ: الإعداد كُتب، والقرص يُنظَّف يومًا */
            }
        }

        return true;
    }

    /**
     * كلُّ ما تحتاجه شاشةُ التخصيص — الخامُ لا المشتقّ.
     *
     * @return array<string, mixed>
     */
    public static function settings(int $businessId): array
    {
        return self::brand($businessId) + [
            'logo' => InvoiceBranding::logoUrl($businessId),
            'cover' => self::coverUrl($businessId),
        ];
    }
}
