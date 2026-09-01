<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * الجسر بين ما يقع في المحلّ وما يُكتب في دفتر الأستاذ.
 *
 * كان في النظام دفتران لا يلتقيان:
 *
 *   `transactions` — دفتر صندوقٍ بسيط: دخلٌ ومصروف. تكتب فيه نقطةُ البيع
 *   وشاشةُ المصروفات، وتقرأ منه التقارير والأرباح.
 *
 *   `journal_entries` — قيدٌ مزدوج بشجرة حسابات وميزان مراجعة. تكتب فيه
 *   سنداتُ الموردين والرواتب والأصول الثابتة وتسويات المخزون والقيود اليدوية.
 *
 * فالمبيعات لم تكن تصل إلى الثاني أبدًا. ونتيجتُه على متجرٍ حقيقيّ: شجرةٌ
 * فيها «إيراد المبيعات» برصيد صفر بينما باع صاحبُها، ومخزونٌ يزيد بالشراء
 * ولا ينقص بالبيع، ورواتبُ وإهلاكٌ بلا إيرادٍ يقابلها. وميزانُ المراجعة
 * يقول «متوازن» — وهو متوازنٌ فعلًا، لأنّ كلّ قيدٍ كُتب متوازن — فيطمئنّ
 * التاجر إلى دفترٍ ليس فيه عملُه.
 *
 * وأثبتُّه على الإنتاج قبل أن أكتب هذا: متجرٌ حقيقيّ فيه ١٠٦ ر.ع مبيعاتٍ في
 * دفتر الصندوق، و«إيراد المبيعات» في شجرته صفر. والمتجر التجريبيّ وحده
 * كانت قيودُه كاملة — لأنّ البذرة تكتب الاثنين — فكان العرض يُظهر ميزةً لا
 * وجود لها عند من يشتري.
 *
 * ولا يُعدّ المبلغ مرّتين: التقارير والأرباح تُقرأ من `transactions`
 * و`expenses` كما كانت، والقيد هنا للدفتر المحاسبيّ وحده.
 */
class Books
{
    /** مصدرُ القيود المكتوبة من هنا — به تُعرَف وتُحذف */
    public const SALE = 'مبيعات';

    public const SALE_COST = 'تكلفة مبيعات';

    public const EXPENSE = 'مصروف';

    /**
     * قيدا البيعة: الإيراد وتكلفتُه.
     *
     * ولا يُكتبان مرّتين — `sourceable` هو الشاهد، فإعادةُ النداء (من أمر
     * الاستدراك، أو من محاولةٍ ثانية) لا تُضاعف الدفتر.
     */
    public static function recordSale(Order $order): void
    {
        if (self::hasEntry($order)) {
            return;
        }

        $subtotal = round((float) $order->subtotal - (float) $order->discount + (float) $order->delivery_fee, 3);
        $tax = round((float) $order->tax, 3);
        $total = round((float) $order->total, 3);

        if ($total <= 0) {
            return;
        }

        /*
         * الطرف المدين يتبع ما وقع فعلًا.
         *
         * فاتورةٌ لم تُدفع ليست نقدًا في الدرج بل ذمّةً على العميل. وبطاقةٌ
         * أو تحويلٌ يدخلان البنك لا الصندوق — وخلطُهما يجعل تسوية كشف البنك
         * مستحيلة، وهي شاشةٌ قائمة في النظام.
         */
        $debit = match (true) {
            (string) $order->payment_status === 'غير مدفوع' => 'receivable',
            in_array((string) $order->payment_method, ['نقدي', 'كاش'], true) => 'cash',
            default => 'bank',
        };

        $at = Carbon::parse($order->ordered_at ?? $order->created_at);
        $cost = round(self::costOf($order), 3);

        DB::transaction(function () use ($order, $debit, $total, $subtotal, $tax, $cost, $at) {
            $lines = [['account' => $debit, 'debit' => $total]];

            if ($subtotal > 0) {
                $lines[] = ['account' => 'sales', 'credit' => $subtotal];
            }
            if ($tax > 0) {
                $lines[] = ['account' => 'tax_payable', 'credit' => $tax];
            }

            Ledger::post(
                $order->business_id,
                __('بيع — فاتورة ').$order->number,
                $lines,
                $at,
                self::SALE,
                $order->branch_id,
                $order->user_id,
                $order,
            );

            // البضاعة تخرج من المخزون بتكلفتها لا بثمنها — وبلا هذا القيد
            // ينتفخ المخزون في الميزانية بكلّ ما بيع منه
            if ($cost > 0) {
                Ledger::post(
                    $order->business_id,
                    __('تكلفة بيع — فاتورة ').$order->number,
                    [
                        ['account' => 'cogs', 'debit' => $cost],
                        ['account' => 'inventory', 'credit' => $cost],
                    ],
                    $at,
                    self::SALE_COST,
                    $order->branch_id,
                    $order->user_id,
                    $order,
                );
            }
        });
    }

    /** الإلغاء يمحو قيدَي بيعته — كما يمحو قيدَ دخلها */
    public static function forgetSale(Order $order): void
    {
        self::entriesFor($order)->each(fn (JournalEntry $e) => $e->delete());
    }

    /**
     * تكلفة البضاعة المباعة في هذه الفاتورة.
     *
     * بالقاعدة نفسها التي تحتسب بها التقارير: اللقطةُ أوّلًا وبطاقةُ المنتج
     * لما بيع قبل وجودها، وتكلفةُ الإضافات معها. وقاعدتان لرقمٍ واحد تعنيان
     * دفترًا يخالف تقريره.
     *
     * @see Demo::reportSummary
     */
    public static function costOf(Order $order): float
    {
        $order->loadMissing('items.addons');
        $cards = Product::where('business_id', $order->business_id)->pluck('cost', 'id');
        $cost = 0.0;

        foreach ($order->items as $item) {
            $unit = (float) $item->cost;
            if ($unit <= 0) {
                $unit = (float) ($cards[$item->product_id] ?? 0);
            }
            $cost += $unit * (int) $item->quantity;

            foreach ($item->addons as $addon) {
                $cost += (float) $addon->cost * (int) $addon->quantity;
            }
        }

        return $cost;
    }

    /* ------------------------------ المصروف ------------------------------ */

    /**
     * قيدُ المصروف — يوم خروج المال لا يوم تسجيل الورقة.
     *
     * فاتورةٌ سُجّلت اليوم وتُدفع بعد أسبوع ليست نقدًا خرج من الدرج، ولذلك
     * يُنادى هذا عند السداد لا عند التسجيل.
     */
    public static function recordExpense(Expense $expense): void
    {
        if (self::hasEntry($expense)) {
            return;
        }

        $amount = round((float) $expense->amount, 3);
        if ($amount <= 0) {
            return;
        }

        Ledger::post(
            $expense->business_id,
            __('مصروف: ').$expense->type,
            [
                ['account' => self::expenseAccount($expense->type, $expense->business_id), 'debit' => $amount, 'memo' => $expense->description],
                ['account' => self::payingAccount($expense->method), 'credit' => $amount],
            ],
            Carbon::parse($expense->spent_at ?? now()),
            self::EXPENSE,
            null,
            null,
            $expense,
        );
    }

    public static function forgetExpense(Expense $expense): void
    {
        self::entriesFor($expense)->each(fn (JournalEntry $e) => $e->delete());
    }

    /**
     * دليلُ الاسم إلى الحساب — لمن لم يختر حسابًا لنوعه.
     *
     * قائمةٌ في الكود لا تكفي وحدها: النوع يكتبه التاجر بيده، ومن كتب
     * «كهرباء» بدل «كهرباء وماء» يسقط منها. فهذه للأنواع الافتراضية التي
     * يبدأ بها كلّ متجر، والاختيار المحفوظ على النوع يسبقها.
     *
     * والرواتب ليست منها عمدًا: مسيرةُ الرواتب تُرحّل إلى «الرواتب والأجور»
     * بنفسها، فربطُ نوعٍ مكتوبٍ باليد بالحساب نفسه يجعل راتبًا واحدًا
     * يُقيَّد مرّتين. ومن كتبه هنا يريد مصروفًا نقديًّا لا مسيرة.
     */
    public const TYPE_ACCOUNTS = [
        'إيجار' => 'rent',
        'كهرباء وماء' => 'utilities',
        'تسويق' => 'marketing',
        'صيانة' => 'maintenance',
        'نقل وتوصيل' => 'transport',
        'مواد خام' => 'direct_purchases',
    ];

    /**
     * الحسابات التي يجوز للتاجر أن يربط نوع مصروفٍ بها — ولا شيء سواها.
     *
     * قائمةٌ مغلقة لا شجرةٌ مفتوحة: من يربط مصروفًا بحساب «الصندوق» أو
     * «إيراد المبيعات» يقلب قيدَه رأسًا على عقب، والدفتر يتوازن ويكذب.
     * والرواتب ليست منها — مسيرةُ الرواتب تُرحّل بنفسها.
     *
     * والاسم يُقرأ من الشجرة الافتراضية لا يُكتب هنا مرّتين.
     */
    public const EXPENSE_ACCOUNTS = [
        'rent', 'utilities', 'marketing', 'maintenance',
        'transport', 'direct_purchases', 'other_expenses',
    ];

    /**
     * خياراتُ الحساب كما تُعرض في الشاشة — مفتاحٌ واسمٌ عربيّ.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function expenseAccountOptions(): array
    {
        $names = [];

        foreach (Ledger::DEFAULT_CHART as [, , , , , $children]) {
            foreach ($children as [, $name, , , $key]) {
                $names[$key] = $name;
            }
        }

        return array_map(
            fn (string $key) => ['key' => $key, 'label' => __($names[$key] ?? $key)],
            self::EXPENSE_ACCOUNTS,
        );
    }

    /**
     * حسابُ المصروف — اختيارُ التاجر أوّلًا، ثمّ اسمُ النوع، ثمّ «أخرى».
     *
     * كان يقرأ الاسم وحده ويطابقه بسطرٍ واحد، فيسقط كلّ ما عدا الإيجار في
     * «مصروفات أخرى»: دفترٌ يعرف أنّ المال خرج ولا يعرف من أيّ باب.
     *
     * والمعرّف يصل ليُسأل جدولُ الأنواع عن اختيار صاحبه — ونوعٌ لا يُعرف
     * صاحبُه يُقرأ باسمه وحده، فلا يسقط الترحيل لأجل معرّفٍ غائب.
     */
    public static function expenseAccount(?string $type, ?int $businessId = null): string
    {
        $name = trim((string) $type);

        if ($businessId !== null && $name !== '') {
            $chosen = ExpenseType::where('business_id', $businessId)
                ->where('name', $name)->value('account_key');

            if (in_array($chosen, self::EXPENSE_ACCOUNTS, true)) {
                return $chosen;
            }
        }

        return self::TYPE_ACCOUNTS[$name] ?? 'other_expenses';
    }

    /** من أين خرج المال — والافتراض الصندوق */
    public static function payingAccount(?string $method): string
    {
        return in_array(trim((string) $method), ['تحويل', 'بطاقة', 'شيك', 'بنك'], true) ? 'bank' : 'cash';
    }

    /* ------------------------------ أدوات ------------------------------ */

    private static function entriesFor($model)
    {
        return JournalEntry::where('sourceable_type', $model::class)
            ->where('sourceable_id', $model->id)->get();
    }

    private static function hasEntry($model): bool
    {
        return JournalEntry::where('sourceable_type', $model::class)
            ->where('sourceable_id', $model->id)->exists();
    }
}
