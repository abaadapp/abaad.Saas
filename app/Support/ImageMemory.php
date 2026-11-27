<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * هل يتّسع الخادمُ لفكّ هذه الصورة الآن؟ — من ذاكرته المتاحة فعلًا، لا من حدّ PHP.
 *
 * ═══ لمَ لا يكفي `memory_limit` ═══
 *
 * مكتبةُ الصور على الخادم (libgd من النظام) تحجز ذاكرة البكسلات خارج حساب
 * PHP. فلا يراها `memory_limit` ولا `memory_get_peak_usage` — قال جردُ الإنتاج
 * إنّ الذروة ٣٦ ميغابايت ونزلت الذاكرةُ المتاحة ١٤٠. و`roomFor` يرفع الحدَّ حتى
 * غيغابايت على خادمٍ سعتُه غيغابايت. فالحارسُ الحقيقيّ يقرأ ما يقوله النظام
 * (`MemAvailable` في `/proc/meminfo`) ويُبقي فوق ما تأخذه الصورةُ هامشًا للبيع
 * وواتساب والدفع وقاعدة البيانات (`images.reserve_mb`).
 *
 * ═══ وكم تأخذ الصورة ═══
 *
 * قِيس بذاكرة العمليّة المقيمة (RSS) — فكٌّ ثمّ نسخةُ عرضٍ ثمّ مصغّرة:
 * ١٢ ميغابكسل ٩٢ ميغابايت، و٢٤ ميغابكسل ١٣٩، و٤٨ ميغابكسل ٢٣٧ (منها نحو ١٨
 * لـPHP نفسه). أي نحو أربعة بايتات للبكسل. و`need` تحسب أربعةً ونصفًا وثمانيةً
 * وأربعين ميغابايت فوقها — أكثر ممّا قِيس، لا أقلّ.
 *
 * ═══ ولا يُسقط بيعًا ═══
 *
 * ما لا يتّسع له الخادمُ لا يُفكّ: الرفعُ يحفظ الصورةَ كما وصلت (كما يفعل حين
 * لا تُفكّ)، والجولةُ تؤجّلها إلى دورٍ آخر. لا استثناءَ يبلغ الصندوق ولا طلبَ
 * زبون — والسببُ يُكتب في السجلّ.
 */
final class ImageMemory
{
    /** بايتاتُ البكسل في الفكّ — أربعةٌ للصورة، ونصفٌ للنسخ والمكتبة */
    private const PER_PIXEL = 4.5;

    /** ما فوق البكسلات — PHP نفسُه، وترميزُ WebP، ونسخةُ العرض */
    private const OVERHEAD = 48 * 1024 * 1024;

    /** ذاكرةُ فكّ صورةٍ بهذا العدد من البكسلات — بالبايت، أكثرُ ممّا قِيس */
    public static function need(int $pixels): int
    {
        return (int) ($pixels * self::PER_PIXEL) + self::OVERHEAD;
    }

    /**
     * سقفُ البكسلات لجولة الصور القديمة — ٢٤ ميغابكسل افتراضًا.
     *
     * صورةُ هاتفٍ عاديّة ١٢. وما فوق السقف يُترك ويُقال: ٢٤ ميغابكسل تأخذ نحو
     * ١٥٦ ميغابايت بحساب `need`، وتُبقي على خادمٍ متاحُه ٤٦١ أكثرَ من الهامش.
     */
    public static function maxPixels(): int
    {
        return (int) (max(1.0, (float) config('images.max_megapixels', 24)) * 1_000_000);
    }

    /** الذاكرةُ المتاحة الآن كما يقولها النظام — أو `null` حيث لا يُقرأ (خارج لينكس) */
    public static function available(): ?int
    {
        $file = (string) config('images.meminfo', '/proc/meminfo');
        $text = is_readable($file) ? @file_get_contents($file) : false;

        if (! is_string($text) || ! preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $text, $m)) {
            return null;
        }

        return (int) $m[1] * 1024;
    }

    /**
     * سببُ ألّا تُفكّ هذه الصورةُ الآن — أو `null` إن اتّسع لها الخادم.
     *
     * وما لا يُقرأ فيه النظامُ يُصدَّق: الحارسان الآخران — السقفُ وحدُّ PHP —
     * باقيان، ولا يُمنع الرفعُ على خادمٍ لا `/proc` فيه.
     */
    public static function shortFor(int $pixels): ?string
    {
        $available = self::available();

        if ($available === null) {
            return null;
        }

        $reserve = max(0, (int) config('images.reserve_mb', 200)) * 1024 * 1024;

        return $available - self::need($pixels) >= $reserve ? null : 'low_memory';
    }

    /**
     * يكتب في السجلّ لمَ لم تُفكّ صورة — ليُعرف ما تُرك ولماذا.
     *
     * ═══ في سجلٍّ لها ═══
     *
     * سجلُّ النظام على الإنتاج لا يكتب إلّا الأخطاء (`LOG_LEVEL=error`)، وصورةٌ
     * تُركت لأنّ الذاكرة لا تكفي ليست خطأً — فكانت تُرمى صامتة. فتُكتب في سجلٍّ
     * للصور وحدها (`logs/images-*.log`) بمستوى التحذير، ولا يتغيّر مستوى سجلّ
     * النظام. وما فيها أبعادٌ وذاكرةٌ واسمُ ملفٍّ عشوائيّ — لا زبونَ ولا متجرَ
     * يُعرف منها.
     *
     * ═══ ولا يتضخّم ═══
     *
     * ذاكرةٌ تبقى ناقصةً ساعةً ورفعٌ بعد رفع: سطرٌ لكلّ مرّة يملأ القرص بما
     * يقوله سطرٌ واحد. فيُكتب الأوّلُ لكلّ (موضع، سبب)، وما بعده في
     * `images.note_every` ثانيةً يُعدّ ولا يُكتب — والسطرُ التالي يقول كم
     * سُكت عنه (`suppressed`).
     *
     * ولا يُسقط شيئًا: سجلٌّ أو ذاكرةٌ مؤقّتةٌ تعذّرت لا تردّ رفعًا ولا توقف جولة.
     */
    public static function note(string $where, string $reason, int $w, int $h, ?string $path = null): void
    {
        try {
            $key = 'images:note:'.$where.':'.$reason;
            $every = max(0, (int) config('images.note_every', 600));

            if ($every > 0 && ! Cache::add($key, true, $every)) {
                Cache::add($key.':quiet', 0, 86400);
                Cache::increment($key.':quiet');

                return;
            }

            Log::channel('images')->warning('images.'.$reason, array_filter([
                'where' => $where, 'path' => $path, 'width' => $w, 'height' => $h,
                'megapixels' => round($w * $h / 1_000_000, 1),
                'need_mb' => (int) round(self::need($w * $h) / 1048576),
                'available_mb' => ($a = self::available()) === null ? null : (int) round($a / 1048576),
                'suppressed' => (int) Cache::pull($key.':quiet', 0) ?: null,
            ], fn ($v) => $v !== null));
        } catch (\Throwable) {
            // ‏سطرُ السجلّ لا يستحقّ رفعًا مردودًا
        }
    }
}
