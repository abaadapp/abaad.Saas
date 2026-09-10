<?php

namespace App\Support;

use App\Models\Business;

/**
 * دليلُ التطبيقات التكاملية — قائمةٌ مغلقة يقرؤها بابٌ واحد.
 *
 * التكاملُ أداةٌ خارج النظام يُربط بها المتجر: خرائط Google تُعرّف بمحلّه،
 * وواتساب يحمل خبرَ الطلب إلى زبونه، وبوّابةُ الدفع تُدخل المال إلى حسابه.
 * وثلاثتها تُربط بالطريقة نفسها — بابٌ مغلقٌ حتى تُفتح مراحلُه — فشكلُ الربط
 * واحدٌ منذ `App\Support\Integration`.
 *
 * وما نقص هنا أنّ الأدوات كانت مبعثرة: خرائط Google تحت «أدوات التسويق»،
 * وواتساب معها، ومن يبحث عن «ما الذي ربطتُه؟» لا يجد شاشةً تجيب. فصارت
 * لها لوحةٌ واحدة، وصار «الربط» قسمًا مستقلًّا عن «الاستعمال»: مفاتيحُ
 * الأداة ومراحلُها هنا، ومقابضُ ما تفعله في قسمها — إشعاراتُ واتساب تبقى
 * في «أدوات التسويق» لأنّها تسويقٌ لا ربط.
 *
 * والقائمة مغلقة: لا يُضاف إليها مفتاحٌ بالكتابة الحرّة. أداةٌ تُعرض ولا
 * حارسَ لها ولا بابَ تفتحه هي بطاقةٌ تكذب على من يضغطها.
 */
class Integrations
{
    public const GOOGLE = 'google';

    public const WHATSAPP = 'whatsapp';

    public const AMWALPAY = 'amwalpay';

    /** الترتيب ترتيبُ العرض — الأقربُ إلى عمل المتجر أوّلًا */
    public const ALL = [self::GOOGLE, self::WHATSAPP, self::AMWALPAY];

    /**
     * ما يُعرّف الأداة ولا يتبدّل بمتجر: اسمُها وموقعُها ولونُها وبابُها.
     *
     * و`route` هو الفارق بين أداةٍ تُربط وأداةٍ في الدليل ولمّا تُبنَ: بلا
     * بابٍ لا يُرسم زرٌّ يفتح شيئًا. انظر `built`.
     */
    public const CATALOG = [
        self::GOOGLE => [
            'name' => 'خرائط Google',
            'site' => 'google.com/maps',
            'line' => 'حدِّد محلّك على الخرائط: يصل إليه الزبون، وتُقرأ تقييماتُه هنا، ويُطبع رمزُ التقييم على الإيصال.',
            'category' => 'الظهور والوصول',
            'tint' => '#ea4335',
            'route' => 'admin.integrations.google',
            'feature' => null,
        ],
        self::WHATSAPP => [
            'name' => 'واتساب بزنس',
            'site' => 'business.whatsapp.com',
            'line' => 'يصل العميل خبرُ طلبه على واتساب لحظةَ تغيّره — بلا أن يتّصل به أحد.',
            'category' => 'التواصل',
            'tint' => '#25d366',
            'route' => 'admin.integrations.whatsapp',
            'feature' => 'whatsapp',
        ],
        self::AMWALPAY => [
            'name' => 'AmwalPay',
            'site' => 'amwalpay.com',
            'line' => 'بوّابةُ دفعٍ عُمانية — يدفع الزبون ببطاقته، ويصل المال إلى حسابك.',
            'category' => 'المدفوعات',
            'tint' => '#1b3a93',
            /*
             * لا باب — ولا يُخترع لها باب.
             *
             * ليس في النظام حرفٌ واحد من AmwalPay: لا مفتاحَ إعدادٍ، ولا
             * عمودَ حساب، ولا نداءَ جلسةِ دفع. و«طرق الدفع» عند الصندوق
             * نصوصٌ تُسجَّل بعد أن يقع الدفع، لا بوّابةٌ تُوقعه.
             *
             * فتُعرض كما هي: معروفةً في الدليل، مطفأةً بلا مقبضٍ يُضغط.
             * وبطاقةٌ تعد بالربط ثمّ تفتح شاشةً فارغة أسوأ من بطاقةٍ تقول
             * «لم تُهيّأ بعد» — الأولى يجرّبها التاجر مرّتين ثمّ يراجعنا،
             * والثانية يقرؤها مرّةً ويعرف.
             */
            'route' => null,
            'feature' => null,
        ],
    ];

    /** أُبنيت الأداة فعلًا؟ — ما لا بابَ له لم يُبنَ */
    public static function built(string $key): bool
    {
        return (self::CATALOG[$key]['route'] ?? null) !== null;
    }

    /**
     * حالُ الأداة عند هذا المتجر — بلا نداءٍ خارج النظام.
     *
     * واللوحةُ لا تسأل Google عن التقييمات لترسم بطاقة: نداءٌ شبكيّ في كلّ
     * فتحةٍ يجعل صفحةً تُفتح كلَّ يومٍ تنتظر خادمًا لا نملكه. فالبطاقةُ تقول
     * ما يُقرأ من القاعدة — «مربوط» أو «لم يكتمل» أو «غير مربوط» — والتفصيلُ
     * وراءها في شاشة الأداة، وهي وحدها التي تسأل.
     *
     * @return array{state:string, label:string}
     */
    public static function status(string $key, Business $business): array
    {
        return match ($key) {
            self::GOOGLE => self::googleStatus($business),
            self::WHATSAPP => self::whatsappStatus($business),
            // ولا حالَ لما لم يُبنَ: لا مفتاحَ يُقرأ ولا مرحلةَ تُقاس
            default => self::state('unbuilt'),
        };
    }

    /**
     * خرائط Google — مفتاحٌ يقرأ، ومحلٌّ يُقرأ عنه.
     *
     * و«مربوط» تعني أنّ الاثنين حاضران: مفتاحٌ بلا محلٍّ لا يقرأ شيئًا،
     * ومحلٌّ بلا مفتاحٍ لا يُقرأ. وأيُّهما وحده «لم يكتمل» لا «مربوط».
     */
    private static function googleStatus(Business $business): array
    {
        $placeId = GoogleReviews::forBusiness($business->id)['place_id'];
        $key = GoogleReviews::apiKey($business->id);
        $started = MarketingSettings::group($business->id, 'connect')['google_setup_started'] === '1';

        if ($placeId !== null && $key !== null) {
            return self::state('ready');
        }

        // بدأ: ضغط الزرّ، أو حدّد محلَّه قبل أن يوجد الزرّ — كما في GoogleReviews::readiness
        return self::state($started || $placeId !== null ? 'partial' : 'off');
    }

    /**
     * واتساب — مراحلُه كلُّها تُقرأ من القاعدة، فتُقاس كاملةً بلا كلفة.
     */
    private static function whatsappStatus(Business $business): array
    {
        $readiness = WhatsAppFeature::readiness($business);

        if ($readiness['ready']) {
            return self::state('ready');
        }

        return self::state($readiness['connected'] ? 'partial' : 'off');
    }

    /** الحالُ واسمُها معًا — فلا تخمّن الشاشة اسمًا من مفتاح */
    private static function state(string $state): array
    {
        return [
            'state' => $state,
            'label' => __(match ($state) {
                'ready' => 'مربوط',
                'partial' => 'لم يكتمل',
                'off' => 'غير مربوط',
                default => 'لم يُهيّأ بعد',
            }),
        ];
    }

    /**
     * بطاقاتُ اللوحة — الدليلُ مقروءًا عند متجرٍ بعينه.
     *
     * و`licensed` سؤالٌ فوق الحال: باقةُ المتجر قد لا تفتح الأداة أصلًا.
     * وإخفاؤها عندها كان يعني أنّ من لم يشترِها لا يعرف أنّها موجودة —
     * فتُعرض ويُقال إنّها خارج باقته، وهو خبرٌ يفيده لا يحجبه.
     *
     * @return list<array<string,mixed>>
     */
    public static function cards(Business $business): array
    {
        return array_map(function (string $key) use ($business) {
            $tool = self::CATALOG[$key];
            $feature = $tool['feature'];

            return [
                'key' => $key,
                'name' => __($tool['name']),
                'site' => $tool['site'],
                'line' => __($tool['line']),
                'category' => __($tool['category']),
                'tint' => $tool['tint'],
                'route' => $tool['route'],
                'built' => self::built($key),
                'licensed' => $feature === null || PlanFeatures::allows($business, $feature),
                'status' => self::status($key, $business),
            ];
        }, self::ALL);
    }
}
