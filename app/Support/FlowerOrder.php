<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Validation\Rule;

/**
 * تفاصيل طلب الورد — المستلِم والموعد والمناسبة وبطاقة الإهداء.
 *
 * محلّ الورد يبيع شيئين في بيعةٍ واحدة: بضاعةً لمشترٍ، وخدمةً لمستلِمٍ آخر
 * في وقتٍ آخر. الفاتورة تعرف الأول وحده، فبقي الثاني يُكتب في «ملاحظات»
 * نصًّا حرًّا: «توصيل الخميس ٦ م لسارة ٩١٢٣٤٥٦٧» — لا يُرشَّح، ولا يُرتَّب
 * عليه، ولا تعرف لوحةُ التجهيز منه شيئًا.
 *
 * والقواعد والتعبئة هنا معًا لأنّ لهما مصدرين يكتبان الطلب نفسه: صندوق
 * البيع، وشاشة تعديل الطلب. وقاعدةٌ تُكتب في موضعين تفترق عند أول تعديل —
 * فيقبل أحدهما ما يرفضه الآخر.
 */
class FlowerOrder
{
    public const PICKUP = 'pickup';

    public const DELIVERY = 'delivery';

    public const FULFILLMENT = [self::PICKUP, self::DELIVERY];

    /**
     * المناسبات — مفاتيح لاتينية لا نصوص عربية.
     *
     * خلافًا للحالات: هذا عمودٌ جديد لا بيانات فيه، فلا ترحيل يُخشى. والمفتاح
     * اللاتينيّ يُترجَم إلى أيّ لغةٍ في الواجهة، والنصّ العربيّ لو خُزّن لصار
     * هو نفسه في الشاشة الإنجليزية.
     */
    public const OCCASIONS = [
        'birthday', 'anniversary', 'graduation', 'wedding', 'newborn',
        'congratulations', 'apology', 'thank_you', 'love', 'other',
    ];

    /** تسمية كل مناسبة — تُقرأ في الخادم (PDF) وتُرسل إلى الشاشة */
    public const OCCASION_LABELS = [
        'birthday' => 'عيد ميلاد',
        'anniversary' => 'ذكرى سنوية',
        'graduation' => 'تخرّج',
        'wedding' => 'زواج',
        'newborn' => 'مولود جديد',
        'congratulations' => 'تهنئة',
        'apology' => 'اعتذار',
        'thank_you' => 'شكر',
        'love' => 'حبّ',
        'other' => 'أخرى',
    ];

    /** أقصى طول لبطاقة الإهداء — بطاقةٌ تُطبع لا رسالة */
    public const CARD_MAX = 500;

    /**
     * الهاتف: أرقامٌ ومسافاتٌ وشرطاتٌ و+ ورموز، ثمانية أرقامٍ فأكثر.
     *
     * لا نمطٌ عمانيّ ضيّق: الزبون يُهدي إلى دبي وإلى الرياض، ورقمٌ صحيح
     * يُرفض عند الكاشير يعني بيعةً تُكتب في «ملاحظات» فتخرج من كل تقرير.
     * والحدّ الأدنى ثمانية لأنّ رقم عُمان ثمانية.
     */
    public const PHONE_RULE = ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-\s]{8,}$/'];

    /**
     * قواعد الحقول — تُدمَج في تحقّق الصندوق وتحقّق شاشة التعديل معًا.
     *
     * `sometimes` في كلّها: الصندوق يبيع باقةً على المنضدة بلا مستلِمٍ ولا
     * موعد، وبيعةُ المارّ يجب أن تبقى ثلاث نقرات. والإلزام مشروطٌ بالتوصيل
     * وحده — انظر `afterValidation`.
     */
    public static function rules(): array
    {
        return [
            'fulfillment_type' => ['sometimes', 'nullable', Rule::in(self::FULFILLMENT)],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'recipient_phone' => array_merge(['sometimes'], self::PHONE_RULE),
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'occasion_type' => ['sometimes', 'nullable', Rule::in(self::OCCASIONS)],
            'card_message' => ['sometimes', 'nullable', 'string', 'max:'.self::CARD_MAX],
            'sender_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'hide_sender' => ['sometimes', 'boolean'],
            'delivery_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'delivery_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /** رسائل عربية للقواعد التي لا تكفي رسالتُها الافتراضية */
    public static function messages(): array
    {
        return [
            'recipient_phone.regex' => __('رقم المستلِم غير صالح — أرقامٌ فقط، ثمانية فأكثر.'),
            'scheduled_for.date' => __('موعد التسليم غير صالح.'),
        ];
    }

    /**
     * ما لا تقوله قواعدُ الحقل الواحد: التوصيل يلزمه مستلِمٌ وهاتفٌ وعنوان.
     *
     * ولا يُفرض إلا حين يكون التنفيذ توصيلًا — فالاستلام من المحل لا يُسأل
     * عن عنوان، وبيعة المنضدة لا تُسأل عن شيء.
     *
     * والقيمة المعتبَرة هي ما سيصير عليه الطلب بعد الحفظ: ما أُرسل إن أُرسل،
     * وإلا ما هو محفوظٌ فيه. فتصحيحُ رقم هاتفٍ في طلب توصيلٍ قائم لا يُطالب
     * صاحبه بإعادة كتابة العنوان لأنّ الشاشة لم تفتحه — وتحويلُ طلب استلامٍ
     * إلى توصيل يُطالَب بالعنوان لأنّه ليس فيه أصلًا.
     *
     * @param  array<string, mixed>  $data  ما وصل بعد التحقق
     * @param  array<string, mixed>|null  $current  ما هو محفوظٌ في الطلب (null عند الإنشاء)
     * @return array<string, string> أخطاء بمفاتيح الحقول، فارغةٌ إن سلِم
     */
    public static function afterValidation(array $data, ?array $current = null): array
    {
        $effective = fn (string $field) => array_key_exists($field, $data)
            ? $data[$field]
            : ($current[$field] ?? null);

        if ($effective('fulfillment_type') !== self::DELIVERY) {
            return [];
        }

        $errors = [];
        $required = [
            'recipient_name' => __('اسم المستلِم مطلوب لطلبات التوصيل.'),
            'recipient_phone' => __('رقم المستلِم مطلوب لطلبات التوصيل.'),
            'delivery_address' => __('عنوان التوصيل مطلوب لطلبات التوصيل.'),
        ];

        foreach ($required as $field => $message) {
            if (blank($effective($field))) {
                $errors[$field] = $message;
            }
        }

        return $errors;
    }

    /**
     * الحقول القابلة للكتابة على الطلب — بأسمائها في القاعدة.
     *
     * تُبنى من `$data` لا من الطلب كلّه: مفتاحٌ لم يُرسل لا يُكتب، فالتعديل
     * الجزئيّ لا يمسح ما لم تفتحه الشاشة.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributes(array $data): array
    {
        $out = [];

        foreach (array_keys(self::rules()) as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $out[$field] = $data[$field];
        }

        if (array_key_exists('hide_sender', $data)) {
            $out['hide_sender'] = (bool) $data['hide_sender'];
        }
        // النصّ الفارغ يُحفظ فراغًا لا سلسلةً فارغة: العمود nullable، و''
        // تُقرأ «مملوءًا» في كل فحصٍ بـfilled()
        foreach (['recipient_name', 'recipient_phone', 'delivery_address', 'card_message',
            'sender_name', 'delivery_notes', 'internal_notes', 'occasion_type', 'fulfillment_type'] as $f) {
            if (array_key_exists($f, $out) && $out[$f] !== null && trim((string) $out[$f]) === '') {
                $out[$f] = null;
            }
        }

        return $out;
    }

    /**
     * ما يُعرض للمستلِم — البطاقة كما تُطبع وتُرسل.
     *
     * `hide_sender` يُطاع هنا لا في الشاشة: الشاشة تُخفي، والـPDF يُبنى في
     * الخادم ويُطبع ويُسلَّم مع الباقة. ومن أخفى اسمه ثم قرأه المستلِمُ على
     * الورقة لم يُخدَع في ميزة — بل في سرٍّ ائتمن النظامَ عليه.
     */
    public static function cardForRecipient(Order $order): array
    {
        return [
            'message' => $order->card_message,
            'sender' => $order->hide_sender ? null : $order->sender_name,
            'hidden' => (bool) $order->hide_sender,
        ];
    }

    /** خيارات المناسبات لقوائم الاختيار — مصدرٌ واحد للخادم والشاشة */
    public static function occasionOptions(): array
    {
        return array_map(
            fn ($k) => ['value' => $k, 'label' => __(self::OCCASION_LABELS[$k])],
            self::OCCASIONS
        );
    }

    public static function fulfillmentOptions(): array
    {
        return [
            ['value' => self::PICKUP, 'label' => __('استلام من المحل')],
            ['value' => self::DELIVERY, 'label' => __('توصيل')],
        ];
    }
}
