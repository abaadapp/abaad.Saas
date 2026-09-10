<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * لكلِّ فرعٍ مكانُه على الخريطة — لا للمتجر كلِّه مكانٌ واحد.
 *
 * ═══ ما كان ═══
 *
 * `google_place_id` صفٌّ في `settings` تحت `business_id`. ومعناه أنّ متجرًا
 * بثلاثة فروعٍ له معرّفٌ واحد: فيُطبع على إيصال فرع المعبيلة رمزٌ يفتح ملفَّ
 * فرع الخوض، ويكتب زبونٌ اشترى من هنا تقييمًا يُحسب هناك. والتاجر لا يرى
 * ذلك أبدًا — هو لا يمسح إيصالاته بنفسه.
 *
 * ولكلّ فرعٍ عند Google ملفٌّ مستقلّ: عنوانُه ومعدّلُه وعددُ تقييماته. فالربطُ
 * ينتمي إلى الفرع بطبعه، وجعلُه للمتجر كان خطأً في الموضع لا نقصًا في الميزة.
 *
 * ═══ ولمَ جدولٌ لا أعمدةٌ على `branches` ═══
 *
 * `branches` عمودُ الفقرات: تشير إليه الطلباتُ وأرصدةُ المخزون وأجهزةُ نقطة
 * البيع والورديّات. وستّةُ أعمدةٍ تصف واجهةَ طرفٍ ثالثٍ قد تُبدّل Google
 * شكلَها في أيّ نسخة لا تُعلَّق على ذلك العمود.
 *
 * وفي الجدول المستقلّ ما لا يصلح عمودًا أصلًا: **مَن ربط ومتى**، و**متى
 * فُكّ الربط**. فالفكُّ لا يمحو الصفَّ — يختمه. ومن يسأل غدًا «مَن غيّر
 * معرّف المكان؟» يجد جوابًا، لا فراغًا.
 *
 * ═══ وما لا يُخزَّن هنا ═══
 *
 * **نصوصُ التقييمات لا تُكتب في قاعدتنا.** شروطُ Google تمنع الاحتفاظ
 * بمحتوى الأماكن، وتقييمٌ حُذف من هناك يجب أن يختفي من هنا. فتُسحب حيّةً
 * وتبقى في الذاكرة ساعاتٍ ثمّ تسقط — كما كانت.
 *
 * والمعدّلُ والعددُ يُحفظان: رقمان يُعرضان في قائمة الفروع، وسحبُهما من
 * Google في كلّ فتحةِ شاشةٍ نداءٌ مدفوعٌ لكلّ فرع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_google_places', function (Blueprint $table) {
            $table->id();

            /*
             * صفٌّ واحدٌ لكلّ فرع — والتفرّدُ في القاعدة لا في الكود.
             *
             * ربطٌ يُبدَّل يُحدِّث صفَّه، ولا يترك صفَّين لفرعٍ واحد يقرأ
             * أحدَهما الإيصالُ والآخرَ الشاشة.
             */
            $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();

            /* معرّفُ المكان عند Google — وهو وحده ما يُسمح بحفظه دائمًا */
            $table->string('place_id');

            /* الاسمُ كما تكتبه Google — ليتحقّق التاجر بعينه أنّه فرعُه */
            $table->string('place_name');
            $table->string('maps_url', 500)->nullable();

            /*
             * المعدّلُ والعدد — مسحوبان لا محسوبان.
             *
             * و`null` لمكانٍ لا تقييمَ له، لا صفرٌ: الصفرُ يُقرأ «سيّئ»
             * والفراغُ يُقرأ «لم يُقيَّم بعد»، وبينهما فرقٌ يراه صاحبُ المحلّ.
             */
            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedInteger('review_count')->nullable();
            $table->timestamp('synced_at')->nullable();

            /* مَن ربط ومتى — ويبقى الصفُّ إن خرج الموظّف */
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();

            /*
             * وفكُّ الربط ختمٌ لا محو.
             *
             * الصفُّ يبقى ليُقرأ أنّ هذا الفرع كان مربوطًا بهذا المكان وفُكّ
             * في هذا اليوم. ومحوُ الصفّ يمحو السؤالَ وجوابَه معًا.
             */
            $table->timestamp('unlinked_at')->nullable();

            $table->timestamps();

            /* قائمةُ الفروع تُرشَّح بالمربوط — والمفكوكُ ليس مربوطًا */
            $table->index('unlinked_at');
        });

        $this->carryTheOldLinksOver();
    }

    /**
     * ما رُبط قبل اليوم ينتقل إلى فرعه — ولا يضيع.
     *
     * ═══ وأيُّ فرع؟ ═══
     *
     * المعرّفُ كان للمتجر، فلا شيء في القاعدة يقول لأيّ فرعٍ هو. وأقربُ
     * قراءةٍ صادقة: **أقدمُ فرع** — هو الذي أُنشئ مع المتجر، وهو الذي كان
     * قائمًا يوم ربط صاحبُه محلَّه.
     *
     * ومتجرٌ بفرعٍ واحدٍ لا احتمالَ فيه أصلًا، وهو حالُ عامّة المتاجر.
     *
     * ولا تخمينَ يتجاوز ذلك: الفروعُ الأخرى تبقى **غيرَ مربوطة** ويرى
     * صاحبُها ذلك في الشاشة فيربطها. ونسخُ المعرّف نفسِه إلى الفروع الثلاثة
     * هو بعينه العطبُ الذي جاءت هذه الهجرة لتُصلحه.
     */
    private function carryTheOldLinksOver(): void
    {
        $rows = DB::table('settings')
            ->where('key', 'google_place_id')
            ->whereNotNull('business_id')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->get(['business_id', 'value']);

        foreach ($rows as $row) {
            $branchId = DB::table('branches')
                ->where('business_id', $row->business_id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('id');

            // متجرٌ ربط محلَّه ثمّ حُذفت فروعُه كلُّها: لا موضعَ يُنقل إليه
            if ($branchId === null) {
                continue;
            }

            DB::table('branch_google_places')->insert([
                'branch_id' => $branchId,
                'place_id' => $row->value,
                /*
                 * والاسمُ فارغٌ حتى أوّل مزامنة.
                 *
                 * لم يكن يُحفظ قبل اليوم — كان يُسحب حيًّا في كلّ فتحة. ولا
                 * يُكتب هنا اسمُ الفرع مكانَه: اسمُ Google هو ما يتحقّق به
                 * التاجر أنّ المكان مكانُه، وكتابةُ اسمنا محلَّه تجعل الشاشة
                 * تؤكّد له ما لم تقله Google.
                 */
                'place_name' => '',
                'linked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_google_places');
    }
};
