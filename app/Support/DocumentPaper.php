<?php

namespace App\Support;

use App\Models\Business;
use App\Models\CustomerInvoice;
use App\Models\GoodsReceiptNote;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Support\Demo;

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
    /** مبلغٌ منسَّق — أو null فلا يُطبع عمودٌ فارغ */
    private static function money(mixed $value): string
    {
        return number_format((float) $value, 3).' '.__('ر.ع');
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
        $pay = self::payment($order);

        $vatBase = (float) $order->subtotal - (float) $order->discount;
        $vatRate = $vatBase > 0 ? round((float) $order->tax / $vatBase * 100, 2) : 0.0;

        $totals = [['label' => __('المجموع الفرعي'), 'value' => self::money($order->subtotal)]];

        if ((float) $order->discount > 0) {
            $totals[] = ['label' => __('الخصم'), 'value' => '− '.self::money($order->discount)];
        }

        /*
         * والنسبةُ مطبوعةٌ مع القيمة، ومقروءةٌ من الفعل لا من الإعلان.
         *
         * فاتورةٌ تقول «الضريبة ١٫٢٥٠» ولا تقول على أيّ نسبةٍ حُسبت لا
         * تُراجَع. ونسبةٌ معلنةٌ تخالف المحتسبة فاتورةٌ تقول ما لا تفعل.
         */
        if ((float) $order->tax > 0) {
            $totals[] = [
                'label' => __('ضريبة القيمة المضافة'),
                'hint' => $vatRate > 0 ? '('.rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.').'%)' : null,
                'value' => self::money($order->tax),
            ];
        }

        if ((float) $order->delivery_fee > 0) {
            $totals[] = ['label' => __('رسوم التوصيل'), 'value' => self::money($order->delivery_fee)];
        }

        $totals[] = ['label' => __('الإجمالي'), 'value' => self::money($order->total), 'grand' => true];

        /* ولا سطرَ سدادٍ على بيعةٍ دُفعت كاملةً عند الصندوق: صفرٌ لا يُطبع */
        if ($pay['partial']) {
            $totals[] = ['label' => __('المسدَّد'), 'value' => self::money($pay['paid'])];
            $totals[] = ['label' => __('الباقي'), 'value' => self::money($pay['outstanding']), 'due' => true];
        }

        return [
            'title' => $extra['title'] ?? __('فاتورة'),
            'number' => $order->number,
            'date' => optional($order->ordered_at)->format('Y-m-d H:i'),
            'branch' => $order->branch,
            'employee' => $order->employee_name,
            'meta' => array_values(array_filter([
                ['label' => __('وسيلة الدفع'), 'value' => __((string) ($order->payment_method ?: 'نقدي'))],
                ['label' => __('شروط الدفع'), 'value' => $pay['terms']],
                ['label' => __('تاريخ الاستحقاق'), 'value' => $pay['due_at']],
            ], fn (array $r) => filled($r['value']))),
            'parties' => [[
                'cap' => __('فاتورة إلى'),
                'lines' => array_values(array_filter([
                    Demo::ln($order->customer_name, $order->customer_name_en) ?: __('عميل نقدي'),
                    filled($extra['customerTax'] ?? null)
                        ? __('الرقم الضريبي').': '.$extra['customerTax']
                        : null,
                    $order->recipient_phone ?: null,
                ])),
            ]],
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->name,
                'note' => $i->note,
                'qty' => self::qty($i->quantity),
                'unit' => self::money($i->price),
                'total' => self::money($i->total ?: $i->price * $i->quantity),
            ])->all(),
            'totals' => $totals,
            'notes' => (string) ($order->notes ?: ''),
        ];
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
                    'cap' => __('المستلِم'),
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
                'unit' => self::money($i->price),
                'total' => self::money($i->total ?: $i->price * $i->quantity),
            ])->all(),
            'totals' => [
                ['label' => __('الإجمالي'), 'value' => self::money($order->total), 'grand' => true],
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
        $meta = array_values(array_filter([
            [
                'label' => __('تاريخ الاستلام المتوقع'),
                'value' => optional($po->expected_delivery_at)->format('Y-m-d'),
            ],
            [
                'label' => __('شروط الدفع'),
                'value' => self::terms($po->payment_terms_days),
            ],
            [
                'label' => __('طريقة الدفع'),
                'value' => PurchaseOrders::methodLabel($po->payment_method),
            ],
            [
                'label' => __('مرجع المورّد'),
                'value' => (string) ($po->supplier_reference ?? ''),
            ],
        ], fn (array $r) => filled($r['value'])));

        /*
         * وتفصيلُ المبلغ — ما وقع منه فقط.
         *
         * `items_subtotal` قيمةُ الأصناف قبل الخصم والشحن والضريبة، وهي ما
         * يُطابقه المورّدُ بجدول أسعاره. ومجموعٌ وحده لا يُطابَق بشيء.
         */
        $totals = [['label' => __('المجموع الفرعي'), 'value' => self::money($po->items_subtotal)]];

        if ((float) $po->supplier_discount > 0) {
            $totals[] = ['label' => __('الخصم'), 'value' => self::money($po->supplier_discount)];
        }
        if ((float) $po->shipping_cost > 0) {
            $totals[] = ['label' => __('الشحن'), 'value' => self::money($po->shipping_cost)];
        }
        if ((float) $po->tax > 0) {
            $totals[] = ['label' => __('الضريبة'), 'value' => self::money($po->tax)];
        }

        $totals[] = ['label' => __('الإجمالي'), 'value' => self::money($po->total), 'grand' => true];

        return [
            'title' => __('أمر شراء'),
            /* ورقمٌ لم يُقطع بعدُ يُقال «مسودّة» — لا سطرٌ ينتهي عند فراغ */
            'number' => $po->number ?: __('مسودة'),
            'date' => optional($po->ordered_at)->format('Y-m-d'),
            'meta' => $meta,
            'branch' => null,
            'employee' => null,
            'parties' => [
                [
                    'cap' => __('المورّد'),
                    'lines' => array_values(array_filter([
                        $po->supplier_name ?: optional($po->supplier)->name,
                        optional($po->supplier)->phone,
                        optional($po->supplier)->email,
                    ])),
                ],
            ],
            'items' => $po->items->map(fn ($i) => [
                'name' => $i->name,
                'qty' => self::qty($i->quantity),
                'unit' => self::money($i->cost),
                'total' => self::money($i->cost * $i->quantity),
            ])->all(),
            'totals' => $totals,
            'notes' => (string) ($po->notes ?? ''),
        ];
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
        return [
            'title' => __('سند استلام بضاعة'),
            'number' => $grn->number,
            'date' => optional($grn->received_at)->format('Y-m-d'),
            'branch' => optional($grn->branch)->name,
            'employee' => $grn->receiver,
            'parties' => [
                [
                    'cap' => __('المورّد'),
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
                'unit' => self::money($i->cost),
                'total' => self::money($i->cost * $i->quantity),
            ])->all(),
            'totals' => [
                [
                    'label' => __('الإجمالي'),
                    'value' => self::money($grn->items->sum(fn ($i) => $i->cost * $i->quantity)),
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
    public static function sample(int $businessId, string $type): array
    {
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
                'unit' => self::money($price),
                'total' => self::money($price * $qty),
            ];
        }

        $titles = [
            'delivery' => 'سند تسليم',
            'purchase' => 'أمر شراء',
            'grn' => 'سند استلام بضاعة',
        ];

        $parties = match ($type) {
            'purchase', 'grn' => [['cap' => __('المورّد'), 'lines' => [__('مورّد الورود'), '91234567']]],
            default => [['cap' => __('المستلِم'), 'lines' => [__('زبون تجريبي'), '91234567', __('مسقط — الخوير')]]],
        };

        return [
            'title' => __($titles[$type] ?? 'مستند'),
            'number' => strtoupper(substr($type, 0, 2)).'-000123',
            'date' => now()->format('Y-m-d H:i'),
            'branch' => __('الفرع الرئيسي'),
            'employee' => __('موظف المبيعات'),
            'parties' => $parties,
            'items' => $items,
            'totals' => $total === null ? [] : [
                ['label' => __('الإجمالي'), 'value' => self::money($total), 'grand' => true],
            ],
            'notes' => __('ملاحظة تجريبية تظهر هنا إن كانت على المستند.'),
        ];
    }

    /** بياناتُ المتجر التي تُطبع في الترويسة */
    public static function business(int $businessId): ?Business
    {
        return Business::find($businessId);
    }
}
