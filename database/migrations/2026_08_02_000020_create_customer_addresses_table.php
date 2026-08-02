<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عناوين العميل — عنوان واحد لا يكفي للتوصيل: للعميل منزل وعمل، ولكلٍّ
 * مدينة ومنطقة وشارع. النسخة القديمة عرضت هذا في الواجهة بلا جدول خلفه
 * (بيانات وهمية في Alpine تضيع عند التحديث)، فهذا الجدول يجعلها حقيقية.
 *
 * customers.address القديم يبقى كما هو: حقل نصّي حرّ للعنوان الرئيسي،
 * والعناوين المتعددة تُبنى فوقه لا مكانه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label');            // المنزل / العمل …
            $table->string('city');
            $table->string('area')->nullable();
            $table->string('street')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['customer_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
