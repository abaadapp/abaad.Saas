<?php

namespace App\Support\Website;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Support\MarketingSettings;

/**
 * ما يعرفه النظام عن التاجر — يُملأ به موقعُه بلا أن يُسأل.
 *
 * التاجر أدخل اسم نشاطه يوم فُتح حسابه، ورفع شعاره، وسجّل هاتفه وعنوانه،
 * وأضاف مئة منتجٍ بأسعارها وصورها. فأن يُفتح له موقعٌ فارغ ويُطلب منه أن
 * يكتب اسم متجره من جديد هو أسوأ ما يمكن أن تفعله شاشة.
 *
 * فتُقرأ بياناتُه ويُبنى موقعُه منها، ويُعرض عليه ليؤكّد أو يعدّل — لا ليُدخل.
 *
 * ومصدرُ كلّ حقلٍ واحدٌ لا اثنان: العنوانُ والنبذة والواتساب تُقرأ من
 * مفاتيح `store_*` — وهي نفسُها التي تقرؤها الصفحة العامّة
 * (`Storefront::page`). ومفتاحان لمعنًى واحد يفترقان عند أوّل تعديل، فيقول
 * الموقعُ المبنيّ هنا غيرَ ما تقوله الصفحة هناك عن المتجر نفسه.
 */
class MerchantData
{
    /**
     * هويّة النشاط كما تُقرأ من مواضعها الأصلية.
     *
     * @return array<string, mixed>
     */
    public static function identity(int $businessId): array
    {
        $business = Business::find($businessId);
        $site = MarketingSettings::group($businessId, 'website');

        return [
            'name' => (string) ($business?->name ?? ''),
            'logo' => (string) ($business?->logo ?? ''),
            'tagline' => trim((string) $site['store_headline']),
            'about' => trim((string) $site['store_about']),
            'phone' => (string) ($business?->phone ?? ''),
            'email' => (string) ($business?->email ?? ''),
            'address' => trim(implode('، ', array_filter([$business?->address, $business?->city]))),
            'city' => (string) ($business?->city ?? ''),
            /*
             * رقم الواتساب من موضعين — أوّلُ ما يوجد.
             *
             * `store_whatsapp` هو ما كتبه لمتجره، وهاتفُ النشاط ما يُلجأ إليه
             * حين لا يكتبه. وسؤالُه عنه ثالثةً وهو مكتوبٌ عندنا هو ما نتجنّبه.
             *
             * ولا يُقرأ من إعدادات الرسائل: إشعاراتُ الطلبات تخرج من وصلة
             * أبعاد المشتركة لا من رقمٍ يكتبه التاجر — انظر `WhatsAppFeature`.
             */
            'whatsapp' => trim((string) $site['store_whatsapp'])
                ?: (string) ($business?->phone ?? ''),
            'instagram' => ltrim(trim((string) $site['store_instagram']), '@'),
        ];
    }

    /**
     * ما يملكه المتجر ممّا تقرؤه الأقسام — فلا يُعرض قسمٌ بلا ما يعرضه.
     *
     * @return array<string, bool>
     */
    public static function available(int $businessId): array
    {
        return [
            'products' => Product::where('business_id', $businessId)->where('active', true)->exists(),
            'categories' => Category::where('business_id', $businessId)->exists(),
            'reviews' => Review::where('business_id', $businessId)->where('status', 'منشور')->exists(),
        ];
    }

    /**
     * محتوى قسمٍ عند إنشائه — مملوءًا بما يعرفه النظام.
     *
     * وما لا يعرفه يبقى على افتراضيّ وصفه: جملةٌ تصلح لأيّ متجر خيرٌ من حقلٍ
     * فارغ، والتاجر يبدّلها في ثانية.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    public static function seed(string $type, array $identity, string $goal): array
    {
        $data = Sections::defaults($type, $goal);
        $name = $identity['name'] !== '' ? $identity['name'] : __('متجرنا');

        $filled = match ($type) {
            Sections::HEADER => [
                'show_cart' => Blueprints::sells($goal),
                'show_whatsapp' => $identity['whatsapp'] !== '',
            ],
            Sections::FOOTER => [
                'about' => $identity['about'] ?: $identity['tagline'],
                'copyright' => __('جميع الحقوق محفوظة — :name', ['name' => $name]),
                'show_payments' => Blueprints::sells($goal),
            ],
            'hero' => [
                'title' => $identity['tagline'] ?: $name,
                'subtitle' => $identity['about'] ?: __('تسوّق من :name — جودةٌ تثق بها وخدمةٌ قريبة.', ['name' => $name]),
                'cta_label' => Blueprints::hasCatalogue($goal) ? __('تسوّق الآن') : __('تواصل معنا'),
                'cta_href' => Blueprints::hasCatalogue($goal) ? '/shop' : '/contact',
            ],
            'image_text' => [
                'title' => __('من نحن'),
                'body' => $identity['about'] ?: __(':name نشاطٌ يخدم زبائنه منذ سنوات، ويحرص على جودة ما يقدّمه.', ['name' => $name]),
            ],
            'contact' => [
                'title' => __('تواصل معنا'),
                'phone' => $identity['phone'],
                'whatsapp' => $identity['whatsapp'],
                'email' => $identity['email'],
                'address' => $identity['address'],
            ],
            'map' => [
                'address' => $identity['address'],
            ],
            'social' => [
                'accounts' => $identity['instagram'] !== ''
                    ? [['network' => 'instagram', 'value' => $identity['instagram']]]
                    : [],
            ],
            'whatsapp' => [
                'number' => $identity['whatsapp'],
                'message' => __('مرحبًا، أودّ الاستفسار عن'),
            ],
            'benefits' => [
                'title' => __('لماذا نحن'),
                'items' => [
                    ['icon' => 'truck', 'title' => __('توصيل سريع'), 'text' => __('نوصل طلبك في وقته.')],
                    ['icon' => 'shield-check', 'title' => __('جودة مضمونة'), 'text' => __('ما نبيعه نستعمله.')],
                    ['icon' => 'headphones', 'title' => __('خدمة قريبة'), 'text' => __('نردّ على استفسارك في يومه.')],
                ],
            ],
            'stats' => [
                'items' => [
                    ['value' => '+1000', 'label' => __('عميل')],
                    ['value' => '+5', 'label' => __('سنوات خبرة')],
                    ['value' => '24س', 'label' => __('زمن الردّ')],
                ],
            ],
            'faq' => [
                'items' => [
                    ['q' => __('كم يستغرق التوصيل؟'), 'a' => __('نوصل داخل المدينة خلال يومٍ إلى يومين.')],
                    ['q' => __('ما طرق الدفع المتاحة؟'), 'a' => __('نقبل النقد والبطاقة والتحويل البنكي.')],
                ],
            ],
            'promo' => [
                'title' => __('عرض هذا الأسبوع'),
                'text' => __('خصمٌ على منتجاتٍ مختارة — لمدّةٍ محدودة.'),
            ],
            default => [],
        };

        // والمملوء لا يتجاوز وصفَ القسم: مفتاحٌ لا يعرفه الوصف لا يُكتب
        return array_merge($data, array_intersect_key($filled, $data));
    }
}
