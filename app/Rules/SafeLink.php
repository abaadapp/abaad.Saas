<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * وجهةُ زرٍّ يُعرض للناس — مسارٌ داخل الموقع أو بروتوكولٌ مأمون.
 *
 * ═══ ولمَ يُحرس ═══
 *
 * زرٌّ وجهتُه `javascript:` سطرُ كودٍ يُنفَّذ في متصفّح كلّ زائر، لا رابطٌ
 * يُفتح. ومتاجرُ أبعاد كلُّها على نطاقٍ واحد — فما يُحقن في صفحةِ متجرٍ
 * يقرأ ما يخصّ النطاق نفسَه. و`data:` مثلُه: صفحةٌ كاملةٌ في الرابط.
 *
 * والقاعدةُ مكتوبةٌ في هذا المستودع منذ البانِي (انظر `Website\Content`)،
 * وهذا الصنفُ مصدرُها الواحد: الباني يُنظّف بها صامتًا، وشاشةُ الإعدادات
 * تردّ بها برسالة. وقائمةٌ واحدةٌ لا تفترق.
 *
 * ═══ ولمَ رسالةٌ هنا وصمتٌ هناك ═══
 *
 * محتوى البانِي يصل حقولًا كثيرةً في حمولةٍ واحدة فيُنظَّف، وهذا حقلٌ
 * واحدٌ كتبه صاحبُ المحلّ بيده وينظر إليه. ومن كتب وجهةً فمُحيت بصمتٍ
 * يعيد كتابتَها ويظنّ الحفظَ معطوبًا.
 */
class SafeLink implements ValidationRule
{
    /** بروتوكولات الروابط المسموحة — وما سواها ليس وجهةً تُفتح */
    public const SCHEMES = ['https', 'http', 'mailto', 'tel'];

    /**
     * أتصلح هذه وجهةً؟
     *
     * والفراغُ يسقط بالسطر الأخير لا بفحصٍ له: لا يبدأ بشرطةٍ مائلة، ولا
     * بروتوكولَ فيه. وكان فوقه فحصٌ صريحٌ نجا من الطفرات — عُطِّل فلم
     * يتغيّر شيء. وحارسان لسؤالٍ واحد يفترقان يومًا.
     */
    public static function allows(string $link): bool
    {
        $raw = trim($link);

        // النسبيّ: مسارٌ داخل الموقع نفسه. و`//host` ليس نسبيًّا رغم شكله
        if (str_starts_with($raw, '/')) {
            return ! str_starts_with($raw, '//');
        }

        return in_array(mb_strtolower((string) parse_url($raw, PHP_URL_SCHEME)), self::SCHEMES, true);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value) || ! self::allows((string) $value)) {
            $fail(__('الوجهةُ رابطٌ يبدأ بـ https، أو مسارٌ داخل متجرك يبدأ بشرطةٍ مائلة — مثل ‎/shop.'));
        }
    }
}
