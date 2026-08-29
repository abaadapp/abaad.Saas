<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الطلب يعرف مستلِمه وساعته.
 *
 * محلّ الورد يبيع شيئين في بيعةٍ واحدة: بضاعةً لمشترٍ، وخدمةً لمستلِمٍ آخر
 * في وقتٍ آخر. والفاتورة تعرف المشتري وحدها، فبقي الباقي يُكتب في
 * «ملاحظات» نصًّا حرًّا: «توصيل الخميس ٦ م لسارة ٩١٢٣٤٥٦٧». نصٌّ لا
 * يُرشَّح ولا يُرتَّب عليه، ولا تعرف منه لوحةُ التجهيز شيئًا — فيقرأ العامل
 * الملاحظات واحدةً واحدة كل صباح ليعرف أيّها اليوم.
 *
 * كلّها nullable: في القاعدة ١٢٠٦ طلبًا كُتبت قبل هذا الترحيل، وأيّ عمودٍ
 * إلزاميٍّ يعني قيمةً مخترَعة تُكتب فيها. والطلب القديم يبقى كما هو: يُعرض
 * ويُطبع ويظهر في التقارير ويُعدَّل — و`scheduled_for = null` تعني «لا موعد»
 * لا «متأخّر».
 *
 * ولا جدول توصيلٍ منفصل: `delivery_notes` مستندُ شحنةٍ يُحرّك المخزون ويُوقَّع
 * عند التسليم، وليس بيانات الطلب. وبياناتُ الطلب يجب أن تُقرأ من الطلب نفسه
 * لا من مستندٍ قد لا يُنشأ أصلًا — فلوحةُ التجهيز تسأل «إلى أين ومتى؟» قبل
 * أن يوجد أيّ إشعار تسليم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // المستلِم: مستقلٌّ عن المشتري ولا يُنشأ له عميل — من يُهدى إليه
            // مرّةً واحدة ليس زبونًا، وإنشاءُ صفٍّ له يُضخّم قائمة العملاء بمن
            // لم يشترِ قطّ ويُفسد كلّ متوسّطٍ يُحسب عليها
            $table->string('recipient_name', 120)->nullable()->after('customer_name_en');
            $table->string('recipient_phone', 32)->nullable()->after('recipient_name');

            // pickup | delivery — انظر App\Support\FlowerOrder
            $table->string('fulfillment_type', 16)->nullable()->after('recipient_phone');

            /*
             * موعد التسليم المطلوب — غير `ordered_at`.
             *
             * `ordered_at` لحظةُ تسجيل الطلب، وهذا موعدُ تنفيذه. طلبٌ يُسجَّل
             * اليوم لتسليمه بعد أسبوع: العمودان يفترقان سبعة أيام، وكل ترتيبٍ
             * للعمل يقوم على الثاني لا الأول.
             */
            $table->dateTime('scheduled_for')->nullable()->after('fulfillment_type');

            $table->string('occasion_type', 32)->nullable()->after('scheduled_for');

            // بطاقة الإهداء
            $table->text('card_message')->nullable()->after('occasion_type');
            $table->string('sender_name', 120)->nullable()->after('card_message');
            /*
             * `hide_sender` بقيمةٍ افتراضية لا nullable: هو سؤالُ نعم/لا،
             * وnull فيه حالةٌ ثالثة لا معنى لها تُقرأ في كل موضعٍ بحسب ظنّ
             * قارئها. والصفر آمن: من لم يطلب الإخفاء لم يُخفَ اسمه.
             */
            $table->boolean('hide_sender')->default(false)->after('sender_name');

            // التوصيل — و`delivery_fee` موجودٌ منذ البداية فلا يُكرَّر
            $table->string('delivery_address', 500)->nullable()->after('hide_sender');
            $table->text('delivery_notes')->nullable()->after('delivery_address');

            /*
             * `notes` القديم يبقى كما هو ولا يُنقل ولا يُعاد تسميته: فيه
             * كتابةُ ١٢٠٦ طلبًا، وتقسيمُه آليًّا إلى «للسائق» و«لنا» تخمينٌ
             * على نصٍّ حرّ. فيُضاف الداخليّ إلى جانبه، ويبقى القديم يُعرض
             * كما كان.
             */
            $table->text('internal_notes')->nullable()->after('delivery_notes');
        });

        Schema::table('orders', function (Blueprint $table) {
            /*
             * فهرسان لا أكثر.
             *
             * لوحة التجهيز تسأل سؤالًا واحدًا: طلبات هذا المتجر (وفرعه) غير
             * المغلقة، مرتّبةً بموعدها. فالفهرس المركّب على [المتجر، الموعد]
             * يخدم الترشيح والترتيب معًا. و[المتجر، الحالة] يخدم تبويبات
             * شاشة المبيعات.
             *
             * ولا فهرس على `fulfillment_type`: قيمتان اثنتان تقسمان الجدول
             * نصفين، وفهرسٌ كهذا لا يُستعمل أصلًا — يقرؤه المخطّط ثم يمسح
             * الجدول لأنه أرخص.
             */
            $table->index(['business_id', 'scheduled_for'], 'orders_business_scheduled_idx');
            $table->index(['business_id', 'status'], 'orders_business_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_business_scheduled_idx');
            $table->dropIndex('orders_business_status_idx');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'recipient_name', 'recipient_phone', 'fulfillment_type', 'scheduled_for',
                'occasion_type', 'card_message', 'sender_name', 'hide_sender',
                'delivery_address', 'delivery_notes', 'internal_notes',
            ]);
        });
    }
};
