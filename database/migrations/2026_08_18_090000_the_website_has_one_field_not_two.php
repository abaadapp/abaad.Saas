<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * نطاق الموقع مفتاحٌ واحد: `site_domain`.
 *
 * كان اثنين — `website` في بيانات النشاط و`site_domain` في أدوات التسويق —
 * فيضبط التاجر نطاقه في أحدهما ويقرأ الزرّ الآخر. وما ضُبط قبل هذه النسخة
 * يُنقل هنا لا يُترك: من كتب نطاقه في الإعدادات لا يجب أن يفتح الشاشة
 * الجديدة فيجدها فارغة ويظنّ أنه لم يكتبه.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = DB::table('settings')->where('key', 'website')->get();

        foreach ($legacy as $row) {
            $value = trim((string) $row->value);
            if ($value === '') {
                continue;
            }

            $current = DB::table('settings')
                ->where('business_id', $row->business_id)
                ->where('key', 'site_domain')
                ->value('value');

            // المكتوب في الشاشة الجديدة أحدث، فلا يُدهَس بالقديم
            if (trim((string) $current) !== '') {
                continue;
            }

            // النطاق وحده: الشاشة الجديدة تعرضه بلا بادئة وتتحقّق منه كذلك
            $domain = preg_replace('#^https?://#i', '', $value);
            $domain = rtrim(explode('/', $domain)[0], '.');

            DB::table('settings')->updateOrInsert(
                ['business_id' => $row->business_id, 'key' => 'site_domain'],
                ['value' => $domain, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        DB::table('settings')->where('key', 'website')->delete();
    }

    public function down(): void
    {
        // لا رجعة: المفتاح القديم حُذف بعد نقل قيمته، والقيمة باقية في الجديد
    }
};
