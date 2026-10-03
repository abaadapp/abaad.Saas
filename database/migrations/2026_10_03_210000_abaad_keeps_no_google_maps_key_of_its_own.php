<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * أبعاد لا تحمل مفتاحًا لخرائط Google — يُحذف صفُّ مفتاحها وحالُ فوترته.
 *
 * ═══ ما كان ═══
 *
 * `google_places_key` في إعدادات المنصّة (`business_id = null`) مفتاحٌ واحدٌ
 * يقرأ لكلّ تاجرٍ لم يلصق مفتاحه، فتقع نداءاتُ المتاجر كلِّها على فاتورة
 * أبعاد. وكان معه `google_billing_state` و`google_trial_ends_at`: حالُ فوترة
 * ذاك المفتاح وموعدُ انتهاء تجربته (`GoogleBilling`، وقد حُذف).
 *
 * ═══ وما تفعله هذه ═══
 *
 * صارت خرائطُ Google ميزةً اختياريّة بمفتاح التاجر وحده، ولم يبقَ في الكود
 * ما يقرأ هذه الصفوف. فتُحذف الثلاثةُ بأسمائها، في صفوف المنصّة وحدها — فلا
 * يبقى في القاعدة مفتاحٌ لأبعاد يعود إليه كودٌ يومًا.
 *
 * ولا يُمسّ شيءٌ سواها:
 *
 *   - مفاتيحُ التجّار (`google_api_key` تحت `business_id` كلِّ متجر) باقية.
 *   - `branch_google_places` كلُّه باقٍ: معرّفُ المكان والاسم والمعدّل
 *     والعدد ورابطُ الخرائط وختمُ المزامنة — ومنه رابطُ التقييم في الإيصال.
 *
 * ═══ ولا رجوع ═══
 *
 * `down` لا يُعيد المفتاح: سرٌّ حُذف لا يُخترع. ومن أراده فهو في مشروع أبعاد
 * على Google Cloud — ويُستحسن إلغاؤه هناك.
 */
return new class extends Migration
{
    private const KEYS = ['google_places_key', 'google_billing_state', 'google_trial_ends_at'];

    public function up(): void
    {
        DB::table('settings')
            ->whereNull('business_id')
            ->whereIn('key', self::KEYS)
            ->delete();
    }

    public function down(): void
    {
        // لا يُعاد سرٌّ حُذف — انظر أعلاه
    }
};
