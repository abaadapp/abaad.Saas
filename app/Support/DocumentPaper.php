<?php

namespace App\Support;

use App\Models\Business;
use App\Models\GoodsReceiptNote;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockTransfer;

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

    public static function forPurchase(PurchaseOrder $po): array
    {
        return [
            'title' => __('أمر شراء'),
            'number' => $po->number,
            'date' => optional($po->ordered_at)->format('Y-m-d'),
            'branch' => null,
            'employee' => null,
            'parties' => [
                [
                    'cap' => __('المورّد'),
                    'lines' => array_values(array_filter([
                        $po->supplier_name ?: optional($po->supplier)->name,
                        optional($po->supplier)->phone,
                    ])),
                ],
            ],
            'items' => $po->items->map(fn ($i) => [
                'name' => $i->name,
                'qty' => self::qty($i->quantity),
                'unit' => self::money($i->cost),
                'total' => self::money($i->cost * $i->quantity),
            ])->all(),
            'totals' => [
                ['label' => __('الإجمالي'), 'value' => self::money($po->total), 'grand' => true],
            ],
            'notes' => (string) ($po->notes ?? ''),
        ];
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

    public static function forTransfer(StockTransfer $t): array
    {
        return [
            'title' => __('سند تحويل مخزني'),
            'number' => $t->number,
            'date' => optional($t->transferred_at)->format('Y-m-d H:i'),
            /*
             * والفرعان في بطاقتين لا في سطرٍ واحد: «من مسقط إلى صلالة» تُقرأ
             * بالعجلة معكوسةً، فتعود البضاعة من حيث جاءت.
             */
            'branch' => $t->from_branch_name,
            'employee' => optional($t->creator)->name,
            'parties' => [
                ['cap' => __('من فرع'), 'lines' => [$t->from_branch_name]],
                ['cap' => __('إلى فرع'), 'lines' => [$t->to_branch_name]],
            ],
            'items' => [[
                'name' => optional($t->product)->name ?? __('صنف محذوف'),
                'qty' => self::qty($t->quantity),
                'unit' => null,
                'total' => null,
            ]],
            'totals' => [],
            'notes' => (string) ($t->notes ?? ''),
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
            'transfer' => 'سند تحويل مخزني',
        ];

        $parties = match ($type) {
            'purchase', 'grn' => [['cap' => __('المورّد'), 'lines' => [__('مورّد الورود'), '91234567']]],
            'transfer' => [
                ['cap' => __('من فرع'), 'lines' => [__('الفرع الرئيسي')]],
                ['cap' => __('إلى فرع'), 'lines' => [__('فرع صلالة')]],
            ],
            default => [['cap' => __('المستلِم'), 'lines' => [__('زبون تجريبي'), '91234567', __('مسقط — الخوير')]]],
        };

        if ($type === 'transfer') {
            $items = [array_replace($items[0], ['unit' => null, 'total' => null])];
            $total = null;
        }

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
