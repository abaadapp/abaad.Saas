<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * القالبُ لا نُسمّيه نحن معتمَدًا — ميتا تقولها.
 *
 * ═══ العطب ═══
 *
 * `whatsapp_template_mappings` يحمل اسمَ القالب ولغتَه ومقبضَ `enabled`.
 * و`enabled` مقبضُنا نحن: نُشعله عند الربط ونُطفئه إن شئنا. ولا عمودَ فيه
 * يقول **هل اعتمدت ميتا هذا القالبَ أصلًا**.
 *
 * فيوم رُبط الرقم سُمّيت القوالبُ الستّة `enabled` ولم تكن مُنشأةً عند ميتا
 * بعد. ثمّ أُنشئت فصارت `PENDING`. وفي الحالين تقرأ شاشةُ التاجر «جاهز»
 * بأربع علاماتٍ خضراء — لأنّ الخطوات الأربعَ تقرأ **إعدادًا** ولا واحدةَ
 * منها تقرأ ما عند ميتا.
 *
 * وأوّلُ طلبٍ يُؤكَّد بعدها: يُبنى الصفّ، وتُحجز الحصّة، ويُنادى ميتا،
 * فتردُّه — لأنّ القالب غير معتمَد. وتُردّ الحصّة ويُقيَّد `failed`، وينتظر
 * الزبون رسالةً لا تأتي.
 *
 * وطمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب: الثاني يُزعج، والأوّل يُنيم.
 *
 * ═══ ولمَ عمودان لا واحد ═══
 *
 * `meta_status` يقول ما قالته ميتا. و`meta_synced_at` يقول **متى** قالته —
 * وبدونه لا يُفرَّق بين «فحصناه فوجدناه غيرَ معتمَد» و«لم نفحصه قطّ».
 * وهما حالان لا يُعامَلان معاملةً واحدة: الأولى تمنع الإرسال بسببٍ يُقرأ،
 * والثانية تُقال كما هي — «لم يُفحص» — ولا تُغلق بابًا يعمل.
 *
 * وفارغٌ يعني «لم يُسأل بعد» لا «مرفوض»: عمودٌ جديد على صفوفٍ قائمة يبدأ
 * فارغًا، ولو قُرئ رفضًا لَأطفأ إشعاراتِ كلّ متجرٍ لحظةَ الترحيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_template_mappings', function (Blueprint $table) {
            // APPROVED · PENDING · REJECTED · PAUSED · DISABLED — بحروف ميتا نفسِها
            $table->string('meta_status', 20)->nullable()->after('enabled');
            $table->timestamp('meta_synced_at')->nullable()->after('meta_status');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_template_mappings', function (Blueprint $table) {
            $table->dropColumn(['meta_status', 'meta_synced_at']);
        });
    }
};
