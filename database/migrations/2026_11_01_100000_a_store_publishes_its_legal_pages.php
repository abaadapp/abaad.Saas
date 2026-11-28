<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الصفحاتُ القانونيّة لكلّ متجر — سياسةُ الخصوصيّة وتعليماتُ حذف البيانات.
 *
 * جدولٌ جديدٌ فارغ، ولا يُكتب فيه صفٌّ عند الترحيل: القالبُ يُبنى من بيانات
 * المتجر حين يُفتح، ولا يصير صفحةً عامّةً حتّى يراجعه صاحبُه وينشره. فلا
 * يتبدّل شيءٌ في متجرٍ قائمٍ بالنشر. انظر `Store\StoreLegal`.
 *
 * والإصدارُ صفر هو المسوّدة (صفٌّ واحدٌ يُعدَّل)، وما فوقه نسخٌ منشورةٌ لا
 * تُعدَّل ولا تُحذف — فـ«ماذا كانت تقول سياستُنا يوم كذا؟» يُجاب بنصّها.
 *
 * والشروطُ ليست هنا: لها نسخُها منذ #136 (`store_terms_versions`)، وتُقرأ
 * منها ولا تُكرَّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_legal_versions')) {
            return;
        }

        Schema::create('store_legal_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedInteger('version');
            $table->text('body_ar');
            $table->text('body_en');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // ورقمُ الإصدار فريدٌ في نوعه ومتجره — فلا مسوّدتان ولا نسختان برقمٍ واحد
            $table->unique(['business_id', 'kind', 'version']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_legal_versions');
    }
};
