<?php

namespace App\Support;

use App\Http\Controllers\PdfController;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Store\GiftCardProduct;
use App\Support\Store\GiftOrders;
use Illuminate\Http\Response;

/**
 * سندُ التسليم ورقةً — يُبنى في موضعٍ واحد يقرؤه البابان.
 *
 * ═══ ولمَ موضعٌ واحد ═══
 *
 * يُطبع من صفحة الطلب (لمن يملك «المبيعات») ومن لوحة التجهيز (لمن يجهّز
 * الشحنة). وورقتان تُبنيان في موضعين تفترقان يومًا: يُضاف حقلٌ إلى إحداهما
 * ولا يُضاف إلى الأخرى، فيقرأ السائقُ عنوانًا ناقصًا في نسخةٍ ويقرؤه كاملًا
 * في الأخرى — ولا شيء يقول أيُّهما الصحيحة.
 *
 * والقالبُ واحدٌ كذلك (`delivery`)، فما يضبطه التاجرُ في «قوالب الأوراق»
 * يقع على الورقتين معًا — ومنه `show_prices` المُطفأ افتراضًا: الورقةُ تمشي
 * مع الشحنة إلى يد المستلِم، ومن استلم هديّةً لا يُقرَأ عليه ثمنُها.
 */
final class DeliveryPaper
{
    /**
     * الورقةُ نصًّا — ومنها تُبنى الـPDF.
     *
     * ═══ ولمَ خطوتان لا خطوة ═══
     *
     * الـPDF مضغوطةٌ لا يُقرأ منها نصّ، فحارسٌ عليها لا يشهد بشيء: لا يقول
     * أنّ الأسعارَ غائبة، ولا أنّ القالبَ المستعمَل قالبُ «سند التسليم».
     * وحارسٌ لا يشهد أسوأ من غياب الحارس — يُقرأ اخضرارُه طمأنينة.
     *
     * فالنصُّ يُبنى هنا ليُقرأ، والضغطُ يقع بعده. (جُرّبت طفرةٌ تبدّل القالبَ
     * إلى `sale` فنجت، لأنّ الاختبارَ كان يبني النصَّ بنفسه ولا يمرّ من هنا.)
     */
    public static function html(int $businessId, Order $order): string
    {
        /*
         * والطلبُ يُمرَّر لا بيانُه وحده: منه يُبنى رمزُ الورقة أونلاين.
         *
         * سندُ التسليم يمشي مع الشحنة إلى يد المستلِم، فيحمل أسفلَه طريقَه
         * إلى نسخةٍ لا تبهت ولا تُبلَّل.
         */
        return DocumentRenderer::generic(
            $businessId,
            'delivery',
            DocumentPaper::forDelivery($order),
            null,
            $order,
        );
    }

    /**
     * الورقةُ A4 ملفًّا — لكلّ طلبٍ سندُه A4، إلّا ما سندُه الشريط.
     *
     * وطلبُ موقعٍ في متجرٍ فُتح له الشريطُ (`paperFor`) سندُه واحدٌ لا اثنان:
     * يُردّ هنا بـ404 من أيّ بابٍ جاء — صفحةِ الطلب أو لوحةِ التجهيز، أو
     * رابطٍ كُتب باليد. فلا تخرج له ورقةٌ تحمل ما يُحجب على الشريط.
     *
     * @return Response
     */
    public static function pdf(int $businessId, Order $order)
    {
        abort_if(self::paperFor($order, self::thermalOn($businessId)) === self::THERMAL, 404);

        Activity::log('report', 'طبع سند تسليم للطلب: '.$order->number, ['subject_id' => $order->id]);

        return DocumentRenderer::pdf(
            self::html($businessId, $order),
            'delivery-'.$order->number,
            __('سند تسليم').' '.$order->number,
        );
    }

    /*
     * ═══════════ والسندُ شريطًا حراريًّا ═══════════
     *
     * الورقةُ A4 تُطبع على طابعة المكتب، والمحلُّ الذي يحمل طلباتِ موقعه من
     * طابعة صندوقه يضع في الصندوق إيصالًا لا يقول إلى من يُسلَّم — أو لا
     * شيء. فيُطبع السندُ على الشريط، من البابين نفسيهما (صفحةُ الطلب ولوحةُ
     * التجهيز)، وبقالب `delivery` نفسِه: ما يضبطه التاجرُ يقع على الورقتين.
     *
     * وهو شريطٌ كالإيصال في كلّ شيء إلّا ما يقرؤه حاملُه: الرموزُ واحدة
     * (`partials/thermal-tokens`)، والشعارُ من حيث يأخذه الإيصال، والعرضُ
     * عرضُ طابعة الصندوق (`PdfController::receiptWidth`)، والطولُ يُقاس
     * ويُقسَم في `MpdfDriver::strip` — فطلبٌ بمئة صنفٍ شريطٌ واحدٌ وقصٌّ واحد.
     * والطابعةُ المباشرة في التطبيق ترسمه كما ترسم الإيصال.
     *
     * ═══ ولمن ═══
     *
     * لطلبات **الموقع** وحدها، في متجرٍ فتحه له مديرُ المنصّة (`thermalOn`).
     * وطلبُ الصندوق وكلُّ قناةٍ سواه سندُه A4 كما كان، ومتجرٌ لم يُفتح له لا
     * يرى الشريطَ ولا يبلغه برابطٍ يكتبه بيده: السؤالُ في الباب (`stripPdf`)
     * لا في الزرّ وحده.
     */

    /** السندُ على الشريط — لطلب موقعٍ في متجرٍ فُتح له */
    public const THERMAL = 'thermal';

    /** السندُ على ورقة A4 — لكلّ ما سواه، كما كان */
    public const A4 = 'a4';

    /** ما يحلّ محلَّ هويّة المشتري في نصٍّ حرٍّ أخفى صاحبُه اسمَه */
    public const HIDDEN = '***';

    /** فتحه مديرُ المنصّة لهذا المتجر؟ — مغلقٌ لكلّ متجرٍ افتراضًا */
    public static function thermalOn(int $businessId): bool
    {
        return (bool) Business::whereKey($businessId)->value('thermal_delivery_note_enabled');
    }

    /**
     * أيُّ سندٍ لهذا الطلب — والجوابُ نفسُه للزرّ وللباب.
     *
     * والقناةُ ما كُتب في `orders.channel` يومَ أُنشئ الطلب (`SalesChannel`)،
     * لا ما يُخمَّن من شكله. و`$shopOn` لشاشةٍ تسأل عن طلباتٍ كثيرةٍ من متجرٍ
     * واحد — لوحةُ التجهيز — فلا يُسأل الجدولُ مرّةً لكلّ بطاقة.
     */
    public static function paperFor(?Order $order, ?bool $shopOn = null): string
    {
        if ($order === null || $order->channel !== SalesChannel::WEBSITE) {
            return self::A4;
        }

        return ($shopOn ?? self::thermalOn((int) $order->business_id)) ? self::THERMAL : self::A4;
    }

    /** الشريطُ نصًّا — ومنه الـPDF، ويقرؤه الاختبار (انظر `html`) */
    public static function strip(int $businessId, Order $order): string
    {
        return DocumentRenderer::deliveryStrip($businessId, $order, PdfController::receiptWidth($businessId));
    }

    /**
     * الشريطُ ملفًّا — وهنا يُسأل: أيُطبع سندُ هذا الطلب على الشريط؟
     *
     * البابان يمرّان من هنا، فالسؤالُ في موضعٍ واحد: متجرٌ لم يُفتح له، أو
     * طلبٌ من غير الموقع، يُردّ بـ404 ولو كُتب الرابطُ باليد — لا ورقةَ بهذا
     * الشكل لهذا الطلب.
     *
     * @return Response
     */
    public static function stripPdf(int $businessId, Order $order)
    {
        abort_unless(self::paperFor($order, self::thermalOn($businessId)) === self::THERMAL, 404);

        $width = PdfController::receiptWidth($businessId);

        Activity::log('report', 'طبع سند تسليم حراري للطلب: '.$order->number, ['subject_id' => $order->id]);

        return Pdf::strip(
            DocumentRenderer::deliveryStrip($businessId, $order, $width),
            'delivery-'.$order->number,
            $width,
        );
    }

    /**
     * ما يُطبع على الشريط — كلُّه، ولا شيءَ سواه.
     *
     * ═══ ولمَ حمولةٌ لا الطلبُ نفسُه ═══
     *
     * الورقةُ تُسلَّم إلى يد المستلِم. وقالبٌ يُعطى الطلبَ يبلغ كلَّ عمودٍ فيه:
     * اسمَ المشتري وهاتفَه والمبلغَ ووسيلةَ الدفع والملاحظاتِ الداخليّة —
     * وشرطٌ واحدٌ يُنسى في Blade يطبع واحدًا منها. فالقالبُ لا يُعطى الطلب:
     * يُعطى هذه الحمولة، تُبنى هنا في الخادم، وما ليس فيها لا يُطبع بحال.
     *
     * @return array{number: string, ordered_at: ?string, scheduled_for: ?string, fulfilment: ?string, branch: ?string, pieces: string, recipient: array{name: ?string, phone: ?string, address: ?string, notes: ?string}, items: list<array{name: string, qty: string, note: ?string, options: list<array{label: string, value: string}>}>, gift: array{occasion: ?string, message: ?string, sender: ?string}|null}
     */
    public static function forRecipient(Order $order): array
    {
        $buyer = self::buyer($order);

        return [
            'number' => (string) $order->number,
            'ordered_at' => $order->ordered_at?->format('Y-m-d H:i'),
            'scheduled_for' => $order->scheduled_for?->format('Y-m-d H:i'),
            'fulfilment' => match ((string) $order->fulfillment_type) {
                FlowerOrder::DELIVERY => __('توصيل'),
                FlowerOrder::PICKUP => __('استلام من المحل'),
                default => null,
            },
            'branch' => filled($order->branch) ? trim((string) $order->branch) : null,
            'pieces' => DocumentPaper::qty($order->items->sum('quantity')),
            'recipient' => self::recipient($order, $buyer),
            'items' => self::stripItems($order, $buyer),
            'gift' => self::gift($order, $buyer),
        ];
    }

    /** أهي هديّة؟ — علمُ الموقع، أو مناسبةٌ أو بطاقةٌ كُتبت عند الصندوق */
    public static function isGift(Order $order): bool
    {
        return (bool) $order->is_gift || filled($order->occasion_type) || filled($order->card_message);
    }

    /**
     * أخفى المشتري اسمَه عن المستلِم؟ — `hide_sender` وحدَه.
     *
     * لا يُسأل معه علمُ الهديّة ولا المناسبةُ ولا البطاقة: طلبٌ طلب صاحبُه
     * «لا تذكر اسمي» ولم يُعلَّم هديّةً — بحقولٍ ناقصةٍ أو متناقضة — يُحمى
     * كما تُحمى الهديّة. الطلبُ قد لا يتّسق، والسرُّ لا يُعلَّق على اتّساقه.
     */
    public static function anonymous(Order $order): bool
    {
        return (bool) $order->hide_sender;
    }

    /**
     * إلى من يُسلَّم — والمشتري لا يُستعار مستلِمًا لهديّةٍ ولا لمن أخفى اسمه.
     *
     * بيعةٌ تُوصَل إلى صاحبها: المستلِمُ هو العميلُ إن لم يُكتب غيرُه، وهاتفُه
     * هاتفُه. والهديّةُ يفترق فيها الاثنان: لا يُستعار للمستلِم اسمُ المشتري
     * ولا هاتفُه ولو غاب اسمُ المستلِم. و«عميل نقدي» ليس اسمًا يُكتب.
     *
     * ومن أخفى اسمَه يُطرح من نصوص المستلِم ما يدلّ عليه (`scrub`)، ويغيب
     * اسمٌ أو هاتفٌ في خانة المستلِم هو المشتري نفسُه — كتب رقمَه ليُتّصل به —
     * فالورقةُ لا تقول لحاملها من أرسل.
     *
     * @param  array{names: list<string>, whole: list<string>, phones: list<string>}|null  $buyer
     * @return array{name: ?string, phone: ?string, address: ?string, notes: ?string}
     */
    public static function recipient(Order $order, ?array $buyer = null): array
    {
        $buyer ??= self::buyer($order);
        $name = trim((string) $order->recipient_name);
        $phone = trim((string) $order->recipient_phone);

        if (! self::isGift($order) && ! self::anonymous($order)) {
            if ($name === '') {
                $customer = trim((string) $order->customer_name);
                $name = $customer === FlowerOrder::WALK_IN || $customer === __(FlowerOrder::WALK_IN) ? '' : $customer;
            }

            $phone = $phone !== '' ? $phone : trim((string) ($order->customer?->phone ?? ''));
        }

        if (self::anonymous($order)) {
            // اسمٌ هو اسمُ المشتري بعينه يغيب — ولو قصُر عن أن يُطارَد في النصّ
            $name = in_array(mb_strtolower($name), $buyer['whole'], true) ? '' : (string) self::scrub($name, $buyer);
            // واسمٌ لم يبقَ منه بعد المحجوب حرفٌ ولا رقم كان أسماءَ المشتري وحدها — «*** و***» ليس مستلِمًا
            $name = preg_match('/[\p{L}\p{N}]/u', str_replace(self::HIDDEN, '', $name)) === 1 ? $name : '';
            $phone = $phone !== '' && self::isBuyerPhone($phone, $buyer['phones']) ? '' : $phone;
        }

        return [
            'name' => $name !== '' ? $name : null,
            'phone' => $phone !== '' ? $phone : null,
            'address' => self::scrub($order->delivery_address, $buyer),
            'notes' => self::scrub($order->delivery_notes, $buyer),
        ];
    }

    /**
     * قسمُ الهديّة — أو null فلا يُطبع رأسُه فوق فراغ.
     *
     * والمُهدي من `FlowerOrder::cardForRecipient` وحدها: «لا تذكر اسمي» يُطاع
     * هناك لكلّ ورقةٍ تبلغ المستلِم. فلا سطرَ «من:» فارغًا ولا مُخفًى — يغيب،
     * ولا يحلّ اسمُ العميل محلَّ مُهدٍ لم يُكتب.
     *
     * ونصُّ البطاقة يُطبع كما كتبه صاحبُه: هو ما أراد أن يقرأه المستلِم، وهو
     * نفسُه على البطاقة التي تصل معه. والمناسبةُ «أخرى» نصٌّ حرٌّ فتُطرح منها
     * هويّةُ من أخفى اسمه.
     *
     * @param  array{names: list<string>, whole: list<string>, phones: list<string>}|null  $buyer
     * @return array{occasion: ?string, message: ?string, sender: ?string}|null
     */
    public static function gift(Order $order, ?array $buyer = null): ?array
    {
        if (! self::isGift($order)) {
            return null;
        }

        $buyer ??= self::buyer($order);
        $card = FlowerOrder::cardForRecipient($order);
        $text = fn (mixed $v) => filled($v) ? trim((string) $v) : null;

        $gift = [
            'occasion' => self::scrub(GiftOrders::occasionLabel($order), $buyer),
            'message' => $text($card['message']),
            'sender' => $text($card['sender']),
        ];

        return array_filter($gift) === [] ? null : $gift;
    }

    /**
     * الأصنافُ كما يحملها السائق — اسمٌ وكميّةٌ وما يميّز البند، بلا ثمن.
     *
     * والملاحظةُ والخياراتُ من قارئَي الإيصال نفسَيهما: `paperNote` تُبقي
     * رسالةَ كرت الهديّة خارج الورقة، و`paperLines` تطرح الداخليَّ من
     * خيارات الطلب المخصَّص. فلا تقرّر ورقةٌ ثالثةٌ ما يُفشى. وما كتبه
     * الناسُ فيهما نصٌّ حرٌّ تُطرح منه هويّةُ من أخفى اسمه؛ واسمُ الصنف
     * اسمُ رفٍّ لا يُمسّ.
     *
     * @param  array{names: list<string>, whole: list<string>, phones: list<string>}|null  $buyer
     * @return list<array{name: string, qty: string, note: ?string, options: list<array{label: string, value: string}>}>
     */
    public static function stripItems(Order $order, ?array $buyer = null): array
    {
        $buyer ??= self::buyer($order);

        return $order->items->map(fn (OrderItem $it) => [
            'name' => (string) $it->name,
            'qty' => DocumentPaper::qty($it->quantity),
            'note' => self::scrub(GiftCardProduct::paperNote($it, $order), $buyer),
            'options' => array_map(
                fn (array $o) => ['label' => $o['label'], 'value' => (string) self::scrub($o['value'], $buyer)],
                $it->isCustom() ? CustomArrangement::paperLines($it->custom_details) : [],
            ),
        ])->values()->all();
    }

    /**
     * ما يدلّ على المشتري — أسماؤه وهاتفه — حين أخفى اسمَه، وإلّا لا شيء.
     *
     * الأسماءُ: اسمُ المُهدي، واسمُ العميل على الطلب بلغتيه، واسمُه في
     * بطاقته بلغتيه. والهاتفُ من بطاقته. و«عميل نقدي» ليس اسمًا.
     *
     * و`names` ما يُطارَد في النصّ الحرّ — ثلاثةُ أحرفٍ فأكثر: حرفان يطابقان
     * أجزاءَ كلماتٍ كثيرة. و`whole` كلُّها بأحرفٍ صغيرة، لخانةٍ تُقارَن كاملةً
     * (اسمُ المستلِم): المطابقةُ التامّة لا تخطئ ولو قصُر الاسم.
     *
     * @return array{names: list<string>, whole: list<string>, phones: list<string>}
     */
    private static function buyer(Order $order): array
    {
        if (! self::anonymous($order)) {
            return ['names' => [], 'whole' => [], 'phones' => []];
        }

        $customer = $order->customer;

        $names = collect([$order->sender_name, $order->customer_name, $order->customer_name_en, $customer?->name, $customer?->name_en])
            ->map(fn ($n) => trim((string) $n))
            ->filter(fn (string $n) => $n !== '' && $n !== FlowerOrder::WALK_IN && $n !== __(FlowerOrder::WALK_IN))
            ->unique()->values();

        $phones = collect([$customer?->phone])
            ->map(fn ($p) => self::phoneKey($p))
            ->filter()->unique()->values()->all();

        return [
            'names' => $names->filter(fn (string $n) => mb_strlen($n) >= 3)->values()->all(),
            'whole' => $names->map(fn (string $n) => mb_strtolower($n))->unique()->values()->all(),
            'phones' => $phones,
        ];
    }

    /**
     * نصٌّ حرٌّ بلا هويّة من أخفى اسمه — وبلا تغييرٍ لمن لم يُخفه.
     *
     * الاسمُ يُطرح كلمةً كاملةً لا جزءَ كلمة، بلا اعتبارٍ لحالة الحروف.
     * والهاتفُ كلُّ سلسلةِ أرقامٍ — بفراغاتها وشَرطاتها وأقواسها، وبالأرقام
     * العربيّة أو الغربيّة — فيها خاناتُه الأخيرة، بمفتاح دولته أو بلا مفتاح.
     *
     * @param  array{names: list<string>, whole: list<string>, phones: list<string>}  $buyer
     */
    private static function scrub(?string $text, array $buyer): ?string
    {
        if (blank($text)) {
            return null;
        }

        $text = trim((string) $text);

        foreach ($buyer['names'] as $name) {
            $text = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', self::HIDDEN, $text);
        }

        if ($buyer['phones'] !== []) {
            $digit = '[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]';
            $text = (string) preg_replace_callback(
                '/\+?'.$digit.'(?:[\s\-().\/]*'.$digit.'){6,}/u',
                fn (array $m) => self::isBuyerPhone($m[0], $buyer['phones']) ? self::HIDDEN : $m[0],
                $text,
            );
        }

        return $text;
    }

    /** أهذه الأرقامُ هاتفُ المشتري؟ — خاناتُه الأخيرةُ فيها */
    private static function isBuyerPhone(string $run, array $keys): bool
    {
        $digits = (string) preg_replace('/\D+/', '', (string) Digits::western($run));

        foreach ($keys as $key) {
            if (str_contains($digits, $key)) {
                return true;
            }
        }

        return false;
    }

    /** آخرُ ثماني خاناتٍ من الرقم — بها يُعرف بمفتاح دولته أو بلا مفتاح */
    private static function phoneKey(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) Digits::western((string) $phone));

        return strlen($digits) >= 7 ? substr($digits, -8) : null;
    }
}
