<?php

namespace App\Support\Archive;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * أوراقُ الأرشيف — ما يقرؤه المحاسب، لا ما في الجداول.
 *
 * ═══ القاعدة: عمودٌ يُقرأ لا عمودٌ يُخزَّن ═══
 *
 * الفرقُ بين تصديرٍ وجردِ جدولٍ أنّ التصدير يُسقط ما لا معنى له لقارئه
 * (`client_uuid`، `form_token`، `pos_device_id`) ويترجم ما يُخزَّن رمزًا
 * (`customer_id` → اسمُ العميل). وملفٌّ فيه ستّةٌ وأربعون عمودًا خامًا
 * يُغلقه من يفتحه.
 *
 * ═══ والمالُ من مالكه ═══
 *
 * لا مجموعَ يُحسب هنا من جديد. الميزانُ من `Ledger::trialBalance`، وحالُ
 * سداد الفاتورة من `CustomerInvoice::paymentState()`، والمتبقّي من
 * `outstanding()`. وحسابٌ ثانٍ لرقمٍ له مالك يفترق عنه يومًا — فيقرأ
 * التاجر في الورقة غيرَ ما يقرأ في الشاشة، ولا يعرف أيَّهما يصدّق.
 *
 * ═══ والصفوفُ تتدفّق ═══
 *
 * `cursor()` في كلّ استعلام: القاعدة تُرسل سطرًا سطرًا ولا تُبنى مجموعةُ
 * نماذجَ كاملة. وشهرُ متجرٍ كبير عشراتُ الآلاف من الطلبات — و`get()`
 * عليها تبني عشراتِ الآلاف من النماذج دفعةً واحدة.
 */
final class Sheets
{
    /**
     * ما يُكتب — بالملفّ ومصدره.
     *
     * والترتيبُ ترتيبُ الأهمّيّة لا الأبجديّة: من يفتح المجلّد يجد المبيعات
     * أوّلًا.
     */
    public const FILES = [
        'Sales' => 'المبيعات',
        'Customer-Invoices' => 'فواتير العملاء',
        'Purchase-Orders' => 'أوامر الشراء',
        'Supplier-Invoices' => 'سندات الموردين',
        'Expenses' => 'المصروفات',
        'Inventory' => 'الجرد',
        'Stock-Movements' => 'حركات المخزون',
        'Customers' => 'العملاء',
        'Suppliers' => 'الموردون',
        'Employees' => 'الموظفون',
        'Payroll' => 'الرواتب',
        'Finance' => 'ميزان المراجعة',
    ];

    /**
     * أوراقٌ لا تُكتب إلّا لمن مُنح قراءتها.
     *
     * الرواتبُ أخطرُ ما في المتجر إن تسرّب: راتبُ كلِّ موظّفٍ باسمه. ومن
     * مُنح تصديرَ بيانات النشاط لم يُمنح بها بالضرورة قراءةَ المسيرة —
     * والفعلان موجودان في النظام منفصلين أصلًا (`payroll.view`).
     */
    public const GATED = ['Payroll' => Permissions::PAYROLL_VIEW];

    /**
     * يكتب ورقةً واحدة، ويردّ عددَ صفوفها — أو `null` إن لا صفَّ فيها.
     *
     * و`null` تعني «لا يُكتب الملفّ»: ملفٌّ فارغٌ برؤوسٍ وحدها يجعل من
     * يفتحه يظنّ بياناتِه ضاعت. ولا يُكتب ما لا مضمونَ له.
     */
    public static function write(string $name, int $bid, Period $period, string $path, array $currency, bool $rtl): ?int
    {
        $book = Book::start(self::FILES[$name] ?? $name, $rtl);

        $book->caption([
            __('المتجر').': '.(Business::find($bid)?->name ?? '—'),
            __('الورقة').': '.__(self::FILES[$name] ?? $name),
            __('الفترة').': '.$period->start()->format('Y-m-d').' — '.$period->end()->format('Y-m-d'),
            __('العملة').': '.($currency['code'] ?? ''),
        ]);

        try {
            match ($name) {
                'Sales' => self::sales($book, $bid, $period),
                'Customer-Invoices' => self::customerInvoices($book, $bid, $period),
                'Purchase-Orders' => self::purchaseOrders($book, $bid, $period),
                'Supplier-Invoices' => self::supplierInvoices($book, $bid, $period),
                'Expenses' => self::expenses($book, $bid, $period),
                'Inventory' => self::inventory($book, $bid, $period),
                'Stock-Movements' => self::stockMovements($book, $bid, $period),
                'Customers' => self::customers($book, $bid, $period),
                'Suppliers' => self::suppliers($book, $bid),
                'Employees' => self::employees($book, $bid),
                'Payroll' => self::payroll($book, $bid, $period),
                'Finance' => self::finance($book, $bid, $period),
                default => throw new \InvalidArgumentException("ورقةٌ لا تُعرف: {$name}"),
            };

            if ($book->rowCount() === 0) {
                return null;
            }

            $book->saveTo($path);

            return $book->rowCount();
        } finally {
            $book->close();
        }
    }

    /* ============================ المبيعات ============================ */

    private static function sales(Book $book, int $bid, Period $period): void
    {
        $book->head([
            __('رقم الطلب'), __('التاريخ'), __('الفرع'), __('العميل'), __('الموظف'),
            __('طريقة الدفع'), __('المجموع الفرعي'), __('الخصم'), __('الضريبة'),
            __('رسوم التوصيل'), __('الإجمالي'), __('حالة الدفع'), __('الحالة'),
        ]);

        /*
         * والملغى يبقى في الورقة.
         *
         * أرشيفُ الشهر سجلٌّ لما جرى، لا تقريرُ أداء. وطلبٌ أُلغي جرى: له رقمٌ
         * قُطع، وقد يُسأل عنه في مراجعة. وحذفُه يجعل الأرقامَ تقفز — من يقرأ
         * ١٢٠ ثمّ ١٢٢ يظنّ ورقةً نقصت من الأرشيف.
         *
         * وحالتُه مكتوبةٌ في عمودها، فمن يجمع يعرف ما يستبعد.
         */
        $rows = Order::where('business_id', $bid)
            ->where('is_held', false)
            ->whereBetween('ordered_at', [$period->start(), $period->end()])
            ->orderBy('id');

        foreach ($rows->cursor() as $order) {
            $book->row([
                Text::of($order->number),
                optional($order->ordered_at)->format('Y-m-d H:i') ?? '—',
                $order->branch ?? '—',
                $order->customer_name ?? '—',
                $order->employee_name ?? '—',
                $order->payment_method ?? '—',
                Amount::of($order->subtotal),
                Amount::of($order->discount),
                Amount::of($order->tax),
                Amount::of($order->delivery_fee),
                Amount::of($order->total),
                $order->payment_status ?? '—',
                $order->status,
            ]);
        }
    }

    /* ======================== فواتير العملاء ======================== */

    private static function customerInvoices(Book $book, int $bid, Period $period): void
    {
        $book->head([
            __('رقم الفاتورة'), __('العميل'), __('الاسم القانوني'), __('الرقم الضريبي'),
            __('تاريخ الإصدار'), __('تاريخ الاستحقاق'), __('أمر الشراء'), __('المرجع'),
            __('المجموع الفرعي'), __('الخصم'), __('الضريبة'), __('الإجمالي'),
            __('المدفوع'), __('المتبقي'), __('حالة السداد'), __('الحالة'),
        ]);

        /*
         * والمسودّاتُ خارج الورقة.
         *
         * المسودّةُ ليست مستندًا: لا رقمَ قُطع لها ولا ذمّةَ نشأت ولا قيدَ
         * كُتب — وقد تُحذف غدًا. وإدخالُها أرشيفًا يُقدَّم إلى جهةٍ مراجِعة
         * يجعل الورقةَ تقول إنّ للمتجر فواتيرَ لم يُصدرها.
         *
         * والملغاةُ تبقى: تلك صدرت ثمّ عُكست، ولها رقمٌ وقيدٌ وعكسُه.
         */
        $rows = CustomerInvoice::where('business_id', $bid)
            ->whereIn('status', [CustomerInvoice::ISSUED, CustomerInvoice::CANCELLED])
            ->whereBetween('issued_at', [$period->start(), $period->end()])
            ->orderBy('id');

        foreach ($rows->cursor() as $invoice) {
            $book->row([
                Text::of($invoice->number),
                $invoice->customer_name ?? '—',
                $invoice->customer_cr ?? '—',
                Text::of($invoice->customer_tax_number ?? '—'),
                optional($invoice->issued_at)->format('Y-m-d') ?? '—',
                optional($invoice->due_at)->format('Y-m-d') ?? '—',
                Text::of($invoice->po_number ?? '—'),
                Text::of($invoice->external_reference ?? '—'),
                Amount::of($invoice->subtotal),
                Amount::of($invoice->discount_total),
                Amount::of($invoice->tax_total),
                Amount::of($invoice->total),
                Amount::of($invoice->paidTotal()),
                Amount::of($invoice->outstanding()),
                $invoice->paymentState(),
                $invoice->status,
            ]);
        }
    }

    /* ========================== أوامر الشراء ========================== */

    private static function purchaseOrders(Book $book, int $bid, Period $period): void
    {
        $book->head([
            __('رقم الأمر'), __('المورّد'), __('التاريخ'), __('الفرع'),
            __('المجموع الفرعي'), __('خصم المورّد'), __('الشحن'), __('الضريبة'),
            __('الإجمالي'), __('حالة الاستلام'), __('طريقة الدفع'), __('مرجع المورّد'),
        ]);

        $branches = self::branchNames($bid);

        $rows = PurchaseOrder::where('business_id', $bid)
            ->whereBetween('ordered_at', [$period->start(), $period->end()])
            ->orderBy('id');

        foreach ($rows->cursor() as $po) {
            $book->row([
                Text::of($po->number),
                $po->supplier_name ?? '—',
                optional($po->ordered_at)->format('Y-m-d') ?? '—',
                $branches[$po->branch_id] ?? '—',
                Amount::of($po->items_subtotal),
                Amount::of($po->supplier_discount),
                Amount::of($po->shipping_cost),
                Amount::of($po->tax),
                Amount::of($po->total),
                $po->status ?? '—',
                $po->payment_method ?? '—',
                Text::of($po->supplier_reference ?? '—'),
            ]);
        }
    }

    /* ======================== سندات الموردين ======================== */

    private static function supplierInvoices(Book $book, int $bid, Period $period): void
    {
        $book->head([
            __('مرجع السند'), __('المورّد'), __('تاريخ الإصدار'), __('تاريخ الاستحقاق'),
            __('المجموع الفرعي'), __('الضريبة'), __('الإجمالي'), __('المدفوع'),
            __('المتبقي'), __('حالة السداد'), __('حالة الاعتماد'),
        ]);

        $suppliers = Supplier::where('business_id', $bid)->pluck('name', 'id')->all();

        $rows = SupplierInvoice::where('business_id', $bid)
            ->whereBetween('issued_at', [$period->start(), $period->end()])
            ->orderBy('id');

        foreach ($rows->cursor() as $bill) {
            $book->row([
                Text::of($bill->supplier_ref ?? '—'),
                $suppliers[$bill->supplier_id] ?? '—',
                optional($bill->issued_at)->format('Y-m-d') ?? '—',
                optional($bill->due_at)->format('Y-m-d') ?? '—',
                Amount::of($bill->subtotal),
                Amount::of($bill->tax),
                Amount::of($bill->total),
                Amount::of($bill->paid),
                Amount::of(max(0, (float) $bill->total - (float) $bill->paid)),
                $bill->status ?? '—',
                $bill->approval_status ?? '—',
            ]);
        }
    }

    /* =========================== المصروفات =========================== */

    private static function expenses(Book $book, int $bid, Period $period): void
    {
        $book->head([
            __('التاريخ'), __('النوع'), __('الوصف'), __('المبلغ'),
            __('طريقة الدفع'), __('المرجع'), __('الحالة'), __('الموظف'),
        ]);

        /*
         * والمحذوفُ خارجها: `expenses` فيها `deleted_at`، والحذفُ الليّن
         * قرارُ صاحبه بأنّ هذا لم يقع. والافتراضيُّ في Eloquent يُسقطها —
         * ويُكتب هنا صريحًا لأنّ من يقرأ يسأل.
         */
        $rows = Expense::where('business_id', $bid)
            ->whereBetween('spent_at', [$period->start()->toDateString(), $period->end()->toDateString()])
            ->orderBy('id');

        foreach ($rows->cursor() as $expense) {
            $book->row([
                optional($expense->spent_at)->format('Y-m-d') ?? '—',
                $expense->type ?? '—',
                $expense->description ?? '—',
                Amount::of(abs((float) $expense->amount)),
                $expense->method ?? '—',
                Text::of($expense->reference ?? '—'),
                $expense->status ?? '—',
                $expense->employee_name ?? '—',
            ]);
        }
    }

    /* ============================= الجرد ============================= */

    /**
     * رصيدُ آخرِ الشهر — محسوبًا رجوعًا من اليوم.
     *
     * ═══ ولمَ يُحسب ولا يُنسخ رصيدُ اليوم ═══
     *
     * لا عمودَ «رصيدُ آخر الشهر» في هذا النظام، ولا لقطةَ جردٍ شهريّة. وكتابةُ
     * الرصيد الحاليّ تحت عنوان «جردُ أغسطس» **كذبٌ في ورقةٍ رسميّة**: من
     * يقرؤها في نوفمبر يحسب تكلفةَ مبيعات أغسطس برصيدٍ لنوفمبر.
     *
     * و`inventory_movements` دفترٌ كامل: كلُّ تغييرٍ في `products.quantity`
     * يكتب سطرًا فيه — فُحص ذلك في المواضع الستّة التي تُغيّره (الاستلام،
     * وتصحيحُ الطلب، والجرد، والتسوية، والاستيراد، و`StockLedger::move`).
     * فالرصيدُ آنَ انتهاءِ الشهر = الرصيدُ الآن − مجموعُ ما تحرّك بعده.
     *
     * والحدُّ الذي يبقى مكتوبٌ في `README`: منتجٌ حُذف بعد الشهر تذهب حركاتُه
     * معه (`cascadeOnDelete`) فلا يظهر في جرده. وهذا يُقال، ولا يُخفى.
     */
    private static function inventory(Book $book, int $bid, Period $period): void
    {
        $book->head([
            __('الصنف'), __('رمز الصنف'), __('التصنيف'),
            __('الرصيد آخر الفترة'), __('التكلفة'), __('قيمة الرصيد'), __('السعر'),
        ]);

        /*
         * وحركاتُ ما بعد الفترة تُجمع مرّةً واحدة بالمنتج.
         *
         * والبديلُ استعلامٌ لكلّ منتج: متجرٌ بألفَي صنفٍ يُطلق ألفَي استعلام.
         * و`quantity` نصٌّ بإشارة («+5» / «-3») فيُقرأ في PHP لا في SQL —
         * جمعُ نصٍّ في القاعدة يختلف بين SQLite وPostgres.
         */
        $after = [];

        foreach (InventoryMovement::where('business_id', $bid)
            ->where('created_at', '>=', $period->next())
            ->select('product_id', 'quantity')
            ->cursor() as $movement) {
            if ($movement->product_id === null) {
                continue;
            }

            $after[$movement->product_id] = ($after[$movement->product_id] ?? 0) + (int) $movement->quantity;
        }

        $categories = Category::where('business_id', $bid)->pluck('name', 'id')->all();

        foreach (Product::where('business_id', $bid)->orderBy('id')->cursor() as $product) {
            $closing = (int) $product->quantity - ($after[$product->id] ?? 0);

            $book->row([
                $product->name,
                Text::of($product->sku ?? '—'),
                $categories[$product->category_id] ?? '—',
                $closing,
                Amount::of($product->cost),
                Amount::of($closing * (float) $product->cost),
                Amount::of($product->price),
            ]);
        }
    }

    /* ======================== حركات المخزون ======================== */

    private static function stockMovements(Book $book, int $bid, Period $period): void
    {
        $book->head([
            __('التاريخ'), __('الصنف'), __('رمز الصنف'), __('النوع'),
            __('الكمية'), __('الفرع'), __('الموظف'), __('ملاحظة'),
        ]);

        $branches = self::branchNames($bid);

        $rows = InventoryMovement::where('business_id', $bid)
            ->whereBetween('created_at', [$period->start(), $period->end()])
            ->orderBy('id');

        foreach ($rows->cursor() as $movement) {
            $book->row([
                optional($movement->created_at)->format('Y-m-d H:i') ?? '—',
                $movement->product_name,
                Text::of($movement->sku ?? '—'),
                $movement->type,
                (int) $movement->quantity,
                $branches[$movement->branch_id] ?? '—',
                $movement->employee_name ?? '—',
                $movement->note ?? '—',
            ]);
        }
    }

    /* ============================ العملاء ============================ */

    /**
     * سجلُّ العملاء كما هو آخرَ الفترة — لا من تعامل فيها وحده.
     *
     * والفاتورةُ تحمل اسمَ عميلها ورقمَه الضريبيَّ منسوخين فيها، لكنّها لا
     * تحمل عنوانَه ولا شروطَ سداده. فمن يراجع فاتورةَ أغسطس يحتاج دفترَ
     * العملاء بجانبها.
     *
     * والحدُّ مكتوبٌ في `README`: هذا سجلٌّ **كما هو لحظةَ التوليد** لا كما
     * كان آخرَ الشهر — العنوانُ يُصحَّح ولا يُؤرَّخ في هذا النظام.
     */
    private static function customers(Book $book, int $bid, Period $period): void
    {
        $book->head([
            __('الاسم'), __('الاسم القانوني'), __('النوع'), __('الهاتف'), __('البريد'),
            __('الرقم الضريبي'), __('السجل التجاري'), __('العنوان'),
            __('البيع الآجل'), __('حد الائتمان'), __('مدة السداد'), __('تاريخ الميلاد'),
        ]);

        $rows = Customer::where('business_id', $bid)
            ->where('created_at', '<', $period->next())
            ->orderBy('id');

        foreach ($rows->cursor() as $customer) {
            $book->row([
                $customer->name,
                $customer->legal_name ?? '—',
                $customer->customer_type ?? '—',
                Text::of($customer->phone ?? '—'),
                $customer->email ?? '—',
                Text::of($customer->tax_number ?? '—'),
                Text::of($customer->commercial_registration ?? '—'),
                $customer->address ?? $customer->billing_address ?? '—',
                $customer->allow_credit_sales ? __('نعم') : __('لا'),
                Amount::of($customer->credit_limit ?? 0),
                $customer->payment_terms_days !== null ? (int) $customer->payment_terms_days : '—',
                // والتنبيهُ والملاحظةُ لا يخرجان في ورقة — انظر CustomerFlags
                Text::of(\App\Support\CustomerFlags::formatBirthday($customer) ?: '—'),
            ]);
        }
    }

    /* =========================== الموردون =========================== */

    private static function suppliers(Book $book, int $bid): void
    {
        $book->head([__('الاسم'), __('الهاتف'), __('البريد'), __('مسؤول التواصل'), __('ملاحظات')]);

        foreach (Supplier::where('business_id', $bid)->orderBy('id')->cursor() as $supplier) {
            $book->row([
                $supplier->name,
                Text::of($supplier->phone ?? '—'),
                $supplier->email ?? '—',
                $supplier->contact_person ?? '—',
                $supplier->notes ?? '—',
            ]);
        }
    }

    /* =========================== الموظفون =========================== */

    /**
     * الموظّفون بأدوارهم — بلا كلمةِ مرورٍ ولا رمزِ تذكّر.
     *
     * والأعمدةُ تُسمّى واحدًا واحدًا لا `$user->toArray()`: عمودٌ سرّيٌّ
     * يُضاف غدًا يدخل الملفَّ صامتًا لو كُتب الصفُّ كلُّه.
     */
    private static function employees(Book $book, int $bid): void
    {
        $book->head([__('الاسم'), __('البريد'), __('الهاتف'), __('الدور'), __('تاريخ الإضافة')]);

        foreach (User::where('business_id', $bid)->orderBy('id')->cursor() as $user) {
            $book->row([
                $user->name,
                $user->email ?? '—',
                Text::of($user->phone ?? '—'),
                Roles::label($user->role),
                optional($user->created_at)->format('Y-m-d') ?? '—',
            ]);
        }
    }

    /* ============================ الرواتب ============================ */

    private static function payroll(Book $book, int $bid, Period $period): void
    {
        $book->head([
            __('المسيرة'), __('الفترة'), __('الموظف'), __('الأساسي'), __('البدلات'),
            __('الإضافي'), __('الاستقطاعات'), __('الصافي'), __('طريقة الدفع'),
            __('مدفوع'), __('حالة المسيرة'),
        ]);

        $runs = PayrollRun::where('business_id', $bid)
            ->whereBetween('created_at', [$period->start(), $period->end()])
            ->orderBy('id')->get();

        foreach ($runs as $run) {
            foreach ($run->lines()->orderBy('id')->cursor() as $line) {
                $book->row([
                    Text::of($run->number),
                    Text::of($run->period),
                    $line->employee_name ?? '—',
                    Amount::of($line->basic),
                    Amount::of($line->allowances),
                    Amount::of($line->overtime),
                    Amount::of($line->deductions),
                    Amount::of($line->net),
                    $line->payment_method ?? '—',
                    $line->paid ? __('نعم') : __('لا'),
                    $run->status ?? '—',
                ]);
            }
        }
    }

    /* ============================ المالية ============================ */

    /**
     * ميزانُ المراجعة حتّى آخر الفترة — من `Ledger` لا من جمعٍ هنا.
     *
     * وهو مصدرُ الحقيقة الماليّ في هذا النظام: الشاشةُ تقرؤه، والتقريرُ
     * يقرؤه، فتقرؤه الورقةُ. وجمعٌ مستقلٌّ من `journal_lines` يفترق عنه يومَ
     * يُضاف حسابٌ أو يُبدَّل جانبُه الطبيعيّ.
     */
    private static function finance(Book $book, int $bid, Period $period): void
    {
        $book->head([__('الرمز'), __('الحساب'), __('النوع'), __('مدين'), __('دائن'), __('الرصيد')]);

        $balance = Ledger::trialBalance($bid, $period->end());

        foreach ($balance['accounts'] ?? [] as $account) {
            // حسابٌ لم يتحرّك قطُّ لا يُكتب: شجرةُ الحسابات ليست ميزانًا
            if ((float) $account['debit'] === 0.0 && (float) $account['credit'] === 0.0) {
                continue;
            }

            $book->row([
                Text::of((string) $account['code']),
                $account['name'],
                $account['type'],
                Amount::of($account['debit']),
                Amount::of($account['credit']),
                Amount::of($account['balance']),
            ]);
        }
    }

    /**
     * أسماءُ الفروع بمعرّفاتها — تُقرأ مرّةً قبل الحلقة.
     *
     * و`Branch::find` داخل حلقةٍ على عشرين ألف حركة يُطلق عشرين ألف
     * استعلام. وهي أكثرُ ما يُبطئ التصديرَ الكبير، ولا تظهر في اختبارٍ
     * ببضعة صفوف.
     */
    private static function branchNames(int $bid): array
    {
        return Branch::where('business_id', $bid)->pluck('name', 'id')->all();
    }

    /** يُستعمل في الحرّاس: هل في هذا الجدول صفٌّ لهذا المتجر في هذه الفترة؟ */
    public static function countFor(string $name, int $bid, Period $period): int
    {
        return match ($name) {
            'Sales' => Order::where('business_id', $bid)->where('is_held', false)
                ->whereBetween('ordered_at', [$period->start(), $period->end()])->count(),
            'Customer-Invoices' => CustomerInvoice::where('business_id', $bid)
                ->whereIn('status', [CustomerInvoice::ISSUED, CustomerInvoice::CANCELLED])
                ->whereBetween('issued_at', [$period->start(), $period->end()])->count(),
            default => 0,
        };
    }

    /** استعلامُ فواتيرِ الفترة — يقرؤه بناءُ الـPDF كما تقرؤه الورقة */
    public static function issuedInvoices(int $bid, Period $period): Builder
    {
        return CustomerInvoice::where('business_id', $bid)
            ->where('status', CustomerInvoice::ISSUED)
            ->whereBetween('issued_at', [$period->start(), $period->end()])
            ->orderBy('id');
    }

    /** لا يُستعمل إلّا في الحرّاس — يثبت أنّ الاستعلامات مقيّدةٌ بالمتجر */
    public static function tables(): array
    {
        return array_keys(self::FILES);
    }

    /** عددُ صفوف جدولٍ للمتجر كلِّه — يقرؤه `README` ليقول كم في كلّ قسم */
    public static function totalRows(string $table, int $bid): int
    {
        return (int) DB::table($table)->where('business_id', $bid)->count();
    }
}
