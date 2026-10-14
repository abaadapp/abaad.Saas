<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * طلبُ «تواصل معنا» يُرى في CRM — غيرَ مقروءٍ حتّى يفتحه قارئُه.
 *
 * ═══ ولمَ جدولٌ ثانٍ لا `crm_reads` ═══
 *
 * `crm_reads.last_read_message_id` مؤشّرٌ في تسلسل `crm_messages` — واتساب.
 * وطلبُ الموقع ملاحظةٌ في `crm_notes`، وتسلسلٌ آخر. ورقمُ ملاحظةٍ يُكتب في
 * عمود رسالة يُطفئ شارةَ المحادثات أو يُضيئها بلا رسالة. فالحالتان منفصلتان
 * جدولًا ومعنًى، وبالشكل نفسِه: صفٌّ لكلّ (قارئ، عميل)، ومؤشّرٌ لآخر ما قُرئ.
 *
 * ولا يُكتب صفٌّ إلّا حين يفتح أحدٌ عميلًا له طلبُ موقع — لا صفَّ لكلّ
 * مستخدمٍ في كلّ عميل.
 *
 * ═══ وما قبل اليوم لا يُحسب ═══
 *
 * يُكتب أعلى رقم ملاحظةٍ ساعةَ الترحيل في إعدادات المنصّة. وما كان عنده أو
 * قبله يُعدّ مقروءًا للجميع: الشارةُ تبدأ بما يصل بعد النشر، لا برقمٍ ضخمٍ
 * من طلباتٍ عولجت قبل أن توجد.
 *
 * ═══ والفهرسُ على الملاحظات ═══
 *
 * الشارةُ تُحسب في كلّ صفحةٍ يفتحها مديرُ المنصّة. و(user_id, user_name, id)
 * يقرأ طلباتِ الموقع بعد المؤشّر وحدها — لا يمرّ على كلّ ملاحظة موظّف.
 */
return new class extends Migration
{
    private const SINCE_KEY = 'crm_website_inquiries_unread_after_note_id';

    public function up(): void
    {
        Schema::create('crm_website_inquiry_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();

            /* آخرُ طلبِ موقعٍ قرأه هذا القارئ من هذا العميل — `crm_notes.id` */
            $table->unsignedBigInteger('last_read_note_id')->default(0);

            $table->timestamps();

            $table->unique(['user_id', 'lead_id']);
            $table->index('lead_id');
        });

        Schema::table('crm_notes', function (Blueprint $table) {
            $table->index(['user_id', 'user_name', 'id'], 'crm_notes_author_id_index');
        });

        DB::table('settings')->whereNull('business_id')->where('key', self::SINCE_KEY)->delete();
        DB::table('settings')->insert([
            'business_id' => null,
            'key' => self::SINCE_KEY,
            'value' => (string) (int) DB::table('crm_notes')->max('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->whereNull('business_id')->where('key', self::SINCE_KEY)->delete();

        Schema::table('crm_notes', function (Blueprint $table) {
            $table->dropIndex('crm_notes_author_id_index');
        });

        Schema::dropIfExists('crm_website_inquiry_reads');
    }
};
