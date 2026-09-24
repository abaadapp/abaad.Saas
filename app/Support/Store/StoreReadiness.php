<?php

namespace App\Support\Store;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Support\FlowerOrder;
use App\Support\MarketingSettings;
use App\Support\Website\MerchantData;

/**
 * جاهزيّةُ المتجر — ما تمّ وما بقي، ليضبطه صاحبُه بيده.
 *
 * ═══ ولمَ قائمةٌ أصلًا ═══
 *
 * صار في شاشة الإعدادات نحوُ ثلاثين مقبضًا للمتجر: العنوان، والأصناف،
 * وطرقُ الاستلام والدفع، والحقول، والأقسام، والصور، والبوّابة. وكلُّها
 * تعمل — ولا شيءَ فيها يقول لصاحبها **أين هو الآن**.
 *
 * فيفتح متجرَه ويجده خاليًا ولا يعرف السبب، أو يُطفئ طريقتَي الدفع فيتوقّف
 * قبولُ الطلبات بلا كلمة. وكلُّ سؤالٍ من هذه يعني رسالةً إلى من بنى له
 * النظام — وهي التي تُغني عنها هذه القائمة.
 *
 * ═══ واللازمُ غيرُ المستحسن ═══
 *
 * «بلا عنوانٍ لا يُفتح المتجر» ليست كـ«بلا نبذةٍ يبدو أقلَّ ثقة». فخلطُهما
 * في قائمةٍ واحدةٍ حمراء يجعل صاحبَه يقرأ الكلَّ تحذيرًا فلا يقرأ شيئًا.
 *
 * ═══ ولا تُكتب خطوةٌ لا يقرؤها النظام ═══
 *
 * كلُّ صفٍّ هنا يُقاس بالمصدر الذي يقرؤه المتجرُ فعلًا — لا بمفتاحٍ يُظنّ
 * أنّه يفعل. فقائمةٌ تقول «تمّ» على ما لا أثرَ له أسوأُ من لا قائمة.
 */
final class StoreReadiness
{
    /**
     * خطواتُ تجهيز المتجر — مرتَّبةً كما تُفعل.
     *
     * و`when` تعني صفًّا لا يُسأل عنه إلّا في حاله: بياناتُ الحساب البنكيّ
     * لا تُطلب ممّن لا يقبل التحويل، ورسمُ التوصيل لا يُطلب ممّن لا يوصّل.
     * وسؤالٌ بلا جوابٍ صحيحٍ لمن لا يعنيه يبقى معلّقًا فيُقرأ عيبًا.
     *
     * @return list<array{key: string, label: string, why: string, done: bool, required: bool, section: string}>
     */
    public static function steps(Business $business): array
    {
        $bid = (int) $business->id;
        $site = MarketingSettings::group($bid, 'website');
        $identity = MerchantData::identity($bid);

        $shown = Product::where('business_id', $bid)
            ->where('active', true)->where('published', true);

        $fulfilments = CheckoutFields::fulfilments($bid);
        $delivers = in_array(FlowerOrder::DELIVERY, $fulfilments, true);
        $transfers = ($site['store_pay_transfer'] ?? '0') === '1';
        $pays = WebCheckout::payments($bid) !== [];

        $rows = [
            /* ═══════════ اللازم ═══════════ */
            [
                'key' => 'slug',
                'label' => __('عنوان متجرك'),
                'why' => __('بلا عنوانٍ لا يُفتح متجرك من أيّ رابط.'),
                'done' => filled($business->site_slug),
                'required' => true,
                'section' => 'website',
            ],
            [
                'key' => 'products',
                'label' => __('صنفٌ معروضٌ واحد على الأقل'),
                'why' => __('متجرٌ بلا بضاعةٍ يفتحه زبونك فيجده خاليًا — ولا يعود إليه.'),
                'done' => $shown->exists(),
                'required' => true,
                'section' => 'website',
            ],
            [
                'key' => 'fulfil',
                'label' => __('طريقة استلامٍ واحدة'),
                'why' => __('توصيلٌ أو استلامٌ من المحلّ — بلا إحداهما لا يُتمّ أحدٌ طلبًا.'),
                'done' => $fulfilments !== [],
                'required' => true,
                'section' => 'website',
            ],
            [
                'key' => 'pay',
                'label' => __('طريقة دفعٍ واحدة'),
                'why' => __('بلا طريقةِ دفعٍ مفتوحة يتوقّف متجرك عن قبول الطلبات — ولا شيءَ في الصفحة يقول ذلك لزبونك.'),
                'done' => $pays,
                'required' => true,
                'section' => 'website',
            ],
            [
                'key' => 'phone',
                'label' => __('رقمٌ يُتّصل به'),
                'why' => __('يظهر في تذييل متجرك — وهو ما يفتحه زبونٌ حين يسأل عن طلبه.'),
                'done' => trim((string) ($identity['phone'] ?? '')) !== '',
                'required' => true,
                'section' => 'business',
            ],
            [
                'key' => 'published',
                'label' => __('نشر المتجر'),
                'why' => __('آخرُ خطوة — وقبلها لا يفتحه أحدٌ سواك.'),
                'done' => ($site['store_on'] ?? '0') === '1',
                'required' => true,
                'section' => 'website',
            ],

            /* ═══════════ والمستحسن ═══════════ */
            [
                'key' => 'category',
                'label' => __('فئةٌ فيها بضاعة'),
                'why' => __('بلا فئةٍ لا يُرسم قسم «تسوّق حسب الفئة» ولا زرُّ الاستكشاف في الواجهة.'),
                'done' => Category::where('business_id', $bid)
                    ->whereIn('id', (clone $shown)->select('category_id'))->exists(),
                'required' => false,
                'section' => 'website',
            ],
            [
                'key' => 'hero',
                'label' => __('صورة الواجهة'),
                'why' => __('أوّلُ ما تقع عليه العين — وبلا اختيارك تُؤخذ من أوّل صنفٍ مبيعًا، أيًّا كان.'),
                'done' => StorePage::heroImage($bid) !== null,
                'required' => false,
                'section' => 'website',
            ],
            [
                'key' => 'about',
                'label' => __('نبذة «عنّا»'),
                'why' => __('بلا نبذةٍ لا يُرسم قسم «عنّا» أصلًا — ولا يعرف زبونك من يشتري منه.'),
                'done' => trim((string) ($site['store_about'] ?? '')) !== '',
                'required' => false,
                'section' => 'website',
            ],
            [
                'key' => 'hours',
                'label' => __('ساعات العمل'),
                'why' => __('يقرؤها زبونك قبل أن يطلب توصيلًا في وقتٍ أنت مغلقٌ فيه.'),
                'done' => trim((string) ($site['store_hours'] ?? '')) !== '',
                'required' => false,
                'section' => 'website',
            ],
            [
                'key' => 'areas',
                'label' => __('مناطق التوصيل'),
                'why' => __('بلا مناطقَ معدودة يكتب زبونك ما يشاء — وقد يكون خارج ما توصّل إليه.'),
                'done' => trim((string) ($site['store_delivery_areas'] ?? '')) !== '',
                'required' => false,
                'section' => 'website',
                'when' => $delivers,
            ],
            [
                'key' => 'bank',
                'label' => __('بيانات الحساب البنكي'),
                'why' => __('فتحتَ التحويل البنكي — وبلا بياناتٍ لا يعرف زبونك إلى أين يحوّل.'),
                'done' => trim((string) ($site['store_bank'] ?? '')) !== '',
                'required' => true,
                'section' => 'website',
                'when' => $transfers,
            ],
        ];

        return array_values(array_map(
            function (array $r) {
                unset($r['when']);

                return $r;
            },
            array_filter($rows, fn ($r) => ($r['when'] ?? true) === true),
        ));
    }

    /**
     * أتمّ اللازمُ كلُّه؟
     *
     * والمستحسنُ لا يُسأل عنه هنا: متجرٌ بلا نبذةٍ يبيع، ومتجرٌ بلا عنوانٍ
     * لا يُفتح. وخلطُهما يجعل الشارةَ حمراءَ أبدًا فلا تُقرأ.
     */
    public static function ready(Business $business): bool
    {
        foreach (self::steps($business) as $step) {
            if ($step['required'] && ! $step['done']) {
                return false;
            }
        }

        return true;
    }
}
