<?php

namespace App\Support;

use App\Http\Controllers\PdfController;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Store\GiftCardProduct;
use App\Support\Store\GiftOrders;

/**
 * «ورقة تجهيز داخلية» — ما على بطاقة اللوحة، على شريط طابعة الصندوق.
 *
 * ═══ ولمَ ورقةٌ رابعة ═══
 *
 * على الطاولة ثلاثُ أوراقٍ ولا واحدةٌ منها للطاولة:
 *
 *   - الإيصالُ وفاتورةُ العميل الداخليّة فيهما المال — وورقةٌ تُترك بجانب
 *     الباقة يقرؤها كلُّ من مرّ.
 *   - وسندُ التسليم للمستلِم: يُخفي ما لا يُقال له — الملاحظاتِ الداخليّة،
 *     واسمَ من أخفى نفسه — وهو عينُ ما يحتاجه من يجهّز.
 *
 * فمن يجهّز ينسخ البطاقةَ بيده على ورقة، أو يعود إلى الشاشة عند كلّ بند.
 *
 * ═══ ما فيها، وما ليس فيها ═══
 *
 * الأصنافُ بمقاساتها وكميّاتها، وتحت كلٍّ إضافاتُه وموادُّه وخياراتُه
 * وملاحظتُه، والملاحظاتُ الثلاث، والهديّةُ واسمُ مرسلها **مع** أنّه مخفيّ
 * عن المستلِم، وقائمةُ تحقّقٍ بما أُشّر منها. وفي ذيلها: «للاستخدام الداخلي
 * فقط — ليست فاتورة ولا سند تسليم».
 *
 * **ولا مالَ بحال:** لا سعرَ ولا إجماليَّ ولا ضريبةَ ولا دفعَ ولا Paymob.
 * والحارسُ هنا لا في القالب: الحمولةُ لا تحمل حقلًا ماليًّا أصلًا، فشرطٌ
 * يُنسى في Blade لا يبلغ رقمًا لم يُعطَه — كسند التسليم (`DeliveryPaper`).
 *
 * ═══ وما لا تفعله ═══
 *
 * تُطبع بضغطةٍ وحدها: لا تُكتب في طابور الصندوق (`PrintJobs`)، ولا تنقل
 * حالَ الطلب، ولا تضع علامةً ولا ترفعها. تقرأ العلامات ولا تكتبها.
 *
 * والأسماءُ بلغة الورقة من لقطة البيع (`ProductName::display`): الإضافةُ
 * تحمل اسمَها الإنجليزيّ منذ بيعت، والبندُ اسمَه كما بيع — لا اسمَ الكتالوج
 * اليوم ولا ترجمةً لم تُحفظ.
 */
final class PrepSheet
{
    /**
     * ورقةُ طلبٍ على اللوحة — وعلاماتُه المحفوظة معه.
     *
     * @param  array<string, array{by: ?string, at: string}>  $checks  علاماتُ الطلب (`PrepChecklist::of`)
     * @return array<string, mixed>
     */
    public static function forOrder(Order $order, array $checks): array
    {
        $locale = app()->getLocale();
        $order->loadMissing(['items.addons', 'items.components', 'items.product', 'customer']);

        $mark = fn (string $key) => $checks[$key] ?? null;

        return [
            'number_label' => __('رقم الطلب'),
            'number' => (string) $order->number,
            'source' => null,
            'printed_at' => now()->format('Y-m-d H:i'),
            'scheduled_for' => $order->scheduled_for?->format('Y-m-d H:i'),
            'fulfilment' => self::fulfilment($order->fulfillment_type),
            'branch' => filled($order->branch) ? trim((string) $order->branch) : null,
            'customer' => ['name' => $order->customer_name, 'phone' => $order->customer?->phone],
            'recipient' => [
                'name' => $order->recipient_name,
                'phone' => $order->recipient_phone,
                'address' => $order->delivery_address ?: GiftOrders::locationLabel($order),
            ],
            'items' => $order->items->map(fn (OrderItem $i) => [
                'key' => 'item:'.$i->id,
                'name' => (string) $i->name,
                'variant' => filled($i->variant_name) ? (string) $i->variant_name : null,
                'qty' => DocumentPaper::qty($i->quantity),
                'note' => filled($i->note) ? (string) $i->note : null,
                'note_label' => GiftCardProduct::cardLine($i, $order) ? __('رسالة الكرت') : __('ملاحظة المنتج'),
                'checked' => $mark('item:'.$i->id),
                'addons' => $i->addons->map(fn ($a) => [
                    'key' => 'addon:'.$a->id,
                    'name' => ProductName::display($a->name, $a->name_en, $locale),
                    'qty' => DocumentPaper::qty($a->quantity),
                    'checked' => $mark('addon:'.$a->id),
                ])->values()->all(),
                'components' => $i->components->map(fn ($c) => [
                    'name' => (string) $c->name,
                    'qty' => DocumentPaper::qty($c->quantity),
                ])->values()->all(),
                'options' => $i->isCustom() ? self::options($i->custom_details) : [],
            ])->values()->all(),
            'tasks' => self::tasks($mark),
            'saved_checks' => true,
            'notes' => self::notes($order->notes, $order->delivery_notes, $order->internal_notes),
            'gift' => self::gift(
                (bool) $order->is_gift || filled($order->card_message),
                GiftOrders::occasionLabel($order),
                $order->card_message,
                $order->sender_name,
                (bool) $order->hide_sender,
            ),
        ];
    }

    /**
     * ورقةُ مهمّة فاتورة عميل — بنودُها من الفاتورة، بلا علاماتٍ محفوظة.
     *
     * مهمّةُ الفاتورة لا علاماتِ لها في القاعدة (`PrepChecklist` للطلبات وحدها)،
     * فتُطبع مربّعاتُها فارغةً يؤشّرها من يجهّز بقلمه — ولا يُخترع لها جدول.
     *
     * @return array<string, mixed>
     */
    public static function forInvoiceTask(CustomerInvoicePreparation $prep, CustomerInvoice $invoice): array
    {
        $invoice->loadMissing(['items', 'customer']);
        $none = fn (string $key) => null;

        return [
            'number_label' => __('رقم الفاتورة'),
            'number' => (string) $invoice->number,
            'source' => __('فاتورة عميل'),
            'printed_at' => now()->format('Y-m-d H:i'),
            'scheduled_for' => $prep->scheduled_for?->format('Y-m-d H:i'),
            'fulfilment' => self::fulfilment($prep->fulfillment_type),
            'branch' => $prep->branch?->name,
            'customer' => ['name' => $invoice->customer_name, 'phone' => $invoice->customer?->phone],
            'recipient' => [
                'name' => $prep->recipient_name,
                'phone' => $prep->recipient_phone,
                'address' => $prep->delivery_address,
            ],
            'items' => InvoiceLineDetails::prepLines($invoice),
            'tasks' => self::tasks($none),
            'saved_checks' => false,
            'notes' => self::notes($invoice->notes, $prep->delivery_notes, $prep->internal_notes),
            'gift' => self::gift(
                (bool) $prep->is_gift || filled($prep->card_message),
                $prep->occasion_type === 'other' && filled($prep->occasion_text)
                    ? __('أخرى').': '.$prep->occasion_text
                    : FlowerOrder::occasionLabel($prep->occasion_type),
                $prep->card_message,
                $prep->sender_name,
                (bool) $prep->hide_sender,
            ),
        ];
    }

    /** الشريطُ نصًّا — بعرض طابعة الصندوق كما يُطبع */
    public static function html(int $businessId, array $sheet, ?int $width = null): string
    {
        return DocumentRenderer::prepSheetStrip($businessId, $sheet, $width ?? PdfController::receiptWidth($businessId));
    }

    /**
     * الشريطُ ملفًّا — ٥٨ أو ٨٠ مم من إعداد النشاط، بطول محتواه.
     *
     * والطويلُ صفحاتٌ يصلها التطبيقُ شريطًا واحدًا بقصّةٍ واحدة (`Pdf::strip`،
     * `mobile/PROTOCOL.md` §5) — كإيصال الألف صنف.
     */
    public static function pdf(int $businessId, array $sheet, string $name, ?int $subjectId = null, ?string $subjectType = null)
    {
        $width = PdfController::receiptWidth($businessId);

        Activity::log('report', 'طبع ورقة تجهيز داخلية: '.$sheet['number'], array_filter([
            'subject_id' => $subjectId, 'subject_type' => $subjectType, 'business_id' => $businessId,
        ]));

        return Pdf::strip(self::html($businessId, $sheet, $width), $name, $width);
    }

    /* ═══════════════════════ أجزاء ═══════════════════════ */

    private static function fulfilment(?string $type): ?string
    {
        return match ((string) $type) {
            FlowerOrder::DELIVERY => __('توصيل'),
            FlowerOrder::PICKUP => __('استلام من المحل'),
            default => null,
        };
    }

    /**
     * خياراتُ الطلب المخصَّص — كلُّها، والداخليُّ منها معلَّمًا.
     *
     * ورقةُ الطاولة لا ورقةُ الزبون: «ملاحظة المنسّق» تُطبع هنا لأنّها كُتبت
     * لمن يجهّز. وتسمياتُها بلغة الورقة من لقطة البيع (`CustomArrangement::view`).
     *
     * @return list<array{label: string, value: string, internal: bool}>
     */
    private static function options(?array $details): array
    {
        return array_values(array_filter(array_map(fn ($f) => [
            'label' => (string) $f['label'],
            'value' => implode(' + ', $f['values']),
            'internal' => (bool) $f['internal'],
        ], CustomArrangement::view($details)['fields']), fn ($f) => trim($f['value']) !== ''));
    }

    /**
     * @param  callable(string): ?array  $mark
     * @return list<array{key: string, label: string, checked: ?array}>
     */
    private static function tasks(callable $mark): array
    {
        return [
            ['key' => PrepChecklist::TASK_PACKAGING, 'label' => __('التغليف'), 'checked' => $mark(PrepChecklist::TASK_PACKAGING)],
            ['key' => PrepChecklist::TASK_INSTRUCTIONS, 'label' => __('مراجعة التعليمات والبطاقة'), 'checked' => $mark(PrepChecklist::TASK_INSTRUCTIONS)],
        ];
    }

    /** الملاحظاتُ الثلاث بأسمائها — كما كتبها أصحابُها، وما فرغ يغيب */
    private static function notes(?string $order, ?string $delivery, ?string $internal): array
    {
        return array_values(array_filter([
            ['label' => __('ملاحظات الطلب'), 'text' => trim((string) $order)],
            ['label' => __('تعليمات التوصيل'), 'text' => trim((string) $delivery)],
            ['label' => __('ملاحظات داخلية'), 'text' => trim((string) $internal)],
        ], fn ($n) => $n['text'] !== ''));
    }

    /**
     * الهديّة — واسمُ المرسل ولو أُخفي، معلَّمًا بأنّه مخفيّ.
     *
     * من يكتب البطاقةَ يعرف ممّن هي ليكتبها، والإخفاءُ عن المستلِم لا عنه
     * (`FlowerOrder::cardForRecipient`). والتنبيهُ مطبوعٌ بجانب الاسم: لا يُكتب
     * على البطاقة ولا على سند التسليم.
     */
    private static function gift(bool $isGift, ?string $occasion, ?string $message, ?string $sender, bool $hidden): ?array
    {
        if (! $isGift && blank($message)) {
            return null;
        }

        return [
            'occasion' => filled($occasion) ? (string) $occasion : null,
            'message' => filled($message) ? (string) $message : null,
            'sender' => filled($sender) ? (string) $sender : null,
            'hidden' => $hidden,
        ];
    }
}
