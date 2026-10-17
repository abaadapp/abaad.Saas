<?php

namespace App\Support;

use App\Http\Controllers\PdfController;
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

    /** @return Response */
    public static function pdf(int $businessId, Order $order)
    {
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
     * الورقةُ A4 تُطبع على طابعة المكتب، والمحلُّ الذي لا يملك إلّا طابعةَ
     * صندوقه يضع في الصندوق إيصالًا لا يقول إلى من يُسلَّم — أو لا شيء.
     * فيُطبع السندُ على الشريط كذلك، من البابين نفسيهما (صفحةُ الطلب ولوحةُ
     * التجهيز)، وبقالب `delivery` نفسِه: ما يضبطه التاجرُ يقع على الورقتين.
     *
     * وهو شريطٌ كالإيصال في كلّ شيء إلّا ما يقرؤه حاملُه: الرموزُ واحدة
     * (`partials/thermal-tokens`)، والشعارُ من حيث يأخذه الإيصال، والعرضُ
     * عرضُ طابعة الصندوق (`PdfController::receiptWidth`)، والطولُ يُقاس
     * ويُقسَم في `MpdfDriver::strip` — فطلبٌ بمئة صنفٍ شريطٌ واحدٌ وقصٌّ واحد.
     * والطابعةُ المباشرة في التطبيق ترسمه كما ترسم الإيصال.
     */

    /** الشريطُ نصًّا — ومنه الـPDF، ويقرؤه الاختبار (انظر `html`) */
    public static function strip(int $businessId, Order $order): string
    {
        return DocumentRenderer::deliveryStrip($businessId, $order, PdfController::receiptWidth($businessId));
    }

    /** @return Response */
    public static function stripPdf(int $businessId, Order $order)
    {
        $width = PdfController::receiptWidth($businessId);

        Activity::log('report', 'طبع سند تسليم حراري للطلب: '.$order->number, ['subject_id' => $order->id]);

        return Pdf::strip(
            DocumentRenderer::deliveryStrip($businessId, $order, $width),
            'delivery-'.$order->number,
            $width,
        );
    }

    /** أهي هديّة؟ — علمُ الموقع، أو مناسبةٌ أو بطاقةٌ كُتبت عند الصندوق */
    public static function isGift(Order $order): bool
    {
        return (bool) $order->is_gift || filled($order->occasion_type) || filled($order->card_message);
    }

    /**
     * إلى من يُسلَّم — وطلبُ الهديّة لا يقع على المشتري بحال.
     *
     * بيعةٌ تُوصَل إلى صاحبها: المستلِمُ هو العميلُ إن لم يُكتب غيرُه، وهاتفُه
     * هاتفُه. والهديّةُ يفترق فيها الاثنان: لا يُستعار للمستلِم اسمُ المشتري
     * ولا هاتفُه ولو غاب اسمُ المستلِم — ومن طلب «لا تذكر اسمي» قرأه
     * المستلِمُ على الورقة لو استُعير. و«عميل نقدي» ليس اسمًا يُكتب.
     *
     * @return array{name: ?string, phone: ?string, address: ?string, notes: ?string}
     */
    public static function recipient(Order $order): array
    {
        $name = trim((string) $order->recipient_name);
        $phone = trim((string) $order->recipient_phone);

        if (! self::isGift($order)) {
            if ($name === '') {
                $buyer = trim((string) Demo::ln($order->customer_name, $order->customer_name_en));
                $name = $buyer === FlowerOrder::WALK_IN || $buyer === __(FlowerOrder::WALK_IN) ? '' : $buyer;
            }

            $phone = $phone !== '' ? $phone : trim((string) ($order->customer?->phone ?? ''));
        }

        $text = fn (?string $v) => filled($v) ? trim((string) $v) : null;

        return [
            'name' => $name !== '' ? $name : null,
            'phone' => $phone !== '' ? $phone : null,
            'address' => $text($order->delivery_address),
            'notes' => $text($order->delivery_notes),
        ];
    }

    /**
     * قسمُ الهديّة — أو null فلا يُطبع رأسُه فوق فراغ.
     *
     * والمُهدي من `FlowerOrder::cardForRecipient` وحدها: «لا تذكر اسمي» يُطاع
     * هناك لكلّ ورقةٍ تبلغ المستلِم. فلا سطرَ «من:» فارغًا ولا مُخفًى — يغيب.
     *
     * @return array{occasion: ?string, message: ?string, sender: ?string}|null
     */
    public static function gift(Order $order): ?array
    {
        if (! self::isGift($order)) {
            return null;
        }

        $card = FlowerOrder::cardForRecipient($order);
        $text = fn (mixed $v) => filled($v) ? trim((string) $v) : null;

        $gift = [
            'occasion' => $text(GiftOrders::occasionLabel($order)),
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
     * خيارات الطلب المخصَّص. فلا تقرّر ورقةٌ ثالثةٌ ما يُفشى.
     *
     * @return list<array{name: string, qty: string, note: ?string, options: list<array{label: string, value: string}>}>
     */
    public static function stripItems(Order $order): array
    {
        return $order->items->map(fn (OrderItem $it) => [
            'name' => (string) $it->name,
            'qty' => DocumentPaper::qty($it->quantity),
            'note' => GiftCardProduct::paperNote($it, $order) ?: null,
            'options' => $it->isCustom() ? CustomArrangement::paperLines($it->custom_details) : [],
        ])->values()->all();
    }
}
