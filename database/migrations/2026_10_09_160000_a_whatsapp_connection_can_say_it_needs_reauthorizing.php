<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وصلةُ واتساب تستطيع أن تقول «تحتاج إعادة تفويض».
 *
 * وُلد العمود `status` بعشرين حرفًا يوم كانت أطولُ حالةٍ `inactive`. ثمّ
 * جاءت `WhatsAppConnection::REAUTH = 'reauthorization_required'` بأربعةٍ
 * وعشرين — فصار حفظُها على PostgreSQL يسقط بـ «value too long»، وSQLite
 * تقبلها صامتة فلم يرَ ذلك اختبار. فالحالةُ موجودةٌ في الكود ولا تُكتب أبدًا.
 *
 * يُوسَّع العمود إلى ٦٤ ولا يُمسّ غيرُه: لا قيمةَ تتغيّر، والافتراضيّ يبقى
 * `inactive`، والفهرسان على `status` يبقيان. وتوسيعُ varchar على PostgreSQL
 * تغييرُ تعريفٍ لا إعادةُ كتابةٍ للجدول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->string('status', 64)->default('inactive')->change();
        });
    }

    /**
     * لا يُصغَّر العمود عند الرجوع.
     *
     * لو كُتبت `reauthorization_required` بعد هذه الهجرة لسقط التصغيرُ إلى ٢٠
     * على PostgreSQL، أو قطعها في غيرها فتصير حالةً لا يعرفها الكود. وعمودٌ
     * أوسع لا يكسر الكودَ القديم، فالأسلم أن يبقى.
     */
    public function down(): void
    {
        //
    }
};
