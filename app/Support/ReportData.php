<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\BankStatementLine;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * بياناتُ التقارير — لكلّ تقريرٍ مؤشّراتُه وصفوفُه ومنتقياتُه.
 *
 * وكان لكلٍّ من هذه بطاقةٌ في الفهرس تقود إلى **شاشة قسمه**: «تقرير الطلبات»
 * يفتح شاشة إدارة الطلبات، وفيها زرُّ تعديلٍ وزرُّ حذف. فمن دخل ليقرأ وجد
 * نفسه في موضع الكتابة — والتقرير قراءةٌ لا إدارة.
 *
 * وأثقلُ من ذلك أنّ شاشة القسم لا تُجيب سؤال التقرير: لا فترةَ تُختار، ولا
 * مؤشّراتٍ تُقرأ بنظرة، ولا مجاميعَ في أسفل عمود. جدولُ إدارةٍ لا تقرير.
 *
 * فصار لكلٍّ بياناتُه هنا: مصدرٌ واحد يقرؤه المتحكّم، ويُختبر بلا شاشة.
 */
class ReportData
{
    /**
     * سقفُ الصفوف في أيّ تقرير.
     *
     * ويُقال على الشاشة حين يُبلَغ لا يُخفى: جدولٌ مبتورٌ بلا ما يقول ذلك
     * يُقرأ على أنه كلّ ما في المتجر، ويُبنى عليه قرار.
     */
    public const LIMIT = 500;

    /** بداية الفترة — أو null فالعمر كلّه */
    private static function start(string $range): ?Carbon
    {
        return Demo::rangeStart(Demo::range($range));
    }

    /** قيمةُ منتقًى إن كانت غير فارغة — و«الكل» ليست قيمة */
    private static function pick(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** صفوفٌ مع خبرِ بترها */
    private static function capped(Collection $rows, int $total): array
    {
        return [
            'rows' => $rows->values()->all(),
            'truncated' => $total > self::LIMIT ? ['shown' => $rows->count(), 'total' => $total] : null,
        ];
    }

    private static function branchOptions(int $bid): array
    {
        return Branch::where('business_id', $bid)->orderBy('id')
            ->get(['id', 'name'])->map(fn ($b) => ['value' => (string) $b->id, 'label' => $b->name])->all();
    }

    private static function categoryOptions(int $bid): array
    {
        return Category::where('business_id', $bid)->orderBy('name')
            ->get(['id', 'name'])->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])->all();
    }

    /* ========================== الحركة المالية ========================== */

    public static function finance(int $bid, array $filters): array
    {
        $start = self::start($filters['range'] ?? 'month');
        $method = self::pick($filters, 'method');
        $type = self::pick($filters, 'type');

        $base = Transaction::where('business_id', $bid)
            ->when($start, fn ($q) => $q->where('occurred_at', '>=', $start))
            ->when($method, fn ($q) => $q->where('method', $method))
            ->when($type, fn ($q) => $q->where('type', $type));

        $income = (float) (clone $base)->where('type', 'دخل')->sum('amount');
        $outgo = (float) (clone $base)->where('type', '!=', 'دخل')->sum('amount');
        $total = (clone $base)->count();

        $rows = (clone $base)->orderByDesc('occurred_at')->orderByDesc('id')->limit(self::LIMIT)->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'description' => $t->description,
                'method' => $t->method,
                'type' => $t->type,
                'amount' => round((float) $t->amount, 3),
                'at' => optional($t->occurred_at)->format('Y-m-d'),
            ]);

        return array_merge(self::capped($rows, $total), [
            'summary' => [
                'income' => round($income, 3),
                'outgo' => round($outgo, 3),
                // الصافي يُقال ولا يُترك للطرح في رأس القارئ
                'net' => round($income - $outgo, 3),
                'count' => $total,
            ],
            'options' => [
                'methods' => collect(Transaction::where('business_id', $bid)->distinct()->pluck('method'))
                    ->filter()->values()->map(fn ($m) => ['value' => $m, 'label' => $m])->all(),
                'types' => collect(Transaction::where('business_id', $bid)->distinct()->pluck('type'))
                    ->filter()->values()->map(fn ($t) => ['value' => $t, 'label' => $t])->all(),
            ],
        ]);
    }

    /* ============================ المصروفات ============================ */

    public static function expenses(int $bid, array $filters): array
    {
        $start = self::start($filters['range'] ?? 'month');
        $type = self::pick($filters, 'type');

        $base = Expense::where('business_id', $bid)
            ->when($start, fn ($q) => $q->where('spent_at', '>=', $start))
            ->when($type, fn ($q) => $q->where('type', $type));

        $total = (float) (clone $base)->sum('amount');
        $count = (clone $base)->count();

        $byType = (clone $base)->selectRaw('type, SUM(amount) as s, COUNT(*) as c')
            ->groupBy('type')->orderByDesc('s')->get();

        $rows = (clone $base)->orderByDesc('spent_at')->orderByDesc('id')->limit(self::LIMIT)->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'description' => $e->description,
                'method' => $e->method,
                'status' => $e->status,
                'amount' => round((float) $e->amount, 3),
                'at' => optional($e->spent_at)->format('Y-m-d'),
            ]);

        return array_merge(self::capped($rows, $count), [
            'summary' => [
                'total' => round($total, 3),
                'count' => $count,
                'average' => $count > 0 ? round($total / $count, 3) : 0.0,
                'topType' => $byType->first()->type ?? null,
                'topTotal' => round((float) ($byType->first()->s ?? 0), 3),
            ],
            'byType' => $byType->map(fn ($r) => [
                'label' => $r->type,
                'value' => round((float) $r->s, 3),
                'count' => (int) $r->c,
            ])->all(),
            'options' => [
                'types' => collect(Expense::where('business_id', $bid)->distinct()->pluck('type'))
                    ->filter()->values()->map(fn ($t) => ['value' => $t, 'label' => $t])->all(),
            ],
        ]);
    }

    /* ======================== كشف الحساب البنكي ======================== */

    public static function bank(int $bid, array $filters): array
    {
        $start = self::start($filters['range'] ?? 'month');
        $status = self::pick($filters, 'match_status');

        $base = BankStatementLine::where('business_id', $bid)
            ->when($start, fn ($q) => $q->where('date', '>=', $start))
            ->when($status, fn ($q) => $q->where('match_status', $status));

        $count = (clone $base)->count();

        /*
         * المطابَقُ وغيرُه يُعدّان على الفترة كلّها لا على المنتقى: مؤشّرٌ
         * يتبع المرشّح يصير «غير المطابَق: ٠» كلّما رُشّح على المطابَق —
         * رقمٌ صحيحٌ يقول كذبًا.
         */
        $scope = BankStatementLine::where('business_id', $bid)
            ->when($start, fn ($q) => $q->where('date', '>=', $start));

        $matched = (clone $scope)->where('match_status', 'matched')->count();

        $rows = (clone $base)->orderByDesc('date')->orderByDesc('id')->limit(self::LIMIT)->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'description' => $l->description,
                'reference' => $l->reference,
                'status' => $l->match_status,
                'amount' => round((float) $l->amount, 3),
                'at' => optional($l->date)->format('Y-m-d'),
            ]);

        return array_merge(self::capped($rows, $count), [
            'summary' => [
                'lines' => (clone $scope)->count(),
                'matched' => $matched,
                'unmatched' => (clone $scope)->count() - $matched,
                'total' => round((float) (clone $scope)->sum('amount'), 3),
            ],
            'options' => [
                'statuses' => collect(BankStatementLine::where('business_id', $bid)->distinct()->pluck('match_status'))
                    ->filter()->values()->map(fn ($s) => ['value' => $s, 'label' => $s])->all(),
            ],
        ]);
    }

    /* ============================= الطلبات ============================= */

    public static function orders(int $bid, array $filters): array
    {
        $start = self::start($filters['range'] ?? 'month');
        $status = self::pick($filters, 'status');
        $branch = self::pick($filters, 'branch_id');
        $method = self::pick($filters, 'payment_method');

        $base = Order::where('business_id', $bid)->where('is_held', false)
            ->when($start, fn ($q) => $q->where('ordered_at', '>=', $start))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->when($method, fn ($q) => $q->where('payment_method', $method));

        $count = (clone $base)->count();
        // الملغى لا يُحسب في الإيراد — انظر Order::scopeSold
        $soldSum = (float) (clone $base)->where('status', '!=', Order::CANCELLED)->sum('total');
        $soldCount = (clone $base)->where('status', '!=', Order::CANCELLED)->count();
        $cancelled = (clone $base)->where('status', Order::CANCELLED)->count();

        $rows = (clone $base)->orderByDesc('ordered_at')->orderByDesc('id')->limit(self::LIMIT)->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'number' => $o->number,
                'customer' => $o->customer_name,
                'branch' => $o->branch,
                'status' => $o->status,
                'method' => $o->payment_method,
                'total' => round((float) $o->total, 3),
                'at' => optional($o->ordered_at)->format('Y-m-d'),
            ]);

        return array_merge(self::capped($rows, $count), [
            'summary' => [
                'count' => $count,
                'total' => round($soldSum, 3),
                // المتوسّط على المُباع لا على الكلّ: الملغى يُنقص المتوسّط بلا أن يُنقص الإيراد
                'average' => $soldCount > 0 ? round($soldSum / $soldCount, 3) : 0.0,
                'cancelled' => $cancelled,
            ],
            'options' => [
                'statuses' => collect(Order::where('business_id', $bid)->distinct()->pluck('status'))
                    ->filter()->values()->map(fn ($s) => ['value' => $s, 'label' => $s])->all(),
                'branches' => self::branchOptions($bid),
                'methods' => collect(Order::where('business_id', $bid)->distinct()->pluck('payment_method'))
                    ->filter()->values()->map(fn ($m) => ['value' => $m, 'label' => $m])->all(),
            ],
        ]);
    }

    /* ============================ المنتجات ============================ */

    public static function products(int $bid, array $filters): array
    {
        $start = self::start($filters['range'] ?? 'month');
        $category = self::pick($filters, 'category_id');

        $products = Product::where('business_id', $bid)
            ->when($category, fn ($q) => $q->where('category_id', $category))
            ->with('category')->get();

        /*
         * المبيعات تُجمع بمعرّف المنتج لا باسمه: صنفان باسمٍ متشابه يندمجان
         * بالاسم، ومنتجٌ أُعيدت تسميته يفقد تاريخه كلّه.
         */
        $sold = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.business_id', $bid)->where('orders.is_held', false)
            ->where('orders.status', '!=', Order::CANCELLED)
            ->when($start, fn ($q) => $q->where('orders.ordered_at', '>=', $start))
            ->whereNotNull('order_items.product_id')
            ->selectRaw('order_items.product_id as pid, SUM(order_items.quantity) as q, SUM(order_items.total) as s')
            ->groupBy('order_items.product_id')->get()->keyBy('pid');

        $rows = $products->map(function ($p) use ($sold) {
            $hit = $sold->get($p->id);
            $revenue = round((float) ($hit->s ?? 0), 3);
            $units = (int) ($hit->q ?? 0);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'category' => $p->category?->name,
                'price' => round((float) $p->price, 3),
                'quantity' => (int) $p->quantity,
                'units' => $units,
                'revenue' => $revenue,
                // الربحُ بتكلفة المنتج وقت القراءة — تقديرٌ لا قيدٌ محاسبيّ
                'profit' => round($revenue - $units * (float) $p->cost, 3),
            ];
        })->sortByDesc('revenue');

        $total = $products->count();

        return array_merge(self::capped($rows->take(self::LIMIT), $total), [
            'summary' => [
                'products' => $total,
                'revenue' => round((float) $rows->sum('revenue'), 3),
                'profit' => round((float) $rows->sum('profit'), 3),
                'sold' => $rows->where('units', '>', 0)->count(),
            ],
            'options' => ['categories' => self::categoryOptions($bid)],
        ]);
    }

    /* ======================== المخزون والكميات ======================== */

    public static function inventory(int $bid, array $filters): array
    {
        $category = self::pick($filters, 'category_id');
        $only = self::pick($filters, 'below');

        $products = Product::where('business_id', $bid)
            ->when($category, fn ($q) => $q->where('category_id', $category))
            ->with('category')->orderBy('name')->get();

        $rows = $products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'category' => $p->category?->name,
            'quantity' => (int) $p->quantity,
            'alert' => (int) $p->alert_qty,
            'cost' => round((float) $p->cost, 3),
            'value' => round((float) $p->cost * (int) $p->quantity, 3),
            'below' => (int) $p->quantity <= (int) $p->alert_qty,
        ]);

        /*
         * المؤشّرات على المخزون كلّه لا على المرشَّح وحده: من رشّح «تحت
         * الحدّ» يريد أن يعرف كم هي من الكلّ — لا أن يقرأ «١٠٠٪ تحت الحدّ».
         */
        $below = $rows->where('below', true)->count();
        $summary = [
            'items' => $rows->count(),
            'quantity' => (int) $rows->sum('quantity'),
            'value' => round((float) $rows->sum('value'), 3),
            'below' => $below,
        ];

        $shown = $only === '1' ? $rows->where('below', true) : $rows;

        return array_merge(self::capped($shown->take(self::LIMIT), $shown->count()), [
            'summary' => $summary,
            'options' => ['categories' => self::categoryOptions($bid)],
        ]);
    }

    /* =========================== أوامر الشراء =========================== */

    public static function purchases(int $bid, array $filters): array
    {
        $start = self::start($filters['range'] ?? 'month');
        $status = self::pick($filters, 'status');
        $supplier = self::pick($filters, 'supplier_id');

        $base = PurchaseOrder::where('business_id', $bid)
            ->when($start, fn ($q) => $q->where('ordered_at', '>=', $start))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($supplier, fn ($q) => $q->where('supplier_id', $supplier));

        $count = (clone $base)->count();
        $total = (float) (clone $base)->sum('total');

        $rows = (clone $base)->orderByDesc('ordered_at')->orderByDesc('id')->limit(self::LIMIT)->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number,
                'supplier' => $p->supplier_name,
                'status' => $p->status,
                'total' => round((float) $p->total, 3),
                'at' => optional($p->ordered_at)->format('Y-m-d'),
                'received' => optional($p->received_at)->format('Y-m-d'),
            ]);

        return array_merge(self::capped($rows, $count), [
            'summary' => [
                'count' => $count,
                'total' => round($total, 3),
                'received' => (clone $base)->whereNotNull('received_at')->count(),
                'pending' => (clone $base)->whereNull('received_at')->count(),
            ],
            'options' => [
                'statuses' => collect(PurchaseOrder::where('business_id', $bid)->distinct()->pluck('status'))
                    ->filter()->values()->map(fn ($s) => ['value' => $s, 'label' => $s])->all(),
                'suppliers' => Supplier::where('business_id', $bid)->orderBy('name')
                    ->get(['id', 'name'])->map(fn ($s) => ['value' => (string) $s->id, 'label' => $s->name])->all(),
            ],
        ]);
    }

    /* ============================ المورّدون ============================ */

    public static function suppliers(int $bid, array $filters): array
    {
        $start = self::start($filters['range'] ?? 'all');

        $orders = PurchaseOrder::where('business_id', $bid)
            ->when($start, fn ($q) => $q->where('ordered_at', '>=', $start))
            ->selectRaw('supplier_id, COUNT(*) as c, SUM(total) as s')
            ->groupBy('supplier_id')->get()->keyBy('supplier_id');

        $rows = Supplier::where('business_id', $bid)->orderBy('name')->get()
            ->map(function ($s) use ($orders) {
                $hit = $orders->get($s->id);

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'phone' => $s->phone,
                    'contact' => $s->contact_person,
                    'orders' => (int) ($hit->c ?? 0),
                    'total' => round((float) ($hit->s ?? 0), 3),
                ];
            })->sortByDesc('total');

        return array_merge(self::capped($rows->take(self::LIMIT), $rows->count()), [
            'summary' => [
                'suppliers' => $rows->count(),
                'active' => $rows->where('orders', '>', 0)->count(),
                'orders' => (int) $rows->sum('orders'),
                'total' => round((float) $rows->sum('total'), 3),
            ],
            'options' => [],
        ]);
    }

    /* =========================== سجل النشاط =========================== */

    public static function activity(int $bid, array $filters): array
    {
        $start = self::start($filters['range'] ?? 'month');
        $user = self::pick($filters, 'user_id');
        $action = self::pick($filters, 'action');

        $base = ActivityLog::where('business_id', $bid)
            ->when($start, fn ($q) => $q->where('created_at', '>=', $start))
            ->when($user, fn ($q) => $q->where('user_id', $user))
            ->when($action, fn ($q) => $q->where('action', $action));

        $count = (clone $base)->count();

        $byAction = (clone $base)->selectRaw('action, COUNT(*) as c')
            ->groupBy('action')->orderByDesc('c')->get();

        $rows = (clone $base)->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIMIT)->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'user' => $a->user_name,
                'action' => $a->action,
                'description' => $a->description,
                'at' => optional($a->created_at)->format('Y-m-d H:i'),
            ]);

        return array_merge(self::capped($rows, $count), [
            'summary' => [
                'count' => $count,
                'users' => (int) (clone $base)->distinct()->count('user_id'),
                'topAction' => $byAction->first()->action ?? null,
                'topCount' => (int) ($byAction->first()->c ?? 0),
            ],
            'byAction' => $byAction->map(fn ($r) => ['label' => $r->action, 'value' => (int) $r->c])->all(),
            'options' => [
                'users' => User::where('business_id', $bid)->orderBy('name')
                    ->get(['id', 'name'])->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->name])->all(),
                'actions' => $byAction->pluck('action')->filter()->values()
                    ->map(fn ($a) => ['value' => $a, 'label' => $a])->all(),
            ],
        ]);
    }

    /* ======================= الكوبونات والتسويق ======================= */

    public static function marketing(int $bid, array $filters): array
    {
        $start = self::start($filters['range'] ?? 'month');

        /*
         * الخصمُ يُقرأ من الطلبات لا من الكوبون: `used_count` عدّادٌ يزيد ولا
         * ينقص، ولا يعرف كم خُصم فعلًا ولا في أيّ فترة. والطلبُ يحمل الرمز
         * والقيمة معًا.
         */
        $used = Order::where('business_id', $bid)->sold()->whereNotNull('coupon_code')
            ->when($start, fn ($q) => $q->where('ordered_at', '>=', $start))
            ->selectRaw('coupon_code, COUNT(*) as c, SUM(coupon_discount) as d, SUM(total) as t')
            ->groupBy('coupon_code')->get()->keyBy('coupon_code');

        $rows = Coupon::where('business_id', $bid)->orderBy('code')->get()
            ->map(function ($c) use ($used) {
                $hit = $used->get($c->code);

                return [
                    'id' => $c->id,
                    'code' => $c->code,
                    'type' => $c->type,
                    'value' => round((float) $c->value, 3),
                    'active' => (bool) $c->active,
                    'uses' => (int) ($hit->c ?? 0),
                    'discount' => round((float) ($hit->d ?? 0), 3),
                    'revenue' => round((float) ($hit->t ?? 0), 3),
                ];
            })->sortByDesc('uses');

        return array_merge(self::capped($rows->take(self::LIMIT), $rows->count()), [
            'summary' => [
                'coupons' => $rows->count(),
                'used' => $rows->where('uses', '>', 0)->count(),
                'uses' => (int) $rows->sum('uses'),
                'discount' => round((float) $rows->sum('discount'), 3),
            ],
            'options' => [],
        ]);
    }
}
