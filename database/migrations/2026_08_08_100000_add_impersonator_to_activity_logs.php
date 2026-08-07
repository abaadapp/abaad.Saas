<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * من فعلها حقًّا: الدعم أم التاجر؟
 *
 * «الدخول كتاجر» يجعل كل عملية تُقيَّد باسم التاجر نفسه — فحين يعدّل الدعمُ
 * سعرًا أو يحذف منتجًا، يقول السجلّ إن صاحب المحل فعلها. ثم يتصل يسأل «من
 * غيّر السعر؟» فتفتحان السجلّ معًا وتجدان اسمه: إمّا يُتَّهم بما لم يفعل، أو
 * يُتَّهم الدعم بما لا يثبت. وفي نزاعٍ حقيقي — فاتورة محذوفة أو مخزون معدَّل —
 * هذا سجلٌّ لا يصلح دليلًا.
 *
 * والاسم يُنسخ مع المعرّف: حسابُ موظف دعمٍ يُحذف بعد سنة، ولا يجوز أن يُمحى
 * معه من السجلّ أنّ أحدًا من الدعم كان هناك.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('impersonator_id')->nullable()->after('user_name');
            $table->string('impersonator_name')->nullable()->after('impersonator_id');
            // الاستعلام المتوقّع: «أرني ما فعله الدعم في هذا المتجر»
            $table->index(['business_id', 'impersonator_id']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'impersonator_id']);
            $table->dropColumn(['impersonator_id', 'impersonator_name']);
        });
    }
};
