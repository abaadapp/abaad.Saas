<?php

namespace App\Support\Website;

use App\Models\Product;
use App\Models\Website;

/**
 * جاهزيةُ المتجر — حقائقُ تُقاس، لا قائمةُ أمنيات.
 *
 * ═══ لماذا تُقاس قبل النشر ═══
 *
 * صاحبُ المتجر يضغط «انشر» ثمّ يوزّع الرابط في واتساب وإنستغرام. وما يكتشفه
 * بعد ذلك يكتشفه من زبونٍ خذلَه: أسعارٌ مخفيّةٌ نسي إظهارها، أو لا رقمَ
 * واتساب فلا زرَّ طلبٍ أصلًا، أو كلُّ صنفٍ نفد فالصفحةُ فارغة. وكلُّها
 * معلومةٌ عندنا قبل أن يضغط.
 *
 * ═══ وما تقوله وما لا تقوله ═══
 *
 * تقول ما هو **قائمٌ الآن** ولا تحكم: لا تمنع النشر ولا تُسمّي شيئًا خطأً.
 * ومتجرٌ بلا نطاقٍ خاصّ ليس ناقصًا — لأبعادَ عنوانٌ يعمل من اليوم الأوّل.
 * فالنطاقُ الخاصّ «اختياريّ» لا «ناقص»، والفرقُ بينهما هو الفرق بين قائمةٍ
 * تُقرأ وقائمةٍ تُتجاهل.
 *
 * ولا تَعِد بما ليس في النظام: «الدفع الإلكتروني» لا يُعدّ بندًا ناقصًا
 * يستطيع التاجر إكماله — لا بوّابةَ في أبعاد بعد. انظر `Commerce`.
 */
final class Readiness
{
    /**
     * @return array<int, array{key: string, label: string, ok: bool, optional: bool, detail: string}>
     */
    public static function check(Website $site): array
    {
        $bid = (int) $site->business_id;
        $goal = $site->goal();
        $commerce = Publication::commerceFor($goal, $bid);
        $sells = Blueprints::hasCatalogue($goal);

        $shown = self::shown($bid);
        $channel = Commerce::channel($bid);
        $url = Domains::canonical($bid);

        $out = [
            [
                'key' => 'published',
                'label' => __('الموقع منشور'),
                'ok' => $site->published_version_id !== null,
                'optional' => false,
                'detail' => $site->published_version_id !== null
                    ? __('ما يراه الزائر هو آخر ما نشرتَه')
                    : __('موقعك مسوّدة — لا يفتحه زائر بعد'),
            ],
            [
                'key' => 'address',
                'label' => __('للموقع عنوان يُفتح'),
                'ok' => $url !== null,
                // ولا نطاقَ خاصٌّ مطلوب: عنوانُ أبعاد يعمل من اليوم الأوّل
                'optional' => true,
                'detail' => $url ?? __('احجز اسم متجرك لتحصل على عنوان'),
            ],
        ];

        if (! $sells) {
            return $out;
        }

        return array_merge($out, [
            [
                'key' => 'products',
                'label' => __('منتجات تظهر للزائر'),
                'ok' => $shown > 0,
                'optional' => false,
                'detail' => $shown > 0
                    ? __(':count منتجًا على الرفّ', ['count' => $shown])
                    : __('لا صنف مُتاح — تحقّق من «يظهر في المتجر» ومن المخزون'),
            ],
            [
                'key' => 'prices',
                'label' => __('الأسعار ظاهرة'),
                'ok' => (bool) $commerce['show_prices'],
                /*
                 * ومخفيُّ السعر اختيارٌ لا نقص: بائعُ الجملة يعرض بضاعته
                 * ويُسعّر لكلّ زبونٍ على حدة. فيُقال «مخفيّة» ولا يُلام.
                 */
                'optional' => true,
                'detail' => $commerce['show_prices']
                    ? __('الزائر يرى ثمن كل صنف')
                    : __('الأسعار مخفيّة — يسأل عنها الزبون'),
            ],
            [
                'key' => 'ordering',
                'label' => __('طريقة يطلب بها الزبون'),
                'ok' => $commerce['allow_orders'] && $channel !== Commerce::CHANNEL_NONE,
                'optional' => false,
                'detail' => match (true) {
                    ! $commerce['allow_orders'] => __('زرّ الطلب مطفأ — الموقع يعرض ولا يستقبل'),
                    $channel === Commerce::CHANNEL_NONE => __('لا رقم واتساب — أضفه ليظهر زرّ الطلب'),
                    default => __('الطلب يفتح محادثة واتساب باسم المنتج وسعره'),
                },
            ],
            [
                'key' => 'payment',
                'label' => __('الدفع'),
                /*
                 * ولا يُعدّ ناقصًا: لا بوّابةَ دفعٍ في أبعاد بعد، فالبندُ
                 * يقول الحقيقة ولا يطلب من التاجر إصلاحَ ما ليس بيده.
                 */
                'ok' => false,
                'optional' => true,
                'detail' => __('يُتّفق عليه في المحادثة — لا دفع إلكتروني في أبعاد بعد'),
            ],
        ]);
    }

    /** كم صنفًا يراه زائرُ الموقع فعلًا — بالشروط الثلاثة نفسِها */
    public static function shown(int $businessId): int
    {
        $ids = Product::where('business_id', $businessId)
            ->where('active', true)->where('published', true)
            ->pluck('id')->all();

        return count(array_filter(Shelf::availability($businessId, $ids)));
    }
}
