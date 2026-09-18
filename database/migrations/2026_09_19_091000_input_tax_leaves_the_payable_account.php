<?php

use App\Models\SupplierInvoice;
use App\Support\Ledger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * يُخرج ضريبةَ المشتريات من «ضريبة مستحقّة» إلى «ضريبة المدخلات».
 *
 * كانت ضريبةُ سندات المورّدين تُقيَّد مدينةً في 2300 نفسِها التي تحمل ضريبةَ
 * المبيعات دائنة — فيقرأ التاجر رقمًا واحدًا لا يعرف شقّيه. والحسابان اليوم
 * اثنان (انظر `Ledger::DEFAULT_CHART` — 1250).
 *
 * ═══ والتاريخ لا يُعاد كتابته ═══
 *
 * سطورُ القيود القديمة تبقى في مكانها كما كُتبت: من قرأ ميزانَ أمسِ يجد ما
 * قرأه. والنقلُ يقع بقيدٍ واحدٍ لكلّ متجر — مدين «المدخلات» / دائن
 * «المستحقّة» بصافي ما رُحّل من سندات المورّدين — وهو ما يفعله المحاسب حين
 * يعيد تصنيف حساب.
 *
 * ولا يُقاس بالمصدر النصّي بل بالمستند: `sourceable_type` هو سند المورّد
 * نفسه. والمعكوسُ محسوبٌ في الصافي لأنّ عكسَه سطرٌ دائنٌ في الحساب نفسه.
 *
 * وقيس على الإنتاج: سطرٌ واحد بـ٢٫٣٠٠ في متجرٍ واحد.
 */
return new class extends Migration
{
    private const MEMO = 'إعادة تصنيف ضريبة المدخلات';

    public function up(): void
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('a.system_key', 'tax_payable')
            ->where('je.sourceable_type', SupplierInvoice::class)
            ->groupBy('je.business_id')
            ->select('je.business_id', DB::raw('SUM(jl.debit) - SUM(jl.credit) as net'))
            ->get();

        foreach ($rows as $row) {
            $net = round((float) $row->net, 3);

            if (abs($net) < 0.0005) {
                continue;
            }

            $bid = (int) $row->business_id;
            Ledger::ensureSystemAccounts($bid);

            // ولا يُكتب مرّتين: الترحيلُ قد يُعاد على قاعدةٍ نصفَ محدَّثة
            $done = DB::table('journal_entries')
                ->where('business_id', $bid)->where('source', self::MEMO)->exists();

            if ($done) {
                continue;
            }

            $input = Ledger::account($bid, 'tax_input');
            $payable = Ledger::account($bid, 'tax_payable');

            // وشجرةٌ عدّلها التاجر لا تُسقط النشر: الطرفان يُرحَّل إليهما أو لا قيد
            if (! $input?->isPostable() || ! $payable?->isPostable()) {
                continue;
            }

            $side = $net > 0;

            Ledger::post(
                $bid,
                __('إعادة تصنيف ضريبة المدخلات إلى حسابها'),
                [
                    ['account' => $input, $side ? 'debit' : 'credit' => abs($net)],
                    ['account' => $payable, $side ? 'credit' : 'debit' => abs($net)],
                ],
                Carbon::now(),
                self::MEMO,
            );
        }
    }

    public function down(): void {}
};
