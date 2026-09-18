<?php

use App\Models\BankAccount;
use App\Support\Bank;
use App\Support\Ledger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * يُدخل الأرصدةَ الافتتاحيّة القائمة إلى الدفتر — مقابلَ حقوق الملكية.
 *
 * كان الرصيد الافتتاحيّ يُجمع على رصيد الورقة في شاشة البنوك ولا يدخل الدفتر
 * أبدًا. فتقول الشاشةُ رقمًا وتقول الميزانيةُ غيرَه، والفرقُ مالٌ حقيقيّ لا
 * يحمله حساب. وقيس على الإنتاج قبل كتابة هذا: ثلاثةُ حساباتٍ بنكيّة، كلُّها
 * برصيدٍ افتتاحيّ خارج الدفتر — ١٢٥٠٠ و٦٠٠ و٢٠٠٠.
 *
 * و`Bank::syncOpening` لا تكتب إن كان القيد مكتوبًا بمبلغه: فتشغيلُ الترحيل
 * مرّتين لا يُضاعف شيئًا، وهو ما يجعله آمنًا على قاعدةٍ نصفَ محدَّثة.
 *
 * ولا نزول: القيدُ المُرحَّل لا يُحذف. من أراد إلغاءه يعكسه من شاشة القيود.
 */
return new class extends Migration
{
    public function up(): void
    {
        $businesses = DB::table('bank_accounts')->distinct()->pluck('business_id');

        foreach ($businesses as $bid) {
            // الحسابُ النظاميُّ الجديد (3150) لا يوجد في شجرةٍ بُنيت قبل هذه النسخة
            Ledger::ensureSystemAccounts((int) $bid);
        }

        /*
         * ═══ وما دخل الدفتر بيدٍ لا يدخله مرّتين ═══
         *
         * متجرُ العرض كان يكتب افتتاحيَّه قيدًا يدويًّا مقابل رأس المال —
         * مصدرُه «افتتاحي» — إلى جانب الصفّ في `bank_accounts`. فلو رُحّل له
         * قيدٌ ثانٍ هنا لصار في الدفتر ضعفُ ما في البنك.
         *
         * وقيس على الإنتاج: قيدٌ واحدٌ من هذا الشكل، للمتجر ٢. وبابُه أُغلق
         * في `DemoStore::bank` — صار يُنادي `Bank::syncOpening` نفسَها.
         */
        $legacy = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.source', 'افتتاحي')
            ->pluck('jl.account_id')->map(fn ($id) => (int) $id)->all();

        BankAccount::whereNotNull('account_id')
            ->when($legacy, fn ($q) => $q->whereNotIn('account_id', $legacy))
            ->orderBy('id')->get()
            ->each(fn (BankAccount $a) => Bank::syncOpening($a));
    }

    public function down(): void {}
};
