<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * لكلّ فرعٍ حسابُ أعماله عند Google، ولكلّ فرعٍ آخرُ سحبٍ له.
 *
 * ═══ العطب ═══
 *
 * إذنٌ واحدٌ يفتح أكثرَ من حساب أعمال: حسابٌ شخصيّ ومجموعةُ مواقع مثلًا. وكان
 * `account_name` عمودًا واحدًا على المتجر يُكتب فوقه عند كلّ ربط. فمن ربط فرعَه
 * الثاني من حسابٍ آخر كسر الأوّل بصمت: يُطلب موقعُه تحت حسابٍ ليس حسابه
 * فتردّ Google ٤٠٤، والشاشةُ لا تقول أيَّ الفروع انقطع.
 *
 * ═══ ولا يُنقل شيء ═══
 *
 * أعمدةٌ جديدةٌ فارغة. والصفوفُ القديمة تقرأ `account_name` كما كانت حتّى يُعاد
 * ربطُها — انظر `GoogleBusiness::businessAccount`.
 *
 * ═══ و`api_blocked_at` غيرُ `last_error` ═══
 *
 * ذاك نصٌّ يُقرأ، وهذا حالٌ يُفرَّق به: مشروعُ أبعاد عند Google لم يُعتمد بعد،
 * فلا يُطلب من التاجر إصلاحُ ما ليس بيده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_google_places', function (Blueprint $table) {
            $table->string('gbp_account')->nullable()->after('gbp_location');
            $table->timestamp('gbp_synced_at')->nullable()->after('gbp_linked_at');
            $table->string('gbp_sync_error', 300)->nullable()->after('gbp_synced_at');
        });

        Schema::table('google_business_accounts', function (Blueprint $table) {
            $table->timestamp('api_blocked_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('google_business_accounts', function (Blueprint $table) {
            $table->dropColumn('api_blocked_at');
        });

        Schema::table('branch_google_places', function (Blueprint $table) {
            $table->dropColumn(['gbp_account', 'gbp_synced_at', 'gbp_sync_error']);
        });
    }
};
