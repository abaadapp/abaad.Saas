<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * الحسابُ البنكيُّ بلا اسمٍ يردّ الورقة ويمضي.
 *
 * ═══ ما كان ═══
 *
 * كانت شاشةُ المالية تُنشئ — من **قراءتها** — حسابًا بنكيًّا بلا اسمٍ ولا
 * بنكٍ ولا آيبان لمن ليس له حساب. فمن فتح الشاشة مرّةً وُلد في متجره صفٌّ
 * يُعرض «حساب بنكي»، وحجب الحالةَ الفارغة التي تدعوه لإضافة حسابه.
 *
 * وهذا الصفُّ يملك ورقة «البنك» (1200) في الشجرة — فكلّ ترحيلٍ بنكيّ يقع
 * فيها. ثمّ يضيف التاجر حسابَه الحقيقيّ فيأخذ ورقةً أختًا لا يدخلها ريال:
 * يقرأ «بنك مسقط: ٢٠٠٠» لا يتحرّك شهرًا كاملًا، و«حساب بنكي: ١٤٧» لا يعرف
 * ما هو ولا من أين جاء.
 *
 * ═══ وما تفعله هذه ═══
 *
 * تُبادل الورقتين: الحسابُ الحقيقيُّ يأخذ ورقة «البنك» بسطورها، والفارغُ
 * يأخذ الورقةَ الخالية ثمّ يُحذف هو وورقتُه.
 *
 * ولا يتحرّك سطرٌ واحدٌ من الدفتر: الأرصدةُ تُصحَّح بنقل **ملكيّة** الورقة
 * لا بنقل القيود. فتاريخُ المال يُقرأ كما وقع، ويصير الرصيدُ منسوبًا إلى
 * البنك الذي كان يستقبله فعلًا.
 *
 * ═══ ومتى لا تفعل شيئًا ═══
 *
 * إن كان صاحبُ الورقة النظامية حسابًا **مسمًّى** — له اسمٌ أو بنكٌ أو آيبان
 * أو رصيدٌ افتتاحيّ — فهو بنكُ التاجر لا صفٌّ اخترعه النظام، ولا يُمسّ.
 * وإن لم يكن للمتجر حسابٌ آخر يستقبل الورقة، بقي الفارغُ حاملًا لها: حذفُه
 * وحده يترك سطورًا لا مالكَ لها.
 *
 * وإن كان على الفارغ كشفٌ مستورد أو تحصيلٌ منسوبٌ إليه فهو ليس فارغًا:
 * أحدٌ استعمله، ويُترك لصاحبه.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('bank_accounts')->distinct()->pluck('business_id') as $businessId) {
            $this->repair((int) $businessId);
        }
    }

    /** لا تراجُع: الورقةُ عادت إلى بنكها، وإعادةُ الفراغ ليست إصلاحًا */
    public function down(): void {}

    private function repair(int $businessId): void
    {
        $systemLeaf = DB::table('accounts')
            ->where('business_id', $businessId)->where('system_key', 'bank')->first();

        if (! $systemLeaf) {
            return;
        }

        $holder = DB::table('bank_accounts')
            ->where('business_id', $businessId)->where('account_id', $systemLeaf->id)
            ->orderBy('id')->first();

        if (! $holder || ! $this->isNameless($holder) || $this->isUsed($holder)) {
            return;
        }

        // الوارثُ: الرئيسيّ، وإلا الأقدم — وله ورقتُه هو
        $heir = DB::table('bank_accounts')
            ->where('business_id', $businessId)->where('id', '!=', $holder->id)
            ->whereNotNull('account_id')
            ->orderByDesc('is_primary')->orderBy('id')->first();

        if (! $heir) {
            return;
        }

        $heirLeaf = $heir->account_id;

        // ورقةُ الوارث لا تُترك إلا خاليةً: سطرٌ عليها يضيع بحذفها
        if (DB::table('journal_lines')->where('account_id', $heirLeaf)->exists()) {
            return;
        }

        DB::transaction(function () use ($holder, $heir, $systemLeaf, $heirLeaf) {
            DB::table('bank_accounts')->where('id', $heir->id)->update(['account_id' => $systemLeaf->id]);
            DB::table('bank_accounts')->where('id', $holder->id)->delete();
            DB::table('accounts')->where('id', $heirLeaf)->whereNull('system_key')->delete();
        });
    }

    /** فارغٌ: لا اسمَ ولا بنكَ ولا صاحبَ ولا آيبان ولا رصيدَ افتتاحيّ */
    private function isNameless(object $account): bool
    {
        foreach (['label', 'bank_name', 'account_name', 'iban'] as $field) {
            if (trim((string) ($account->{$field} ?? '')) !== '') {
                return false;
            }
        }

        return round((float) ($account->opening_balance ?? 0), 3) === 0.0;
    }

    /** ومن استُعمل ليس فارغًا مهما خلا اسمُه */
    private function isUsed(object $account): bool
    {
        return DB::table('bank_statement_lines')->where('bank_account_id', $account->id)->exists()
            || DB::table('customer_payments')->where('bank_account_id', $account->id)->exists();
    }
};
