<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * البريد يصير اختياريًّا: الكاشير يدخل برمزه.
 *
 * كان NOT NULL وفريدًا على مستوى المنصة كلّها. والتفرّد صحيحٌ ويبقى — البريد
 * معرّف دخولٍ لا بيان اتصال، ولو تكرّر لما عرف النظام أيّ حسابٍ يفتح. لكن
 * إلزامه من لا يدخل به أصلًا كان يعني أن أوّل متجرين يريدان `cashier@`
 * يصطدمان، فيخترع الثاني بريدًا وهميًّا لا يقرأه أحد — ويصير في القاعدة
 * عناوين لا تُراسَل، ثمّ تُرسَل إليها تقارير المتجر يومًا.
 *
 * والفهرس الفريد يبقى كما هو: SQLite وPostgreSQL يسمحان بتكرار NULL فيه،
 * فلا يتعارض الاختياري مع التفرّد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // العودة تستلزم ملء الفارغ أولًا، وإلا فشل القيد
        \Illuminate\Support\Facades\DB::table('users')->whereNull('email')
            ->update(['email' => \Illuminate\Support\Facades\DB::raw("'user-' || id || '@local.invalid'")]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
