<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Setting;

/**
 * هويّةُ المتجر: ما يُطبع في رأس ورقه — ومن يقول إنّها صحيحة.
 *
 * `Paper::brand()` تكتب اسمَ المتجر في أعلى الفاتورة الضريبيّة والإيصال
 * والتقرير. وحين لا اسمَ للمتجر يكتب النظامُ اسمًا من عنده. فيخرج المستندُ
 * باسمٍ لم يختره أحد — والزبونُ الذي يطلب فاتورةً لمحاسبه يعيدها.
 *
 * وهذا موضعٌ واحد يُسأل: الشريطُ في اللوحة، وشاشةُ التهيئة، وحارسُ الفاتورة
 * الضريبيّة — ثلاثتُها تقرأ من هنا. ولو سأل كلٌّ منها بطريقته لاختلفت
 * الأجوبة يومًا: يقول الشريط «اكتمل» ويرفض الحارسُ الطباعة.
 */
final class ShopIdentity
{
    /**
     * أسماءٌ يكتبها النظامُ عن التاجر — لا أسماءٌ اختارها.
     *
     * وهي مأخوذةٌ من مواضعها لا مخترعة: `Demo::shopName()` و
     * `Website\Builder` تكتبان «متجري»، و`Paper::brand()` تكتب «نظام Abad
     * POS». والترجمةُ الإنجليزيّة معهما، لأنّ الاسم يُحفظ بلغة من كتبه.
     *
     * وتُقرأ في `confirmed()` وحدها — ولا تمنع أحدًا: من اسمُ متجره أحدُها
     * فعلًا يُقرّه مرّةً ويمضي.
     */
    public const PLACEHOLDERS = ['متجري', 'My store', 'نظام Abad POS', 'Abad POS system'];

    /**
     * هل أقرّ التاجرُ هويّةَ متجره؟
     *
     * إمّا بختمٍ صريح، وإمّا باسمٍ لا يكتبه النظام — فمن سمّى متجره لا يُسأل
     * عن اسمه، والعمودُ لمن اسمُه اسمُ النظام.
     */
    public static function confirmed(?Business $business): bool
    {
        if ($business === null) {
            return false;
        }

        if ($business->identity_confirmed_at !== null) {
            return true;
        }

        $name = trim((string) $business->name);

        return $name !== '' && ! in_array($name, self::PLACEHOLDERS, true);
    }

    /**
     * خطواتُ التهيئة — وما تمّ منها.
     *
     * والاسمُ وحده لازم: هو ما يُطبع. وما عداه يُذكر لأنّ غيابَه يُرى على
     * الورق — عنوانٌ ناقص، أو رقمٌ ضريبيٌّ لا يظهر على فاتورةٍ ضريبيّة.
     *
     * والضريبةُ خطوةٌ يُجاب عنها بـ«لستُ مسجّلًا» كما يُجاب برقم: سؤالٌ بلا
     * جوابٍ صحيحٍ لغير المسجَّل يبقى معلّقًا أبدًا فيُقرأ عيبًا في النظام.
     *
     * @return array<int, array{key: string, label: string, done: bool, required: bool}>
     */
    public static function steps(Business $business): array
    {
        $bid = (int) $business->id;
        $settings = Setting::where('business_id', $bid)
            ->whereIn('key', ['vat_number', 'vat_enabled'])
            ->pluck('value', 'key');

        $name = trim((string) $business->name);
        $vatAnswered = trim((string) ($settings['vat_number'] ?? '')) !== ''
            || (string) ($settings['vat_enabled'] ?? '1') === '0';

        return [
            [
                'key' => 'name',
                'label' => __('اسم المتجر'),
                'done' => $name !== '' && ! in_array($name, self::PLACEHOLDERS, true),
                'required' => true,
            ],
            [
                'key' => 'vat',
                'label' => __('الرقم الضريبي'),
                'done' => $vatAnswered,
                'required' => false,
            ],
            [
                'key' => 'phone',
                'label' => __('رقم الهاتف'),
                'done' => trim((string) $business->phone) !== '',
                'required' => false,
            ],
            [
                'key' => 'address',
                'label' => __('العنوان'),
                'done' => trim((string) $business->address) !== '',
                'required' => false,
            ],
            [
                'key' => 'logo',
                'label' => __('الشعار'),
                'done' => trim((string) $business->logo) !== '',
                'required' => false,
            ],
        ];
    }

    /**
     * ما يُرسَل مع كلِّ صفحة — أو لا شيء.
     *
     * `null` حين أُقرّت الهويّة: شريطٌ يُرى كلَّ يوم بعد إتمامه يُقرأ زخرفةً،
     * وحقلٌ فارغٌ في العقد أهونُ من شريطٍ لا ينطفئ.
     *
     * @return array{done: int, total: int, missing: array<int, string>}|null
     */
    public static function context(?Business $business): ?array
    {
        if ($business === null || self::confirmed($business)) {
            return null;
        }

        $steps = self::steps($business);

        return [
            'done' => count(array_filter($steps, fn ($s) => $s['done'])),
            'total' => count($steps),
            'missing' => array_values(array_map(
                fn ($s) => $s['label'],
                array_filter($steps, fn ($s) => ! $s['done'])
            )),
        ];
    }
}
