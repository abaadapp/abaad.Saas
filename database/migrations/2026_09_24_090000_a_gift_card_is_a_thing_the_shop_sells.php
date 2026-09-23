<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كرتُ الهدية — نصُّه مرتَّبًا كما كتبه صاحبُه، وملفٌّ يُرفق به.
 *
 * ═══ ولمَ ثلاثةُ أعمدةٍ لا واحد ═══
 *
 * `card_message` قائمٌ منذ أوّل يوم ويحمل النصّ. وما يُضاف هنا هو ما لم
 * يكن يُسأل عنه: كيف يُصفّ النصّ على الكرت، وأيُّ ملفٍّ أرفقه الزبون.
 *
 * والمحاذاةُ عمودٌ لا حرفٌ يُدسّ في النصّ: من دسّها في النصّ طبعها على
 * الكرت حرفًا زائدًا، ومن قرأ النصّ في شاشة التجهيز قرأها معه.
 *
 * والملفُّ مسارٌ على القرص الخاصّ لا على العامّ: يرفعه زائرٌ مجهول من
 * الموقع، ويُقرأ ببابٍ يسأل عن صاحب الطلب — انظر
 * `OrderAttachmentController`، والعلّةُ نفسُها المشروحة في
 * `FinancialAttachmentController`.
 *
 * والاسمُ الأصليّ يُحفظ إلى جانب المسار: المخزَّنُ عشوائيٌّ لئلّا يُخمَّن،
 * والمعروضُ هو ما سمّاه صاحبُه وإلّا نزل إلى المنسّق بلا معنى.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'card_align')) {
                // 'right' | 'center' | 'left' — والفراغُ يعني ما اعتاده المحلّ
                $table->string('card_align', 8)->nullable()->after('card_message');
            }
            if (! Schema::hasColumn('orders', 'card_file')) {
                $table->string('card_file', 255)->nullable()->after('card_align');
            }
            if (! Schema::hasColumn('orders', 'card_file_name')) {
                $table->string('card_file_name', 160)->nullable()->after('card_file');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['card_align', 'card_file', 'card_file_name'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
