<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لكلّ ورقةٍ رمزٌ يعيد صاحبَها إليها.
 *
 * الورقة تُطبع مرّةً ثمّ تعيش في جيبٍ أو دُرج: تبهت حروفُها، وتُبلَّل،
 * وتضيع. والزبون الذي يعود بضمانٍ بعد ستّة أشهر يحمل قصاصةً لا تُقرأ.
 * فيحمل أسفلُها رمزًا يفتح نسختَها الحيّة — لا تبهت ولا تضيع.
 *
 * والرمزُ عشوائيٌّ لا رقمٌ متسلسل: `/i/1` و`/i/2` يعنيان أنّ من رأى فاتورةً
 * واحدة يقرأ فواتير المحلّ كلَّها بزيادة الرقم. واثنان وعشرون حرفًا من
 * ستّةٍ وستّين احتمالًا لا تُخمَّن.
 *
 * والصلةُ متعدّدةُ الشكل: اليوم الطلبُ وحده يحمل رمزًا — هو ما يصل يد
 * الزبون — وغدًا قد يحمله غيرُه بلا هجرةٍ ثانية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('token', 32)->unique();
            $table->morphs('linkable');
            $table->timestamps();

            /*
             * ورقةٌ واحدة ورمزٌ واحد.
             *
             * بلا هذا القيد تصنع كلُّ طباعةٍ رمزًا جديدًا: يطبع التاجر
             * الفاتورة ثلاث مرّات فتحمل النسخُ الثلاث ثلاثةَ عناوين لشيءٍ
             * واحد، ويبقى في القاعدة صفّان ميّتان لكلّ حيّ.
             */
            $table->unique(['linkable_type', 'linkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_links');
    }
};
