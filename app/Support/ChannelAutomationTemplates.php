<?php

namespace App\Support;

/**
 * ‏‏قوالبُ يبدأ منها التاجر — يضغط واحدًا فيفتح المعالجُ مملوءًا، ويعدّل ثمّ ينشر.
 *
 * وما بين قوسين مربّعين ينتظر أن يكتبه التاجر بنفسه (ساعاتُه وعنوانُه)، والنشرُ يُرفض قبله
 * (`ChannelAutomations::PLACEHOLDER`) — فلا تخرج ساعاتُ عملٍ لم يكتبها أحد.
 */
class ChannelAutomationTemplates
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            [
                'key' => 'price',
                'name' => __('السعر ← رابط المنتج'),
                'description' => __('يسأل الزبون عن السعر، فيصله اسم المنتج وسعره ورابطه.'),
                'keywords' => ['السعر', 'بكم', 'كم سعر', 'price'],
                'reply_ar' => "{المنتج} بسعر {السعر}\nتفاصيله وطلبه من هنا: {رابط_المنتج}",
                'reply_en' => "{product} costs {price}\nDetails and ordering: {product_link}",
                'buttons' => [],
                'needs_product' => true,
            ],
            [
                'key' => 'store_link',
                'name' => __('رابط المتجر'),
                'description' => __('يطلب الزبون الرابط، فيصله زر يفتح متجرك.'),
                'keywords' => ['رابط المتجر', 'الرابط', 'المتجر', 'link'],
                'reply_ar' => 'أهلًا {الاسم}! تصفّح منتجاتنا واطلب مباشرة من متجرنا.',
                'reply_en' => 'Hi {name}! Browse our products and order directly from our store.',
                'buttons' => [['type' => ChannelAutomations::BUTTON_LINK, 'title' => 'افتح المتجر', 'url' => '{رابط_المتجر}']],
                'needs_product' => false,
            ],
            [
                'key' => 'order_status',
                'name' => __('حالة الطلب'),
                'description' => __('يسأل الزبون عن طلبه، فيصله رقم آخر طلب له وحاله من أبعاد.'),
                'keywords' => ['حالة الطلب', 'وين طلبي', 'طلبي', 'order status'],
                'reply_ar' => '{آخر_طلب}',
                'reply_en' => '{last_order}',
                'buttons' => [],
                'needs_product' => false,
            ],
            [
                'key' => 'hours',
                'name' => __('ساعات العمل'),
                'description' => __('يسأل الزبون متى تفتحون، فيصله دوامك كما تكتبه.'),
                'keywords' => ['ساعات العمل', 'الدوام', 'متى تفتحون', 'hours'],
                'reply_ar' => 'ساعات عملنا: [اكتب أيامك وساعاتك]',
                'reply_en' => 'Our opening hours: [write your days and hours]',
                'buttons' => [],
                'needs_product' => false,
            ],
            [
                'key' => 'location',
                'name' => __('الموقع والعنوان'),
                'description' => __('يسأل الزبون أين أنتم، فيصله عنوانك ورابط الخريطة.'),
                'keywords' => ['العنوان', 'اللوكيشن', 'وين مكانكم', 'location'],
                'reply_ar' => "تجدنا في: [اكتب عنوانك]\nالخريطة: [ألصق رابط خرائط Google]",
                'reply_en' => "Find us at: [write your address]\nMap: [paste your Google Maps link]",
                'buttons' => [],
                'needs_product' => false,
            ],
            [
                'key' => 'delivery',
                'name' => __('التوصيل والدفع'),
                'description' => __('يسأل الزبون عن التوصيل أو الدفع، فيصله ما تقدّمه.'),
                'keywords' => ['التوصيل', 'توصيل', 'الدفع', 'delivery'],
                'reply_ar' => 'نوصّل إلى [اكتب المناطق] خلال [المدة]، والدفع [نقدًا / بالبطاقة / برابط دفع].',
                'reply_en' => 'We deliver to [areas] within [time], and you can pay [cash / card / payment link].',
                // ‏‏وضغطُ زرّ الردّ يصل بعنوانه نصًّا — فيطابق قالبَي السعر ورابط المتجر بكلمتيهما
                'buttons' => [['type' => ChannelAutomations::BUTTON_REPLY, 'title' => 'السعر'], ['type' => ChannelAutomations::BUTTON_REPLY, 'title' => 'رابط المتجر']],
                'needs_product' => false,
            ],
        ];
    }
}
