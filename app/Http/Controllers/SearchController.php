<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\GoodsReceiptNote;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Demo;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * البحث العام الموحّد للشريط العلوي (JSON، مجمّع حسب النوع).
 */
class SearchController extends Controller
{
    public function admin(Request $request)
    {
        $q = Search::term($request);
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }
        $user = auth()->user();
        $bid = $user->business_id ?? Demo::bid();
        $like = "%{$q}%";
        $op = Search::like();

        /*
         * البحث لا يتجاوز صلاحيات صاحبه.
         *
         * المسار نفسه مفتوح لكل من دخل اللوحة — هو أداةٌ في الشريط العلوي لا
         * قسم. لكن نتائجه تقود إلى ثلاثة أقسام، فمن لا يملك «العملاء» كان
         * يقرأ أسماءهم وأرقامهم من مربّع البحث ثم يصطدم بـ403 عند الضغط:
         * البيانات وصلته قبل الباب المغلق.
         */
        $products = $user->allows('products') ? Product::where('business_id', $bid)
            ->where(fn ($w) => $w->where('name', $op, $like)->orWhere('sku', $op, $like))
            ->limit(5)->get()->map(fn ($p) => [
                'label' => $p->name, 'meta' => $p->sku ?: '—',
                'url' => route('admin.products.show', $p->id),
            ]) : collect();

        /*
         * والملغى يُوجَد.
         *
         * كان البحث يمرّ بـ`sold()` فيستثني الملغى، وشاشةُ المبيعات تعرضه
         * وتعدّه وتفتح صفحته. وأكثرُ ما يُبحث عن رقم فاتورةٍ يقع عند
         * الإرجاع والاعتراض — أي على فاتورةٍ أُلغيت. فمن معه ورقةٌ ملغاة
         * كان يكتب رقمها فيُقال له «لا نتائج»، ويظنّ أنّ بيعَه ضاع من
         * النظام. والمعلَّق وحده يخرج: سلّةٌ لم تُبَع بعد ولا رقم لها يُبحث.
         */
        $orders = $user->allows('orders') ? Order::where('business_id', $bid)->where('is_held', false)
            ->where(fn ($w) => $w->where('number', $op, $like)->orWhere('customer_name', $op, $like))
            ->orderByDesc('id')->limit(5)->get()->map(fn ($o) => [
                'label' => $o->number, 'meta' => $o->customer_name ?? __('عميل نقدي'),
                'url' => route('admin.orders.show', $o->number),
            ]) : collect();

        $customers = $user->allows('customers') ? Customer::where('business_id', $bid)
            ->where(fn ($w) => $w->where('name', $op, $like)->orWhere('phone', $op, $like))
            ->limit(5)->get()->map(fn ($c) => [
                'label' => $c->name, 'meta' => $c->phone ?: '—',
                'url' => route('admin.customers.show', $c->id),
            ]) : collect();

        /*
         * والمورّدون كذلك: البحث كان يعرف من نبيع له ولا يعرف ممّن نشتري.
         *
         * وقائمتهم لا تُفتح على صفحةٍ لكلّ مورّد — فالوجهة قائمتُهم مُرشَّحةً
         * باسمه، لا رابطٌ يقود إلى صفحةٍ لا وجود لها.
         */
        $suppliers = $user->allows('suppliers') ? Supplier::where('business_id', $bid)
            ->where(fn ($w) => $w->where('name', $op, $like)
                ->orWhere('phone', $op, $like)
                ->orWhere('contact_person', $op, $like))
            ->limit(5)->get()->map(fn ($s) => [
                'label' => $s->name, 'meta' => $s->phone ?: ($s->contact_person ?: '—'),
                'url' => route('admin.suppliers.index', ['q' => $s->name]),
            ]) : collect();

        return response()->json(['groups' => array_values(array_filter([
            $this->group(__('المنتجات'), 'package', $products),
            $this->group(__('الطلبات'), 'shopping-cart', $orders),
            $this->group(__('العملاء'), 'users', $customers),
            $this->group(__('الموردين'), 'truck', $suppliers),
            $this->group(__('السندات والمعاملات'), 'receipt', $this->documents($user, $bid, $q, $op)),
        ]))]);
    }

    public function super(Request $request)
    {
        $q = Search::term($request);
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }
        $like = "%{$q}%";
        /*
         * والمعامل يُسأل هنا كما يُسأل في بحث اللوحة.
         *
         * كانت هذه الدالّة وحدها تكتب `like` نصًّا: تجري الاختبارات على SQLite
         * فتمرّ، ويجري الإنتاج على PostgreSQL فتفرّق بين الحرف الكبير والصغير.
         * فمن كتب بريد تاجرٍ بحرفٍ كبيرٍ واحد لم يجده — وهذه الشاشة أوّل ما
         * يُفتح حين يتّصل التاجر.
         */
        $op = Search::like();

        $businesses = Business::where(fn ($w) => $w->where('name', $op, $like)->orWhere('owner_name', $op, $like))
            ->limit(6)->get()->map(fn ($b) => [
                'label' => $b->name, 'meta' => $b->owner_name ?: '—',
                'url' => route('super-admin.businesses.show', $b->id),
            ]);

        $users = User::where(fn ($w) => $w->where('name', $op, $like)->orWhere('email', $op, $like))
            ->limit(6)->get()->map(fn ($u) => [
                'label' => $u->name, 'meta' => $u->email ?: '—',
                'url' => route('super-admin.users.show', $u->id),
            ]);

        return response()->json(['groups' => array_values(array_filter([
            $this->group(__('الشركات'), 'building-2', $businesses),
            $this->group(__('المستخدمون'), 'users', $users),
        ]))]);
    }

    /**
     * السندات تُطلب برموزها — لا بأسمائها.
     *
     * التاجر يمسك ورقةً في يده: سندَ استلامٍ من المورّد، أو قيدًا يسأل عنه
     * محاسبُه، أو مصروفًا يريد إيصاله. وكان الشريط يعرف المنتجات والعملاء
     * ولا يعرف الأوراق — فمن معه «GRN-000042» لا باب له إلا أن يفتح
     * المخزون، ثمّ تبويب الإشعارات، ثمّ يبحث فيها. ثلاث نقراتٍ ليجد ما
     * رمزُه في يده.
     *
     * والوجهة تُرشَّح بالرمز نفسه لا تُفتح على أوّل القائمة: رابطٌ يُنزلك في
     * صفحةٍ فيها ستّون سندًا ويتركك تبحث ليس رابطًا. ولذلك لا يُدرَج هنا إلا
     * ما لصفحته مُرشِّحٌ يقرأ `q` فعلًا — سندُ التسليم وسندُ نقل المخزون
     * خارجَ القائمة لأنّ لا شاشة تعرضهما بعد، ورمزٌ يُوجَد ولا يُفتح أسوأ
     * من رمزٍ لا يُوجَد.
     *
     * ولا يُسأل عن الأوراق إلا حين يحتمل الطلبُ أن يكون رمزًا — أي حين يحمل
     * رقمًا. الرموز كلّها بادئةٌ ورقم، فالبحث عن «أحمد» لا يستحقّ سبعة
     * استعلامات على سبعة جداول لا يمكن أن يحمل أيٌّ منها «أحمد» في خانة
     * رمزه. وهذا يجري مع كل ضغطة حرفٍ في الشريط.
     */
    private function documents(User $user, int $bid, string $q, string $op): Collection
    {
        if (! preg_match('/\d/', $q)) {
            return collect();
        }

        $like = "%{$q}%";
        $rows = collect();

        /*
         * سبعةٌ في سقفٍ واحد لا سقفٌ لكلّ نوع.
         *
         * سبعة أنواعٍ بخمسة صفوفٍ لكلٍّ تعني خمسةً وثلاثين صفًّا في قائمةٍ
         * منسدلة — ومن كتب رمزًا كاملًا يريد صفًّا واحدًا لا خمسةً وثلاثين.
         */
        $rows = $rows->concat($user->allows('purchases')
            ? PurchaseOrder::where('business_id', $bid)->where('number', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($p) => [
                    'label' => $p->number,
                    'meta' => __('أمر شراء'),
                    'url' => route('admin.purchases.orders', ['q' => $p->number]),
                ])
            : []);

        $rows = $rows->concat($user->allows('purchases')
            ? SupplierInvoice::where('business_id', $bid)->where('supplier_ref', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($i) => [
                    'label' => $i->supplier_ref,
                    'meta' => __('سند مورّد'),
                    'url' => route('admin.purchases.invoices', ['q' => $i->supplier_ref]),
                ])
            : []);

        $rows = $rows->concat($user->allows('inventory')
            ? GoodsReceiptNote::where('business_id', $bid)->where('number', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($g) => [
                    'label' => $g->number,
                    'meta' => __('إشعار استلام'),
                    'url' => route('admin.inventory.receipts', ['q' => $g->number]),
                ])
            : []);

        $rows = $rows->concat($user->allows('inventory')
            ? StockAdjustment::where('business_id', $bid)->where('number', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($a) => [
                    'label' => $a->number,
                    'meta' => __('تسوية مخزون'),
                    'url' => route('admin.inventory.adjustments', ['q' => $a->number]),
                ])
            : []);

        $rows = $rows->concat($user->allows('finance')
            ? JournalEntry::where('business_id', $bid)->where('number', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($j) => [
                    'label' => $j->number,
                    'meta' => __('قيد يومية'),
                    'url' => route('admin.finance.journal', ['q' => $j->number]),
                ])
            : []);

        /*
         * والمصروف يُفتح على كلّ الشهور لا على الشهر الجاري.
         *
         * شاشة المصروفات تبدأ من شهرها، فرابطٌ برمزٍ من مايو يقع على أبريل
         * ولا يعرض شيئًا. و`month=` فارغةً تعني «كلّ الشهور» — انظر
         * `ListFilters::expenseSpan`.
         */
        $rows = $rows->concat($user->allows('expenses')
            ? Expense::where('business_id', $bid)->where('reference', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($e) => [
                    'label' => $e->reference,
                    'meta' => __('مصروف'),
                    'url' => route('admin.expenses.index', ['month' => '', 'q' => $e->reference]),
                ])
            : []);

        /*
         * ومعاملةُ البيع لا تُعرض مرّتين.
         *
         * نقطة البيع تكتب لكل فاتورةٍ معاملةَ دخلٍ مرجعُها رقمُ الفاتورة
         * نفسه، فالبحث عن «INV-000012» كان سيردّ صفَّين برمزٍ واحد: الفاتورة
         * ومعاملتَها. والفاتورة أنفعُ الوجهتين — فيها الأصناف والعميل وزرّ
         * الطباعة — فتُترك المعاملة لصاحب الحركة اليدوية وحده.
         */
        $rows = $rows->concat($user->allows('finance')
            ? Transaction::where('business_id', $bid)->whereNull('order_id')
                ->where('reference', $op, $like)
                ->orderByDesc('id')->limit(3)->get()->map(fn ($t) => [
                    'label' => $t->reference,
                    'meta' => $t->type ?: __('معاملة'),
                    'url' => route('admin.reports.finance', ['range' => 'all', 'q' => $t->reference]),
                ])
            : []);

        return $rows->take(7)->values();
    }

    private function group(string $title, string $icon, $items): ?array
    {
        return $items->isEmpty() ? null : ['title' => $title, 'icon' => $icon, 'items' => $items->all()];
    }
}
