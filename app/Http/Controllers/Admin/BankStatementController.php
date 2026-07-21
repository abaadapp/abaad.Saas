<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

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

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    private function account(): BankAccount
    {
        return BankAccount::firstOrCreate(['business_id' => $this->bid()]);
    }

    /** حفظ بيانات الحساب البنكي والرصيد الافتتاحي */
    public function updateAccount(Request $request)
    {
        $data = $request->validate([
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'max:64'],
            'opening_balance' => ['nullable', 'numeric'],
            'opening_date' => ['nullable', 'date'],
        ]);
        $this->account()->update($data);
        Activity::log('updated', 'حدّث بيانات الحساب البنكي');

        return back()->with('toast', ['msg' => 'تم حفظ بيانات الحساب البنكي', 'type' => 'success']);
    }

    /** استيراد كشف البنك (xlsx/xls/csv) ثم مطابقته تلقائيًا */
    public function import(Request $request)
    {
        $request->validate([
            'statement' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,xlsm,csv'],
        ], [
            'statement.extensions' => 'الصيغ المدعومة: XLSX، XLS، XLSM، CSV.',
        ], ['statement' => 'ملف الكشف']);

        $rows = IOFactory::load($request->file('statement')->getRealPath())
            ->getActiveSheet()->toArray(null, true, true, false);

        if (count($rows) < 2) {
            return back()->with('toast', ['msg' => 'الملف فارغ أو لا يحتوي بيانات.', 'type' => 'error']);
        }

        $cols = $this->detectColumns($rows[0]);
        if ($cols['date'] === null || $cols['amount'] === null) {
            return back()->with('toast', [
                'msg' => 'تعذّر التعرّف على الأعمدة. يجب أن يحتوي الملف على عمودَي «التاريخ» و«المبلغ».',
                'type' => 'error',
            ]);
        }

        $bid = $this->bid();
        // استيراد جديد يستبدل الكشف السابق حتى لا تتراكم أسطر مكرّرة
        BankStatementLine::where('business_id', $bid)->delete();

        $imported = 0;
        foreach (array_slice($rows, 1) as $row) {
            $rawDate = $row[$cols['date']] ?? null;
            $rawAmount = $row[$cols['amount']] ?? null;
            if ($rawDate === null || $rawDate === '' || $rawAmount === null || $rawAmount === '') {
                continue;
            }

            $date = $this->parseDate($rawDate);
            if (! $date) {
                continue;
            }

            BankStatementLine::create([
                'business_id' => $bid,
                'date' => $date,
                'description' => $cols['desc'] !== null ? (string) ($row[$cols['desc']] ?? '') : null,
                'reference' => $cols['ref'] !== null ? (string) ($row[$cols['ref']] ?? '') : null,
                'amount' => (float) str_replace([',', ' '], '', (string) $rawAmount),
            ]);
            $imported++;
        }

        $matched = $this->reconcile();
        Activity::log('created', "استورد كشف البنك ({$imported} سطرًا) وطابَق {$matched}");

        return back()->with('toast', [
            'msg' => "تم استيراد {$imported} سطرًا · مطابَق تلقائيًا: {$matched}",
            'type' => 'success',
        ]);
    }

    /** إعادة تشغيل المطابقة يدويًا */
    public function rematch()
    {
        $matched = $this->reconcile();

        return back()->with('toast', ['msg' => "أُعيدت المطابقة — مطابَق: {$matched}", 'type' => 'success']);
    }

    public function clear()
    {
        BankStatementLine::where('business_id', $this->bid())->delete();
        Activity::log('deleted', 'حذف كشف البنك المستورد');

        return back()->with('toast', ['msg' => 'تم حذف الكشف المستورد', 'type' => 'warning']);
    }

    /**
     * المطابقة التلقائية: لكل سطر بنكي ابحث عن معاملة بنفس المبلغ وتاريخ قريب.
     * تُعاد الحسبة من الصفر في كل مرة حتى لا تبقى مطابقات قديمة خاطئة.
     */
    private function reconcile(): int
    {
        $bid = $this->bid();

        BankStatementLine::where('business_id', $bid)
            ->update(['transaction_id' => null, 'match_status' => 'غير مطابق']);

        $transactions = Transaction::where('business_id', $bid)->get();
        $usedTransactions = [];
        $matched = 0;

        foreach (BankStatementLine::where('business_id', $bid)->orderBy('date')->get() as $line) {
            // مبالغ المصروفات مخزّنة بإشارة سالبة، لذا نقارن بالقيمة المطلقة
            $target = abs((float) $line->amount);
            $incoming = (float) $line->amount >= 0;

            $hit = $transactions->first(function ($t) use ($target, $incoming, $line, $usedTransactions) {
                if (in_array($t->id, $usedTransactions, true)) {
                    return false;
                }
                if (($t->type === 'دخل') !== $incoming) {
                    return false;
                }
                if (abs(abs((float) $t->amount) - $target) > self::AMOUNT_TOLERANCE) {
                    return false;
                }
                if (! $t->occurred_at) {
                    return false;
                }

                return abs($t->occurred_at->startOfDay()->diffInDays($line->date->startOfDay(), false)) <= self::DAYS_TOLERANCE;
            });

            if ($hit) {
                $usedTransactions[] = $hit->id;
                $line->update(['transaction_id' => $hit->id, 'match_status' => 'مطابق']);
                $matched++;
            }
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
        ];
        $found = ['date' => null, 'desc' => null, 'ref' => null, 'amount' => null];

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
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return \Carbon\Carbon::parse((string) $raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
