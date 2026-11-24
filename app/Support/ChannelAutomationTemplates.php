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
    /**
     * ‏‏قوالبُ إنستقرام — تعليقٌ يصل صاحبَه ردٌّ خاصّ (وردٌّ علنيٌّ تحته)، ورسالةٌ يصلها ردُّها.
     *
     * والردُّ الخاصّ على التعليق نصٌّ لا غير، فالرابطُ في نصّه. والردودُ العلنيّة جُملٌ عامّة لا تعد بشيء.
     *
     * @return list<array<string, mixed>>
     */
    public static function instagram(): array
    {
        $public = ['أرسلنا لك التفاصيل على الخاص 💌', 'تفقّد رسائلك الخاصة ✨', 'وصلتك التفاصيل على الخاص 🌷'];
        $comment = ['trigger' => InstagramAutomations::COMMENT, 'match' => KeywordMatch::CONTAINS, 'public_replies' => $public, 'buttons' => []];
        $message = ['trigger' => InstagramAutomations::MESSAGE, 'match' => KeywordMatch::CONTAINS, 'public_replies' => []];

        return [
            $comment + [
                'key' => 'ig_comment_price',
                'name' => __('تعليق بالسعر ← رسالة خاصة'),
                'description' => __('يعلّق أحد «بكم؟» فيصله السعر والرابط على الخاص، ويُكتب تحت تعليقه أنه أُرسل.'),
                'keywords' => ['السعر', 'بكم', 'كم سعر', 'price'],
                'reply_ar' => "أهلًا {الاسم}! {المنتج} بسعر {السعر}\nتفاصيله وطلبه من هنا: {رابط_المنتج}",
                'reply_en' => "Hi {name}! {product} costs {price}\nDetails and ordering: {product_link}",
                'needs_product' => true,
            ],
            $comment + [
                'key' => 'ig_comment_link',
                'name' => __('تعليق بكلمة ← رابط المتجر'),
                'description' => __('تقول في منشورك «علّق بكلمة: رابط»، فيصل كل من علّق رابط متجرك.'),
                'keywords' => ['رابط', 'الرابط', 'link'],
                'reply_ar' => "أهلًا {الاسم}! هذا رابط متجرنا:\n{رابط_المتجر}",
                'reply_en' => "Hi {name}! Here is our store:\n{store_link}",
                'needs_product' => false,
            ],
            ['match' => KeywordMatch::ANY] + $comment + [
                'key' => 'ig_comment_any',
                'name' => __('أي تعليق على منشور ← رسالة خاصة'),
                'description' => __('لمنشورات تختارها: كل من يعلّق يصله ما تكتبه على الخاص.'),
                'keywords' => [],
                'reply_ar' => "أهلًا {الاسم}! شكرًا لتعليقك — التفاصيل هنا:\n{رابط_المتجر}",
                'reply_en' => "Hi {name}! Thanks for your comment — details here:\n{store_link}",
                'needs_product' => false,
            ],
            $message + [
                'key' => 'ig_dm_price',
                'name' => __('رسالة بالسعر ← رابط المنتج'),
                'description' => __('يسأل الزبون عن السعر في الرسائل، فيصله اسم المنتج وسعره وزر يفتحه.'),
                'keywords' => ['السعر', 'بكم', 'كم سعر', 'price'],
                'reply_ar' => '{المنتج} بسعر {السعر}',
                'reply_en' => '{product} costs {price}',
                'buttons' => [['type' => ChannelAutomations::BUTTON_LINK, 'title' => 'اطلب الآن', 'url' => '{رابط_المنتج}']],
                'needs_product' => true,
            ],
            $message + [
                'key' => 'ig_dm_hours',
                'name' => __('ساعات العمل'),
                'description' => __('يسأل الزبون متى تفتحون، فيصله دوامك كما تكتبه.'),
                'keywords' => ['ساعات العمل', 'الدوام', 'متى تفتحون', 'hours'],
                'reply_ar' => 'ساعات عملنا: [اكتب أيامك وساعاتك]',
                'reply_en' => 'Our opening hours: [write your days and hours]',
                'buttons' => [],
                'needs_product' => false,
            ],
            $message + [
                'key' => 'ig_dm_delivery',
                'name' => __('التوصيل والدفع'),
                'description' => __('يسأل الزبون عن التوصيل أو الدفع، فيصله ما تقدّمه.'),
                'keywords' => ['التوصيل', 'توصيل', 'الدفع', 'delivery'],
                'reply_ar' => 'نوصّل إلى [اكتب المناطق] خلال [المدة]، والدفع [نقدًا / بالبطاقة / برابط دفع].',
                'reply_en' => 'We deliver to [areas] within [time], and you can pay [cash / card / payment link].',
                'buttons' => [['type' => ChannelAutomations::BUTTON_REPLY, 'title' => 'السعر']],
                'needs_product' => false,
            ],
        ];
    }

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
