<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPayment;
use App\Models\GoodsReceiptNote;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Support\Demo;
use App\Support\FlowerOrder;
use App\Support\Document\Snapshot;
use Illuminate\Database\Eloquent\Model;

/**
 * الورقة كما تُرسم — بيانُ المستند مفصولًا عن رسمه.
 *
 * والقالبُ الواحد يرسم الأنواع كلَّها: أمرُ شراءٍ وسندُ استلامٍ وسندُ نقلٍ
 * وسندُ تسليمٍ أوراقٌ واحدةُ الهيكل — ترويسةٌ فيها هويّة المتجر، وبطاقتا
 * طرفين، وجدولُ أصناف، وتوقيع. وأربعةُ ملفّاتِ رسمٍ لأربعتها تفترق عند أوّل
 * تعديل: يُصلَح سطرٌ في واحدةٍ ويبقى معطوبًا في ثلاث.
 *
 * والأرقامُ تُنسَّق هنا لا في القالب: صيغةُ المال قرارٌ واحد، وتكرارُها في
 * الرسم يجعل ورقةً تكتب ثلاث خانات وأخرى اثنتين للمبلغ نفسه.
 */
class DocumentPaper
{
    /**
     * عملةُ المستند — من متجرِه هو، لا من متجر من يقرأ.
     *
     * ورقةٌ تُرسم حيث لا جلسة: رابطٌ عامٌّ يفتحه زبونٌ لا حساب له، وطابورٌ
     * يعالج متجرين بالتتابع. فـ`Demo::baseCurrency` — وهي تقرأ متجر الداخل
     * — تُخرج فاتورةَ متجرٍ بعملة متجرٍ آخر.
     *
     * @return array<string, mixed>
     */
    private static function currency(mixed $businessId, ?Model $document = null): array
    {
        /*
         * وعملةُ الورقة من لقطتها إن كانت مختومة — لا من المتجر اليوم.
         *
         * تاجرٌ بدّل عملتَه من الريال إلى الدرهم كانت فواتيرُه القديمة
         * تُعاد طباعتُها بالدرهم: الرقمُ في القاعدة ١٢٫٥٠٠ ولم يتغيّر،
         * لكنّه أُعيد وسمُه بعملةٍ أخرى وفقد منزلة — فتقول الورقةُ إنّ
         * الزبون دفع اثني عشر درهمًا وهو دفع ريالًا ونصفًا.
         * انظر `Document\Snapshot`.
         */
        $snap = Snapshot::currency(Snapshot::of($document, (int) $businessId));

        return $snap !== [] ? $snap : Money::of((int) $businessId);
    }

    /**
     * مبلغٌ منسَّق بعملة صاحبه.
     *
     * وكان السطرُ هنا `number_format($value, 3).' '.__('ر.ع')` — ثلاثُ
     * منازلَ وريالٌ عمانيٌّ **مثبَّتان**. فتاجرٌ في دبي عملتُه الدرهم كانت
     * لوحتُه تكتب «5.25 د.إ» وفاتورتُه «5.250 ر.ع»: منزلةٌ زائدة وعملةُ
     * بلدٍ آخر، على الورقة التي يرسلها باسمه.
     *
     * @param  array<string, mixed>  $cur  وصفُ العملة من `currency()`
     */
    private static function money(mixed $value, array $cur): string
    {
        return Money::format((float) $value, $cur);
    }

    /** كميّةٌ بلا كسورٍ زائدة: «3» لا «3.000» */
    private static function qty(mixed $value): string
    {
        $n = (float) $value;

        return $n == (int) $n ? (string) (int) $n : rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }

    /**
     * فاتورةُ البيع ورقةً — أكملُ ما يُطبع في النظام.
     *
     * ═══ وحقولُ السداد تُقرأ من الدفتر لا من عمودٍ في `orders` ═══
     *
     * «المسدَّد» و«الباقي» و«تاريخ الاستحقاق» و«شروط الدفع» ليست أعمدةً في
     * `orders`، ولا يجوز أن تكون:
     *
     *  • البيعُ الآجل في نقطة البيع **يُصدر فاتورةَ عميلٍ فعلًا**
     *    (`CustomerInvoices::fromOrder`)، وهي التي تحمل الاستحقاقَ والشروط.
     *  • و«المسدَّد» ليس عمودًا هناك أيضًا: `CustomerInvoice::paidTotal()`
     *    يجمعه من `customer_payment_allocations` — أي من الدفتر نفسِه.
     *
     * فعمودٌ ثانٍ في `orders` يحمل ما دُفع يعني مصدرين للرقم نفسه: كلُّ
     * تحصيلٍ وكلُّ إلغاءٍ وكلُّ إشعارٍ دائن يجب أن يكتب في الاثنين، ونسيانُ
     * أحدها مرّةً واحدة يُخرج ورقةً تقول «الباقي صفر» ودفترًا يقول غير ذلك.
     * وهو الصنفُ الذي لا يُكتشف إلّا عند مراجعةٍ خارجيّة.
     *
     * والبيعُ النقديّ لا استحقاقَ له أصلًا: دُفع عند الصندوق، فلا تُطبع له
     * سطورُ سدادٍ فارغة — انظر `payment()`.
     *
     * @return array<string, mixed>
     */
    public static function forSale(Order $order, array $extra = []): array
    {
        $cur = self::currency($order->business_id, $order);
        $pay = self::payment($order);

        $vatBase = (float) $order->subtotal - (float) $order->discount;
        $vatRate = $vatBase > 0 ? round((float) $order->tax / $vatBase * 100, 2) : 0.0;

        $totals = [['label' => 'المجموع الفرعي', 'value' => self::money($order->subtotal, $cur)]];

        if ((float) $order->discount > 0) {
            $totals[] = ['label' => 'الخصم', 'value' => '− '.self::money($order->discount, $cur)];
        }

        /*
         * والنسبةُ مطبوعةٌ مع القيمة، ومقروءةٌ من الفعل لا من الإعلان.
         *
         * فاتورةٌ تقول «الضريبة ١٫٢٥٠» ولا تقول على أيّ نسبةٍ حُسبت لا
         * تُراجَع. ونسبةٌ معلنةٌ تخالف المحتسبة فاتورةٌ تقول ما لا تفعل.
         */
        if ((float) $order->tax > 0) {
            $totals[] = [
                'label' => 'ضريبة القيمة المضافة',
                'hint' => $vatRate > 0 ? '('.rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.').'%)' : null,
                'value' => self::money($order->tax, $cur),
            ];
        }

        if ((float) $order->delivery_fee > 0) {
            $totals[] = ['label' => 'رسوم التوصيل', 'value' => self::money($order->delivery_fee, $cur)];
        }

        $totals[] = ['label' => 'الإجمالي', 'value' => self::money($order->total, $cur), 'grand' => true];

        /* ولا سطرَ سدادٍ على بيعةٍ دُفعت كاملةً عند الصندوق: صفرٌ لا يُطبع */
        if ($pay['partial']) {
            $totals[] = ['label' => 'المسدَّد', 'value' => self::money($pay['paid'], $cur)];
            $totals[] = ['label' => 'الباقي', 'value' => self::money($pay['outstanding'], $cur), 'due' => true];
        }

        return [
            'title' => $extra['title'] ?? __('فاتورة'),
            'number' => $order->number,
            /*
             * والحالةُ تُختم على الورقة — و«مكتمل» وحدها لا تُختم.
             *
             * فاتورةٌ ملغاةٌ تخرج من الدرج بعد شهرٍ فلا شيءَ عليها يقول
             * إنّها أُلغيت. و«مكتمل» هي الحالُ الطبيعيّ لكلّ فاتورةٍ في
             * الدرج: ختمُها على كلّ ورقةٍ ضجيجٌ لا خبر.
             */
            'status' => in_array((string) $order->status, ['مكتمل', ''], true) ? '' : (string) $order->status,
            'date' => optional($order->ordered_at)->format('Y-m-d H:i'),
            'branch' => $order->branch,
            'employee' => $order->employee_name,
            'meta' => array_values(array_filter([
                ['label' => 'وسيلة الدفع', 'value' => __((string) ($order->payment_method ?: 'نقدي'))],
                ['label' => 'شروط الدفع', 'value' => $pay['terms']],
                ['label' => 'تاريخ الاستحقاق', 'value' => $pay['due_at']],
                /*
                 * ═══ وأساسيّاتُ الطلب على ورقته ═══
                 *
                 * الفاتورةُ كانت تحمل الرقمَ والتاريخَ والأصنافَ والمجموع —
                 * وتسكت عن **ما طُلب**: أتوصيلٌ هو أم استلام، ومتى، وإلى من.
                 * فمن يحملها لا يعرف من قراءتها ما اتُّفق عليه، ويعود يسأل
                 * صاحبَ المحلّ بالهاتف عمّا كان يجب أن يكون مكتوبًا.
                 *
                 * وكلُّها تُرشَّح: بيعةُ صندوقٍ بلا موعدٍ ولا مستلِم لا تطبع
                 * سطورًا فارغة — ورقةٌ فيها حقولٌ خاوية تُقرأ نموذجًا لم يُملأ.
                 */
                ['label' => 'نوع التسليم', 'value' => match ((string) $order->fulfillment_type) {
                    FlowerOrder::DELIVERY => __('توصيل'),
                    FlowerOrder::PICKUP => __('استلام من المحل'),
                    default => '',
                }],
                ['label' => 'موعد التسليم', 'value' => optional($order->scheduled_for)->format('Y-m-d H:i') ?: ''],
                ['label' => 'المناسبة', 'value' => (string) ($order->occasion_type ?: '')],
            ], fn (array $r) => filled($r['value']))),
            'parties' => array_values(array_filter([
                [
                    'cap' => 'العميل',
                    'lines' => array_values(array_filter([
                        Demo::ln($order->customer_name, $order->customer_name_en) ?: __('عميل نقدي'),
                        /*
                         * ═══ ورقمُه هو — لا رقمُ من يستلم ═══
                         *
                         * كان هذا السطرُ `recipient_phone`: **هاتفَ المستلِم**
                         * تحت عنوان «العميل». وفي طلب هديّةٍ هما شخصان دائمًا
                         * — فتخرج الورقةُ باسم مريم ورقمِ من أُرسلت إليه.
                         *
                         * ومن يتّصل بالرقم ليسأل عن فاتورةٍ يقع على المهدى
                         * إليه، فيكشف له هديّةً لم تصله بعد.
                         *
                         * فصار رقمُ العميل من بطاقته، والمستلِمُ في طرفه أدناه.
                         */
                        self::customerPhone($order),
                        filled($extra['customerTax'] ?? null)
                            ? __('الرقم الضريبي').': '.$extra['customerTax']
                            : null,
                    ])),
                ],
                [
                    'cap' => 'المستلِم',
                    'lines' => array_values(array_filter([
                        $order->recipient_name ?: null,
                        $order->recipient_phone ?: null,
                        $order->delivery_address ?: null,
                    ])),
                ],
            ], fn (array $p) => count(array_filter($p['lines'])) > 0)),
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->name,
                'note' => $i->note,
                /*
                 * وخياراتُ الطلب المخصَّص تحت اسمه.
                 *
                 * بندٌ مخصَّص اسمُه اسمُ القالب — «باقة» — وسعرُه. وما سُئل
                 * عنه الزبون ودُوِّن (اللون، المقاس، اسم المهدى إليه) كان
                 * يبقى في القاعدة ولا يبلغ ورقتَه.
                 *
                 * والداخليُّ منها لا يُطبع — انظر `CustomArrangement::paperLines`،
                 * وهي القارئُ نفسُه الذي يقرأ للشريط الحراريّ.
                 */
                'custom' => $i->isCustom() ? CustomArrangement::paperLines($i->custom_details) : [],
                'qty' => self::qty($i->quantity),
                'unit' => self::money($i->price, $cur),
                'total' => self::money($i->total ?: $i->price * $i->quantity, $cur),
            ])->all(),
            'totals' => $totals,
            'notes' => (string) ($order->notes ?: ''),
        ];
    }

    /**
     * هاتفُ العميل من بطاقته — لا من سطرٍ على الطلب.
     *
     * فالطلبُ لا يحمل عمودَ هاتفٍ للمشتري أصلًا: ما فيه `recipient_phone`
     * وهو لمن يستلم. والرقمُ يُقرأ من `customers` عبر `customer_id`.
     *
     * وبيعةُ صندوقٍ بلا عميلٍ مسجَّل تردّ `null` فلا يُطبع سطر — و«عميل
     * نقدي» لا هاتفَ له يُكتب.
     *
     * وطلبٌ لم يُحفظ (معاينةُ محرّر القوالب) لا يُستعلَم عنه: `customer_id`
     * فيه فارغٌ فلا يقع استعلام.
     */
    private static function customerPhone(Order $order): ?string
    {
        if (! $order->customer_id) {
            return null;
        }

        return optional(Customer::find($order->customer_id))->phone ?: null;
    }

    /**
     * حالُ سداد بيعةٍ — من فاتورتها إن صدرت، وإلّا فهي مدفوعة.
     *
     * والفاتورةُ تُقرأ عبر العلاقة القائمة (`Order::customerInvoices`) لا
     * باستعلامٍ يُكتب هنا: هي التي تعرف أن الملغاةَ لا تُحتسب.
     *
     * @return array{paid: float, outstanding: float, partial: bool, terms: string, due_at: string}
     */
    private static function payment(Order $order): array
    {
        /*
         * وطلبٌ لم يُحفظ لا يُستعلَم عنه.
         *
         * معاينةُ المحرّر ترسم طلبًا مُخترعًا بلا مفتاح، واستعلامُ علاقته
         * يخرج بـ`order_id is null` — فيردّ صفًّا عشوائيًّا أو يسقط. وهو
         * لا يملك فاتورةً بحال: نقديٌّ حتى يُثبت غيرَ ذلك.
         */
        $invoice = ! $order->exists ? null : ($order->relationLoaded('customerInvoices')
            ? $order->customerInvoices->firstWhere('status', '!=', CustomerInvoice::CANCELLED)
            : $order->customerInvoices()->where('status', '!=', CustomerInvoice::CANCELLED)->first());

        if ($invoice === null) {
            /* بيعةٌ نقديّة: دُفعت عند الصندوق، ولا سطرَ سدادٍ يُطبع لها */
            return [
                'paid' => (float) $order->total,
                'outstanding' => 0.0,
                'partial' => false,
                'terms' => '',
                'due_at' => '',
            ];
        }

        $paid = $invoice->paidTotal();
        $outstanding = $invoice->outstanding();

        return [
            'paid' => $paid,
            'outstanding' => $outstanding,
            /* والسطرُ يُطبع حين يبقى شيء — أو حين سُدِّد بعضُه فيُعرف كم */
            'partial' => $outstanding > 0 || ($paid > 0 && $paid < (float) $invoice->total),
            'terms' => self::terms($invoice->payment_terms_days),
            'due_at' => optional($invoice->due_at)->format('Y-m-d') ?: '',
        ];
    }

    /**
     * سندُ تسليمٍ لطلب — من الطلب لا من جدول `delivery_notes`.
     *
     * ذاك جدولٌ لا يكتب فيه شيءٌ في النظام كلّه: نموذجٌ وهجرةٌ بلا متحكّم ولا
     * شاشة. وورقةٌ تُرسم منه تخرج فارغةً أبدًا.
     */
    public static function forDelivery(Order $order): array
    {
        $cur = self::currency($order->business_id, $order);

        return [
            'title' => __('سند تسليم'),
            'number' => $order->number,
            'date' => optional($order->ordered_at)->format('Y-m-d H:i'),
            'branch' => $order->branch,
            'employee' => $order->employee_name,
            /*
             * والمستلِم قبل المشتري: الورقة تمشي مع الشحنة، والسائق يقرأ إلى
             * من يُسلّم لا من دفع. وطلبُ هديّةٍ يفترق فيه الاثنان دائمًا.
             */
            'parties' => [
                [
                    'cap' => 'المستلِم',
                    'lines' => array_values(array_filter([
                        $order->recipient_name ?: ($order->customer_name ?: __('عميل نقدي')),
                        $order->recipient_phone ?: null,
                        $order->delivery_address ?: null,
                        $order->delivery_notes ?: null,
                    ])),
                ],
            ],
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->name,
                'qty' => self::qty($i->quantity),
                'unit' => self::money($i->price, $cur),
                'total' => self::money($i->total ?: $i->price * $i->quantity, $cur),
            ])->all(),
            'totals' => [
                ['label' => 'الإجمالي', 'value' => self::money($order->total, $cur), 'grand' => true],
            ],
            'notes' => (string) ($order->delivery_notes ?: $order->notes ?: ''),
        ];
    }

    /**
     * أمرُ شراءٍ ورقةً — وهي ما يقرؤه المورّد ليجهّز الشحنة.
     *
     * ═══ وما زِيد عليها ═══
     *
     * كانت تحمل الرقمَ والتاريخَ واسمَ المورّد وسطرَ إجماليٍّ واحد. وثلاثةٌ
     * يسألها المورّدُ هاتفيًّا حين لا يجدها: **متى يريدها**، و**كيف يُسدَّد**،
     * و**ممّ تكوّن الإجمالي**. فمن يجهّز الشحنة لا يعرف موعدَ الوصول
     * المطلوب، ومن يُصدر السند لا يعرف مدّة السداد المتّفق عليها، ومن يراجع
     * المبلغ لا يرى الخصمَ ولا الشحنَ ولا الضريبة — يرى مجموعًا لا يُطابقه
     * بجدوله.
     *
     * ═══ ولا شيءَ يُطبع فارغًا ═══
     *
     * السطورُ تُرشَّح: أمرٌ بلا خصمٍ لا يطبع «الخصم: 0.000»، وبلا شحنٍ كذلك.
     * وورقةٌ فيها أصفارٌ تُقرأ نموذجًا لم يُملأ.
     *
     * ═══ ولا ملاحظاتٍ داخليّة ═══
     *
     * `internal_notes` عمودٌ آخر لا يبلغ ورقةَ المورّد بحال — ولا سطرَ هنا
     * يقرؤه. وما لا يُرسَل لا يُطبع.
     */
    public static function forPurchase(PurchaseOrder $po): array
    {
        $cur = self::currency($po->business_id, $po);

        $meta = array_values(array_filter([
            [
                'label' => 'تاريخ الاستلام المتوقع',
                'value' => optional($po->expected_delivery_at)->format('Y-m-d'),
            ],
            [
                'label' => 'شروط الدفع',
                'value' => self::terms($po->payment_terms_days),
            ],
            [
                'label' => 'طريقة الدفع',
                'value' => PurchaseOrders::methodLabel($po->payment_method),
            ],
            [
                'label' => 'مرجع المورّد',
                'value' => (string) ($po->supplier_reference ?? ''),
            ],
            /*
             * وحالُ الأمر حقلٌ مسمّى لا كلمةٌ عائمة.
             *
             * المورّدُ الذي يفتح الورقة يحتاج أن يعرف: أهي مسوّدةٌ أُرسلت
             * سهوًا، أم أمرٌ قائم، أم أمرٌ استُلمت بضاعتُه؟ وكلمةٌ ملوّنةٌ
             * تحت المبلغ بلا تسمية تُقرأ زينة. فتُكتب باسمها في عمود
             * الحقول حيث يبحث عنها — انظر المواصفة §أمر الشراء.
             */
            [
                'label' => 'الحالة',
                'value' => (string) $po->status,
            ],
        ], fn (array $r) => filled($r['value'])));

        /*
         * وتفصيلُ المبلغ — ما وقع منه فقط.
         *
         * `items_subtotal` قيمةُ الأصناف قبل الخصم والشحن والضريبة، وهي ما
         * يُطابقه المورّدُ بجدول أسعاره. ومجموعٌ وحده لا يُطابَق بشيء.
         */
        $totals = [['label' => 'المجموع الفرعي', 'value' => self::money($po->items_subtotal, $cur)]];

        if ((float) $po->supplier_discount > 0) {
            $totals[] = ['label' => 'الخصم', 'value' => self::money($po->supplier_discount, $cur)];
        }
        if ((float) $po->shipping_cost > 0) {
            $totals[] = ['label' => 'الشحن', 'value' => self::money($po->shipping_cost, $cur)];
        }
        if ((float) $po->tax > 0) {
            $totals[] = ['label' => 'الضريبة', 'value' => self::money($po->tax, $cur)];
        }

        $totals[] = ['label' => 'الإجمالي', 'value' => self::money($po->total, $cur), 'grand' => true];

        return [
            'title' => __('أمر شراء'),
            /* ورقمٌ لم يُقطع بعدُ يُقال «مسودّة» — لا سطرٌ ينتهي عند فراغ */
            'number' => $po->number ?: __('مسودة'),
            /*
             * والحالةُ من حقل الجدول نفسِه لا من اشتقاقٍ في القالب.
             *
             * ورقةُ أمرٍ ملغيٍّ تُطبع وتُرسل إلى المورّد فيجهّز شحنةً لا
             * أحدَ ينتظرها. فتُختم الورقةُ بحالتها — انظر `partials/stamp`.
             * والقيمةُ نصُّ `status` كما هو في النموذج: «مسودة» أو «مُرسل»
             * أو «مستلم» — لا قائمةٌ ثانيةٌ هنا تفترق عنها.
             */
            'status' => (string) $po->status,
            'date' => optional($po->ordered_at)->format('Y-m-d'),
            'meta' => $meta,
            'branch' => null,
            'employee' => null,
            'parties' => [
                [
                    'cap' => 'المورّد',
                    'lines' => array_values(array_filter([
                        $po->supplier_name ?: optional($po->supplier)->name,
                        optional($po->supplier)->phone,
                        optional($po->supplier)->email,
                    ])),
                ],
            ],
            /*
             * ووحدةُ الشراء سطرُ وصفٍ تحت اسم الصنف.
             *
             * «باقة همس الربيع» وحدَها تقول ما يُطلب ولا تقول **كيف**
             * يُشحن. و`purchase_unit` و`units_per_purchase_unit` في صفّ
             * البند أصلًا — يكتبهما التاجرُ عند إنشاء الأمر ولا يراهما
             * المورّدُ على الورقة التي تصله. فيُطبعان حيث يُقرآن:
             * «كرتون × 12» تحت الاسم، بحبرٍ أخفّ.
             */
            'items' => $po->items->map(fn ($i) => [
                'name' => $i->name,
                'note' => self::packing($i->purchase_unit, $i->units_per_purchase_unit),
                'qty' => self::qty($i->quantity),
                'unit' => self::money($i->cost, $cur),
                'total' => self::money($i->cost * $i->quantity, $cur),
            ])->all(),
            'totals' => $totals,
            'notes' => (string) ($po->notes ?? ''),
        ];
    }

    /**
     * وحدةُ الشراء نصًّا — «كرتون» أو «كرتون × ١٢».
     *
     * ولا تُطبع «× ١» : معاملٌ واحدٌ يعني أنّ الوحدة هي الحبّة، والضربُ
     * فيه سطرٌ يشغل مكانًا ولا يقول شيئًا.
     */
    private static function packing(?string $unit, mixed $per): string
    {
        $unit = trim((string) $unit);
        $per = (float) $per;

        if ($unit === '') {
            return '';
        }

        return $per > 1 ? $unit.' × '.self::qty($per) : $unit;
    }

    /**
     * شروطُ السداد نصًّا — «مستحق فورًا» أو «صافي ٣٠ يومًا».
     *
     * و«فورًا» تُقال صراحةً: صفرٌ مطبوعًا «0 يومًا» يُقرأ حقلًا لم يُملأ.
     * وهي الصيغةُ نفسُها في فاتورة العميل — لا صيغتان لشيءٍ واحد.
     */
    private static function terms(mixed $days): string
    {
        if ($days === null || $days === '') {
            return '';
        }

        return (int) $days === 0
            ? __('مستحق فورًا')
            : __('صافي :n يومًا', ['n' => (int) $days]);
    }

    public static function forGrn(GoodsReceiptNote $grn): array
    {
        $cur = self::currency($grn->business_id, $grn);

        return [
            'title' => __('سند استلام بضاعة'),
            'number' => $grn->number,
            /* وحالةُ السند تُختم عليه: «بانتظار الاعتماد» ورقةٌ لا تُصرف بها بضاعة */
            'status' => (string) $grn->status,
            'date' => optional($grn->received_at)->format('Y-m-d'),
            'branch' => optional($grn->branch)->name,
            'employee' => $grn->receiver,
            'parties' => [
                [
                    'cap' => 'المورّد',
                    'lines' => array_values(array_filter([
                        optional($grn->supplier)->name,
                        optional($grn->purchaseOrder)->number
                            ? __('أمر الشراء').': '.$grn->purchaseOrder->number
                            : null,
                    ])),
                ],
            ],
            'items' => $grn->items->map(fn ($i) => [
                'name' => $i->name,
                'qty' => self::qty($i->quantity),
                'unit' => self::money($i->cost, $cur),
                'total' => self::money($i->cost * $i->quantity, $cur),
            ])->all(),
            'totals' => [
                [
                    'label' => 'الإجمالي',
                    'value' => self::money($grn->items->sum(fn ($i) => $i->cost * $i->quantity), $cur),
                    'grand' => true,
                ],
            ],
            'notes' => (string) ($grn->notes ?? ''),
        ];
    }

    /**
     * ورقةٌ بمثالٍ من بضاعة المتجر نفسه — للمعاينة في المحرّر.
     *
     * وبأسماء أصنافه لا بـ«صنف ١» و«صنف ٢»: التاجر يحكم على الورقة بما يراه
     * فيها، وسطرٌ باسمٍ حقيقيّ يُظهر له طولَ السطر واصطفافَ العمود كما سيكون.
     */
    /**
     * فاتورةُ المورّد — سندُ التزامٍ لا ورقةُ بضاعة.
     *
     * ═══ ولمَ لا جدولَ أصنافٍ فيها ═══
     *
     * `supplier_invoices` صفٌّ بلا بنود: مبلغٌ وضريبةٌ ومرجعُ المورّد وتاريخٌ
     * واستحقاق. والبضاعةُ نفسُها في أمر الشراء وسندات الاستلام، ولكلٍّ ورقتُه.
     *
     * وجرّ بنودِ أمر الشراء إلى هنا يخرج ورقةً كاذبة: المطابقةُ قد تقول إنّ
     * الإجماليّين مختلفان — وهو سببُ وجود `SupplierInvoices::match` أصلًا —
     * فتُطبع أصنافٌ بمجموعٍ لا يساوي إجماليَّ الورقة التي هي عليها.
     *
     * فما تحمله هذه الورقة ما في الصفّ: الطرفان، والتواريخ، والمرجع، وأمرُ
     * الشراء الذي تقابله، والمبالغ.
     */
    public static function forSupplierInvoice(SupplierInvoice $invoice): array
    {
        $cur = self::currency($invoice->business_id, $invoice);
        $outstanding = $invoice->outstanding();

        $totals = [['label' => 'المجموع الفرعي', 'value' => self::money($invoice->subtotal, $cur)]];

        if ((float) $invoice->tax > 0) {
            $totals[] = ['label' => 'الضريبة', 'value' => self::money($invoice->tax, $cur)];
        }

        $totals[] = ['label' => 'الإجمالي', 'value' => self::money($invoice->total, $cur), 'grand' => true];

        if ((float) $invoice->paid > 0 || $outstanding > 0) {
            $totals[] = ['label' => 'المسدَّد', 'value' => self::money($invoice->paid, $cur)];
            /*
             * و«الباقي» يُطبع دائمًا، ولا يكون **الرقمَ الأهمّ** إلّا إن بقي.
             *
             * فاتورةٌ سُدِّدت كاملةً باقيها صفر — وصفرٌ بمقاس العنوان في صدر
             * الورقة يُقرأ خبرًا، وليس بخبر. فيعود الرقمُ الأهمّ إجماليَّها،
             * ويبقى الصفرُ في سلّم المجاميع حيث يُقرأ إقرارًا بالسداد.
             */
            $totals[] = ['label' => 'الباقي', 'value' => self::money($outstanding, $cur), 'due' => $outstanding > 0];
        }

        return [
            'title' => __('فاتورة مورّد'),
            'number' => (string) $invoice->supplier_ref,
            /* والختمُ حالُ الاعتماد: هي دورةُ حياة المستند. والسدادُ في المجاميع */
            'status' => (string) ($invoice->approval_status ?: ''),
            'date' => optional($invoice->issued_at)->format('Y-m-d'),
            'branch' => null,
            'employee' => null,
            'meta' => array_values(array_filter([
                ['label' => 'تاريخ الإصدار', 'value' => optional($invoice->issued_at)->format('Y-m-d')],
                ['label' => 'تاريخ الاستحقاق', 'value' => optional($invoice->due_at)->format('Y-m-d')],
                ['label' => 'أمر الشراء', 'value' => (string) (optional($invoice->purchaseOrder)->number ?? '')],
                ['label' => 'حالة السداد', 'value' => (string) ($invoice->status ?: '')],
            ], fn (array $r) => filled($r['value']))),
            'parties' => [[
                'cap' => 'المورّد',
                'lines' => array_values(array_filter([
                    optional($invoice->supplier)->name,
                    optional($invoice->supplier)->phone,
                    optional($invoice->supplier)->email,
                ])),
            ]],
            'items' => [],
            'totals' => $totals,
            'notes' => (string) ($invoice->notes ?? ''),
        ];
    }

    /**
     * إشعارٌ دائن — يُنقص ذمّةَ العميل ولا يُعيد كتابة الفاتورة.
     *
     * و`amount` مبلغٌ شاملٌ للضريبة و`tax_amount` نصيبُها منه — انظر
     * `CustomerInvoices::creditNote`. فالصافي فرقُهما، ولا يُعاد حسابُ شيء
     * هنا: الورقةُ تعرض ما في الصفّ.
     *
     * وبياناتُ العميل من الفاتورة الأصليّة: الإشعارُ لا يحملها، وهو معلَّقٌ
     * بها دائمًا (`customer_invoice_id` غيرُ قابلٍ للفراغ).
     */
    public static function forCreditNote(CustomerCreditNote $note): array
    {
        $invoice = $note->invoice;
        $cur = self::currency($note->business_id, $note);

        $net = round((float) $note->amount - (float) $note->tax_amount, 3);

        $totals = [['label' => 'المجموع الفرعي', 'value' => self::money($net, $cur)]];

        if ((float) $note->tax_amount > 0) {
            $totals[] = ['label' => 'ضريبة القيمة المضافة', 'value' => self::money($note->tax_amount, $cur)];
        }

        $totals[] = ['label' => 'إجمالي الإشعار', 'value' => self::money($note->amount, $cur), 'grand' => true];

        return [
            'title' => __('إشعار دائن'),
            'number' => (string) $note->number,
            'status' => '',
            'date' => optional($note->issued_at)->format('Y-m-d'),
            'branch' => null,
            'employee' => null,
            /*
             * والسببُ ليس في الشريط.
             *
             * خلايا الشريط سطرٌ واحدٌ قصير — تاريخٌ أو رقم. وسببُ الإشعار
             * جملةٌ يكتبها بشر («رُدّت ثلاثُ باقاتٍ لتلفها عند التسليم»)،
             * فتُقصّ في خليّةٍ بعرض خمسة سنتيمترات أو تُقرأ مرّتين: هنا وفي
             * لوحة الملاحظات تحتها. ومكانُها اللوحة.
             */
            'meta' => array_values(array_filter([
                ['label' => 'الفاتورة الأصلية', 'value' => (string) (optional($invoice)->number ?? '')],
                ['label' => 'تاريخ الإشعار', 'value' => optional($note->issued_at)->format('Y-m-d')],
            ], fn (array $r) => filled($r['value']))),
            'parties' => [[
                'cap' => 'العميل',
                'lines' => array_values(array_filter([
                    optional($invoice)->customer_name,
                    filled(optional($invoice)->customer_tax_number)
                        ? __('الرقم الضريبي').': '.$invoice->customer_tax_number
                        : null,
                    optional($invoice)->customer_address,
                ])),
            ]],
            'items' => [],
            'totals' => $totals,
            'notes' => (string) ($note->reason ?? ''),
        ];
    }

    /**
     * سندُ قبض — إقرارُ المتجر بأنّه استلم مالًا من عميل.
     *
     * وبنودُه **توزيعُ المبلغ على الفواتير** لا أصناف: من دفع بمئةٍ تُسدَّد
     * بها ثلاثُ فواتيرَ يريد أن يعرف أيُّها سُدِّدت وبكم. وما لم يُوزَّع
     * يُقال صراحةً — رصيدٌ عند المتجر لا مبلغٌ ضاع.
     */
    public static function forCustomerReceipt(CustomerPayment $payment): array
    {
        $cur = self::currency($payment->business_id, $payment);

        $allocated = $payment->allocatedTotal();
        $spare = $payment->unallocated();

        $totals = [
            ['label' => 'المبلغ المستلم', 'value' => self::money($payment->amount, $cur), 'grand' => true],
        ];

        if ($allocated > 0 && $spare > 0) {
            $totals[] = ['label' => 'المسدَّد من الفواتير', 'value' => self::money($allocated, $cur)];
        }

        if ($spare > 0) {
            $totals[] = ['label' => 'رصيد لم يُخصَّص بعد', 'value' => self::money($spare, $cur), 'due' => true];
        }

        return [
            'title' => __('سند قبض'),
            'number' => (string) $payment->number,
            /* والملغى يُختم ملغى: سندٌ أُلغي ويُطبع بلا خبرٍ يقول ذلك سندٌ يُصدَّق */
            'status' => $payment->cancelled_at !== null ? __('ملغى') : '',
            'date' => optional($payment->occurred_at)->format('Y-m-d'),
            'branch' => null,
            'employee' => null,
            'meta' => array_values(array_filter([
                ['label' => 'تاريخ القبض', 'value' => optional($payment->occurred_at)->format('Y-m-d')],
                ['label' => 'وسيلة الدفع', 'value' => __((string) ($payment->method ?: 'نقدي'))],
                ['label' => 'الحساب البنكي', 'value' => (string) (optional($payment->bankAccount)->name ?? '')],
                ['label' => 'المرجع', 'value' => (string) ($payment->external_reference ?? '')],
                ['label' => 'حال الشيك', 'value' => (string) ($payment->cheque_status ?? '')],
                [
                    'label' => 'استحقاق الشيك',
                    'value' => $payment->cheque_due_at ? (string) $payment->cheque_due_at : '',
                ],
            ], fn (array $r) => filled($r['value']))),
            'parties' => [[
                'cap' => 'الدافع',
                'lines' => array_values(array_filter([
                    optional($payment->customer)->name,
                    optional($payment->customer)->phone,
                    optional($payment->customer)->tax_number
                        ? __('الرقم الضريبي').': '.$payment->customer->tax_number
                        : null,
                ])),
            ]],
            'items' => $payment->allocations->map(fn ($a) => [
                'name' => __('فاتورة').' '.(optional($a->invoice)->number ?? '—'),
                'note' => optional($a->invoice)->issued_at
                    ? __('صدرت في').' '.$a->invoice->issued_at->format('Y-m-d')
                    : null,
                'qty' => '1',
                'unit' => self::money($a->amount, $cur),
                'total' => self::money($a->amount, $cur),
            ])->all(),
            'totals' => $totals,
            'notes' => (string) ($payment->notes ?? ''),
        ];
    }

    public static function sample(int $businessId, string $type): array
    {
        $cur = self::currency($businessId);
        $names = Product::where('business_id', $businessId)
            ->where('active', true)->orderBy('id')->limit(3)->pluck('name')->all();

        if ($names === []) {
            $names = [__('باقة ورد'), __('صندوق هدايا'), __('بطاقة معايدة')];
        }

        $items = [];
        $total = 0.0;

        foreach (array_values($names) as $i => $name) {
            $qty = $i + 1;
            $price = 4.5 * ($i + 1);
            $total += $price * $qty;

            $items[] = [
                'name' => $name,
                'qty' => self::qty($qty),
                'unit' => self::money($price, $cur),
                'total' => self::money($price * $qty, $cur),
            ];
        }

        $titles = [
            'delivery' => 'سند تسليم',
            'purchase' => 'أمر شراء',
            'grn' => 'سند استلام بضاعة',
            'supplier_invoice' => 'فاتورة مورّد',
            'credit_note' => 'إشعار دائن',
            'customer_receipt' => 'سند قبض',
        ];

        $parties = match ($type) {
            'purchase', 'grn', 'supplier_invoice' => [['cap' => 'المورّد', 'lines' => [__('مورّد الورود'), '91234567']]],
            'credit_note' => [['cap' => 'العميل', 'lines' => [__('زبون تجريبي'), __('مسقط — الخوير')]]],
            'customer_receipt' => [['cap' => 'الدافع', 'lines' => [__('زبون تجريبي'), '91234567']]],
            default => [['cap' => 'المستلِم', 'lines' => [__('زبون تجريبي'), '91234567', __('مسقط — الخوير')]]],
        };

        $stamps = [
            'purchase' => 'مُرسل',
            'grn' => 'معتمد',
            'supplier_invoice' => 'معتمد',
        ];

        /*
         * وورقتان بلا بنود — ومثالٌ يخترع لهما أصنافًا يُري التاجرَ ما لن يراه.
         *
         * فاتورةُ المورّد وإشعارُ الدائن صفّان بمبلغٍ لا بجدول — انظر
         * `forSupplierInvoice`. وسندُ القبض بنودُه فواتيرُ سُدِّدت لا أصناف.
         */
        if (in_array($type, ['supplier_invoice', 'credit_note'], true)) {
            $items = [];
        }

        if ($type === 'customer_receipt') {
            $items = [[
                'name' => __('فاتورة').' INV-000101',
                'qty' => '1',
                'unit' => self::money($total, $cur),
                'total' => self::money($total, $cur),
            ]];
        }

        /*
         * ═══ ومثالٌ ناقصٌ يُري التاجرَ ورقةً لن تخرج ═══
         *
         * كان المثالُ بندًا أو ثلاثةً وسطرَ إجماليٍّ واحدًا، بلا حقولِ
         * مستندٍ البتّة: لا تاريخَ استحقاقٍ ولا مرجعَ ولا حالةَ سداد. فيرى
         * التاجرُ في «قوالب الأوراق» ورقةً نصفُها خالٍ، ويضبط عليها
         * مقاسَ خطّه وتذييلَه — ثمّ تخرج الورقةُ الحقيقيّةُ غيرَها.
         *
         * وحقولُ المثال هي حقولُ الباني الحقيقيّ لكلّ نوع بأسمائها — انظر
         * `forSale` و`forPurchase` و`forSupplierInvoice` — فما يُضبط على
         * المعاينة يقع على الورقة.
         *
         * ولا حسابَ هنا يخصّ متجرًا: أرقامُ المثال مثالٌ، وضريبتُه خمسةٌ
         * في المئة عرضًا لا احتسابًا. والحسابُ الحقيقيُّ في `Support\Vat`
         * وحدَه.
         */
        $day = fn (int $plus) => now()->addDays($plus)->format('Y-m-d');

        $meta = match ($type) {
            'purchase' => [
                ['label' => 'تاريخ الاستلام المتوقع', 'value' => $day(7)],
                ['label' => 'شروط الدفع', 'value' => self::terms(30)],
                ['label' => 'الحالة', 'value' => __('مُرسل')],
            ],
            /* ولا تاريخَ استلامٍ هنا: القالبُ يضعه من `date` — فيُطبع مرّتين */
            'grn' => [
                ['label' => 'أمر الشراء', 'value' => 'PU-000123'],
            ],
            'supplier_invoice' => [
                ['label' => 'تاريخ الإصدار', 'value' => $day(0)],
                ['label' => 'تاريخ الاستحقاق', 'value' => $day(30)],
                ['label' => 'أمر الشراء', 'value' => 'PU-000123'],
                ['label' => 'حالة السداد', 'value' => __('غير مدفوع')],
            ],
            'credit_note' => [
                ['label' => 'الفاتورة الأصلية', 'value' => 'INV-000101'],
                ['label' => 'تاريخ الإشعار', 'value' => $day(0)],
            ],
            'customer_receipt' => [
                ['label' => 'تاريخ القبض', 'value' => $day(0)],
                ['label' => 'وسيلة الدفع', 'value' => __('تحويل بنكي')],
            ],
            default => [
                ['label' => 'وسيلة الدفع', 'value' => __('نقدي')],
            ],
        };

        /* وسلّمُ المجاميع كسلّم الورقة الحقيقيّة: فرعيٌّ ثمّ ضريبةٌ ثمّ إجمالي */
        $tax = round($total * 0.05, 3);

        $totals = match (true) {
            $type === 'customer_receipt' => [
                ['label' => 'المبلغ المستلم', 'value' => self::money($total, $cur), 'grand' => true],
            ],
            $type === 'credit_note' => [
                ['label' => 'المجموع الفرعي', 'value' => self::money($total, $cur)],
                ['label' => 'ضريبة القيمة المضافة', 'hint' => '(5%)', 'value' => self::money($tax, $cur)],
                ['label' => 'إجمالي الإشعار', 'value' => self::money($total + $tax, $cur), 'grand' => true],
            ],
            $type === 'supplier_invoice' => [
                ['label' => 'المجموع الفرعي', 'value' => self::money($total, $cur)],
                ['label' => 'الضريبة', 'value' => self::money($tax, $cur)],
                ['label' => 'الإجمالي', 'value' => self::money($total + $tax, $cur), 'grand' => true],
                ['label' => 'المسدَّد', 'value' => self::money(0, $cur)],
                ['label' => 'الباقي', 'value' => self::money($total + $tax, $cur), 'due' => true],
            ],
            default => [
                ['label' => 'المجموع الفرعي', 'value' => self::money($total, $cur)],
                ['label' => 'ضريبة القيمة المضافة', 'hint' => '(5%)', 'value' => self::money($tax, $cur)],
                ['label' => 'الإجمالي', 'value' => self::money($total + $tax, $cur), 'grand' => true],
            ],
        };

        return [
            'title' => __($titles[$type] ?? 'مستند'),
            'number' => strtoupper(substr($type, 0, 2)).'-000123',
            /* وخَتمٌ في المعاينة: التاجر يضبط قالبَه على ما سيخرج فعلًا */
            'status' => __($stamps[$type] ?? ''),
            'date' => now()->format('Y-m-d H:i'),
            'branch' => __('الفرع الرئيسي'),
            'employee' => __('موظف المبيعات'),
            'meta' => $meta,
            'parties' => $parties,
            'items' => $items,
            'totals' => $totals,
            'notes' => __('ملاحظة تجريبية تظهر هنا إن كانت على المستند.'),
        ];
    }

    /** بياناتُ المتجر التي تُطبع في الترويسة */
    public static function business(int $businessId): ?Business
    {
        return Business::find($businessId);
    }
}
