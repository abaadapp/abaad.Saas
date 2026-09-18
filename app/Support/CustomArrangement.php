<?php

namespace App\Support;

use App\Models\CustomOrderField;
use App\Models\CustomOrderTemplate;
use App\Models\OrderItemComponent;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * الطلبُ المخصَّص — باقةٌ تُركَّب على الطاولة، ومكوّناتُها تُعرف.
 *
 * ═══ ولمَ لا يكفي أن يُكتب السعرُ وحده ═══
 *
 * «ورد بعشرين ريالًا» تقول كم يدفع الزبون، ولا تقول كم وردةً خرجت من
 * الدلو. والرفُّ لا يُخصم بالمال — يُخصم بالعدد. فالقيمةُ للفاتورة،
 * والمكوّناتُ للمخزون، ولا تُستنبط إحداهما من الأخرى.
 *
 * ═══ وضعان للتسعير، ومصدرٌ واحد للخصم ═══
 *
 *   `VALUE`  — قيمةُ الورد + أثمانُ الإضافات. الزبون يدفع مجموعَهما.
 *   `BUDGET` — ميزانيةٌ نهائيّة. الزبون يدفع ما قاله، والإضافاتُ لا تزيده.
 *
 * والفرقُ بينهما في **السعر وحده**. أمّا ما يُخصم من الرفّ وما يُحسب تكلفةً
 * فواحدٌ في الوضعين: من أخذ ثماني ورداتٍ أخذها سواءٌ قال «بعشرين» أو
 * «بثلاثين للطلب كلّه».
 *
 * ═══ وما هنا وما ليس هنا ═══
 *
 * هنا: قراءةُ ما أُرسل، والتحقّقُ منه، وحسابُ التكلفة، وقولُ ما يُستهلك.
 * وليس هنا: الخصمُ نفسُه — `StockLedger` يفعله؛ ولا فحصُ التوفّر —
 * `PosController::assertStock` يفعله بالدالّة التي تقرأ ما تقرؤه هذه.
 *
 * ولا تُقرأ من هذا الملفّ قيمةُ سعرٍ ولا تكلفةٍ أرسلها المتصفّح: الأسعارُ
 * والتكاليفُ تُقرأ من صفوف المتجر، كما تفعل `PosController::priceItems`
 * لكلّ بندٍ عاديّ.
 */
class CustomArrangement
{
    /** قيمةُ الورد + الإضافات — الإضافةُ تزيد ما يدفعه الزبون */
    public const MODE_VALUE = 'value';

    /** ميزانيةٌ نهائيّة — الإضافةُ لا تزيد ما يدفعه الزبون */
    public const MODE_BUDGET = 'budget';

    public const MODES = [self::MODE_VALUE, self::MODE_BUDGET];

    /** أكثرُ ما يُقبل من موادّ في طلبٍ واحد — حدٌّ يمنع حمولةً مصنوعة */
    public const MAX_COMPONENTS = 60;

    /** أقصى كميّةٍ لمادّةٍ واحدة — ولا يحرس مخزونًا، إنّما يردّ رقمًا لا معنى له */
    public const MAX_QUANTITY = 9999;

    /** أكثرُ ما يُقبل من إجاباتِ حقولٍ في طلبٍ واحد */
    public const MAX_FIELDS = 60;

    /** مفتاحُ تفعيل الميزة في إعدادات المتجر */
    public const ENABLED_KEY = 'custom_orders_enabled';

    /** رقمُ صيغة اللقطة — ٢ هي العامّة، وما دونها يُقرأ بقارئ الشكل القديم */
    public const SNAPSHOT_VERSION = 2;

    /**
     * أمفعَّلةٌ الطلباتُ المخصَّصة في هذا المتجر؟
     *
     * والافتراضُ «نعم»: الميزةُ تعمل اليوم في كلّ متجر، وترقيةٌ تُطفئ ما كان
     * يعمل عطبٌ لا إعداد. ومن أراد إطفاءها أطفأها — كما تُقرأ `Vat::enabled`.
     */
    public static function enabled(int $businessId): bool
    {
        return (string) (Setting::where('business_id', $businessId)
            ->where('key', self::ENABLED_KEY)->value('value') ?? '1') !== '0';
    }

    /**
     * القالبُ الذي يُباع به — من متجر البائع، حيًّا ونشطًا.
     *
     * ═══ ولمَ يُقرأ هنا لا في الشاشة ═══
     *
     * المتصفّح يرسل معرّفًا، والمعرّفُ يُزوَّر. فقالبُ متجرٍ آخر، أو قالبٌ
     * أوقفه صاحبُه قبل دقيقة، أو قالبٌ حُذف — كلُّها تصل بالشكل نفسه. والحصرُ
     * هنا يجعل الجواب واحدًا: «قالب غير متاح» — لا يقول أيُّها كان، فلا
     * يُستدلّ به على قوالب متجرٍ آخر.
     *
     * @throws ValidationException
     */
    public static function template(int $businessId, $id, string $field = 'custom.template_id'): CustomOrderTemplate
    {
        $template = ((int) $id) > 0
            ? CustomOrderTemplate::sellable($businessId)->find((int) $id)
            : CustomOrderTemplate::ensureDefault($businessId);

        if (! $template) {
            throw ValidationException::withMessages([
                $field => __('قالب الطلب المخصص غير متاح.'),
            ]);
        }

        return $template;
    }

    /**
     * الاسمُ الذي يُقرأ على الفاتورة وفي التقارير — اسمُ القالب.
     *
     * ولا يُقرأ يومَ الطباعة من القالب الحيّ: هذا يُكتب لقطةً في
     * `order_items.name` لحظة البيع، فقالبٌ يُعاد تسميتُه بعد شهر لا يُعيد
     * كتابة فاتورةٍ طُبعت ووُقّعت. وهو ما يفعله `displayName` لكلّ بند.
     *
     * و«طلب مخصص» حين لا قالب: حالٌ لا تقع في بيعةٍ جديدة — القالبُ مشروطٌ
     * في `PosController` — وتبقى للطلبات المعلَّقة قبل الترقية.
     */
    public static function label(?CustomOrderTemplate $template = null): string
    {
        return $template?->display() ?: __('طلب مخصص');
    }

    /**
     * التسمياتُ التي حملتها الحقولُ الثلاثةُ القديمة.
     *
     * ═══ ولمَ تُكتب بنصّها لا تُترجَم ═══
     *
     * هي **تاريخ**: طلبٌ بيع في سبتمبر يحمل «ألوان الورد» لأنّ ذلك ما رآه
     * الموظّف يومها. ولو قُرئت من ملفّ الترجمة لَتبدّلت بتبدّله — فتقرأ
     * فاتورةَ الأمس بكلماتِ اليوم.
     *
     * @var array<string, array{0: string, 1: string, 2: bool}> مفتاح => [عربي, إنجليزي, داخليّ]
     */
    private const LEGACY_FIELDS = [
        'colors' => ['ألوان الورد', 'Flower Colors', false],
        'packaging_label' => ['لون التغليف', 'Packaging Color', false],
        'florist_notes' => ['ملاحظات المنسق', 'Florist Notes', true],
    ];

    /**
     * قواعدُ ما يصل من الصندوق.
     *
     * والسعرُ يُقرأ من الطلب هنا — خلافًا لكلّ بندٍ آخر — لأنّه **لا صنفَ
     * له يُقرأ منه**. وهذا هو معنى «مخصَّص». ولذلك يُحرَس بصلاحيةٍ في
     * `PosController`، لا يُترك مفتوحًا لأنّ الشاشة ترسله.
     */
    public static function rules(string $prefix): array
    {
        $p = $prefix.'.';

        /*
         * و«مطلوبٌ **مع**» لا «مطلوب».
         *
         * القاعدةُ تُكتب على `items.*.custom.mode`، والنجمةُ تعني كلَّ بند.
         * فـ`required` المجرّدة كانت تُطالب **كلّ** بندٍ في السلّة بوضعٍ
         * وسعرٍ مخصَّصين — أي أنّ بيعةَ باقةٍ عاديّة تُردّ بـ٤٢٢. أمسكها
         * ٤٨ اختبارًا قائمًا قبل أن تصل أحدًا.
         *
         * والشرطُ على وجود `custom` نفسِه: من أرسله أرسل معه ما يُعرّفه،
         * ومن لم يرسله لا يُسأل.
         */
        $withCustom = 'required_with:'.$prefix;

        return [
            $prefix => ['nullable', 'array'],
            // القالبُ الذي يُباع به — وجودُه وملكيّتُه يُفحصان في `template()`
            // ويُقبل غيابُه: سلّةٌ عُلّقت قبل القوالب تُباع بقالب المتجر الأوّل
            $p.'template_id' => ['nullable', 'integer'],
            $p.'mode' => [$withCustom, 'string', 'in:'.implode(',', self::MODES)],
            // السعرُ الذي يدفعه الزبون — موجبٌ دائمًا
            $p.'price' => [$withCustom, 'numeric', 'min:0.001'],
            // القيمةُ الأساسية وحدها — تُعرض وتُحفظ، ولا تُخصم منها بضاعة
            $p.'base_value' => ['nullable', 'numeric', 'min:0'],

            /*
             * إجاباتُ حقول القالب — معرّفٌ وقيمة، ولا نوعَ يُرسَل.
             *
             * النوعُ يُقرأ من صفّ الحقل لا من الطلب: من أرسل «نصّ» لحقلٍ
             * نوعُه «اختيار» لا يُصدَّق. وهو المبدأ نفسُه الذي يمنع قراءة
             * التكلفة من المتصفّح.
             */
            $p.'fields' => ['nullable', 'array', 'max:'.self::MAX_FIELDS],
            $p.'fields.*.field_id' => ['required_with:'.$p.'fields', 'integer'],
            /*
             * والقيمةُ تُذكر ولو بلا قيدٍ عليها — وإلّا لم تصل أصلًا.
             *
             * `Validator::validated()` لا يُعيد إلّا المفاتيحَ التي لها قاعدة.
             * فبلا هذا السطر كانت الإجاباتُ كلُّها تُحذف قبل أن يقرأها
             * المحرّك: يخرج الطلبُ بلا لونٍ ولا مقاسٍ ولا ملاحظة، والشاشةُ
             * أرسلتها والخادمُ قال «تمّ».
             *
             * ولا نوعَ يُشترط هنا: النوعُ يُقرأ من صفّ الحقل في `readField` —
             * نصٌّ أو رقمٌ أو صحّةٌ أو قائمةُ معرّفات — ولا يُصدَّق من الطلب.
             */
            $p.'fields.*.value' => ['nullable'],

            $p.'components' => ['nullable', 'array', 'max:'.self::MAX_COMPONENTS],
            $p.'components.*.product_id' => ['required_with:'.$p.'components', 'integer'],
            $p.'components.*.quantity' => ['required_with:'.$p.'components', 'numeric', 'min:0.001', 'max:'.self::MAX_QUANTITY],
            $p.'components.*.restockable' => ['nullable', 'boolean'],

            /*
             * ═══ وما تحت هذا السطر يُقبل ولا يُطلب — سلّاتٌ عُلّقت قبل الترقية ═══
             *
             * سلّةٌ عُلّقت أمس تُستأنف اليوم بمفاتيح الأمس (`flower_value`
             * و`colors` وأختيها). ولو رُفضت لَسقطت بـ٤٢٢ في وجه الكاشير على
             * طلبٍ صحيح، أو — أسوأ — لَمرّت بلا ألوانها فخرجت الباقةُ بغير
             * ما طُلبت.
             *
             * فتُقبل وتُحوَّل إلى حقولٍ عامّة في `details()`، ولا تُكتب بعدها
             * صيغةٌ قديمةٌ أبدًا.
             */
            $p.'flower_value' => ['nullable', 'numeric', 'min:0'],
            $p.'colors' => ['nullable', 'array', 'max:20'],
            $p.'colors.*' => ['string', 'max:40'],
            $p.'packaging_label' => ['nullable', 'string', 'max:120'],
            $p.'florist_notes' => ['nullable', 'string', 'max:1000'],
            $p.'components.*.kind' => ['nullable', 'string', 'in:'.implode(',', OrderItemComponent::KINDS)],
        ];
    }

    /**
     * يقرأ إجاباتِ الحقول ويردّها لقطاتٍ جاهزةً للحفظ.
     *
     * ═══ والحقلُ يُقرأ من القالب لا من الطلب ═══
     *
     * معرّفُ حقلٍ من قالبٍ آخر — ولو من المتجر نفسِه — يُردّ: القالبُ يصف
     * طلبًا بعينه، وحقلٌ من غيره يجعل الطلبَ يحمل سؤالًا لم يُسأل. وكذلك
     * الخيارُ: يُفحص أنّه من هذا الحقل لا من حقلٍ سواه.
     *
     * والنوعُ يقرّر كيف تُقرأ القيمة، وهو من الصفّ لا من الحمولة.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>> لقطاتٌ: id · label · label_en · type · internal · values
     *
     * @throws ValidationException
     */
    public static function fieldValues(CustomOrderTemplate $template, array $rows, string $field = 'custom.fields'): array
    {
        /*
         * ═══ والموقوفُ يُقرأ ولا يُسأل ═══
         *
         * كان الترشيحُ `active = true` هنا، فحقلٌ يُوقفه صاحبُ النشاط في
         * الظهيرة يقتل كلَّ سلّةٍ عُلّقت بجوابه في الصباح: تُستأنف فتُرسل
         * معرّفَه، ولا يجده هذا القارئ، فيُردّ «حقل غير متاح في هذا القالب»
         * — رسالةٌ عن حقلٍ لا يراه الكاشيرُ أصلًا ولا يملك حذفَه من سلّته.
         * فيقف الزبونُ عند الصندوق ولا سبيلَ إلى الدفع إلّا بهدم السلّة.
         *
         * و«الإيقاف» في الحقل يقول «لا تسأله بعد اليوم» لا «أبطِل ما
         * أُجيب»: لا مالَ فيه ولا بضاعة، إنّما سؤالٌ في استمارة. وهو خلافُ
         * إيقاف **القالب** — ذاك يقول «لا يُباع هذا» فيبقى حارسَ بيعٍ
         * صارمًا في `template()`.
         *
         * فتُقرأ الحقولُ كلُّها، ويبقى الحارسُ الذي يعني شيئًا: حقلٌ من
         * قالبٍ آخر يُردّ. والموقوفُ لا **يُطالَب** به — انظر أسفلُ.
         */
        /** @var Collection<int, CustomOrderField> $fields */
        $fields = $template->fields()->with('options')->get()->keyBy('id');

        $sent = [];
        foreach ($rows as $idx => $row) {
            $sent[(int) ($row['field_id'] ?? 0)] = ['idx' => $idx, 'row' => $row];
        }

        $out = [];
        $errors = [];

        foreach ($fields as $id => $f) {
            $at = $sent[$id]['idx'] ?? null;
            $raw = $sent[$id]['row']['value'] ?? null;
            $key = "{$field}.".($at ?? 0).'.value';

            $values = self::readField($f, $raw, $key, $errors);

            if (! $values) {
                // والمطلوبُ يُطالَب به ولو لم يُرسَل أصلًا — لا يُتخطّى بحذفه.
                // والموقوفُ لا يُطالَب: سؤالٌ رُفع عن الشاشة لا يُسأل عنه أحد،
                // وإلّا صار إيقافُ حقلٍ مطلوبٍ إقفالًا للصندوق كلِّه
                if ($f->required && $f->active && ! isset($errors[$key])) {
                    $errors[$key] = __('«:name» مطلوب.', ['name' => $f->display()]);
                }

                continue;
            }

            $out[] = [
                'id' => (int) $f->id,
                'label' => (string) $f->label,
                'label_en' => $f->label_en,
                'type' => (string) $f->type,
                'internal' => (bool) $f->internal,
                'values' => $values,
            ];
        }

        /*
         * ومعرّفٌ لا يُعرف يُردّ ولا يُبتلع.
         *
         * حقلٌ أُوقف أو حُذف بينما النافذةُ مفتوحة، أو معرّفٌ من قالبٍ آخر:
         * تخطّيه صامتًا يعني بيعةً تُكتب ناقصةَ ما كتبه الموظّف — وهو يظنّ
         * أنّه كتبه.
         */
        foreach ($sent as $id => $one) {
            if ($id > 0 && ! $fields->has($id)) {
                $errors["{$field}.{$one['idx']}.field_id"] = __('حقل غير متاح في هذا القالب.');
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * قيمةُ حقلٍ واحد بحسب نوعه — قائمةُ تسمياتٍ، أو فارغٌ حين لا جواب.
     *
     * والفارغُ يعني «لم يُجَب»: حقلٌ اختياريٌّ تُرك يسقط من اللقطة كلّها، فلا
     * تحمل بطاقةُ التجهيز سؤالًا بلا جواب.
     *
     * @param  array<string, string>  $errors
     * @return array<int, array{label: string, label_en: ?string}>
     */
    private static function readField(CustomOrderField $f, $raw, string $key, array &$errors): array
    {
        /*
         * ═══ وما ليس قيمةً مفردة لا يُقرأ نصًّا ═══
         *
         * حقلٌ نوعُه «اختيار متعدّد» يُبدّله صاحبُ النشاط إلى «نصّ قصير»،
         * وسلّةٌ عُلّقت قبل التبديل تُستأنف بعده: `resumeFields` تقرأ نوعَ
         * **اللقطة** فتردّ مصفوفةَ معرّفات، والحقلُ الحيُّ صار نصًّا.
         * فـ`(string) $raw` على مصفوفة تُسقط الطلبَ بـ٥٠٠ في وجه الكاشير —
         * أو تكتب الكلمةَ `Array` في لقطةِ بيعةٍ تُقرأ بعد سنة.
         *
         * ولا تُقرأ معرّفاتُ خياراتٍ نصًّا: «٣ · ٤» ليست جوابًا. فتُعدّ
         * كأنّها لم تُجَب — يُطالَب بها الكاشيرُ من جديد إن كانت مطلوبة،
         * ويُكتب ما يكتبه هو لا ما خلّفه شكلٌ مضى.
         *
         * وهي حارسُ المدخل لا حارسُ الاستئناف وحده: ما يصل من المتصفّح
         * يُقرأ بنوعِ الصفّ، وأيُّ شكلٍ سواه يُردّ — كما تُقرأ التكلفةُ من
         * صفّ الصنف لا من الحمولة.
         */
        if (! $f->takesOptions() && (is_array($raw) || is_object($raw))) {
            return [];
        }

        if ($f->takesOptions()) {
            $wanted = array_filter(array_map('intval', (array) $raw));
            if (! $wanted) {
                return [];
            }

            if ($f->type === CustomOrderField::SELECT && count($wanted) > 1) {
                $errors[$key] = __('«:name» يقبل خيارًا واحدًا.', ['name' => $f->display()]);

                return [];
            }

            // والخيارُ الموقوف كالحقل الموقوف: يُقرأ ولا يُعرض — وهو تسميةٌ
            // تُنسخ في اللقطة، لا مالٌ ولا رصيد. والحارسُ أنّه من هذا الحقل
            $options = $f->options->keyBy('id');
            $picked = [];

            foreach ($wanted as $oid) {
                $o = $options->get($oid);
                if (! $o) {
                    // خيارُ حقلٍ آخر، أو خيارٌ أُوقف — يُقال باسم الحقل
                    $errors[$key] = __('خيار غير متاح في «:name».', ['name' => $f->display()]);

                    return [];
                }
                /*
                 * ومعرّفُ الخيار يُحفظ مع تسميته.
                 *
                 * التسميةُ للقراءة بعد سنة، والمعرّفُ لاستئناف سلّةٍ عُلّقت
                 * قبل ساعة: بلا معرّفٍ تُستأنف فتعود الاختياراتُ نصًّا لا
                 * يطابقه زرّ، فيجدها الكاشير فارغةً ويختار من جديد.
                 *
                 * وهما لا يفترقان: يُكتبان في السطر نفسِه من الصفّ نفسِه.
                 */
                $picked[] = ['label' => (string) $o->label, 'label_en' => $o->label_en, 'option_id' => (int) $o->id];
            }

            return $picked;
        }

        if ($f->type === CustomOrderField::CHECKBOX) {
            // و«لا» تُترك فارغةً: سؤالٌ جوابُه «لا» لا يُكتب على بطاقة التجهيز
            return filter_var($raw, FILTER_VALIDATE_BOOL)
                ? [['label' => __('نعم'), 'label_en' => null]]
                : [];
        }

        if ($f->type === CustomOrderField::NUMBER) {
            if ($raw === null || $raw === '' || ! is_numeric($raw)) {
                if ($raw !== null && $raw !== '') {
                    $errors[$key] = __('«:name» رقم.', ['name' => $f->display()]);
                }

                return [];
            }

            return [['label' => rtrim(rtrim(number_format((float) $raw, 3, '.', ''), '0'), '.'), 'label_en' => null]];
        }

        $text = trim((string) ($raw ?? ''));
        if ($text === '') {
            return [];
        }

        $max = $f->type === CustomOrderField::LONG_TEXT
            ? CustomOrderField::LONG_MAX
            : CustomOrderField::SHORT_MAX;

        if (mb_strlen($text) > $max) {
            $errors[$key] = __('«:name» أطول من :max حرفًا.', ['name' => $f->display(), 'max' => $max]);

            return [];
        }

        return [['label' => $text, 'label_en' => null]];
    }

    /**
     * يقرأ المكوّنات من الطلب ويردّها لقطاتٍ جاهزةً للكتابة.
     *
     * ═══ والصنفُ يُقرأ من متجرِ البائع لا من الطلب ═══
     *
     * معرّفٌ يصل من المتصفّح لا يُوثق به: قد يشير إلى صنفِ متجرٍ آخر. فالبحثُ
     * محصورٌ بـ`business_id`، وما لم يُوجد فيه يُردّ باسمه — لا يُتخطّى
     * صامتًا. وتخطّيه كان يعني بيعًا يخصم أقلَّ ممّا أُخذ.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>> لقطاتٌ: product · name · sku · kind · quantity · unit_cost · total_cost
     *
     * @throws ValidationException
     */
    public static function components(
        int $businessId,
        array $rows,
        string $field = 'custom.components',
        ?CustomOrderTemplate $template = null,
    ): array {
        if (! $rows) {
            return [];
        }

        $ids = collect($rows)->pluck('product_id')->filter()->map(fn ($i) => (int) $i)->unique()->all();

        /** @var Collection<int, Product> $products */
        $products = Product::where('business_id', $businessId)
            ->whereIn('id', $ids)->get()->keyBy('id');

        $out = [];
        $errors = [];

        foreach ($rows as $idx => $row) {
            $product = $products->get((int) ($row['product_id'] ?? 0));

            if (! $product) {
                // صنفُ متجرٍ آخر، أو صنفٌ حُذف — يُقال ولا يُبتلع
                $errors["{$field}.{$idx}.product_id"] = __('صنف غير موجود في هذا المتجر.');

                continue;
            }

            if (! $product->active) {
                $errors["{$field}.{$idx}.product_id"] = __('«:name» موقوف عن البيع.', ['name' => $product->name]);

                continue;
            }

            $qty = round((float) $row['quantity'], 3);
            // التكلفةُ من صفّ الصنف لا من الطلب — ولو أرسلها المتصفّح أُهملت
            $unit = round((float) $product->cost, 3);

            /*
             * ═══ النوعُ صار اختياريًّا ولا يقرّر شيئًا ═══
             *
             * كان `flower` افتراضًا لكلّ مادّة لا يُقال نوعُها — أي أنّ من
             * يبيع العطر يكتب في دفتره «ورد». فلا يُكتب اليوم إلّا إن أُرسل،
             * ولا يُرسل إلّا من سلّةٍ عُلّقت قبل الترقية.
             */
            $kind = in_array($row['kind'] ?? null, OrderItemComponent::KINDS, true)
                ? $row['kind']
                : null;

            $out[] = [
                'product' => $product,
                'name' => $product->name,
                'sku' => $product->sku,
                'kind' => $kind,
                'quantity' => $qty,
                'unit_cost' => $unit,
                'total_cost' => round($unit * $qty, 3),
                /*
                 * ═══ أتعود هذه المادّةُ إلى الرفّ؟ ═══
                 *
                 * السياسةُ تُكتب لحظةَ البيع لا تُخمَّن يوم الإلغاء — وهذا لم
                 * يتغيّر. الذي تغيّر **من يقولها**: كانت نوعَ المادّة يقولها
                 * («التغليف يعود وما سواه لا يعود»)، وهو افتراضُ محلِّ ورد لا
                 * قاعدةَ نظام. من يؤجّر الكراسي يعود عنده كلُّ شيء، ومن يبيع
                 * الطعام لا يعود عنده شيء.
                 *
                 * فثلاثةُ مصادر بترتيبٍ من الأخصّ إلى الأعمّ:
                 *   ١ · ما قاله الموظّف لهذه المادّة بعينها.
                 *   ٢ · نوعُ المادّة — لسلّةٍ عُلّقت قبل الترقية وحدها، فيبقى
                 *       سلوكُها كما كان يوم عُلّقت.
                 *   ٣ · افتراضُ القالب الذي كتبه صاحبُ النشاط.
                 *
                 * ولا شيءَ منها يصل من المتصفّح إلّا الأوّل — منطقيٌّ مُحقَّقٌ
                 * لا يمسّ مالًا: أثرُه أن تعود بضاعةٌ أو لا تعود عند الإلغاء،
                 * وهو قرارُ من يبيع.
                 */
                'restockable' => array_key_exists('restockable', $row) && $row['restockable'] !== null
                    ? filter_var($row['restockable'], FILTER_VALIDATE_BOOL)
                    : ($kind !== null
                        ? $kind === OrderItemComponent::PACKAGING
                        : (bool) $template?->components_restockable_default),
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * ما تأكله موادُّ الطلب من الرفّ — بالكسر قبل الرفع.
     *
     * تُنادى من فحصِ التوفّر ومن الخصم معًا. ولو حُسبت في موضعين لجاز أن
     * يفحص أحدهما خمسةَ عشر ويخصم الآخر ستّةَ عشر — فيُقبل بيعٌ لا رصيد له.
     * وهي القاعدةُ نفسُها التي تحرس `PosController::addonConsumption`.
     *
     * ═══ والموادُّ لوحدةٍ واحدة، فتُضرب في الكميّة ═══
     *
     * اللقطةُ تصف باقةً واحدة: «ثماني ورداتٍ وكيس». فإن ضغط الكاشير «+»
     * في السلّة صار الثمنُ ثمنين — ووجب أن يصير الوردُ ستّةَ عشر. ولولا
     * الضربُ لَباع باقتين وخصم واحدة، ويظلّ الرفُّ يقول ما ليس فيه حتى
     * الجرد. وهو الضربُ نفسه الذي تفعله `Recipe::consumptionFor` بكميّة
     * البند.
     *
     * و`$units` لا قيمةَ افتراضية له: مناداةٌ تنساه تُنقص الرفّ صامتةً،
     * والتوقيعُ الذي يُجبر على ذكره لا يُنسى.
     *
     * @param  array<int, array<string, mixed>>  $components  لقطاتٌ من `components()`
     * @param  int  $units  كميّةُ البند — عددُ الباقات المتماثلة
     * @return array<int, float> [معرّف الصنف => الكمية العشرية]
     */
    public static function consumption(array $components, int $units): array
    {
        if ($units < 1) {
            return [];
        }

        $out = [];

        foreach ($components as $c) {
            $pid = (int) ($c['product']?->id ?? $c['product_id'] ?? 0);
            $qty = (float) ($c['quantity'] ?? 0);

            if ($pid > 0 && $qty > 0) {
                $out[$pid] = ($out[$pid] ?? 0.0) + $qty * $units;
            }
        }

        return $out;
    }

    /**
     * تكلفةُ الموادّ — مجموعُ لقطاتها.
     *
     * ولا تُقرأ من الطلب: الموظّفُ يرى الرقم في الشاشة ولا يكتبه، والخادمُ
     * يحسبه من `products.cost` كما يحسب تكلفةَ كلّ بندٍ ذي وصفة.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    public static function materialCost(array $components): float
    {
        return round(array_sum(array_column($components, 'total_cost')), 3);
    }

    /**
     * وصفُ الطلب كما يُحفظ في `order_items.custom_details`.
     *
     * ═══ لقطةٌ لا مرجع ═══
     *
     * اسمُ القالب وتسمياتُ حقوله وخياراتِه تُنسخ هنا. فصاحبُ النشاط يُعيد
     * تسمية قالبه بعد شهر، أو يحذف خيارًا، أو يُوقف القالبَ كلَّه — ويبقى
     * طلبُ سبتمبر مقروءًا كما بيع. وقراءةُ القالب الحيّ يوم الطباعة كانت
     * تعيد كتابة ورقةٍ سُلِّمت.
     *
     * والحقولُ الداخليّة تُحفظ بوسمها: من يقرؤها يعرف أنّها للتجهيز لا
     * للعميل، ولا يحتاج أن يسأل القالبَ الحيّ عن حقلٍ قد يكون حُذف.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $fields  لقطاتٌ من `fieldValues()`
     */
    public static function details(
        array $data,
        float $materialCost,
        ?CustomOrderTemplate $template = null,
        array $fields = [],
    ): array {
        /*
         * وحقولُ الشكل القديم تُحوَّل هنا إلى حقولٍ عامّة.
         *
         * سلّةٌ عُلّقت قبل الترقية تُستأنف بمفاتيحها القديمة. وحفظُها كما هي
         * كان يُبقي صيغتين تُقرآن بقارئين — و«فحصان لسؤالٍ واحد يفترقان يوم
         * يُبدَّل أحدهما». فتُكتب حقولًا عامّةً بتسمياتها التي رآها الموظّف
         * يومها، ولا تُكتب بعد اليوم صيغةٌ قديمة أبدًا.
         */
        $fields = array_merge($fields, self::legacyAsFields($data));

        return array_filter([
            'v' => self::SNAPSHOT_VERSION,
            'mode' => $data['mode'],
            'template' => $template ? [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'name_en' => $template->name_en,
            ] : null,
            'base_label' => $template ? [
                'label' => (string) ($template->base_label ?: 'القيمة الأساسية'),
                'label_en' => $template->base_label_en ?: 'Base Value',
            ] : null,
            'base_value' => isset($data['base_value']) || isset($data['flower_value'])
                ? round((float) ($data['base_value'] ?? $data['flower_value']), 3)
                : null,
            'fields' => $fields,
            /*
             * وتكلفةُ الموادّ تُحفظ هنا **وفي `order_items.cost` معًا** — ولا
             * تناقض: العمودُ هو ما تقرؤه التقاريرُ والربحيّة كما تقرؤه لكلّ
             * بند، وهذه لقطةٌ تُعرض في بطاقة الطلب بجانب موادّها.
             */
            'material_cost' => $materialCost,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * الحقولُ الثلاثةُ القديمة حقولًا عامّة — للحمولات وللّقطات المحفوظة معًا.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private static function legacyAsFields(array $data): array
    {
        $out = [];

        foreach (self::LEGACY_FIELDS as $key => [$ar, $en, $internal]) {
            $raw = $data[$key] ?? null;

            $values = is_array($raw)
                ? array_values(array_filter(array_map(
                    fn ($v) => ['label' => trim((string) $v), 'label_en' => null],
                    $raw,
                ), fn ($v) => $v['label'] !== ''))
                : (filled($raw) ? [['label' => trim((string) $raw), 'label_en' => null]] : []);

            if (! $values) {
                continue;
            }

            $out[] = [
                // ولا `id`: لا صفَّ حقلٍ خلفها، وهي تسميةٌ محفوظةٌ لا مرجع
                'label' => $ar,
                'label_en' => $en,
                'type' => is_array($raw) ? CustomOrderField::MULTI_SELECT : CustomOrderField::SHORT_TEXT,
                'internal' => $internal,
                'values' => $values,
            ];
        }

        return $out;
    }

    /**
     * إجاباتُ الحقول كما ترسلها الشاشةُ — لاستئناف سلّةٍ عُلّقت.
     *
     * تعكس `fieldValues()`: تلك تقرأ ما أُرسل وتكتب لقطة، وهذه تقرأ اللقطة
     * وتردّ ما يُرسَل. ولقطةٌ لا يُستأنف منها طلبٌ تجعل سلّةً معلَّقة تعود
     * ناقصةَ ما كتبه الموظّف — وهو يظنّ أنّها عادت كاملة.
     *
     * @param  array<string, mixed>|null  $details
     * @return array<int, array{field_id: int, value: mixed}>
     */
    public static function resumeFields(?array $details): array
    {
        $out = [];

        foreach (($details['fields'] ?? []) as $f) {
            $id = (int) ($f['id'] ?? 0);
            if ($id < 1) {
                // حقلُ الشكل القديم — لا صفَّ له يُعاد إليه
                continue;
            }

            $values = $f['values'] ?? [];
            $type = $f['type'] ?? null;

            $options = in_array($type, CustomOrderField::OPTION_TYPES, true);

            $value = match (true) {
                $options => array_values(array_filter(array_map(fn ($v) => (int) ($v['option_id'] ?? 0), $values))),
                $type === CustomOrderField::CHECKBOX => $values !== [],
                default => (string) ($values[0]['label'] ?? ''),
            };

            $out[] = ['field_id' => $id, 'value' => $value];
        }

        return $out;
    }

    /**
     * اللقطةُ كما تُقرأ على الشاشة — وهي البابُ الوحيد لقراءتها.
     *
     * ═══ صيغتان تُقرآن بقارئٍ واحد ═══
     *
     * الطلباتُ التي بيعت قبل القوالب تحمل الشكل الأوّل: `flower_value`
     * و`colors` و`packaging_label` و`florist_notes` مفاتيحَ في الجذر. وما
     * بعدها تحمل الشكلَ العامّ: قالبٌ وحقول.
     *
     * ولا يُهاجَر القديمُ إلى الجديد في القاعدة: هجرةٌ تكتب فوق ثلاثةَ عشرَ
     * ألفَ صفٍّ تاريخيّ لتُجمّل شكلًا، وأيُّ خطأٍ فيها لا يُستعاد. يُقرأ
     * القديمُ كما كُتب، ويُردّ بالشكل الذي تفهمه الشاشة — قارئٌ واحدٌ لا
     * شاشتان.
     *
     * @param  array<string, mixed>|null  $details
     * @return array{template: ?string, base_label: ?string, base_value: ?float, fields: array<int, array{label: string, internal: bool, values: array<int, string>}>}
     */
    public static function view(?array $details): array
    {
        $details ??= [];
        $legacy = (int) ($details['v'] ?? 1) < self::SNAPSHOT_VERSION;

        $fields = $legacy
            ? self::legacyAsFields($details)
            : ($details['fields'] ?? []);

        return [
            'template' => isset($details['template'])
                ? Demo::ln($details['template']['name'] ?? null, $details['template']['name_en'] ?? null)
                : null,
            'base_label' => isset($details['base_label'])
                ? Demo::ln($details['base_label']['label'] ?? null, $details['base_label']['label_en'] ?? null)
                : null,
            'base_value' => isset($details['base_value'])
                ? (float) $details['base_value']
                : (isset($details['flower_value']) ? (float) $details['flower_value'] : null),
            'fields' => array_map(fn ($f) => [
                'label' => Demo::ln($f['label'] ?? null, $f['label_en'] ?? null),
                'internal' => (bool) ($f['internal'] ?? false),
                'values' => array_map(
                    fn ($v) => Demo::ln($v['label'] ?? null, $v['label_en'] ?? null),
                    $f['values'] ?? [],
                ),
            ], array_values($fields)),
        ];
    }

    /**
     * سطورُ الطلب المخصَّص كما تُطبع على الورق — الظاهرةُ منها وحدها.
     *
     * ═══ العطب ═══
     *
     * التاجر يكتب شكلَ طلبه — «اللون» و«المقاس» و«اسم المهدى إليه» — ويملؤه
     * عند الصندوق، ثمّ تخرج الفاتورةُ بسطرٍ واحد: اسمُ القالب وسعرُه. فكلّ
     * ما سُئل عنه الزبونُ ودُوِّن لا أثرَ له على ورقته.
     *
     * فيعود بعد يومين يقول «طلبتُ الأحمر»، ولا ورقةَ تحسم. وموظّفٌ آخر
     * يُسلّم الطلبَ فلا يجد على الإيصال ما يطابقه به. والبيانُ كان في
     * القاعدة طَوالَ الوقت.
     *
     * ═══ و«غير الداخليّ» وحده ═══
     *
     * `internal` علامةٌ يضعها التاجرُ على حقلٍ لعينه هو: «ملاحظة المنسّق»،
     * «التكلفة التقديريّة». وطباعتُها على ورقة الزبون تُفشي ما لم يُقصد
     * إفشاؤه — والعلامةُ تصير كاذبةً إن لم تُقرأ حيث يُطبع.
     *
     * ═══ وقارئٌ واحدٌ للورقتين ═══
     *
     * ورقةُ A4 والشريطُ الحراريّ ورقتان لطلبٍ واحد. ولو حسبت كلٌّ منهما
     * سطورَها لافترقتا يوم يُبدَّل معنى `internal` في إحداهما — فيخرج
     * للزبون على الشريط ما يُخفى عنه على الورقة.
     *
     * @param  array<string, mixed>|null  $details
     * @return array<int, array{label: string, value: string}>
     */
    public static function paperLines(?array $details): array
    {
        $out = [];

        foreach (self::view($details)['fields'] as $f) {
            // وحقلٌ تُرك فارغًا لا يُطبع سطرًا بتسميةٍ ونقطتين وبياض
            $values = array_values(array_filter(
                $f['values'],
                fn ($v) => trim((string) $v) !== '',
            ));

            if ($f['internal'] || $values === []) {
                continue;
            }

            $out[] = ['label' => (string) $f['label'], 'value' => implode(' · ', $values)];
        }

        return $out;
    }
}
