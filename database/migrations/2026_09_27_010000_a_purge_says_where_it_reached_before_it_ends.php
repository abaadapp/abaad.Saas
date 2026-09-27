<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صفٌّ واحدٌ لكلّ حذفٍ نهائيّ — يقول أين وصل، ويبقى بعد أن تزول الشركة.
 *
 * ═══ ولمَ جدولٌ ولا يكفي سجلُّ النشاط ═══
 *
 * سجلُّ النشاط يُكتب **بعد** أن يتمّ الفعل: سطرٌ يقول «حُذفت». وهذه شاشةٌ
 * تُسأل **أثناءه**: أين وصلت؟ أرشفةٌ أم رفعٌ أم حذف؟ ومديرُ منصّةٍ أغلق
 * الصفحةَ ثمّ فتحها يجب أن يجد الجواب — فالحالُ تُكتب في القاعدة لا في
 * الذاكرة المؤقّتة: مسحُ الذاكرة لا يُنسي عمليّةً تمسّ بيانات تاجر.
 *
 * ═══ و`business_id` فريدٌ ولا مفتاحَ أجنبيَّ له ═══
 *
 * فريدٌ لأنّ الشركةَ تُمحى مرّةً واحدةً في عمرها: فصفٌّ واحدٌ لها مهما
 * تكرّرت المحاولات، وإعادةُ المحاولة تُحدِّث صفَّها لا تُنشئ ثانيًا. وهذا
 * حارسُ التزامن الحقيقيّ في القاعدة — والقفلُ في الذاكرة يوفّر عملًا
 * مكرَّرًا، وهذا يمنع خطأً. ولكلٍّ موضعُه.
 *
 * وبلا مفتاحٍ أجنبيّ لأنّ غايةَ الصفّ أن يبقى **بعد** أن يُمحى صفُّ
 * الشركة. ومفتاحٌ بـCASCADE يمحو الدليلَ مع المدلول، وبـRESTRICT يمنع
 * الحذفَ الذي جاء الصفُّ ليوثّقه.
 *
 * والاسمُ يُنسخ هنا لأنّه لا يُقرأ من جدولٍ بعد المحو — وهو وحدَه: لا
 * هاتفَ ولا عنوانَ ولا بياناتِ زبون. أقلُّ ما يُعرَف به ما مُحي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_purges', function (Blueprint $table) {
            $table->id();

            /* فريدٌ بلا مفتاحٍ أجنبيّ — انظر ترويسة الملفّ */
            $table->unsignedBigInteger('business_id')->unique();
            $table->string('business_name', 150);

            /* مَن طلب — والاسمُ معه، فحسابٌ يُحذف لاحقًا لا يُفقد الشهادة */
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_by_name', 150)->nullable();

            // pending | running | done | failed — انظر PurgeRun::STATUSES
            $table->string('status', 10)->default('pending');
            // queued | archiving | uploading | verifying | deleting | files | done
            $table->string('stage', 12)->default('queued');

            /* ما جرى — أعدادٌ لا نسخٌ من البيانات */
            $table->unsignedInteger('rows_total')->nullable();
            $table->unsignedInteger('users_deleted')->nullable();
            $table->unsignedInteger('files_deleted')->nullable();

            /* الأرشيفُ المحلّيّ وبصمتُه */
            $table->string('archive_path', 255)->nullable();
            $table->string('archive_sha256', 64)->nullable();
            $table->unsignedBigInteger('archive_bytes')->nullable();

            /* والنسخةُ المستقلّة — وبصمتُها بعد التشفير */
            $table->string('offsite_disk', 40)->nullable();
            $table->string('offsite_path', 255)->nullable();
            $table->string('offsite_sha256', 64)->nullable();
            $table->unsignedBigInteger('offsite_bytes')->nullable();

            /* لحظةُ نجاحِ استعادةٍ حقيقيّة — لا وعدٌ بها */
            $table->timestamp('verified_at')->nullable();

            /* ملفّاتٌ تعذّر حذفُها — مساراتٌ لا محتوى */
            $table->json('failures')->nullable();
            $table->string('error', 500)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_purges');
    }
};
