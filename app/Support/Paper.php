<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Setting;

/**
 * هويّةُ المتجر كما تُطبع في ترويسة كلّ ورقة.
 *
 * وكانت تُقرأ بشكلين: نصفُ القوالب يكتب `$business['name']` والنصفُ الآخر
 * `$business->name` — لأنّ نصف المتحكّمات ترسل مصفوفةً والنصفَ الآخر
 * نموذجًا. فقالبٌ واحد لا يصلح للاثنين، وكلُّ ورقةٍ تُكتب من جديد.
 *
 * وهنا تُقرأ الصورتان وتخرج واحدة — فالترويسة قالبٌ واحد لكلّ الأوراق.
 */
class Paper
{
    /**
     * اسمُ المتجر وما تحته من سطور.
     *
     * @param  array<string,mixed>|Business|null  $business
     * @return array{name: string, sub: string, logo: ?string, lines: array<int, string>}
     */
    public static function brand(mixed $business, string $vatNumber = ''): array
    {
        $get = static fn (string $key): string => trim((string) (is_array($business)
            ? ($business[$key] ?? '')
            : ($business?->{$key} ?? '')));

        $sub = array_filter([$get('type'), $get('city')]);
        $lines = [];

        if (($address = $get('address')) !== '') {
            $lines[] = $address;
        }

        if (($phone = $get('phone')) !== '') {
            $lines[] = __('هاتف').': '.$phone;
        }

        if (($email = $get('email')) !== '') {
            $lines[] = $email;
        }

        if (($vat = trim($vatNumber)) !== '') {
            $lines[] = __('الرقم الضريبي').': '.$vat;
        }

        return [
            'name' => $get('name') !== '' ? $get('name') : __('نظام Abad POS'),
            'sub' => implode(' — ', $sub),
            'logo' => ($logo = $get('logo')) !== '' ? $logo : null,
            'lines' => $lines,
        ];
    }

    /**
     * أتُرسَم الورقةُ من اليمين؟ — سؤالٌ واحد يجيب عنه موضعٌ واحد.
     *
     * وورقةُ العميل تُطبع بلغةٍ يختارها صاحبُ المحلّ (`InvoiceBranding`)،
     * لا بلغة من يضغط الزرّ: موظّفٌ يقرأ اللوحةَ إنجليزيّةً يُصدر لوزارةٍ
     * ورقتُها عربيّة. فالمتحكّمُ يضبط اللغة ثمّ يرسم، وهذه تقرأ ما ضُبط.
     *
     * ولا تُكتب في كلّ قالب: اثنان وعشرون ملفًّا يقرؤون الاتجاه، وشرطٌ
     * يُكتب في كلٍّ منها يفترق عند أوّل لغةٍ تُضاف.
     */
    /**
     * اتّجاهُ نصٍّ يكتبه بشر — من أوّل حرفٍ قويٍّ فيه.
     *
     * ═══ ولمَ يُحسب هنا ولا يُترك للمحرّك ═══
     *
     * تاجرٌ عربيٌّ يبيع أصنافًا بأسماءٍ إنجليزية، وآخرُ يضبط نظامَه
     * بالإنجليزية ويكتب ملاحظاتِه بالعربية. والنصُّ في لغةٍ غير لغة الورقة
     * تنقلب علاماتُ ترقيمه: «يرجى التسليم قبل الظهر.» تخرج نقطتُها إلى
     * **أوّل** السطر، فتُقرأ سطرًا مبتورًا.
     *
     * وللمتصفّح حلٌّ لهذا — `dir="auto"` و`unicode-bidi: plaintext` —
     * **وmpdf لا يعرف واحدًا منهما**. جرّبتُهما فخرجت النقطةُ في موضعها
     * الخاطئ كما كانت. فيُحسب الاتّجاه في PHP ويُكتب صريحًا: قيمةٌ يفهمها
     * المحرّكان معًا.
     *
     * والحرفُ «القويّ» هو ما له اتّجاهٌ في ذاته — حرفُ هجاء. والأرقامُ
     * وعلاماتُ الترقيم والفراغ محايدةٌ تتبع ما حولها، فلا تُسأل: رقمُ
     * فاتورةٍ يبدأ بـ`INV` لاتينيّ، و«٢٠٢٦» وحدها لا تقول شيئًا.
     */
    public static function dirOf(?string $text): string
    {
        foreach (preg_split('//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $c = mb_ord($ch, 'UTF-8');

            if ($c === false) {
                continue;
            }

            /* والأرقامُ العربية-الهندية محايدة: «٢٠٢٦» وحدها لا تقلب سطرًا */
            if (($c >= 0x0660 && $c <= 0x0669) || ($c >= 0x06F0 && $c <= 0x06F9)) {
                continue;
            }

            // العربية والفارسية والعبرية وما يتبعها من نطاقات العرض
            if (($c >= 0x0590 && $c <= 0x08FF) || ($c >= 0xFB1D && $c <= 0xFDFF) || ($c >= 0xFE70 && $c <= 0xFEFF)) {
                return 'rtl';
            }

            if (($c >= 0x0041 && $c <= 0x005A) || ($c >= 0x0061 && $c <= 0x007A) || ($c >= 0x00C0 && $c <= 0x024F)) {
                return 'ltr';
            }
        }

        /* ونصٌّ بلا حرفٍ قويّ — رقمٌ أو شرطة — يتبع الورقة ولا يُقلب */
        return self::rtl() ? 'rtl' : 'ltr';
    }

    public static function rtl(): bool
    {
        return app()->getLocale() !== 'en';
    }

    /**
     * الرقمُ الضريبيّ للمتجر — من موضعٍ واحد.
     *
     * كانت ثلاثةُ قوالبٍ تقرؤه من ثلاثة مفاتيح: `$tpl['vat_number']` و
     * `$vatNumber` و`Demo::vatSettings()['number']`. فورقةٌ تطبعه وأختُها
     * لا، والفاتورة الضريبية بلا رقمٍ ليست فاتورةً ضريبية.
     */
    public static function vatNumber(int $businessId): string
    {
        return trim((string) Setting::where('business_id', $businessId)
            ->where('key', 'vat_number')->value('value'));
    }
}
