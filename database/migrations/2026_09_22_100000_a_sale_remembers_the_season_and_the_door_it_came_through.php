<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * البيعةُ تذكر موسمَها والبابَ الذي دخلت منه.
 *
 * ═══ لمَ لقطةٌ على البند لا قراءةٌ من جدول الصلة ═══
 *
 * «أيُّ الأصناف في الموسم اليوم» لا يقول «أيُّ بيعةٍ كانت للموسم»: صنفٌ
 * يُزال من رمضان بعد انقضائه تختفي بيعاتُه من تقرير رمضان، وصنفٌ في
 * موسمين معًا لا يُعرف لأيّهما بيع. فتُكتب النسبةُ على البند ساعةَ البيع
 * ولا تُقرأ من صلةٍ تتبدّل. والاسمُ معها: موسمٌ يُعاد تسميته أو يُحذف لا
 * يُفقد التقريرَ سياقَه.
 *
 * ═══ ولمَ لا مفتاحَ أجنبيًّا ═══
 *
 * مفتاحٌ يُسقط البندَ مع الموسم يمحو بيعةً وقعت — والبندُ مالٌ لا يُمحى.
 * ومفتاحٌ يُصفّره يمحو النسبةَ وحدَها. فلا مفتاح: المعرّفُ يبقى كما كُتب
 * والاسمُ إلى جانبه.
 *
 * ═══ والبابُ على الطلب ═══
 *
 * لا عمودَ في `orders` يقول من أين جاءت — ولا حاجةَ كانت: كلُّ طلبٍ من
 * الصندوق. والتقريرُ يفرّق بين قنوات البيع، والموقعُ يومًا سيُنشئ طلبًا.
 * فيُكتب البابُ على الطلب حين يُعرف، ويبقى فارغًا لما سبق هذه الهجرة:
 * لا يُخمَّن تاريخٌ لم يُكتب.
 *
 * وكلُّ ما هنا إضافيٌّ: لا صفٌّ يُعاد كتابتُه ولا قيمةٌ تُملأ بأثرٍ رجعيّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('season_id')->nullable()->after('custom_details');
            $table->string('season_name', 120)->nullable()->after('season_id');
            // استعلامُ التقرير: بنودُ موسمٍ بعينه
            $table->index('season_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel', 20)->nullable()->after('pos_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['season_id']);
            $table->dropColumn(['season_id', 'season_name']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
