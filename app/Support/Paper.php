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
