<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * استعادة الحساب: بريدٌ يُثبَت أنّ صاحبه يقرؤه.
 *
 * سبعةٌ من كلّ عشرة تجّار يدخلون ببريدٍ على `@abaadapp.om` — عنوانٌ لا صندوق
 * خلفه. والرابط كان يُسلَّم إلى `businesses.email`، وهو حقلٌ كتبه موظّف
 * المنصّة ولم يؤكّده أحد: لا `verified_at` له، ولا رسالةَ خرجت إليه قطّ.
 *
 * فالبريد وحده لا يكفي — يلزم أن يُثبَت أنّه بريد صاحبه. وهذا ما يضيفه هذا
 * الترحيل: عنوانٌ، وختمُ تحقّقٍ عليه، ومسارٌ يُثبته.
 *
 * ------------------------------------------------------------------------
 *
 * ولمَ على `users` لا على `businesses`؟
 *
 * الاستعادة تُعيد **حسابَ دخول**، والحساب صفٌّ في `users`. وللمتجر الواحد
 * قد يكون مديران، فبريدٌ واحد على المتجر يترك السؤال بلا جواب: صندوقُ
 * أيّهما هذا، ومن يُعاد إليه؟ ثمّ إنّ لارافيل كلّها — الرموز والجلسات
 * والبصمة — مبنيّةٌ على `users`، والعزل بين المتاجر يأتي مجّانًا من
 * `users.business_id`.
 *
 * ولا تفرّد عالميّ على العنوان: صاحبُ محلّين يستعمل بريده نفسه لهما، ومنعُه
 * يدفعه إلى بريدٍ ثانٍ لا يقرؤه. والأمان من هويّة الحساب لا من ندرة العنوان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('recovery_email')->nullable()->after('email');
            /*
             * الختم هو المعنى كلّه.
             *
             * عنوانٌ بلا ختم عنوانٌ مكتوب؛ والختم لا يوضع إلا بعد أن يعود
             * رمزٌ أُرسل إليه. ولذلك لا يُقبل في الاستعادة إلا المختوم.
             */
            $table->timestamp('recovery_email_verified_at')->nullable()->after('recovery_email');
        });

        /*
         * ما بدأه صاحب الحساب — حالةٌ يملكها الخادم لا المتصفّح.
         *
         * لو حُملت الحالة في الطلب («تحقّقتُ، وهذا معرّف متجري») لَكفى تعديلُ
         * سطرٍ في أدوات المتصفّح لتغيير كلمة مرور متجرٍ آخر. فالمتصفّح لا
         * يحمل إلا رمزًا مبهمًا، والخادم وحده يعرف صاحبه.
         */
        Schema::create('password_recovery_challenges', function (Blueprint $table) {
            $table->id();
            // ما يحمله المتصفّح — عشوائيٌّ لا يُخمَّن ولا يدلّ على صاحبه
            $table->string('token', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->cascadeOnDelete();

            // otp_sent | email_verified | authorized | used
            $table->string('state', 32)->default('otp_sent');

            /** ختم اجتياز الرمز — بعده وحده يُسمح بكلمةٍ جديدة */
            $table->timestamp('verified_email_at')->nullable();
            /** بدء صلاحية التعيين: قصيرةٌ ومرّةً واحدة */
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at');

            // للتدقيق لا للثقة — لا يُقرأ منه قرار
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'state']);
            $table->index('expires_at');
        });

        /*
         * الرمز — بصمتُه لا هو.
         *
         * ستّة أرقامٍ تُخزَّن نصًّا تعني أنّ من قرأ صفًّا واحدًا في القاعدة
         * غيّر كلمة مرور صاحبه. والبصمة باتجاهٍ واحد: لا تُستخرج، وتُقارَن.
         */
        Schema::create('password_recovery_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained('password_recovery_challenges')->cascadeOnDelete();
            // password_reset | recovery_email_verification — رمزٌ لغرضه وحده
            $table->string('purpose', 40);
            /** العنوان الذي أُرسل إليه — يُقارَن عند التحقّق فلا يُقبل رمزٌ لعنوانٍ آخر */
            $table->string('target_email');
            $table->string('otp_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['challenge_id', 'purpose']);
        });

        /*
         * ما كُتب في `businesses.email` يُنقل عنوانًا **غير مختوم**.
         *
         * وهذا أهمّ قرارٍ في الترحيل: ختمُه لأنّه موجود يعني أن نُسمّي
         * «مُتحقَّقًا منه» عنوانًا كتبه موظّفٌ بيده ولم يفتحه صاحبه قطّ —
         * ومن قرأه يومًا يملك المتجر. فيُنقل تسهيلًا لا إثباتًا: يظهر لصاحبه
         * مكتوبًا، ولا يُقبل في الاستعادة حتى يعود منه رمز.
         *
         * والعناوين الداخلية لا تُنقل: `@abaadapp.om` لا صندوق خلفه.
         */
        $domain = \App\Support\MerchantAccount::DOMAIN;

        // استعلامٌ مرتبط لا وصلة: `UPDATE … JOIN` لا تعرفها SQLite، والاختبارات عليها
        DB::table('users')
            ->where('role', 'admin')
            ->whereNull('recovery_email')
            ->whereNotNull('business_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('businesses')
                ->whereColumn('businesses.id', 'users.business_id')
                ->whereNotNull('businesses.email')
                ->where('businesses.email', '!=', '')
                ->where('businesses.email', 'not like', '%'.$domain))
            ->update([
                'recovery_email' => DB::raw(
                    '(select email from businesses where businesses.id = users.business_id)'
                ),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('password_recovery_otps');
        Schema::dropIfExists('password_recovery_challenges');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['recovery_email', 'recovery_email_verified_at']);
        });
    }
};
