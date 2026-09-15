<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * للمتجر نسخةٌ يقرؤها هو عن شهرٍ أُغلق.
 *
 * ═══ ولمَ جدولٌ جديد ولم يكفِ ما هو قائم ═══
 *
 * في النظام نسخةٌ احتياطيّة تعمل منذ شهور: `backup:run` تكتب JSON لكلّ متجر،
 * وتتحقّق منه، وتحذف ما جاوز مدّته. وهي **نسخةُ استعادة**: مصدرُها الجداولُ
 * الخام كما هي، وقارئُها الحاسوب لا الإنسان، وغايتُها أن يعود المتجرُ كما
 * كان بعد عطب.
 *
 * وهذا شيءٌ آخر: ورقةُ إكسل يفتحها محاسبُ المتجر، وفواتيرُ PDF بتصميمها
 * الذي اعتُمد، ومرفقاتٌ كما رُفعت. لا تُستعاد بها قاعدةٌ ولا يُدّعى ذلك.
 * وخلطُهما في جدولٍ واحد يجعل مدّةَ الاحتفاظ واحدةً لهما — وأربعةَ عشرَ
 * يومًا مدّةٌ صحيحةٌ لنسخةِ عطبٍ وخاطئةٌ تمامًا لأرشيفٍ ضريبيّ يُطلب بعد سنة.
 *
 * ═══ والوحدانيّة في القاعدة لا في الكود ═══
 *
 * المولّدُ بابان: مجدولٌ أوّلَ الشهر، وزرٌّ يضغطه صاحبُ المتجر. والبابان
 * يقعان معًا — يضغط الزرَّ في اللحظة التي يعمل فيها المجدول، أو يضغطه
 * مرّتين لأنّ الأولى لم تُظهر شيئًا بعد. وفحصٌ في PHP ثمّ إدراجٌ ليس ذرّيًّا:
 * بين السؤال والكتابة يمرّ طلبٌ آخر.
 *
 * فالفهرسُ الفريد هو الحارس، والكودُ يلتقط انكسارَه ويردّ «هذا موجود».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            /*
             * السنةُ والشهرُ عددان لا نصٌّ «2026-08».
             *
             * الترتيبُ نصًّا يصحّ ما دامت الصيغةُ واحدة، ويكسر يوم يُكتب صفٌّ
             * بـ«2026-8». والعددُ يُرتَّب ويُقارَن ولا يحتمل صيغتين.
             */
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            // بانتظار | قيد الإنشاء | جاهز | فشل | منتهي — انظر BusinessArchive
            $table->string('status', 20)->default('بانتظار');

            /*
             * القرصُ يُخزَّن مع المسار لا يُفترض.
             *
             * اليوم `local`، وغدًا قرصٌ بعيد. وصفٌّ كُتب بالأمس على القرص
             * المحلّيّ يبقى مقروءًا بعد التحويل لأنّه يحمل قرصَه معه —
             * وبلا ذلك تصير كلُّ الأرشيفات القديمة روابطَ إلى العدم.
             */
            $table->string('storage_disk', 40)->nullable();
            $table->string('storage_path')->nullable();

            $table->unsignedBigInteger('file_size')->nullable();

            // بصمةُ SHA-256 — ٦٤ حرفًا ست عشريًّا
            $table->string('checksum', 64)->nullable();

            $table->unsignedSmallInteger('archive_version')->default(1);

            // من طلبه بيده — وفارغٌ يعني المجدول، وهو تمييزٌ يُقرأ في السجلّ
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            /*
             * وسببُ الفشل نصٌّ يُقرأ، لا أثرُ استثناءٍ كامل.
             *
             * أثرُ الاستثناء يحمل مساراتِ الخادم وأسماءَ ملفّاته إلى شاشةٍ
             * يفتحها تاجر — ولا يفهم منها شيئًا ولا يجوز أن يراها.
             */
            $table->string('failure_reason', 500)->nullable();

            $table->timestamps();

            $table->unique(['business_id', 'year', 'month']);

            // «أرِني أرشيفات متجري من الأحدث» — وهو الاستعلامُ الوحيد في الشاشة
            $table->index(['business_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_archives');
    }
};
