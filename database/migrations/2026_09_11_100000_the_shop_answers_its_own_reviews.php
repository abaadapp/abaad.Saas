<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المتجرُ يقرأ تقييماته ويردّ عليها — بإذنِ صاحبه لا بمفتاحٍ عامّ.
 *
 * ═══ الفرقُ عن Places ═══
 *
 * `branch_google_places` يقرأ الملفَّ **العامّ**: اسمٌ ومعدّلٌ وعددٌ وخمسةُ
 * نصوصٍ تختارها Google. ولا رَدَّ فيه ولا قائمةَ تقييماتٍ كاملة — تلك بابٌ
 * آخر: «Business Profile APIs»، يُفتح بإذنِ صاحب الملفّ (OAuth) ووصولٍ
 * مُعتمَدٍ من Google.
 *
 * ═══ ولمَ تُخزَّن التقييماتُ هنا وقد مُنع خزنُها هناك ═══
 *
 * ما يُمنع خزنُه محتوى **الأماكن** المقروءُ بـPlaces. وهذه تقييماتُ ملفِّ
 * التاجر نفسِه، يقرؤها بإذنه ليديرها: يردّ عليها، ويُنبَّه إلى الجديد،
 * ويُنبَّه إلى المنخفض. ولا يُعرف «الجديد» بلا صفٍّ يقول ما رُئي من قبل.
 *
 * وما يختفي من Google يُختم هنا `gone_at` ولا يبقى معروضًا كأنّه قائم.
 *
 * ═══ والرمزُ لا يُكتب نصًّا ═══
 *
 * رمزُ الوصول والتجديد معمَّيان في النموذج. ومن نسخ القاعدة لا ينسخ معها
 * إذنًا يردّ باسم التاجر على زبائنه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_business_accounts', function (Blueprint $table) {
            $table->id();

            /* إذنٌ واحدٌ لكلّ متجر — وتفرّدٌ في القاعدة لا فحصٌ في الكود */
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();

            /* حسابُ Google كما يسمّيه هو: `accounts/123…` */
            $table->string('account_name')->nullable();
            $table->string('account_email')->nullable();

            // معمَّيان في النموذج — انظر GoogleBusinessAccount::$casts
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            /*
             * النطاقاتُ الممنوحة كما ردّتها Google — لا كما طلبناها.
             *
             * المستخدم قد يمنح بعضَ ما طُلب. وقراءةُ المطلوب مكانَ الممنوح
             * تعني شاشةً تعرض «الردّ على التقييم» ثمّ يُردّ الردُّ بـ٤٠٣.
             */
            $table->string('scopes', 500)->nullable();

            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();

            /* الفصلُ ختمٌ لا محو — والرمزان يُمحيان عنده وحدهما */
            $table->timestamp('revoked_at')->nullable();

            /* آخرُ خطأٍ من Google — يُقرأ في الشاشة فيُعرف لمَ توقّفت المزامنة */
            $table->string('last_error', 300)->nullable();
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();
        });

        Schema::table('branch_google_places', function (Blueprint $table) {
            /*
             * موقعُ الفرع في ملفّ الأعمال — `locations/456…`.
             *
             * وهو **غيرُ** `place_id`: ذاك معرّفُ المكان على الخرائط للعامّة،
             * وهذا معرّفُ الموقع في حساب صاحبه. ولا يُشتقّ أحدُهما من الآخر.
             */
            $table->string('gbp_location')->nullable()->after('place_id');
            $table->timestamp('gbp_linked_at')->nullable()->after('gbp_location');

            /* موقعٌ واحدٌ لا يُربط بفرعين: تقييماتُه تُسحب مرّتين وتُعدّ مرّتين */
            $table->unique('gbp_location');
        });

        Schema::create('google_business_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            /* معرّفُ التقييم عند Google — فريدٌ في الجدول فلا يُكتب مرّتين */
            $table->string('review_id')->unique();

            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();

            /* اسمُ الكاتب كما تعرضه Google — ولا بريدَ ولا معرّفَ شخص */
            $table->string('author', 191)->nullable();
            $table->string('author_photo', 500)->nullable();

            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('updated_google_at')->nullable();

            /* ردُّ المتجر — كما هو **عند Google** لا كما كُتب عندنا */
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();

            /*
             * أوّلُ مرّةٍ وصلنا فيها هذا التقييم.
             *
             * منه يُعرف «الجديد» في الجرس. ولو قيس بتاريخ Google لَانهال على
             * التاجر مئةُ إشعارٍ يوم يربط ملفَّه أوّلَ مرّة.
             */
            $table->timestamp('first_seen_at')->nullable();

            /* واختفى من Google: يُختم ولا يُمحى — ولا يبقى معروضًا كأنّه قائم */
            $table->timestamp('gone_at')->nullable();

            $table->timestamps();

            $table->index(['branch_id', 'reviewed_at']);
            $table->index(['branch_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_business_reviews');

        Schema::table('branch_google_places', function (Blueprint $table) {
            $table->dropUnique(['gbp_location']);
            $table->dropColumn(['gbp_location', 'gbp_linked_at']);
        });

        Schema::dropIfExists('google_business_accounts');
    }
};
