<?php

namespace App\Support;

use App\Models\Order;

/**
 * «طباعة تلقائية عند تأكيد طلب الموقع» — متى يحقّ للصندوق أن يفتح الورقة.
 *
 * ═══ ولمَ يقولها الخادم لا الشاشة ═══
 *
 * الشاشةُ ترى حالَ الطلب كما وصلها، وقد أكّده زميلٌ قبل ثانية. و«من» التي
 * تُعتمد هي ما قرأه `OrderTransition::apply` مقفلًا داخل النقل — والخادمُ
 * وحده يعرفها. فيُومِض رقمَ الطلب في الجلسة إن كان هذا النقلُ بعينه هو
 * «جديد ← مؤكّد» لطلبٍ من الموقع، ولا تطبع الشاشةُ إلّا عليه.
 *
 * والومضةُ تمرّ في الردّ الذي يلي النقلَ وحده: لا يحملها تحديثٌ دوريّ
 * (`only`) ولا إعادةُ تحميل ولا فتحُ الطلب ثانيةً — فلا يُطبع طلبٌ مرّتين
 * بسبب الضغطة نفسها.
 *
 * ═══ وما لا يَعِد به ═══
 *
 * الطباعةُ تقع في المتصفّح الذي ضُغط فيه الزرّ، على طابعة الصندوق المربوط
 * به (`PosTerminal`). ولا جسرَ في النظام يوصل أمرًا من هاتفٍ إلى طابعةٍ في
 * المحلّ: عنوانُ الطابعة وبوابتُها في الإعدادات وصفٌ لا اتّصال، والخادمُ
 * لا يبلغ عنوانًا محلّيًّا كـ192.168.x.x أصلًا.
 */
final class WebsiteConfirmPrint
{
    /** مفتاحُ الومضة في الجلسة — ويصل الواجهةَ في `flash.websiteConfirmed` */
    public const FLASH = 'website_confirmed';

    /** أهذا النقلُ هو ما تُطبع عليه الورقة؟ — `$from` كما قرأه النقلُ مقفلًا */
    public static function confirmed(Order $order, ?string $from, string $to): bool
    {
        return $order->channel === SalesChannel::WEBSITE
            && $from === OrderStatus::PENDING
            && $to === OrderStatus::CONFIRMED;
    }

    /** الومضةُ التي تُضاف إلى ردّ النقل الناجح — فارغةٌ لكلّ نقلٍ آخر */
    public static function flash(Order $order, ?string $from, string $to): array
    {
        return self::confirmed($order, $from, $to) ? [self::FLASH => $order->number] : [];
    }
}
