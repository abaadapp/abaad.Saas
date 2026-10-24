<?php

namespace App\Support;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\PosDevice;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ما مرّ بالحساب البنكي فعلًا.
 *
 * كان كشف الحساب البنكي والمطابقةُ يقرآن كلّ معاملة أيًّا كانت وسيلتها، والنقد
 * لا يمرّ بالبنك. فكان «الرصيد الحالي» مجموعَ ما دخل المتجر وخرج منه لا رصيدَ
 * الحساب، ولا يطابق كشف البنك في ريالٍ واحد أبدًا.
 *
 * وأسوأ منه أثرًا: كانت بيعةٌ نقدية بـ٤٧٫٢٥٠ تُطابَق بإيداعٍ بنكيّ بالمبلغ
 * نفسه فيُكتب «مطابق» — فتقول الشاشة «الحساب سليم» وهي لم تقارن شيئًا. وهذه
 * الشاشة تُفتح لكشف الفرق لا لإخفائه.
 *
 * وتاريخ الرصيد الافتتاحي يُحترم هنا: الرصيد الافتتاحي يتضمّن ما قبله، فجمعُه
 * إليه يحسبه مرّتين.
 */
class Bank
{
    /** الوسائل التي تمرّ بالحساب البنكي */
    public const METHODS = ['بطاقة', 'تحويل بنكي'];

    /** مصدرُ قيد الرصيد الافتتاحيّ — به يُعرف في الدفتر */
    public const OPENING = 'رصيد افتتاحي';

    /**
     * الحساب الرئيسي — وجهةُ ما لا يُنسب إلى حسابٍ بعينه.
     *
     * صار للنشاط أكثر من حساب، و«أوّل ما يوجد» يتبدّل بترتيب الصفوف فيتبدّل
     * الكشف بلا أن يمسّه أحد. فالرئيسيّ أوّلًا، ثمّ الأقدم.
     *
     * ═══ ولا يُنشئ شيئًا ═══
     *
     * كانت تُنشئ حسابًا بنكيًّا بلا اسمٍ ولا بنكٍ ولا آيبان حين لا تجد
     * واحدًا — وهي تُستدعى من **قراءة** الشاشة. فمن فتح «المالية» مرّةً
     * واحدة وُلد في متجره صفٌّ يُعرض «حساب بنكي»، وحجب الحالةَ الفارغة
     * المكتوبة في الشاشة: «أضف حساب نشاطك البنكي…» — دعوةٌ لا تظهر أبدًا.
     *
     * وأسوأ منه أثرًا: هذا الصفُّ يملك ورقة «البنك» (1200) في الشجرة، فكلُّ
     * ترحيلٍ بنكيٍّ يقع فيها. ثمّ يضيف التاجر حسابَه الحقيقيّ فيأخذ ورقةً
     * أختًا لا يدخلها شيء — فيقرأ رصيدًا لا يتحرّك، ورصيدًا يتحرّك لحسابٍ
     * لا يعرفه.
     */
    public static function current(int $businessId): ?BankAccount
    {
        return BankAccount::where('business_id', $businessId)
            ->orderByDesc('is_primary')->orderBy('id')->first();
    }

    /**
     * ورقةُ الحساب البنكيّ في الشجرة — وجهةُ الترحيل.
     *
     * ═══ ولماذا لا يكفي المفتاح النظاميّ ═══
     *
     * كلُّ ترحيلٍ بنكيٍّ في النظام يقول `'bank'`، والمفتاح يقصد ورقةً واحدة
     * (1200) يملكها **أوّل** حسابٍ بنكيّ وُجد. و`BankAccount::balance()`
     * تقرأ ورقةَ حسابها هي. فمتجرٌ بحسابين يقرأ رصيدَ حسابه الرئيسيّ لا
     * يتحرّك مهما حُصِّل فيه، والمالُ كلُّه في ورقة حسابٍ آخر.
     *
     * وهذا لا يُصلح في مواضع الترحيل — سبعةٌ منها تُنسى ثامنتُها — بل في
     * السطر الذي يقرأ الحساب: `Ledger::post`.
     *
     * وورقةٌ مغلقةٌ أو ذاتُ أبناءٍ لا يُرحَّل إليها، فتُردّ `null` ويسقط
     * الترحيل إلى الورقة النظامية: قيدٌ في مكانٍ غير دقيق خيرٌ من بيعةٍ
     * تسقط في وجه الكاشير.
     */
    public static function leaf(int $businessId, int|string|null $bankAccountId = null): ?Account
    {
        $account = ($bankAccountId !== null && $bankAccountId !== '')
            ? BankAccount::where('business_id', $businessId)->whereKey($bankAccountId)->first()
            : self::current($businessId);

        $leaf = $account?->account;

        return $leaf && $leaf->isPostable() ? $leaf : null;
    }

    /**
     * مجموعُ ما في البنك — رقمٌ واحد لكلّ شاشةٍ تسأله.
     *
     * ═══ والموقوفةُ تُجمع مع المفعّلة ═══
     *
     * حسابٌ أُوقف قد يبقى فيه رصيد، وإخفاؤه من «أين المال الآن» يجعل الشاشة
     * تقول رقمًا أصغر ممّا في الدفتر بلا أن تقول لماذا. وكانت شاشة الحسابات
     * تجمع المفعّلة وحدها، والملخّصُ يجمع الكلّ — ويقول تعليقُه إنّهما
     * متّفقان. شاشتان ترسمان «مجموع الأرصدة» برقمين.
     *
     * ═══ وما لا يملكه حسابٌ يُعدّ ═══
     *
     * متجرٌ باع بالبطاقة ولم يسجّل حسابه البنكيّ بعد: مالُه في ورقة «البنك»
     * (1200) ولا حسابَ يملكها، فجمعُ الحسابات وحدها يقرأ صفرًا والميزانيةُ
     * تقرأ المال. ولا يُعدُّ مرّتين: يُضاف حين لا يملكه أحد وحده.
     */
    public static function total(int $businessId): float
    {
        $accounts = BankAccount::where('business_id', $businessId)->with('account')->get();

        // أرصدةُ الأوراق كلِّها باستعلامٍ واحد — انظر `Account::balancesFor`
        $balances = Account::balancesFor($accounts->pluck('account'));
        $sum = (float) $accounts->sum(fn (BankAccount $a) => $balances[$a->account_id] ?? 0.0);

        $system = Account::where('business_id', $businessId)->where('system_key', 'bank')->first();
        $owned = $accounts->pluck('account_id')->filter()->map(fn ($id) => (int) $id)->all();

        if ($system && ! in_array($system->id, $owned, true)) {
            $sum += $system->balance();
        }

        return round($sum, 3);
    }

    /**
     * قيدُ الرصيد الافتتاحيّ للحساب البنكيّ — يُكتب ويُصحَّح من هنا وحده.
     *
     * ═══ لماذا صار يُقيَّد ═══
     *
     * كان الافتتاحيّ خارج الدفتر: `BankAccount::balance()` تجمعه على رصيد
     * الورقة، فتقول شاشةُ البنوك ٦٠٥ وتقول الميزانيةُ ١٠٥ — والفرقُ مالٌ
     * حقيقيّ لا يظهر في أيّ حساب. وثلاثةُ حساباتٍ على الإنتاج كلُّها كذلك.
     * والتاجرُ يقارن الشاشتين ولا يجد ما يفسّر الفرق.
     *
     * ومقابلُه حقوقُ الملكية لا الإيراد: المالُ الذي كان في البنك قبل أوّل
     * يومٍ ليس دخلَ هذا الشهر. فقائمةُ الدخل لا تتحرّك، والميزانيةُ تتوازن.
     *
     * ═══ والتصحيح عكسٌ لا تعديل ═══
     *
     * التاجر يصحّح الافتتاحيّ بعد أن يقرأ كشفه. فيُعكس القيدُ القديم ويُكتب
     * جديد — لا تُغيَّر سطورُ قيدٍ مُرحَّل في مكانها. ولا يُكتب شيءٌ إن لم
     * يتغيّر المبلغ: حفظُ الاسم وحده لا يُنشئ قيدين.
     *
     * وورقةٌ غير قابلةٍ للترحيل (مغلقة أو صارت أبًا) تُترك بلا قيد: حفظُ
     * بيانات الحساب لا يُردّ في وجه التاجر لأجل شكل شجرته.
     */
    public static function syncOpening(BankAccount $account, ?int $userId = null): void
    {
        $wanted = round((float) $account->opening_balance, 3);
        $leaf = self::leaf((int) $account->business_id, $account->id);

        /*
         * وورقةٌ لا يُرحَّل إليها لا يُمسّ قيدُها.
         *
         * حسابٌ أُغلق في الشجرة أو صار له فروع: لو مضينا لعُكس القيدُ القديم
         * ثمّ سقطت كتابةُ الجديد — فيُمحى الافتتاحيُّ من الدفتر صامتًا لأنّ
         * التاجر حفظ اسم بنكه. فلا يُعكس ما لا يُعاد كتابته.
         */
        if (! $leaf) {
            return;
        }

        $live = Books::liveEntriesFor($account)->load('lines');

        /*
         * ما في الدفتر الآن لهذا الحساب — سطورُ **ورقته** وحدها، بإشارة المدين.
         *
         * وجمعُ سطور القيد كلِّها يخرج صفرًا دائمًا: القيدُ متوازن بطبعه. فلو
         * قيس به لَقال إنّ المبلغ المكتوب صفرٌ أبدًا، فأُعيد الترحيل في كلّ
         * حفظ — ويولد للحساب الواحد قيدُ افتتاحٍ في كلّ مرّةٍ يُحفظ فيها اسمُه.
         */
        $posted = round((float) $live->sum(
            fn (JournalEntry $e) => $e->lines->where('account_id', $leaf->id)->sum('debit')
                - $e->lines->where('account_id', $leaf->id)->sum('credit')
        ), 3);

        /*
         * والقيدُ يتبع ورقتَه إن تبدّلت.
         *
         * ورقةُ الحساب البنكيّ ليست ثابتة: صفٌّ بلا ورقة يُستدرك فيأخذ واحدة،
         * وترحيلُ «الحساب بلا اسم» نقل ورقةً من صفٍّ إلى صفّ. فلو قيس الاتفاقُ
         * بالمبلغ وحده لبقي الافتتاحيُّ مدينًا لورقةٍ هجرها صاحبُها: يقرأ
         * التاجر رصيدًا ناقصًا، ويجلس ماله في حسابٍ لا يملكه أحد.
         */
        $inPlace = $live->every(fn (JournalEntry $e) => $e->lines->contains('account_id', $leaf->id));

        if ($inPlace && abs($posted - $wanted) < 0.0005) {
            return;
        }

        $live->each(fn (JournalEntry $e) => Ledger::reverse($e, null, $userId, __('تصحيح الرصيد الافتتاحي')));

        if (abs($wanted) < 0.0005) {
            return;
        }

        $equity = Ledger::account((int) $account->business_id, 'opening_balance_equity');

        // وحقوقُ الملكية كذلك: الطرفان يُرحَّل إليهما أو لا قيد
        if (! $equity?->isPostable()) {
            return;
        }

        $side = $wanted > 0;

        Ledger::post(
            (int) $account->business_id,
            __('رصيد افتتاحي — ').$account->displayName(),
            [
                ['account' => $leaf, $side ? 'debit' : 'credit' => abs($wanted)],
                ['account' => $equity, $side ? 'credit' : 'debit' => abs($wanted)],
            ],
            $account->opening_date ? $account->opening_date->copy() : Carbon::parse($account->created_at ?? now()),
            self::OPENING,
            null,
            $userId,
            $account,
        );
    }

    /**
     * الحسابُ البنكيّ الذي يودع فيه صندوقٌ بعينه — وإلا الرئيسيّ.
     *
     * جهازُ الشبكة موصولٌ ببنكٍ في العتاد، فالجهازُ يقولها لا الكاشير. ومن لم
     * يُسنَد إلى حساب — أو بيعةٌ لم تخرج من جهازٍ أصلًا (طلبُ توصيلٍ، متجرٌ
     * إلكترونيّ) — يسقط إلى الرئيسيّ كما كان الحالُ دائمًا.
     */
    public static function depositFor(int $businessId, int|string|null $posDeviceId): ?int
    {
        $chosen = ($posDeviceId !== null && $posDeviceId !== '')
            ? PosDevice::where('business_id', $businessId)->whereKey($posDeviceId)->value('bank_account_id')
            : null;

        return $chosen ? (int) $chosen : self::current($businessId)?->id;
    }

    /**
     * معاملات النظام التي يُتوقّع ظهورها في كشف البنك.
     *
     * تُستثنى المعاملات السابقة لتاريخ الرصيد الافتتاحي لأنها داخلةٌ فيه.
     *
     * ═══ ولحسابٍ بعينه حين يُسأل عنه ═══
     *
     * كانت تردّ ما مرّ بالبنك كلِّه أيًّا كان الحساب، ومطابقةُ الكشف تقرأ
     * منها. فمتجرٌ بحسابين يستورد كشفَ الأوّل فيُطابَق بإيداعٍ دخل الثاني
     * ويُكتب «مطابق» — عن شيئين لم يلتقيا. وهي الشاشة التي تُفتح لكشف الفرق
     * لا لإخفائه.
     *
     * والفارغُ يمرّ مع الجميع: معاملاتٌ سبقت هذا العمود لا حسابَ عليها،
     * وحجبُها يجعل كلَّ ما قبل اليوم يبدو ناقصًا من البنك.
     */
    public static function transactions(int $businessId, int|string|null $bankAccountId = null): Builder
    {
        // وتاريخُ الافتتاح تاريخُ الحساب المسؤول عنه لا تاريخُ الرئيسيّ
        $named = ($bankAccountId !== null && $bankAccountId !== '')
            ? BankAccount::where('business_id', $businessId)->whereKey($bankAccountId)->first()
            : null;
        $openingDate = ($named ?? self::current($businessId))?->opening_date;

        return Transaction::query()
            ->where('business_id', $businessId)
            ->whereIn('method', self::METHODS)
            ->when($bankAccountId, fn ($q) => $q->where(fn ($w) => $w
                ->where('bank_account_id', $bankAccountId)->orWhereNull('bank_account_id')))
            ->when($openingDate, fn ($q) => $q->where('occurred_at', '>=', $openingDate->copy()->startOfDay()));
    }

    /**
     * اتّجاهُ معاملةٍ في البنك: إيداعٌ أم سحب.
     *
     * كان يُقرأ من النوع وحده — «دخل» وارد وما سواه صادر. والتحويلُ من
     * الصندوق إلى البنك نوعُه «تحويل»، فيُقرأ سحبًا وهو إيداع: لا يُطابَق
     * بإيداعه في كشف المصرف، ويُطابَق بسحبٍ لم يقع. فالتحويلُ يُقرأ من
     * نوعه (`kind`)، وما سواه من «دخل».
     */
    public static function isDeposit(Transaction $transaction): bool
    {
        return match ($transaction->kind) {
            'cash_to_bank' => true,
            'bank_to_cash' => false,
            default => $transaction->type === 'دخل',
        };
    }

    /**
     * ورقةُ الدفتر التي يُقرأ منها كشفُ حساب — حيث رحّل الدفترُ مالَه فعلًا.
     *
     * ورقتُه هو إن كانت قابلةً للترحيل. وإلّا فالرئيسيُّ يقرأ الورقةَ النظاميّة
     * (1200): إليها يسقط `Ledger::resolve('bank')` حين لا ورقةَ للحساب. وغيرُ
     * الرئيسيّ بلا ورقةٍ لا يملك في الدفتر ما يُقرأ. ولا يُنشئ شيئًا.
     */
    public static function statementLeaf(int $businessId, BankAccount $account): ?Account
    {
        if ((int) $account->business_id !== $businessId) {
            return null;
        }

        if ($leaf = self::leaf($businessId, $account->id)) {
            return $leaf;
        }

        if (self::current($businessId)?->id !== $account->id) {
            return null;
        }

        return Account::where('business_id', $businessId)->where('system_key', 'bank')->first();
    }

    /**
     * كشفُ حسابٍ بنكيّ من دفتر الأستاذ — سطورُ ورقته بعد تاريخ رصيده الافتتاحي.
     *
     * ═══ لماذا الدفتر لا جدولُ الحركات ═══
     *
     * كان الكشفُ يقرأ جدولَ الحركات (`transactions`)، وكثيرٌ ممّا يدخل البنك
     * أو يخرج منه لا يكتب فيه صفًّا: تحصيلُ فاتورة العميل بالتحويل، وتسويةُ
     * Paymob، والشيكُ المحصَّل، وسدادُ المورّد، والرواتب. وما يكتب صفًّا قد
     * يُقرأ اتّجاهه خطأً (التحويلُ من الصندوق كان سحبًا)، وقد يكتبه بيعٌ
     * آجلٌ لم يُقبض. فيقول الكشفُ رصيدًا والدفترُ رصيدًا آخر، ولا شيء يقول
     * أيّهما الصحيح. والدفترُ هو ما تقرؤه الميزانيّة؛ فالكشفُ يقرؤه أيضًا،
     * فيتّفقان بالبناء لا بالتذكّر.
     *
     * ═══ والرصيدُ الافتتاحيّ رأسُ الكشف لا سطرٌ فيه ═══
     *
     * قيدُه (وعكسُه إن عُدّل) يُستبعد من السطور، ويُقرأ رقمُه من الحساب.
     * وما قبل تاريخه داخلٌ فيه فلا يُعدّ ثانيةً — لكنّه لا يُخفى صامتًا:
     * يُقال عددُه وصافيه (`before_opening`)، ومعه رصيدُ الورقة كلّها. فمتجرٌ
     * أدخل رصيدَه الافتتاحيّ بعد حركاتٍ سبقته يرى الفرقَ ويعرف سببه.
     *
     * @return array{rows: list<array<string, mixed>>, before_opening: array{count: int, net: float}, ledger_balance: float}
     */
    public static function statement(int $businessId, BankAccount $account): array
    {
        $leaf = self::statementLeaf($businessId, $account);

        if (! $leaf) {
            return ['rows' => [], 'before_opening' => ['count' => 0, 'net' => 0.0], 'ledger_balance' => 0.0];
        }

        $openingDate = $account->opening_date?->toDateString();

        $lines = fn () => DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('accounts.business_id', $businessId)
            ->where('journal_entries.posted', true)
            ->where('journal_lines.account_id', $leaf->id);

        // قيدُ الرصيد الافتتاحيّ لهذا الحساب وعكوسُه — رقمُه رأسُ الكشف
        $notOpening = fn ($q) => $q->where(fn ($w) => $w
            ->whereNull('journal_entries.sourceable_type')
            ->orWhere('journal_entries.sourceable_type', '!=', BankAccount::class)
            ->orWhere('journal_entries.sourceable_id', '!=', $account->id));

        $before = $openingDate
            ? $notOpening($lines())->where('journal_entries.entry_date', '<', $openingDate)
                ->selectRaw('count(*) as n, coalesce(sum(journal_lines.debit - journal_lines.credit), 0) as net')->first()
            : null;

        $rows = $notOpening($lines())
            ->when($openingDate, fn ($q) => $q->where('journal_entries.entry_date', '>=', $openingDate))
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.id')->orderBy('journal_lines.id')
            ->get([
                'journal_lines.id', 'journal_lines.debit', 'journal_lines.credit', 'journal_entries.number',
                'journal_entries.entry_date', 'journal_entries.description', 'journal_entries.source',
            ])
            ->map(fn ($l) => [
                'id' => (int) $l->id,
                'reference' => $l->number,
                'date' => Carbon::parse($l->entry_date)->toDateString(),
                'description' => $l->description,
                'source' => $l->source,
                // مدينُ الورقة مالٌ دخل البنك، ودائنُها مالٌ خرج منه
                'in' => round((float) $l->debit, 3),
                'out' => round((float) $l->credit, 3),
            ])->all();

        return [
            'rows' => $rows,
            'before_opening' => ['count' => (int) ($before->n ?? 0), 'net' => round((float) ($before->net ?? 0), 3)],
            'ledger_balance' => round((float) $lines()->sum(DB::raw('journal_lines.debit - journal_lines.credit')), 3),
        ];
    }
}
