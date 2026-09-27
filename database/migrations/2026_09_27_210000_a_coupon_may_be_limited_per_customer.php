<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حدُّ الكوبون لكلّ زبون — عمودٌ يُضاف، ولا يمسّ كوبونًا قائمًا.
 *
 * ═══ ما كان ═══
 *
 * `max_uses` حدٌّ إجماليٌّ واحد: كودٌ حدُّه مرّتان يستهلكه أوّلُ زبونين ثمّ
 * يُغلق على الناس كلِّهم. والتاجر يريد غيرَ ذلك: «مرّتان **لكلّ** زبون» —
 * أحمدُ مرّتان ومحمّدٌ مرّتان، ولا ينتهي الكود.
 *
 * ═══ والفراغُ يعني «كما كان» ═══
 *
 * `null` هو أصلُ العمود، فكلُّ كوبونٍ في القاعدة اليوم يبقى على إعداده
 * بحرفه: حدُّه الإجماليّ وتاريخُه وحدُّه الأدنى ونوعُه وحالُه. ولا يستيقظ
 * تاجرٌ على كودٍ صار مقيَّدًا لم يقيّده هو.
 *
 * ولا يُصفَّر `used_count`، ولا تُبنى استعمالاتٌ ماضيةٌ بالتخمين: من استعمل
 * كودًا قبل اليوم لا سجلَّ له، ولا يُختلق له سجلٌّ. والحدُّ لكلّ زبون يُحسب
 * ممّا يُسجَّل بعد تفعيله — وهو أصدقُ من رقمٍ يُخترع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedInteger('per_customer_limit')->nullable()->after('max_uses');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('per_customer_limit');
        });
    }
};
