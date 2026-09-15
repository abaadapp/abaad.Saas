<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرفقاتُ دفتر المبيعات — صورةٌ تُرسَل وصورةٌ تصل.
 *
 * ═══ ولمَ جدولٌ مستقلّ ═══
 *
 * للدعم `support_attachments` منذ أوّل يوم، ولم يكن للمبيعات شيء: كلُّ ما
 * وصل من عميلٍ محتمَلٍ صورةً أو ورقةً كُتب سطرًا يقول «أرسل صورة — لا تُعرض
 * هنا»، وكلُّ ما أراد الموظّفُ إرسالَه لم يكن له بابٌ يُرسَل منه.
 *
 * ولا يُوسَّع جدولُ الدعم ليحمل الاثنين: ذاك تحت محادثةٍ لها `business_id`،
 * وهذا تحت عميلٍ محتمَلٍ ليس لأحدٍ من التجّار. وعمودُ «أيُّ نوعٍ صاحبُه»
 * يفتح على القارئ بابَ خلطِ الدفترين — وهو ما يحرسه
 * `CrmStaysOutOfTenantDataTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('crm_messages')->cascadeOnDelete();

            /*
             * والقرصُ عمودٌ لا ثابتٌ في الكود.
             *
             * اليومَ `local`، وغدًا قد يُنقل ما مضى إلى مساحةٍ أخرى ويبقى
             * القديمُ مكانه. ومسارٌ بلا قرصِه يُقرأ من الخطأ فلا يُفتح.
             */
            $table->string('disk', 20)->default('local');
            $table->string('path');

            /* ما سمّاه صاحبُه — يُعرض ولا يُبنى منه مسار */
            $table->string('name', 240);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_attachments');
    }
};
