<?php

use App\Support\Ledger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * حسابان نظاميّان لكلّ متجرٍ له شجرة — «Paymob تحت التسوية» و«رسوم بوابات الدفع».
 *
 * `seedChart` تبني الشجرة الافتراضية للمتجر الجديد وحده. فيُستدرك الحسابان
 * هنا لمن بُنيت شجرتُه قبلهما (`Ledger::ensureSystemAccounts`): يُبنى الناقص
 * تحت أبيه ولا يُمسّ ما سواه، وإعادةُ التشغيل لا تُكرّر شيئًا — ما وُجد
 * بمفتاحه يُتخطّى.
 *
 * ومتجرٌ بلا شجرة لا تُبنى له هنا: شجرتُه تُبنى كاملةً يومَ يفتح المالية،
 * وفيها الحسابان. ولا يُعدَّل قيدٌ قديم.
 */
return new class extends Migration
{
    public function up(): void
    {
        $businesses = DB::table('accounts')->distinct()->pluck('business_id');

        foreach ($businesses as $bid) {
            Ledger::ensureSystemAccounts((int) $bid);
        }
    }

    public function down(): void {}
};
