<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Setting;
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

    /** ما يُكتب في `customer_name` حين لا يُختار عميل — ليس اسمًا يُعتدّ به */
    public const WALK_IN = 'عميل نقدي';

    /**
     * المناسبات — مفاتيح لاتينية لا نصوص عربية.
     *
     * خلافًا للحالات: هذا عمودٌ جديد لا بيانات فيه، فلا ترحيل يُخشى. والمفتاح
     * اللاتينيّ يُترجَم إلى أيّ لغةٍ في الواجهة، والنصّ العربيّ لو خُزّن لصار
     * هو نفسه في الشاشة الإنجليزية.
     *
     * وهذه الثابتة وحدها؛ وما يضيفه المحلّ بيده يُخزَّن بنصّه — انظر
     * `CUSTOM_KEY` أدناه وسببه.
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

    /**
     * مناسبات المتجر — ما يضيفه صاحب المحل بنفسه.
     *
     * القائمة أعلاه ثابتةٌ في الكود لأنها المشترَك بين كل محلّ ورد. لكنّ
     * المحلّات تبيع ما لا يخطر في بالٍ: «عقيقة»، «افتتاح فرع»، «يوم المعلّم».
     * ومن لم يجد مناسبته كتبها في نصّ البطاقة أو في الملاحظات — فخرجت من كل
     * ترشيحٍ وكل عدّ.
     *
     * وتُخزَّن بنصّها لا بمفتاحٍ لاتينيّ: مفتاحٌ يُترجَم يلزمه ملفّ ترجمة،
     * وما يكتبه التاجر بيده لا ملفّ له. فالقيمة هي التسمية، وهي ما يُعرض.
     */
    public const CUSTOM_KEY = 'custom_occasions';

    /**
     * حدّ القائمة.
     *
     * الإضافة بيد الكاشير — وهو يبيع بسرعة ويكتب بسرعة. بلا حدٍّ تصير
     * القائمة ثلاثين سطرًا، نصفها أخطاءٌ إملائية لمناسبةٍ واحدة، فلا يجد
     * فيها أحدٌ ما يريد ولا يُرشَّح عليها شيء.
     */
    public const CUSTOM_MAX = 20;

    /** أقصى طول للمناسبة المضافة */
    public const CUSTOM_LABEL_MAX = 40;

    /** حقول تفاصيل الطلب — مصدرٌ واحد للقواعد وللتعبئة (انظر الاختبار) */
    public const FIELDS = [
        'fulfillment_type', 'recipient_name', 'recipient_phone', 'scheduled_for',
        'occasion_type', 'card_message', 'sender_name', 'hide_sender',
        'delivery_address', 'delivery_notes', 'internal_notes',
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
            'occasion_type' => ['sometimes', 'nullable', Rule::in(self::allOccasions())],
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

        $errors = [];

        /*
         * طلبٌ له موعدٌ طلبٌ يذهب إلى لوحة التجهيز — وبطاقته لا تُقرأ ناقصة.
         *
         * من يقف عند الطاولة يسأل: لمن؟ ومتى؟ وإلى أين؟ فبطاقةٌ تقول «عميل
         * نقدي» لعشرة طلباتٍ في يومٍ واحد لا تُسلَّم لأحد. والشرط معلَّقٌ
         * بالموعد وحده لأنّه هو ما يُدخل الطلب اللوحةَ أصلًا
         * (`Order::awaitingPreparation`): بيعةُ المنضدة لا موعد لها، وإلزامُ
         * الكاشير باسمٍ لكلّ عبوة ماءٍ يبيعها يحوّل ثلاث نقراتٍ إلى استجواب.
         *
         * وعند الإنشاء وحده (`$current === null`).
         *
         * لأنّ شاشة تعديل التفاصيل لا تعرض اسم العميل أصلًا: فرضُ القاعدة
         * فيها يمنع صاحب المحلّ من تصحيح عنوان طلبٍ قديم بسبب حقلٍ لا يراه
         * ولا يستطيع تغييره من هناك — قفلٌ بلا مفتاح على بياناتٍ سابقة
         * لهذه القاعدة.
         */
        if ($current === null && filled($effective('scheduled_for'))) {
            if (blank($effective('fulfillment_type'))) {
                $errors['fulfillment_type'] = __('حدّد نوع التنفيذ: توصيل أو استلام من المحل.');
            }

            // الخطأ يُعلَّق على `customer` لا `customer_name`: هذا الفرع
            // للإنشاء وحده، ومصدرُه صندوقُ البيع — وحقلُه هناك اسمه `customer`
            $customer = $data['customer'] ?? $data['customer_name'] ?? null;

            if (blank($customer) || trim((string) $customer) === self::WALK_IN) {
                $errors['customer'] = __('اسم العميل مطلوب للطلبات التي تُجهَّز.');
            }
        }

        if ($effective('fulfillment_type') !== self::DELIVERY) {
            return $errors;
        }

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

        foreach (self::FIELDS as $field) {
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
    public static function occasionOptions(?int $bid = null): array
    {
        $built = array_map(
            fn ($k) => ['value' => $k, 'label' => __(self::OCCASION_LABELS[$k])],
            self::OCCASIONS
        );

        // المضافة بعد الثابتة: «أخرى» تبقى آخر المعروف، وما أضافه المحلّ بعده
        $custom = array_map(
            fn ($label) => ['value' => $label, 'label' => $label],
            self::customOccasions($bid)
        );

        return array_merge($built, $custom);
    }

    /** تسمية أي مناسبة — المضافة تسمية نفسها، والمجهولة تُعرض كما خُزّنت */
    public static function occasionLabel(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return isset(self::OCCASION_LABELS[$value]) ? __(self::OCCASION_LABELS[$value]) : $value;
    }

    /** المفاتيح الثابتة ومناسبات المتجر معًا — ما يُقبل في `occasion_type` */
    public static function allOccasions(?int $bid = null): array
    {
        return array_merge(self::OCCASIONS, self::customOccasions($bid));
    }

    /**
     * مناسبات المتجر المحفوظة.
     *
     * صفٌّ واحد في الإعدادات لا جدولٌ جديد: قائمةُ نصوصٍ لا علاقة لها بغيرها،
     * ولا يُسأل عنها إلا مع خيارات الطلب.
     *
     * @return array<int, string>
     */
    public static function customOccasions(?int $bid = null): array
    {
        $bid ??= Demo::bid();
        if (! $bid) {
            return [];
        }

        $raw = Setting::where('business_id', $bid)->where('key', self::CUSTOM_KEY)->value('value');
        $list = json_decode((string) $raw, true);

        if (! is_array($list)) {
            return [];
        }

        $clean = [];
        foreach ($list as $v) {
            $v = trim((string) $v);
            if ($v !== '' && ! in_array($v, $clean, true)) {
                $clean[] = $v;
            }
        }

        return array_slice($clean, 0, self::CUSTOM_MAX);
    }

    /**
     * إضافة مناسبةٍ للمتجر — تُعيد القائمة بعد الإضافة.
     *
     * والموجود لا يُضاف مرّتين: المقارنة بلا حساسيةٍ لحالة الأحرف ولا
     * للمسافات، فـ«عقيقة » و«عقيقة» واحدة. والمطابق لتسمية مناسبةٍ ثابتة
     * يُردّ إلى مفتاحها لا يُخزَّن نسخةً ثانية منها.
     *
     * @return array{value: string, options: array<int, array{value: string, label: string}>}
     */
    public static function addOccasion(string $label, ?int $bid = null): array
    {
        $bid ??= Demo::bid();
        $label = trim(preg_replace('/\s+/u', ' ', $label));

        $same = fn (string $a, string $b) => mb_strtolower($a) === mb_strtolower($b);

        // ما يطابق ثابتًا يُختار لا يُضاف: قائمةٌ فيها «زواج» مرّتين تربك من يقرأها
        foreach (self::OCCASION_LABELS as $key => $builtin) {
            if ($same($label, $builtin) || $same($label, __($builtin)) || $same($label, $key)) {
                return ['value' => $key, 'options' => self::occasionOptions($bid)];
            }
        }

        $list = self::customOccasions($bid);

        foreach ($list as $existing) {
            if ($same($label, $existing)) {
                return ['value' => $existing, 'options' => self::occasionOptions($bid)];
            }
        }

        if (count($list) >= self::CUSTOM_MAX) {
            throw new \RuntimeException(__('بلغت الحدّ الأقصى للمناسبات المضافة (:max) — احذف واحدة قبل الإضافة.', ['max' => self::CUSTOM_MAX]));
        }

        $list[] = $label;

        Setting::updateOrCreate(
            ['business_id' => $bid, 'key' => self::CUSTOM_KEY],
            ['value' => json_encode(array_values($list), JSON_UNESCAPED_UNICODE)],
        );

        return ['value' => $label, 'options' => self::occasionOptions($bid)];
    }

    public static function fulfillmentOptions(): array
    {
        return [
            ['value' => self::PICKUP, 'label' => __('استلام من المحل')],
            ['value' => self::DELIVERY, 'label' => __('توصيل')],
        ];
    }
}
