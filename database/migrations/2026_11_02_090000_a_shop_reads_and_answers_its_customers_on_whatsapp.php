<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * صندوقُ محادثات واتساب — ما يكتبه زبونُ المحلّ إليه، وما يردّ به موظّفوه.
 *
 * ═══ ما يُضاف — وكلُّه إضافة ═══
 *
 * - `businesses.whatsapp_inbox_enabled`: يفتحه فريقُ أبعاد لنشاطٍ بعينه،
 *   ومغلقٌ افتراضًا (`WhatsAppInbox::available`). ويُفتح هنا مرّةً لمن في
 *   `whatsapp.inbox_businesses` — ولا اسمَ ولا رقمَ في الكود.
 * - `users.whatsapp_inbox`: صلاحيةُ الموظّف على المحادثات — فارغٌ (لا شيء)،
 *   أو `view`، أو `reply`. ومستقلٌّ عن قائمة الأقسام: لا يُورَث بدورٍ ولا
 *   بقسم التسويق ولا التكاملات، ويمنحه صاحبُ النشاط وحده.
 * - `whatsapp_chats`: محادثةٌ لكلّ زبون على رقمٍ بعينه من أرقام المحلّ.
 * - `whatsapp_chat_messages`: الواردُ والصادر — ومن ردّ، ومن أيّ رقم، وحالُه.
 * - `whatsapp_chat_attachments`: صورةٌ أو PDF على رسالة.
 * - `whatsapp_chat_reads`: آخرُ ما قرأه كلُّ موظّف — ومنه «غير المقروء».
 *
 * ولكلّ جدولٍ `business_id`: الاستعلامُ يبدأ من متجر الجلسة في كلّ صفّ، ولا
 * يُستنتج المتجرُ من أبٍ قد يُنسى شرطُه.
 *
 * ولا يُحذف ولا يُعاد تسميةُ شيء، ولا يُمسّ صفُّ وصلةٍ ولا رقم.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('businesses', 'whatsapp_inbox_enabled')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->boolean('whatsapp_inbox_enabled')->default(false);
            });
        }

        if (! Schema::hasColumn('users', 'whatsapp_inbox')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('whatsapp_inbox', 10)->nullable();
            });
        }

        if (! Schema::hasTable('whatsapp_chats')) {
            Schema::create('whatsapp_chats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('whatsapp_connection_id')->nullable()->index()
                    ->constrained('whatsapp_connections')->nullOnDelete();
                // رقمُ المحلّ الذي جرت عليه — هويّةُ المحادثة لا صفُّ الوصلة
                $table->string('phone_number_id', 64);
                $table->string('source_mode', 20);
                $table->string('customer_phone', 32);
                $table->string('profile_name', 150)->nullable();
                $table->foreignId('customer_id')->nullable()->index()->constrained()->nullOnDelete();
                // آخرُ رسالةٍ من الزبون — منها وحدها تُحسب نافذةُ الأربعِ والعشرين
                $table->timestamp('last_inbound_at')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->string('last_preview', 200)->nullable();
                $table->string('last_direction', 3)->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'phone_number_id', 'customer_phone']);
                $table->index(['business_id', 'last_message_at']);
            });
        }

        if (! Schema::hasTable('whatsapp_chat_messages')) {
            Schema::create('whatsapp_chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('chat_id')->constrained('whatsapp_chats')->cascadeOnDelete();
                $table->string('direction', 3);
                $table->text('body')->nullable();
                $table->string('media_type', 20)->nullable();
                // وإشعارٌ وصل مرّتين يُكتب مرّة — والفهرسُ الفريد هو الحارسُ خلفه
                $table->string('wamid', 191)->nullable()->unique();
                $table->foreignId('whatsapp_connection_id')->nullable()->index()
                    ->constrained('whatsapp_connections')->nullOnDelete();
                // الرقمُ الذي خرجت منه أو وصلت إليه
                $table->string('phone_number_id', 64)->nullable();
                $table->foreignId('sender_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
                $table->string('sender_name', 150)->nullable();
                $table->string('status', 20);
                $table->string('error_code', 60)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamps();

                $table->index(['chat_id', 'id']);
                $table->index(['business_id', 'direction', 'id']);
            });
        }

        if (! Schema::hasTable('whatsapp_chat_attachments')) {
            Schema::create('whatsapp_chat_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('message_id')->index()->constrained('whatsapp_chat_messages')->cascadeOnDelete();
                $table->string('disk', 20);
                $table->string('path');
                $table->string('name', 240);
                $table->string('mime', 100);
                $table->unsignedInteger('size')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('whatsapp_chat_reads')) {
            Schema::create('whatsapp_chat_reads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('chat_id')->constrained('whatsapp_chats')->cascadeOnDelete();
                $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('last_read_message_id')->default(0);
                $table->timestamps();

                $table->unique(['chat_id', 'user_id']);
            });
        }

        $ids = array_values(array_filter(array_map('intval', (array) config('whatsapp.inbox_businesses', []))));

        if ($ids !== []) {
            DB::table('businesses')->whereIn('id', $ids)->update(['whatsapp_inbox_enabled' => true]);
        }
    }

    public function down(): void
    {
        // إسقاطُ الجداول يمحو محادثاتِ زبائنَ حقيقيّين — فلا تراجعَ بعد أوّل رسالة
        if (Schema::hasTable('whatsapp_chat_messages') && DB::table('whatsapp_chat_messages')->exists()) {
            throw new RuntimeException('لا يُتراجع: في صندوق محادثات واتساب رسائلُ زبائن. ارجع بالكود وحده (Revert PR) واترك الجداول — الكودُ القديم لا يقرؤها.');
        }

        foreach (['whatsapp_chat_reads', 'whatsapp_chat_attachments', 'whatsapp_chat_messages', 'whatsapp_chats'] as $table) {
            Schema::dropIfExists($table);
        }

        foreach ([['users', 'whatsapp_inbox'], ['businesses', 'whatsapp_inbox_enabled']] as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
