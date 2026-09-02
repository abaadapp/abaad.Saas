<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Support\Activity;
use App\Support\Bank;
use App\Support\Demo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Support\Sheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * كشف حساب الشركة البنكي ومطابقته مع معاملات النظام.
 *
 * المطابقة تعتمد قاعدة واضحة: نفس المبلغ (بفارق ≤ 0.005 ر.ع لتفادي فروق التقريب)
 * وتاريخ ضمن ±3 أيام. كل سطر بنكي يُطابَق بمعاملة واحدة فقط ولا تُستخدم المعاملة مرتين.
 */
class BankStatementController extends Controller
{
    private const AMOUNT_TOLERANCE = 0.005;

    private const DAYS_TOLERANCE = 3;

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /**
     * الحساب المقصود — المطلوب في الطلب، وإلا الرئيسيّ.
     *
     * صار للنشاط أكثر من حساب بنكي، و«أوّل ما يوجد» يتبدّل بترتيب الصفوف:
     * تستورد كشف حساب التحصيل فيدخل على حساب المصروفات بلا أن تدري.
     */
    private function account(?Request $request = null): BankAccount
    {
        $id = $request?->input('bank_account_id');

        if ($id) {
            $found = BankAccount::where('business_id', $this->bid())->find($id);
            if ($found) {
                return $found;
            }
        }

        return Bank::account($this->bid());
    }

    /*
     * حفظ بيانات الحساب انتقل إلى Finance\BankAccountController.
     *
     * صار للنشاط أكثر من حساب، وبابان يكتبان في الجدول نفسه بتحقّقٍ مكتوب
     * مرّتين يفترقان عند أوّل تعديل: يُشدَّد أحدهما ويبقى الآخر مفتوحًا.
     */

    /** استيراد كشف البنك (xlsx/xls/csv) ثم مطابقته تلقائيًا */
    public function import(Request $request)
    {
        $request->validate([
            'statement' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,xlsm,csv'],
        ], [
            'statement.extensions' => __('الصيغ المدعومة: XLSX، XLS، XLSM، CSV.'),
        ], ['statement' => __('ملف الكشف')]);

        // والترميز يُقرأ لا يُفترض — انظر `Sheet`: كشوف البنوك تُحفظ CSV كما تُحفظ الجداول
        $rows = Sheet::spreadsheet($request->file('statement')->getRealPath())
            ->getActiveSheet()->toArray(null, true, true, false);

        if (count($rows) < 2) {
            return back()->with('toast', ['msg' => __('الملف فارغ أو لا يحتوي بيانات.'), 'type' => 'error']);
        }

        $cols = $this->detectColumns($rows[0]);
        $hasAmount = $cols['amount'] !== null || $cols['debit'] !== null || $cols['credit'] !== null;
        if ($cols['date'] === null || ! $hasAmount) {
            return back()->with('toast', [
                'msg' => __('تعذّر التعرّف على الأعمدة. يجب أن يحتوي الملف على عمود «التاريخ» وعمود «المبلغ» أو عمودَي «مدين» و«دائن».'),
                'type' => 'error',
            ]);
        }

        $bid = $this->bid();
        $account = $this->account($request);

        /*
         * الاستيراد يضيف ولا يمسح.
         *
         * كان كل استيرادٍ يحذف الكشف السابق كلّه: تستورد فبراير فيضيع يناير
         * بلا سؤال ولا تحذير. والمكرّر يُمنع بالمقارنة لا بالمسح — سطران
         * بنفس التاريخ والمبلغ والمرجع والبيان هو السطر نفسه أُعيد استيراده.
         *
         * والمقارنة داخل الحساب الواحد: إيداعان بالمبلغ نفسه في حسابين
         * مختلفين حركتان لا واحدة.
         */
        $seen = BankStatementLine::where('business_id', $bid)
            ->where(fn ($w) => $w->where('bank_account_id', $account->id)->orWhereNull('bank_account_id'))
            ->get()
            ->map(fn ($l) => $this->fingerprint($l->date->format('Y-m-d'), (float) $l->amount, $l->reference, $l->description))
            ->flip();

        $imported = 0;
        $duplicates = 0;

        foreach (array_slice($rows, 1) as $row) {
            $rawDate = $row[$cols['date']] ?? null;
            if ($rawDate === null || $rawDate === '') {
                continue;
            }

            $amount = $this->rowAmount($row, $cols);
            if ($amount === null) {
                continue;
            }

            $date = $this->parseDate($rawDate);
            if (! $date) {
                continue;
            }

            $description = $cols['desc'] !== null ? (string) ($row[$cols['desc']] ?? '') : null;
            $reference = $cols['ref'] !== null ? (string) ($row[$cols['ref']] ?? '') : null;

            $key = $this->fingerprint($date, $amount, $reference, $description);
            if ($seen->has($key)) {
                $duplicates++;

                continue;
            }
            $seen[$key] = true;

            BankStatementLine::create([
                'business_id' => $bid,
                'bank_account_id' => $account->id,
                'date' => $date,
                'description' => $description,
                'reference' => $reference,
                'amount' => $amount,
            ]);
            $imported++;
        }

        $matched = $this->reconcile();
        Activity::log('created', "استورد كشف البنك ({$imported} سطرًا) وطابَق {$matched}");

        $msg = __('تم استيراد :imported سطرًا · مطابَق تلقائيًا: :matched', ['imported' => $imported, 'matched' => $matched]);
        if ($duplicates) {
            $msg .= ' · '.__('تُخطّي :n سطرًا مكرّرًا', ['n' => $duplicates]);
        }

        return back()->with('toast', ['msg' => $msg, 'type' => 'success']);
    }

    /** بصمة السطر: نفس التاريخ والمبلغ والمرجع والبيان = السطر نفسه */
    private function fingerprint(string $date, float $amount, ?string $reference, ?string $description): string
    {
        return $date.'|'.number_format($amount, 3, '.', '').'|'.trim((string) $reference).'|'.trim((string) $description);
    }

    /**
     * مبلغ السطر من عمود «المبلغ»، أو من عمودَي «مدين» و«دائن».
     *
     * أغلب كشوف البنوك هنا تُصدَّر بعمودين منفصلين لا بعمودٍ بإشارة، وكان
     * الملفّ يُرفض كلّه برسالة «تعذّر التعرّف على الأعمدة». والدائن وارد
     * والمدين صادر — فيُوحَّدان في رقمٍ بإشارة.
     */
    private function rowAmount(array $row, array $cols): ?float
    {
        $num = function ($raw): ?float {
            if ($raw === null || trim((string) $raw) === '') {
                return null;
            }
            $clean = str_replace([',', ' ', "\u{00a0}"], '', (string) $raw);

            return is_numeric($clean) ? (float) $clean : null;
        };

        if ($cols['amount'] !== null) {
            return $num($row[$cols['amount']] ?? null);
        }

        $credit = $cols['credit'] !== null ? $num($row[$cols['credit']] ?? null) : null;
        $debit = $cols['debit'] !== null ? $num($row[$cols['debit']] ?? null) : null;

        if ($credit === null && $debit === null) {
            return null;
        }

        return abs($credit ?? 0) - abs($debit ?? 0);
    }

    /** إعادة تشغيل المطابقة يدويًا */
    public function rematch()
    {
        $matched = $this->reconcile();

        return back()->with('toast', ['msg' => __('أُعيدت المطابقة — مطابَق: :matched', ['matched' => $matched]), 'type' => 'success']);
    }

    /** حذف الكشف المستورد لحسابٍ واحد — لا لكشوف الحسابات كلّها */
    public function clear(Request $request)
    {
        $account = $this->account($request);

        BankStatementLine::where('business_id', $this->bid())
            ->where(fn ($w) => $w->where('bank_account_id', $account->id)->orWhereNull('bank_account_id'))
            ->delete();
        Activity::log('deleted', 'حذف كشف البنك المستورد');

        return back()->with('toast', ['msg' => __('تم حذف الكشف المستورد'), 'type' => 'warning']);
    }

    /**
     * المطابقة التلقائية: لكل سطر بنكي ابحث عن معاملة بنفس المبلغ وتاريخ قريب.
     * تُعاد الحسبة من الصفر في كل مرة حتى لا تبقى مطابقات قديمة خاطئة.
     *
     * والمرشَّحون هم ما مرّ بالبنك وحده: كانت بيعةٌ نقدية تُطابَق بإيداعٍ
     * بالمبلغ نفسه فيُكتب «مطابق» عن شيئين لم يلتقيا قط.
     *
     * والأقرب أوّلًا، لا الأوّل الذي يصلح.
     *
     * كان كلّ سطرٍ يأخذ أوّل معاملةٍ تمرّ عليه في ترتيب القاعدة — أي بمعرّفها
     * لا بتاريخها. فسطرٌ يصلح له مرشّحان يخطف البعيد منهما، ويبقى السطر الذي
     * لا يملك سواه بلا مطابقة. وهذا يقع كثيرًا في محلٍّ يبيع بالبطاقة أكثر من
     * مرّة بالمبلغ نفسه في الأسبوع — وهو حال أيّ محلّ.
     *
     * فتُجمع الأزواج الصالحة كلّها أوّلًا، وتُرتَّب بفارق الأيام تصاعديًّا، ثم
     * تُوزَّع: الزوج الأقرب يظفر، ومن أُخذ طرفُه يسقط. فلا يُزاحَم البعيدُ
     * القريبَ، ولا يُجوَّع سطرٌ له مرشّحٌ واحد.
     *
     * وعند تساوي الفارق يُرجَّح الأقدمُ تاريخًا ثم الأصغرُ معرّفًا: توزيعٌ
     * واحدٌ لا يتبدّل بين استيرادٍ وآخر، فلا يرى التاجر مطابقةً تنتقل من سطرٍ
     * إلى سطر بلا سبب.
     */
    private function reconcile(): int
    {
        $bid = $this->bid();

        BankStatementLine::where('business_id', $bid)
            ->update(['transaction_id' => null, 'match_status' => 'غير مطابق']);

        $transactions = Bank::transactions($bid)->get();
        $lines = BankStatementLine::where('business_id', $bid)->orderBy('date')->orderBy('id')->get();

        // كلّ زوجٍ صالح، مع فارق أيامه
        $pairs = [];
        foreach ($lines as $line) {
            // مبالغ المصروفات مخزّنة بإشارة سالبة، لذا نقارن بالقيمة المطلقة
            $target = abs((float) $line->amount);
            $incoming = (float) $line->amount >= 0;

            foreach ($transactions as $t) {
                if (($t->type === 'دخل') !== $incoming) {
                    continue;
                }
                if (abs(abs((float) $t->amount) - $target) > self::AMOUNT_TOLERANCE) {
                    continue;
                }
                if (! $t->occurred_at) {
                    continue;
                }

                $days = abs($t->occurred_at->startOfDay()->diffInDays($line->date->startOfDay(), false));
                if ($days > self::DAYS_TOLERANCE) {
                    continue;
                }

                $pairs[] = ['line' => $line, 'transaction' => $t, 'days' => $days];
            }
        }

        // الأقرب أوّلًا، ثمّ الأقدم، ثمّ الأصغر معرّفًا — ترتيبٌ لا يتبدّل
        usort($pairs, fn ($a, $b) => [$a['days'], $a['line']->date->timestamp, $a['line']->id, $a['transaction']->id]
            <=> [$b['days'], $b['line']->date->timestamp, $b['line']->id, $b['transaction']->id]);

        $usedLines = [];
        $usedTransactions = [];
        $matched = 0;

        foreach ($pairs as $pair) {
            $lineId = $pair['line']->id;
            $trxId = $pair['transaction']->id;

            if (isset($usedLines[$lineId]) || isset($usedTransactions[$trxId])) {
                continue;
            }

            $usedLines[$lineId] = true;
            $usedTransactions[$trxId] = true;
            $pair['line']->update(['transaction_id' => $trxId, 'match_status' => 'مطابق']);
            $matched++;
        }

        return $matched;
    }

    private function detectColumns(array $header): array
    {
        $aliases = [
            'date' => ['التاريخ', 'تاريخ', 'date', 'value date', 'تاريخ العملية'],
            'desc' => ['البيان', 'الوصف', 'التفاصيل', 'description', 'details', 'narrative'],
            'ref' => ['المرجع', 'رقم المرجع', 'reference', 'ref'],
            'amount' => ['المبلغ', 'القيمة', 'amount', 'value'],
            // كشوف البنوك هنا تُصدَّر بعمودَي مدين/دائن أكثر ممّا تُصدَّر بعمودٍ بإشارة
            'debit' => ['مدين', 'المدين', 'سحب', 'منصرف', 'debit', 'withdrawal', 'debit amount'],
            'credit' => ['دائن', 'الدائن', 'إيداع', 'وارد', 'credit', 'deposit', 'credit amount'],
        ];
        $found = ['date' => null, 'desc' => null, 'ref' => null, 'amount' => null, 'debit' => null, 'credit' => null];

        foreach ($header as $i => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            foreach ($aliases as $field => $names) {
                if ($found[$field] === null && in_array($key, array_map('mb_strtolower', $names), true)) {
                    $found[$field] = $i;
                }
            }
        }

        return $found;
    }

    private function parseDate($raw): ?string
    {
        // أرقام إكسل التسلسلية للتاريخ
        if (is_numeric($raw)) {
            try {
                return Date::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return Carbon::parse((string) $raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
