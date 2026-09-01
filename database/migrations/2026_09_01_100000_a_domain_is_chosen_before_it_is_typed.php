<?php

use App\Models\DomainRequest;
use App\Support\DomainOptions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * شاشة الدومين صارت تسأل قبل أن تطلب — وهذه هجرةُ ما يترتّب على السؤال.
 *
 * شيئان:
 *
 * ١) جدول `domain_requests`: من طلب من أبعاد أن تشتري له نطاقًا. لا مسجّل
 *    نطاقاتٍ في النظام، فالطلب يقف هنا حتى يراه المشغّل ويجهّزه.
 *
 * ٢) من ضبط نطاقه قبل هذه النسخة يُكتب له `site_domain_mode = 'own'`.
 *    وبدون هذا السطر تُفتح شاشةُ الاختيار الأولى في وجه تاجرٍ نطاقُه يعمل
 *    منذ شهور: يفتح إعداداته فيجد ثلاث بطاقاتٍ تسأله «كيف تريد عنوانك؟»
 *    وكأنّ ما ضبطه لم يكن. وأثرُ ذلك ليس ارتباكًا وحسب — من يعيد الاختيار
 *    قد يختار غير ما هو عليه فيغيّر عنوان متجره وهو يظنّ أنّه يؤكّده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // النطاق كما كتبه التاجر — رغبةٌ لا ملكية، فلا قيد فريد عليه
            $table->string('domain');
            // ملاحظة التاجر عند الطلب، ثم ردّ المشغّل عند الإغلاق
            $table->string('note', 500)->nullable();
            $table->string('status')->default(DomainRequest::PENDING);
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // الشاشتان تسألان السؤال نفسه: طلبات هذا المتجر، الأحدث أوّلًا
            $table->index(['business_id', 'status']);
        });

        /*
         * الخيار يُكتب لمن له نطاقٌ ولا خيار له — ولمن له خيارٌ يُترك خيارُه.
         *
         * ولا `insertOrIgnore` هنا: جدول الإعدادات بلا قيدٍ فريد على
         * (business_id, key)، فالتجاهل لا يتجاهل شيئًا. والاستثناء يُحسب
         * صراحةً: صفّان لمفتاحٍ واحد يعنيان قيمتين لإعدادٍ واحد ولا أحد يعرف
         * أيّهما تُقرأ.
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
        Schema::dropIfExists('domain_requests');

        DB::table('settings')->where('key', 'site_domain_mode')->delete();
        DB::table('settings')->where('key', 'site_subdomain')->delete();
    }
};
