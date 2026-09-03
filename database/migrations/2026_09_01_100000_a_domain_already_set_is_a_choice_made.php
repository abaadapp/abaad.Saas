<?php

use App\Support\DomainOptions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * من ضبط نطاقه قبل هذه النسخة صاحبُ خيارٍ لا صاحبُ فراغ.
 *
 * صار للعنوان طرقٌ ثلاث — نطاقٌ يملكه التاجر، أو نطاقٌ فرعيّ، أو طلبٌ من
 * أبعاد — و`DomainOptions::mode` تقرأ أيَّها اختار. وبدون هذا السطر يُقرأ
 * من ضبط نطاقه منذ شهورٍ على أنّه «لم يختر بعد»: تُفتح شاشةُ الاختيار في
 * وجهه وكأنّ ما ضبطه لم يكن. وأثرُ ذلك ليس ارتباكًا وحسب — من يعيد الاختيار
 * قد يختار غير ما هو عليه فيغيّر عنوان متجره وهو يظنّ أنّه يؤكّده.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * الخيار يُكتب لمن له نطاقٌ ولا خيار له — ولمن له خيارٌ يُترك خيارُه.
         *
         * ولا `insertOrIgnore` هنا: جدول الإعدادات بلا قيدٍ فريد على
         * (business_id, key)، فالتجاهل لا يتجاهل شيئًا. والمكتوب سلفًا
         * يُستثنى صراحةً: صفّان لمفتاحٍ واحد يعنيان قيمتين لإعدادٍ واحد ولا
         * أحد يعرف أيّهما تُقرأ.
         */
        $already = DB::table('settings')
            ->where('key', 'site_domain_mode')
            ->whereNotNull('business_id')
            ->pluck('business_id')
            ->all();

        $rows = DB::table('settings')
            ->where('key', 'site_domain')
            ->whereNotNull('business_id')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->whereNotIn('business_id', $already ?: [0])
            ->distinct()
            ->pluck('business_id');

        foreach ($rows as $businessId) {
            DB::table('settings')->insert([
                'business_id' => $businessId,
                'key' => 'site_domain_mode',
                'value' => DomainOptions::OWN,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'site_domain_mode')->delete();
        DB::table('settings')->where('key', 'site_subdomain')->delete();
    }
};
