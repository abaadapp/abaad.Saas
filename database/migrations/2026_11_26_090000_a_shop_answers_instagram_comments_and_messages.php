<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ‏‏أتمتةُ إنستقرام — أعمدةٌ تقبل الفراغ على جداول الأتمتة والإشعارات، ولا صفَّ قائمٌ يتبدّل.
 *
 *  - `channel_automations.posts`: المنشوراتُ التي اختارها التاجر (فارغةٌ: كلُّها). و`public_replies`:
 *    ما يُكتب علنًا تحت التعليق بعد أن تصل الرسالةُ الخاصّة.
 *  - `channel_automation_runs.message_id`: معرّفُ رسالتنا عند Meta — به يُعرف أنّ رسالةً مرتدّةً
 *    (`message_echoes`) خرجت منّا لا من يد التاجر.
 *  - `instagram_events.counterpart`: من كتب أو من كُتب إليه — يُسأل به «أردّ التاجرُ بنفسه؟». و`attempts`:
 *    كم مرّةً أُجّل لعطبٍ عابرٍ عند Meta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_automations', function (Blueprint $table) {
            $table->json('posts')->nullable();
            $table->json('public_replies')->nullable();
        });

        Schema::table('channel_automation_runs', function (Blueprint $table) {
            $table->string('message_id', 191)->nullable();
        });

        Schema::table('instagram_events', function (Blueprint $table) {
            $table->string('counterpart', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->index(['business_id', 'counterpart', 'field'], 'instagram_events_counterpart_index');
            $table->index(['processed_at', 'received_at'], 'instagram_events_pending_index');
        });
    }

    public function down(): void
    {
        // ‏‏ولا تُسقط الأعمدةُ وفيها ما اختاره التاجر وكتبه: أرجِع الكود وحده، وتبقى بلا قارئ
        if (DB::table('channel_automations')->where('channel', 'instagram')->exists()) {
            throw new RuntimeException('أتمتة إنستقرام: في القاعدة أتمتاتٌ كتبها التجّار — لا تُسقط الأعمدة. أرجِع الكود وحده.');
        }

        Schema::table('instagram_events', function (Blueprint $table) {
            $table->dropIndex('instagram_events_counterpart_index');
            $table->dropIndex('instagram_events_pending_index');
            $table->dropColumn(['counterpart', 'attempts']);
        });

        Schema::table('channel_automation_runs', fn (Blueprint $table) => $table->dropColumn('message_id'));
        Schema::table('channel_automations', fn (Blueprint $table) => $table->dropColumn(['posts', 'public_replies']));
    }
};
