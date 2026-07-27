<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** سجل حركات نقاط الولاء: كل كسب/استبدال بتاريخه ورصيده بعد الحركة. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('type', 16);              // earn | redeem
            $table->integer('points');               // موجب للكسب، سالب للاستبدال
            $table->integer('balance_after');        // الرصيد بعد الحركة (لعرض دقيق)
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
    }
};
