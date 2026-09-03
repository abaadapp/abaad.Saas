<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseType;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * الدفتران يُكتبان معًا — الحركة التشغيلية وقيدها المحاسبيّ.
 *
 * في النظام دفتران: `transactions` يقول «خرج من الدرج ثلاثون ريالًا اليوم»،
 * و`journal_entries` يقول «مدين مصروفات ثلاثون / دائن الصندوق ثلاثون».
 * الأوّل يقرؤه التاجر، والثاني يقرؤه المحاسب — وهما عن الحدث نفسه.
 *
 * وكانا يُكتبان من أبوابٍ متفرّقة: شاشة المالية تكتب في الأوّل وحده، وصرفُ
 * الرواتب يكتب في الثاني وحده، والمصروف يكتب في الأوّل ويُسمّي الدالّة
 * `postToLedger` وهي لا ترحّل شيئًا. فيقرأ التاجر مصروفًا لا أثر له في ميزان
 * المراجعة، ويقرأ المحاسب قيدًا لا يجد له سطرًا في الحركة.
 *
 * فصار البابُ واحدًا: كلّ حركةٍ تمرّ من هنا فتُكتب في الدفترين في معاملةٍ
 * واحدة — أو لا تُكتب في أيٍّ منهما.
 *
 * والتاجر لا يُسأل عن مدينٍ ودائن. يُسأل: **ماذا حدث؟** — مصروف، دخل آخر،
 * إيداعُ مالٍ من المالك، سحبٌ له، تحويلٌ بين الصندوق والبنك. والوصفةُ في
 * `MOVEMENTS` تترجم جوابه إلى قيدٍ صحيح، فلا يعرف عن الحسابات شيئًا ويصحّ
 * دفترُه.
 */
class Books
{
    /** الصندوق والبنك — الوجهتان الوحيدتان اللتان يُسأل عنهما التاجر */
    public const CASH = 'cash';

    public const BANK = 'bank';

    /**
     * وصفة كل حركة.
     *
     * `debit` و`credit` إمّا مفتاحٌ نظاميّ في الشجرة، وإمّا `'@side'` أي
     * «الجهة التي اختارها التاجر» — الصندوق أو البنك — وإمّا `'@expense'` أي
     * «حساب نوع المصروف».
     *
     * `direction` اتّجاه المال كما يُكتب في `transactions.type`: «دخل» لِما
     * يدخل، و«مصروف» لِما يخرج، و«تحويل» لِما لا يدخل ولا يخرج بل ينتقل.
     *
     * `asks` سؤال الشاشة عن الجهة — و`null` يعني أنّ النوع نفسه يحدّدها.
     */
    public const MOVEMENTS = [
        'expense' => [
            'label' => 'مصروف',
            'hint' => 'مالٌ خرج مقابل شيءٍ للمتجر — إيجار، كهرباء، صيانة',
            'direction' => 'مصروف',
            'asks' => 'من أين خرج المال؟',
            // حسابُ نوعه إن رُبط، وإلا «مصروفات أخرى» — انظر expenseAccountFor
            'debit' => '@expense',
            'credit' => '@side',
        ],
        'other_income' => [
            'label' => 'دخل آخر (غير المبيعات)',
            'hint' => 'مالٌ دخل من غير البيع — تعويض، إيجار محلٍّ تملكه، فرق عملة',
            'direction' => 'دخل',
            'asks' => 'أين دخل المال؟',
            'debit' => '@side',
            'credit' => 'other_income',
        ],
        'owner_deposit' => [
            'label' => 'إيداع نقدي من المالك',
            'hint' => 'مالٌ وضعه المالك في المتجر — ليس بيعًا ولا دخلًا',
            'direction' => 'دخل',
            'asks' => 'أين وُضع المال؟',
            'debit' => '@side',
            'credit' => 'capital',
        ],
        'owner_withdrawal' => [
            'label' => 'سحب مال للمالك',
            'hint' => 'مالٌ أخذه المالك لنفسه — لا يُنقص ربح المتجر',
            'direction' => 'مصروف',
            'asks' => 'من أين أُخذ المال؟',
            'debit' => 'drawings',
            'credit' => '@side',
        ],
        'cash_to_bank' => [
            'label' => 'تحويل من الصندوق إلى البنك',
            'hint' => 'المال ينتقل ولا يدخل ولا يخرج — لا يمسّ الربح',
            'direction' => 'تحويل',
            'asks' => null,
            'debit' => 'bank',
            'credit' => 'cash',
        ],
        'bank_to_cash' => [
            'label' => 'تحويل من البنك إلى الصندوق',
            'hint' => 'المال ينتقل ولا يدخل ولا يخرج — لا يمسّ الربح',
            'direction' => 'تحويل',
            'asks' => null,
            'debit' => 'cash',
            'credit' => 'bank',
        ],
    ];

    /**
     * حركاتٌ يكتبها النظام عن مستنداتها — لا تُسجَّل من شاشة المالية.
     *
     * البيع تكتبه نقطة البيع، والسداد يكتبه سندُ المورّد، والصرفُ تكتبه
     * مسيرة الرواتب. وتسجيلُ أيٍّ منها يدويًّا يعني الحدث مرّتين في الدفتر.
     */
    public const AUTOMATIC = [
        Transaction::SALE => 'مبيعات',
        'supplier_payment' => 'سداد مورّد',
        'payroll_payment' => 'صرف رواتب',
    ];

    /**
     * الوسيلة تتبع الجهة.
     *
     * `Bank::transactions` تبني كشف المطابقة من الوسيلة لا من الحساب، فحركةٌ
     * بنكية بوسيلة «نقدي» تغيب عن المطابقة — ويظهر سطر البنك بلا ما يقابله
     * فيُقرأ فرقًا.
     */
    public static function methodFor(string $side): string
    {
        return $side === self::BANK ? 'تحويل بنكي' : 'نقدي';
    }

    /** الوسيلة → الجهة: ما ليس نقدًا مرّ بالبنك */
    public static function sideForMethod(?string $method): string
    {
        return ($method ?? 'نقدي') === 'نقدي' ? self::CASH : self::BANK;
    }

    /** أنواع الحركة التي تُسجَّل يدويًّا — والبيع ليس منها */
    public static function manualKinds(): array
    {
        return array_keys(self::MOVEMENTS);
    }

    /**
     * ما تعرضه الشاشة: النوع وسؤاله وشرحه — بلا حسابٍ ولا طرف.
     *
     * الوصفة المحاسبية تبقى هنا ولا تُرسل: الواجهة التي تعرف الحسابات تُغري
     * بأن تختار منها، وأوّلُ اختيارٍ يكسر القاعدة التي بُني عليها هذا الملفّ.
     */
    public static function movementOptions(): array
    {
        $out = [];

        foreach (self::MOVEMENTS as $key => $m) {
            $out[] = [
                'value' => $key,
                'label' => __($m['label']),
                'hint' => __($m['hint']),
                'asks' => $m['asks'] ? __($m['asks']) : null,
                'direction' => $m['direction'],
            ];
        }

        return $out;
    }

    /** اسم النوع كما يُقرأ — والقديم الذي لا نوع له يبقى «حركة» */
    public static function label(?string $kind): string
    {
        if (isset(self::MOVEMENTS[$kind])) {
            return __(self::MOVEMENTS[$kind]['label']);
        }

        return isset(self::AUTOMATIC[$kind]) ? __(self::AUTOMATIC[$kind]) : __('حركة');
    }

    /**
     * صفٌّ تشغيليّ لقيدٍ كتبه مستندُه — لا قيدٌ لصفّ.
     *
     * صرفُ الرواتب وسدادُ المورّد يُرحَّلان إلى دفتر الأستاذ ولا يكتبان شيئًا
     * في `transactions`: مالٌ خرج من الدرج فعلًا ولا يظهر في «الحركة المالية»
     * ولا في مطابقة كشف البنك — فيرى المحاسب سطر البنك بلا ما يقابله ويقرؤه
     * فرقًا، ويقرأ التاجر شهرًا أنفق فيه ألفين وشاشتُه تقول ثلاثمئة.
     *
     * والصفّ يتبع القيد لا العكس: هذه الأحداث مستنداتُها في مكانٍ آخر، وما
     * يُكتب هنا صورةُ حركتها النقدية. فإن كان للقيد صفٌّ فلا يُكتب ثانٍ.
     */
    public static function mirror(
        JournalEntry $entry,
        string $kind,
        float $amount,
        string $side,
        string $description,
        ?string $employee = null,
    ): ?Transaction {
        if (Transaction::withTrashed()->where('journal_entry_id', $entry->id)->exists()) {
            return null;
        }

        return Transaction::create([
            'business_id' => $entry->business_id,
            'branch_id' => $entry->branch_id,
            'reference' => Transaction::nextReference($entry->business_id),
            'description' => $description,
            'method' => self::methodFor($side),
            'type' => 'مصروف',
            'kind' => $kind,
            'journal_entry_id' => $entry->id,
            'amount' => round($amount, 3),
            'employee_name' => $employee,
            'occurred_at' => $entry->entry_date,
        ]);
    }

    /* ============================ بيع نقطة البيع ============================ */

    /**
     * ترحيل بيعةٍ إلى دفتر الأستاذ — بالمخزون المستمرّ.
     *
     * قيدٌ واحد للفاتورة كلّها، لا قيدان: النقد والإيراد والضريبة والتكلفة
     * والمخزون في مستندٍ واحد. وقيدٌ واحد يُعكَس بضغطةٍ واحدة حين تُلغى
     * الفاتورة؛ وقيدان يُنسى ثانيهما فيبقى نصفُ البيعة في الدفتر.
     *
     * والسطور:
     *   مدين  الصندوق أو البنك   = إجمالي الفاتورة (ما قُبض فعلًا)
     *   دائن  إيراد المبيعات     = الصافي بعد الخصم، ومعه رسوم التوصيل
     *   دائن  ضريبة مستحقّة      = الضريبة — إن كان النشاط مسجَّلًا فيها
     *   مدين  تكلفة البضاعة      = لقطةُ التكلفة يوم البيع
     *   دائن  المخزون            = المبلغ نفسه
     *
     * والضريبة تتبع **تسجيل النشاط** لا نسبةَ الصنف: متجرٌ غير مسجَّل لا
     * يُنشأ له التزامٌ ضريبيّ أصلًا، ولو حملت أصنافُه نسبًا قديمة في بطاقاتها.
     * فالمبلغ كلّه إيراد. وجبايةُ ضريبةٍ من غير المخوَّل بجبايتها خطأٌ يقع على
     * الزبون وعلى الإقرار معًا — والدفتر لا يُصلحه لكنّه لا يُثبّته.
     *
     * والتكلفة من `order_items.cost` — لقطةُ يوم البيع لا بطاقةُ المنتج
     * اليوم: المورّد يرفع سعره فتتغيّر تكلفةُ ما بيع الشهر الماضي.
     *
     * ولا تُرحَّل البيعة مرّتين: يُسأل الدفترُ عن قيدٍ حيٍّ لهذه الفاتورة قبل
     * الكتابة (`JournalEntry::live()->forSource()`)، فإعادةُ المحاولة بعد
     * انقطاعٍ لا تُضاعف إيراد اليوم.
     */
    public static function recordSale(Order $order, ?int $userId = null): ?JournalEntry
    {
        return DB::transaction(function () use ($order, $userId) {
            if (self::liveEntryFor($order)) {
                return null;
            }

            $bid = (int) $order->business_id;
            $total = round((float) $order->total, 3);
            $tax = Vat::enabled($bid) ? round((float) $order->tax, 3) : 0.0;

            /*
             * الإيراد ما بقي بعد الضريبة — يُحسب طرحًا لا جمعًا.
             *
             * `subtotal - discount + delivery` يبدو أوضح، لكنّه يفترض أنّ
             * الحقول الأربعة متّسقة دائمًا؛ وفاتورةٌ قديمة أو مصحَّحة قد تخالف.
             * والطرح من الإجمالي يُبقي القيد متوازنًا مهما كانت، وهو الشرط
             * الذي يرفضه الدفتر إن اختلّ.
             */
            $revenue = round($total - $tax, 3);

            if ($total < 0.001 && $revenue < 0.001) {
                return null;
            }

            $cost = round((float) $order->items()->selectRaw(
                'COALESCE(SUM(cost * quantity), 0) c'
            )->value('c'), 3);

            $side = self::sideForMethod($order->payment_method);

            $lines = [
                ['account' => $side, 'debit' => $total, 'memo' => $order->number],
                ['account' => 'sales', 'credit' => $revenue],
            ];

            if ($tax >= 0.001) {
                $lines[] = ['account' => 'tax_payable', 'credit' => $tax];
            }

            // بيعةُ صنفٍ لا تكلفة له مسجَّلة: لا سطرَ بصفر — الدفتر يرفضه
            if ($cost >= 0.001) {
                $lines[] = ['account' => 'cogs', 'debit' => $cost];
                $lines[] = ['account' => 'inventory', 'credit' => $cost];
            }

            $entry = Ledger::post(
                $bid,
                __('مبيعات: ').$order->number,
                $lines,
                Carbon::parse($order->ordered_at ?? now()),
                'مبيعات',
                $order->branch_id,
                $userId,
                $order,
            );

            // صفُّ الحركة يعرف قيدَه، فتقول الشاشة «مُرحَّلة» عن بيعةٍ رُحّلت
            Transaction::where('business_id', $bid)->where('order_id', $order->id)
                ->update(['journal_entry_id' => $entry->id]);

            return $entry;
        });
    }

    /**
     * القيد الحيّ لهذه الفاتورة — أو لا شيء.
     *
     * حيٌّ يعني: مربوطًا بها، لم يُعكس، وليس عكسًا لغيره. وهو السؤال الذي
     * يسبق كلّ ترحيلٍ وكلّ عكس.
     */
    public static function liveEntryFor(Order $order): ?JournalEntry
    {
        return JournalEntry::forSource($order)->live()->first();
    }

    /**
     * تصحيحُ فاتورةٍ رُحّلت: عكسٌ ثمّ ترحيلٌ بالقيم الجديدة.
     *
     * ولا تُعدَّل سطورُ القيد في مكانها — انظر `Ledger::reverse`.
     */
    public static function repostSale(Order $order, ?int $userId = null, ?string $reason = null): ?JournalEntry
    {
        return DB::transaction(function () use ($order, $userId, $reason) {
            $live = self::liveEntryFor($order);

            if ($live) {
                Ledger::reverse($live, null, $userId, $reason);
            }

            return self::recordSale($order->fresh(), $userId);
        });
    }

    /**
     * إلغاءُ فاتورةٍ رُحّلت: عكسٌ بلا ترحيلٍ بعده.
     *
     * والتاريخ يبقى: القيد الأصليّ في مكانه، وعكسُه إلى جانبه، والفاتورة في
     * سجلّ المبيعات موسومةً بالإلغاء. لا يُمحى شيء — يُلغى أثرُه فقط.
     */
    public static function unpostSale(Order $order, ?int $userId = null, ?string $reason = null): ?JournalEntry
    {
        $live = self::liveEntryFor($order);

        return $live ? Ledger::reverse($live, null, $userId, $reason) : null;
    }

    /**
     * ترحيلٌ لا يُسقط بيعةً إن تعثّر.
     *
     * البيع طريقٌ تشغيليّ حرج: الزبون واقفٌ عند الصندوق، والفاتورة طُبعت،
     * والمخزون نقص. وحسابٌ مغلقٌ في الشجرة أو شجرةٌ عُبث بها يجب ألّا يمنع
     * البيعةَ من أن تتمّ — لكنّه يجب ألّا يمرّ صامتًا: يُكتب في السجلّ برقم
     * الفاتورة، فيُستدرَك بأمر `sales:post-ledger`.
     */
    public static function trySale(Order $order, ?int $userId = null): void
    {
        try {
            self::recordSale($order, $userId);
        } catch (\Throwable $e) {
            Log::warning('تعذّر ترحيل بيعة إلى دفتر الأستاذ', [
                'order' => $order->number,
                'business_id' => $order->business_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** وكذلك التصحيح والإلغاء: لا يُحبس تصحيحٌ لأنّ الدفتر تعثّر */
    public static function tryRepostSale(Order $order, ?int $userId = null, ?string $reason = null): void
    {
        try {
            self::repostSale($order, $userId, $reason);
        } catch (\Throwable $e) {
            Log::warning('تعذّر إعادة ترحيل بيعة مصحَّحة', [
                'order' => $order->number,
                'business_id' => $order->business_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function tryUnpostSale(Order $order, ?int $userId = null, ?string $reason = null): void
    {
        try {
            self::unpostSale($order, $userId, $reason);
        } catch (\Throwable $e) {
            Log::warning('تعذّر عكس قيد فاتورة ملغاة', [
                'order' => $order->number,
                'business_id' => $order->business_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ============================== المصروف ============================== */

    /**
     * حساب المصروف حسب نوعه — و«مصروفات أخرى» لما لا حساب له.
     *
     * كان كلّ مصروفٍ يقع في 5900 مهما كان: الإيجارُ والكهرباءُ والصيانة في
     * سطرٍ واحد اسمه «أخرى» يبتلعها جميعًا، فلا تقول قائمةُ الدخل أين يذهب
     * مال المتجر. والربط يجعلها تقول — ويبقى اختياريًّا فلا ينكسر متجرٌ قائم.
     *
     * والحساب يُفحص قبل أن يُعاد: ورقةٌ أُغلقت أو صارت أبًا لغيرها لا تقبل
     * قيدًا، وردُّها هنا يُسقط تسجيلَ المصروف برسالةٍ لا يفهمها من سجّله.
     */
    public static function expenseAccountFor(int $businessId, ?string $typeName): Account|string
    {
        if (($typeName ?? '') === '') {
            return 'other_expenses';
        }

        $account = ExpenseType::where('business_id', $businessId)
            ->where('name', $typeName)->with('account')->first()?->account;

        return $account && $account->business_id === $businessId && $account->isPostable()
            ? $account
            : 'other_expenses';
    }

    /**
     * حركةٌ يدوية من شاشة المالية: صفٌّ في الحركة وقيدٌ في الدفتر معًا.
     *
     * `$data`: kind, amount, side (cash|bank)، واختياريًّا description،
     * occurred_at، branch_id، client_uuid، expense_type.
     *
     * @throws RuntimeException إن اختلّ الترحيل — والمعاملة كلّها تسقط معه
     */
    public static function recordMovement(int $businessId, array $data, ?int $userId = null, ?string $employee = null): Transaction
    {
        $kind = $data['kind'];
        $recipe = self::MOVEMENTS[$kind] ?? throw new RuntimeException(__('نوع حركةٍ غير معروف'));

        $amount = round((float) $data['amount'], 3);

        // حركةٌ بصفر لا تعني شيئًا: صفٌّ في الدفتر لا يغيّر رصيدًا ولا يُصحَّح
        if ($amount < 0.001) {
            throw new RuntimeException(__('المبلغ يجب أن يكون أكبر من صفر'));
        }

        $side = $recipe['asks'] === null ? null : ($data['side'] ?? self::CASH);
        $occurredAt = isset($data['occurred_at']) && $data['occurred_at']
            ? Carbon::parse($data['occurred_at'])
            : now();

        // الوسيلة تتبع الجهة، والتحويل يُنسب إلى البنك: هو طرفُه المُطابَق
        $method = $side !== null
            ? self::methodFor($side)
            : self::methodFor(self::BANK);

        $description = trim((string) ($data['description'] ?? '')) ?: self::label($kind);

        return DB::transaction(function () use (
            $businessId, $kind, $recipe, $amount, $side, $occurredAt, $method,
            $description, $data, $userId, $employee
        ) {
            /*
             * التكرار يُمنع بالمعرّف الذي يولّده المتصفّح.
             *
             * ضغطتان على «حفظ»، أو إعادةُ إرسالٍ بعد انقطاع، تكتبان الحركة
             * مرّتين — والمال لا يخرج مرّتين. والفحص داخل المعاملة لا قبلها:
             * طلبان متزامنان يمرّان من فحصٍ خارجها كلاهما.
             */
            if (! empty($data['client_uuid'])) {
                $existing = Transaction::where('business_id', $businessId)
                    ->where('client_uuid', $data['client_uuid'])->lockForUpdate()->first();

                if ($existing) {
                    return $existing;
                }
            }

            $transaction = Transaction::create([
                'business_id' => $businessId,
                'branch_id' => $data['branch_id'] ?? null,
                'reference' => Transaction::nextReference($businessId),
                'client_uuid' => $data['client_uuid'] ?? null,
                'description' => $description,
                'method' => $method,
                'type' => $recipe['direction'],
                'kind' => $kind,
                'amount' => $amount,
                'employee_name' => $employee,
                'occurred_at' => $occurredAt,
            ]);

            /*
             * المصروف اليدويّ يظهر في شاشة المصروفات أيضًا.
             *
             * وإلا صار للمصروف بابان: ما يُسجَّل هنا لا يُرى هناك، والتاجر
             * يقرأ «مصروفات الشهر» ناقصةً بلا أن يقول شيءٌ لماذا.
             */
            if ($kind === 'expense') {
                self::expenseFor($transaction, $data, $employee);
            }

            self::post($transaction, $recipe, $side, $userId, $data['expense_type'] ?? null);

            return $transaction->fresh();
        });
    }

    /**
     * ترحيل حركةٍ إلى دفتر الأستاذ وربطُها بقيدها.
     *
     * ولا تُرحَّل مرّتين: الحركة التي تحمل قيدًا مرحَّلًا تُترك كما هي.
     */
    private static function post(
        Transaction $transaction,
        array $recipe,
        ?string $side,
        ?int $userId,
        ?string $expenseType = null,
    ): void {
        if ($transaction->journal_entry_id && JournalEntry::whereKey($transaction->journal_entry_id)->exists()) {
            return;
        }

        $resolve = fn (string $key) => match ($key) {
            '@side' => $side ?? self::CASH,
            '@expense' => self::expenseAccountFor($transaction->business_id, $expenseType),
            default => $key,
        };

        $entry = Ledger::post(
            $transaction->business_id,
            $transaction->description,
            [
                ['account' => $resolve($recipe['debit']), 'debit' => (float) $transaction->amount],
                ['account' => $resolve($recipe['credit']), 'credit' => (float) $transaction->amount],
            ],
            Carbon::parse($transaction->occurred_at),
            self::label($transaction->kind),
            $transaction->branch_id,
            $userId,
            $transaction,
        );

        $transaction->update(['journal_entry_id' => $entry->id]);
    }

    /** صفّ المصروف المقابل لحركةٍ من شاشة المالية */
    private static function expenseFor(Transaction $transaction, array $data, ?string $employee): void
    {
        Expense::create([
            'business_id' => $transaction->business_id,
            'reference' => self::nextExpenseReference($transaction->business_id),
            'type' => $data['expense_type'] ?? __('مصروف عام'),
            'description' => $transaction->description,
            'amount' => $transaction->amount,
            'method' => $transaction->method,
            'status' => Expense::PAID,
            'employee_name' => $employee,
            'spent_at' => Carbon::parse($transaction->occurred_at)->toDateString(),
            'transaction_id' => $transaction->id,
        ]);
    }

    /**
     * مصروفٌ دُفع: صفٌّ في الحركة وقيدٌ في الدفتر.
     *
     * يُستدعى من شاشة المصروفات — عند التسجيل مدفوعًا وعند السداد لاحقًا.
     * ولا يُكرَّر: المصروف الذي يحمل حركةً مرحَّلة يُترك كما هو، فإعادةُ
     * الضغط على «سُدِّد» لا تُخرج المال مرّتين.
     */
    public static function recordExpense(Expense $expense, ?int $userId = null): Transaction
    {
        return DB::transaction(function () use ($expense, $userId) {
            $existing = $expense->transaction_id
                ? Transaction::withTrashed()->find($expense->transaction_id)
                : null;

            if ($existing && ! $existing->trashed()) {
                self::post(
                    $existing,
                    self::MOVEMENTS['expense'],
                    self::sideForMethod($existing->method),
                    $userId,
                    $expense->type,
                );

                return $existing;
            }

            $transaction = Transaction::create([
                'business_id' => $expense->business_id,
                'reference' => Transaction::nextReference($expense->business_id),
                // الوصف اختياريّ فقد يغيب عن الطلب أصلًا — لا يكفي أن يكون nullable
                'description' => $expense->type.(($expense->description ?? '') !== '' ? ' — '.$expense->description : ''),
                'method' => $expense->method,
                'type' => 'مصروف',
                'kind' => 'expense',
                'amount' => $expense->amount,
                'employee_name' => $expense->employee_name,
                'occurred_at' => $expense->spent_at,
            ]);

            $expense->update(['transaction_id' => $transaction->id]);

            self::post(
                $transaction,
                self::MOVEMENTS['expense'],
                self::sideForMethod($expense->method),
                $userId,
                $expense->type,
            );

            return $transaction->fresh();
        });
    }

    /**
     * تُمحى الحركة ويُمحى قيدُها معها.
     *
     * حذفُ المصروف من شاشته كان يُخفي صفَّه في الحركة ويترك قيدَه في الدفتر:
     * تقرأ المصروفات صفرًا، ويقرأ ميزانُ المراجعة ثلاثمئة. والقيد اليتيم لا
     * يُعرف ما كان مقابله ولا يُصحَّح.
     *
     * والقيد يُمحى لا يُعكس عمدًا: الحذف هنا تراجعٌ عن إدخالٍ خاطئ — وله في
     * الشاشة زرُّ «تراجع» — لا تصحيحُ حدثٍ وقع. وقيدٌ عكسيّ لِما أُدخل قبل
     * ثانيةٍ يملأ الدفتر بضجيجٍ لا يقرؤه أحد.
     */
    public static function unrecord(?Transaction $transaction): void
    {
        if (! $transaction) {
            return;
        }

        DB::transaction(function () use ($transaction) {
            if ($transaction->journal_entry_id) {
                JournalEntry::whereKey($transaction->journal_entry_id)->delete();
                $transaction->update(['journal_entry_id' => null]);
            }

            $transaction->delete();
        });
    }

    /** أوّل رقمٍ مرجعيّ غير مشغول — بنفس صياغة شاشة المصروفات */
    private static function nextExpenseReference(int $businessId): string
    {
        $last = Expense::withTrashed()->where('business_id', $businessId)
            ->whereNotNull('reference')->orderByDesc('id')->value('reference');

        $n = ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int) $m[1] + 1) : 1001;

        return 'EXP-'.$n;
    }
}
