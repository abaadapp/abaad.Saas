<?php

namespace App\Support;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * دفتر الأستاذ — البابُ الوحيد الذي تُكتب منه القيود.
 *
 * القيود تأتي من ثلاثة مصادر: الشاشة، والترحيل التلقائي من المبيعات
 * والمشتريات، ومسيرة الرواتب. ولو كتب كلٌّ منها بنفسه لتفرّقت قواعد التوازن
 * وترقيم المراجع وحساب الفترة — فبابٌ واحد يفرضها جميعًا.
 */
class Ledger
{
    /**
     * الشجرة الافتراضية لمتجرٍ جديد.
     *
     * مختصرةٌ عمدًا: خمسة جذور وما تحتها ممّا يحتاجه متجر تجزئة فعلًا. شجرةٌ
     * من مئتي حساب تُقرأ كأنها استمارةُ ضرائب، ويتركها التاجر كما هي فلا
     * يستعمل منها عشرة.
     */
    public const DEFAULT_CHART = [
        ['1', 'الأصول', 'أصل', 'debit', null, [
            ['1100', 'الصندوق', 'أصل', 'debit', 'cash'],
            ['1200', 'البنك', 'أصل', 'debit', 'bank'],
            ['1300', 'ذمم العملاء', 'أصل', 'debit', 'receivable'],
            ['1400', 'المخزون', 'أصل', 'debit', 'inventory'],
            ['1500', 'الأصول الثابتة', 'أصل', 'debit', 'fixed_assets'],
            // أصلٌ طبيعته دائنة — ولهذا لا تُشتقّ الطبيعة من النوع
            ['1590', 'مجمّع الإهلاك', 'أصل', 'credit', 'accumulated_depreciation'],
        ]],
        ['2', 'الخصوم', 'خصم', 'credit', null, [
            ['2100', 'ذمم الموردين', 'خصم', 'credit', 'payable'],
            ['2200', 'رواتب مستحقّة', 'خصم', 'credit', 'salaries_payable'],
            ['2300', 'ضريبة مستحقّة', 'خصم', 'credit', 'tax_payable'],
        ]],
        ['3', 'حقوق الملكية', 'حقوق ملكية', 'credit', null, [
            ['3100', 'رأس المال', 'حقوق ملكية', 'credit', 'capital'],
            ['3200', 'الأرباح المحتجزة', 'حقوق ملكية', 'credit', 'retained_earnings'],
            /*
             * ما يأخذه المالك لنفسه — حقوقُ ملكيةٍ طبيعتها مدينة.
             *
             * وليس مصروفًا: المصروف يُنقص الربح، وسحبُ المالك يُنقص حقّه في
             * المتجر ولا يمسّ ربح الشهر. وخلطُهما يجعل متجرًا رابحًا يقرأ
             * نفسه خاسرًا كلّما أخذ صاحبه مصروفه من الدرج.
             */
            ['3300', 'مسحوبات المالك', 'حقوق ملكية', 'debit', 'drawings'],
        ]],
        ['4', 'الإيرادات', 'إيراد', 'credit', null, [
            ['4100', 'إيراد المبيعات', 'إيراد', 'credit', 'sales'],
            // ما يدخل من غير البيع: بيع أصلٍ بربح، فرق عملة، تعويض
            ['4800', 'إيرادات أخرى', 'إيراد', 'credit', 'other_income'],
            // إيرادٌ طبيعته مدينة: يُنقص الإيراد لا يزيده
            ['4900', 'مردودات المبيعات', 'إيراد', 'debit', 'sales_returns'],
        ]],
        ['5', 'المصروفات', 'مصروف', 'debit', null, [
            ['5100', 'تكلفة البضاعة المباعة', 'مصروف', 'debit', 'cogs'],
            ['5200', 'الرواتب والأجور', 'مصروف', 'debit', 'salaries'],
            ['5300', 'الإيجار', 'مصروف', 'debit', 'rent'],
            ['5400', 'مصروف الإهلاك', 'مصروف', 'debit', 'depreciation'],
            /*
             * أنواع المصروف الشائعة في متجرٍ صغير — تُبنى ولا تُترك لـ«أخرى».
             *
             * كانت المصروفات كلّها تُرحَّل إلى «مصروفات أخرى» ما عدا الإيجار،
             * فيصير سطرًا واحدًا يبتلع الكهرباء والتسويق والصيانة والنقل معًا.
             * ومن يقرأ قائمة الدخل يعرف كم أنفق ولا يعرف على ماذا — وهو أوّل
             * سؤالٍ يُسأل حين يُراد خفض المصروف.
             *
             * و«مشتريات مباشرة» ليست تكلفة البضاعة المباعة: تلك تُرحَّل من
             * البيع بلقطة التكلفة، وخلطُ الاثنين يُقيّد الوردةَ مرّتين.
             */
            ['5500', 'الكهرباء والماء', 'مصروف', 'debit', 'utilities'],
            ['5600', 'التسويق والإعلان', 'مصروف', 'debit', 'marketing'],
            ['5700', 'الصيانة', 'مصروف', 'debit', 'maintenance'],
            ['5800', 'النقل والتوصيل', 'مصروف', 'debit', 'transport'],
            ['5150', 'مشتريات مباشرة', 'مصروف', 'debit', 'direct_purchases'],
            ['5900', 'مصروفات أخرى', 'مصروف', 'debit', 'other_expenses'],
        ]],
    ];

    /**
     * يبني الشجرة لنشاطٍ لا شجرة له — ولا يلمس شجرةً قائمة.
     *
     * يُستدعى عند إنشاء النشاط وعند أوّل فتحٍ لشاشة الحسابات: متجرٌ أُنشئ قبل
     * هذه النسخة لا شجرة له، وشاشةٌ فارغة لا تقول له ماذا يفعل.
     */
    public static function seedChart(int $businessId): int
    {
        if (Account::where('business_id', $businessId)->exists()) {
            return 0;
        }

        $created = 0;

        DB::transaction(function () use ($businessId, &$created) {
            foreach (self::DEFAULT_CHART as [$code, $name, $type, $side, $key, $children]) {
                $parent = Account::create([
                    'business_id' => $businessId, 'code' => $code, 'name' => $name,
                    'type' => $type, 'normal_side' => $side, 'system_key' => $key,
                ]);
                $created++;

                foreach ($children as [$ccode, $cname, $ctype, $cside, $ckey]) {
                    Account::create([
                        'business_id' => $businessId, 'parent_id' => $parent->id,
                        'code' => $ccode, 'name' => $cname, 'type' => $ctype,
                        'normal_side' => $cside, 'system_key' => $ckey,
                    ]);
                    $created++;
                }
            }
        });

        return $created;
    }

    /**
     * يستدرك حسابًا نظاميًّا أُضيف إلى الشجرة الافتراضية بعد بناء شجرة النشاط.
     *
     * `seedChart` لا تلمس شجرةً قائمة — وهو الصواب: التاجر يعدّل شجرته
     * ويحذف منها ويضيف، فإعادةُ بنائها تمحو عملَه. لكنّ النتيجة أن كل حسابٍ
     * يضيفه النظام لاحقًا (كـ«إيرادات أخرى» حين صار بيع الأصول يُرحَّل) لا
     * يصل إلا إلى المتاجر الجديدة، فيسقط الترحيل التلقائي عند القدماء
     * برسالة «حسابٌ غير موجود» لا يفهمها أحد.
     *
     * فيُبنى الناقص وحده تحت أبيه، ولا يُمسّ ما سواه. والرمز يُزاح إن كان
     * مشغولًا بحسابٍ أنشأه التاجر بيده.
     *
     * @return int عدد ما أُضيف
     */
    public static function ensureSystemAccounts(int $businessId): int
    {
        if (! Account::where('business_id', $businessId)->exists()) {
            return self::seedChart($businessId);
        }

        $existing = Account::where('business_id', $businessId)->pluck('system_key')->filter()->all();
        $added = 0;

        foreach (self::DEFAULT_CHART as [$code, $name, $type, $side, $key, $children]) {
            foreach ($children as [$ccode, $cname, $ctype, $cside, $ckey]) {
                if (in_array($ckey, $existing, true)) {
                    continue;
                }

                // الأب قد يكون محذوفًا أو مُعاد تسميته — يُطلب برمزه ثم يُبنى
                $parent = Account::firstOrCreate(
                    ['business_id' => $businessId, 'code' => $code],
                    ['name' => $name, 'type' => $type, 'normal_side' => $side, 'system_key' => $key],
                );

                Account::create([
                    'business_id' => $businessId,
                    'parent_id' => $parent->id,
                    'code' => self::freeCode($businessId, $ccode),
                    'name' => $cname,
                    'type' => $ctype,
                    'normal_side' => $cside,
                    'system_key' => $ckey,
                ]);
                $added++;
            }
        }

        return $added;
    }

    /** أوّل رمزٍ غير مشغول ابتداءً من المطلوب — الرمز فريدٌ في القاعدة */
    private static function freeCode(int $businessId, string $wanted): string
    {
        $code = $wanted;
        $n = 0;

        while (Account::where('business_id', $businessId)->where('code', $code)->exists()) {
            $code = $wanted.'-'.(++$n);
        }

        return $code;
    }

    /**
     * حسابٌ بمفتاحه النظاميّ — ويُبنى إن غاب بدل أن يُردّ فارغًا.
     *
     * كانت `ensureSystemAccounts` تُستدعى في `index` كلّ شاشةٍ ماليّة ولا
     * تُستدعى في كتاباتها: التسوية والسند والراتب والأصل كلّها تُرحّل بلا أن
     * تسأل هل في الشجرة ما تُرحّل إليه. فالكتابة تعتمد على أنّ القراءة سبقتها
     * — وهي تسبقها في المتصفّح دائمًا، فبقي العطب كامنًا لا ظاهرًا.
     *
     * وسبعةُ متحكّمات على هذا النسق، فالإصلاح في السطر الذي يقرأ الحساب لا في
     * سبعة مواضع تُنسى الثامنةُ منها. والبناء عند الغياب وحده: الطريق المعتاد
     * يجد الحساب من أوّل استعلامٍ ولا يدفع شيئًا.
     */
    public static function account(int $businessId, string $systemKey): ?Account
    {
        $account = Account::where('business_id', $businessId)->where('system_key', $systemKey)->first();

        if ($account) {
            return $account;
        }

        self::ensureSystemAccounts($businessId);

        return Account::where('business_id', $businessId)->where('system_key', $systemKey)->first();
    }

    /**
     * كتابة قيدٍ وترحيله في عمليّةٍ واحدة.
     *
     * `$lines` مصفوفة: ['account' => مفتاح نظاميّ أو Account, 'debit'|'credit' => مبلغ, 'memo' => نص].
     *
     * والمعاملة تلفّ الكل: قيدٌ يُكتب رأسُه ثم تسقط سطوره يترك في الدفتر
     * رأسًا بلا مبلغ — يظهر في القوائم ولا يعني شيئًا، ولا يُعرف ما كان.
     *
     * @throws RuntimeException إن اختلّ التوازن أو غاب حسابٌ أو كان غير قابلٍ للترحيل
     */
    public static function post(
        int $businessId,
        string $description,
        array $lines,
        ?Carbon $date = null,
        string $source = 'يدوي',
        ?int $branchId = null,
        ?int $userId = null,
        $sourceable = null,
    ): JournalEntry {
        if (count($lines) < 2) {
            throw new RuntimeException(__('القيد يحتاج سطرين على الأقل: مدين ودائن'));
        }

        return DB::transaction(function () use (
            $businessId, $description, $lines, $date, $source, $branchId, $userId, $sourceable
        ) {
            $entry = JournalEntry::create([
                'business_id' => $businessId,
                'branch_id' => $branchId,
                'number' => JournalEntry::nextNumber($businessId),
                'entry_date' => ($date ?? now())->toDateString(),
                'description' => $description,
                'source' => $source,
                'sourceable_type' => $sourceable ? $sourceable::class : null,
                'sourceable_id' => $sourceable?->id,
                'created_by' => $userId,
            ]);

            $sides = [];

            foreach ($lines as $line) {
                $account = $line['account'] instanceof Account
                    ? $line['account']
                    : self::account($businessId, (string) $line['account']);

                if (! $account) {
                    throw new RuntimeException(__('حسابٌ غير موجود في الشجرة: :key', ['key' => (string) $line['account']]));
                }

                if (! $account->isPostable()) {
                    throw new RuntimeException(__('لا يُرحَّل إلى «:name»: حسابٌ مغلق أو له حسابات فرعية', ['name' => $account->name]));
                }

                $debit = round((float) ($line['debit'] ?? 0), 3);
                $credit = round((float) ($line['credit'] ?? 0), 3);

                // سطرٌ بطرفين أو بلا طرف يمرّ من التوازن ويُفسد دفتر الأستاذ
                if (($debit > 0) === ($credit > 0)) {
                    throw new RuntimeException(__('كل سطر إمّا مدينٌ وإمّا دائن — لا كلاهما ولا واحدَ منهما'));
                }

                /*
                 * والحساب لا يقع في طرفي القيد الواحد.
                 *
                 * «مدين الصندوق ٥٠ / دائن الصندوق ٥٠» متوازنٌ تمامًا ولا يعني
                 * شيئًا: لا يتحرّك به رصيد، ويتضخّم به مجموعا ميزان المراجعة
                 * بمالٍ لم يوجد. وهو أوّل ما يقع فيه من يملأ نموذج القيد
                 * اليدويّ فيختار الحساب نفسه في السطرين سهوًا — ولا شيء كان
                 * يردّه، فيبقى في الدفتر قيدٌ لا يُقرأ ولا يُشرح.
                 *
                 * والسطران في الطرف الواحد مقبولان: «دائن الصندوق ٣٠ إيجارًا
                 * ودائن الصندوق ٢٠ كهرباءً» بيانٌ أوضح من ضمّهما.
                 */
                $side = $debit > 0 ? 'd' : 'c';
                $seen = $sides[$account->id] ?? null;

                if ($seen !== null && $seen !== $side) {
                    throw new RuntimeException(__('«:name» في طرفي القيد معًا — مدينًا ودائنًا: قيدٌ يُلغي نفسه ولا يحرّك رصيدًا.', ['name' => $account->name]));
                }

                $sides[$account->id] = $side;

                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $account->id,
                    'debit' => $debit,
                    'credit' => $credit,
                    'memo' => $line['memo'] ?? null,
                ]);
            }

            // يسقط هنا فتُلغى المعاملة كلّها: لا رأسَ بلا سطور ولا قيدَ مختلّ
            $entry->post();

            return $entry->fresh('lines');
        });
    }

    /**
     * عكسُ قيدٍ مُرحَّل — والتصحيح لا يكون بغيره.
     *
     * القيد المُرحَّل لا تُغيَّر سطورُه في مكانها: من قرأ الميزان أمس قرأ
     * رقمًا، ومن يقرؤه اليوم يقرأ غيره، ولا شيء يقول إنّ شيئًا تغيّر. فالتصحيح
     * قيدان — عكسيٌّ يُلغي الأوّل، وجديدٌ بالقيم المصحَّحة — والثلاثة تبقى
     * معلَّقةً بمستندها فيُقرأ تاريخُه كاملًا.
     *
     * ولا يُعكس القيد مرّتين: يُقرأ بقفلٍ ويُختم بـ`reversed_at`، فطلبان
     * متزامنان على الإلغاء نفسه لا يكتبان عكسين.
     *
     * وسطورُه تُبنى بحسابها لا بمفتاحها النظاميّ: القيد قد يكون على ورقة بنكٍ
     * أنشأها التاجر بيده ولا مفتاح لها، فالبحث بالمفتاح لا يجدها.
     *
     * @return JournalEntry|null القيد العكسيّ — أو null إن كان معكوسًا أصلًا
     */
    public static function reverse(
        JournalEntry $entry,
        ?Carbon $date = null,
        ?int $userId = null,
        ?string $reason = null,
    ): ?JournalEntry {
        return DB::transaction(function () use ($entry, $date, $userId, $reason) {
            $original = JournalEntry::whereKey($entry->id)->lockForUpdate()->first();

            if (! $original || $original->reversed_at) {
                return null;
            }

            $lines = $original->lines()->with('account')->get()->map(fn ($line) => [
                'account' => $line->account,
                'debit' => (float) $line->credit,
                'credit' => (float) $line->debit,
                'memo' => $line->memo,
            ])->all();

            if (count($lines) < 2) {
                return null;
            }

            $reversal = self::post(
                $original->business_id,
                mb_substr(__('عكس: ').$original->description.($reason ? ' — '.$reason : ''), 0, 255),
                $lines,
                $date ?? now(),
                mb_substr(__('عكس ').$original->source, 0, 30),
                $original->branch_id,
                $userId,
                $original->sourceable,
            );

            $reversal->update(['reverses_id' => $original->id]);
            $original->update(['reversed_at' => now()]);

            return $reversal;
        });
    }

    /**
     * ميزان المراجعة — كل حسابٍ ورصيده، ومجموع الطرفين.
     *
     * والمجموعان يجب أن يتطابقا دائمًا. إن لم يتطابقا فالخلل ليس في الشاشة
     * بل في الدفتر، ويعني أن قيدًا كُتب من غير هذا الباب.
     */
    public static function trialBalance(int $businessId, ?Carbon $through = null): array
    {
        $rows = Account::where('accounts.business_id', $businessId)
            ->leftJoin('journal_lines', 'journal_lines.account_id', '=', 'accounts.id')
            ->when($through, function ($q) use ($through) {
                $q->leftJoin('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->where(fn ($w) => $w->whereNull('journal_entries.id')
                        ->orWhere('journal_entries.entry_date', '<=', $through->toDateString()));
            })
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type', 'accounts.normal_side')
            ->orderBy('accounts.code')
            ->get([
                'accounts.id', 'accounts.code', 'accounts.name', 'accounts.type', 'accounts.normal_side',
                DB::raw('COALESCE(SUM(journal_lines.debit),0) as debit'),
                DB::raw('COALESCE(SUM(journal_lines.credit),0) as credit'),
            ]);

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $accounts = [];

        foreach ($rows as $row) {
            $debit = (float) $row->debit;
            $credit = (float) $row->credit;
            $totalDebit += $debit;
            $totalCredit += $credit;

            $diff = $debit - $credit;
            $accounts[] = [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'debit' => round($debit, 3),
                'credit' => round($credit, 3),
                'balance' => round($row->normal_side === 'credit' ? -$diff : $diff, 3),
            ];
        }

        return [
            'accounts' => $accounts,
            'total_debit' => round($totalDebit, 3),
            'total_credit' => round($totalCredit, 3),
            'balanced' => abs($totalDebit - $totalCredit) < 0.0005,
        ];
    }
}
