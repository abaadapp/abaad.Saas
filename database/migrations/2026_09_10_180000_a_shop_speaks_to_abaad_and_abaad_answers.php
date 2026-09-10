<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المتجرُ يكلّم أبعادًا، وأبعادُ تُجيب — في مكانٍ يبقى.
 *
 * ═══ ما كان ═══
 *
 * لم يكن. صاحبُ المحلّ يجد عطبًا فيبحث عن رقمِ واتساب أحدهم، أو يكتب
 * بريدًا لا يعرف أيقرؤه أحد. وما جرى بينه وبين الدعم يبقى في هاتفِ من
 * ردّ عليه: يخرج الموظّف فيخرج معه تاريخُ المتجر كلُّه، ومن يخلفه يسأل
 * السؤالَ نفسَه من أوّله.
 *
 * ═══ وحدٌّ لا يُعبر ═══
 *
 * هذه محادثاتُ **أبعاد ↔ صاحب المتجر**. وليست محادثاتِ **المتجر ↔
 * زبائنه** — تلك في `whatsapp_messages` تحت `business_id`، ولا سطرَ هنا
 * يقرؤها ولا سطرَ هناك يكتب هنا. وخلطُهما يعني أنّ من يفتح لوحةَ المنصّة
 * يقرأ ما كتبته زبونةٌ لمحلّ ورودٍ عن هديّةٍ لزوجها.
 *
 * ═══ والقنواتُ أربعٌ وواحدةٌ تعمل ═══
 *
 * `channel` عمودٌ من أوّل يوم وإن كانت `in_app` وحدَها تعمل: إضافةُ
 * واتسابَ الدعم لاحقًا تصير صفًّا بقيمةٍ أخرى لا هجرةً تُعيد بناء
 * الجدول. و`external_*` كذلك — معرّفُ الخيط عند المزوّد ومعرّفُ الرسالة،
 * وهما ما يحتاجه البريدُ ليردّ في الخيط نفسِه.
 *
 * ولا يُقال إنّها تعمل قبل أن تعمل: عمودٌ فارغٌ ينتظر ليس قناةً موصولة.
 *
 * ═══ والقراءةُ لكلّ قارئ ═══
 *
 * `support_reads` صفٌّ لكلّ قارئٍ لا عمودٌ واحد على المحادثة: الدعمُ
 * أكثرُ من واحد، ومقروءٌ واحد يعني أنّ فتحَ زميلٍ للمحادثة يُطفئ الشارةَ
 * عن الجميع فتُنسى.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_conversations', function (Blueprint $table) {
            $table->id();

            /* رقمٌ يُقال في الهاتف — ولا يتبدّل بعد أن يُقطع */
            $table->string('reference', 24)->unique();

            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            /*
             * ومن فتحها — ويبقى الصفُّ إن حُذف الموظّف.
             *
             * محادثةٌ تُمحى لأنّ كاشيرًا تُرك عملُه تمحو معها ما قاله الدعم.
             */
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('subject', 160);
            $table->string('category', 40);

            // in_app · whatsapp · email · instagram · facebook — وواحدةٌ تعمل
            $table->string('channel', 20)->default('in_app');

            // new · open · waiting_customer · waiting_abaad · resolved · closed
            $table->string('status', 20)->default('new');

            // low · normal · high · urgent — شأنُ المنصّة وحدها
            $table->string('priority', 20)->default('normal');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            /*
             * وآخرُ رسالةٍ عمودٌ لا حسابٌ من الجدول الآخر.
             *
             * الترتيبُ عليه في كلّ فتحةِ شاشة، و`max(created_at)` على جدول
             * الرسائل يعني مسحَه كلَّه لترتيب عشرين صفًّا.
             */
            $table->timestamp('last_message_at')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // خيطُ المزوّد الخارجيّ — للبريد وواتساب حين يوصلان
            $table->string('external_thread_id', 191)->nullable();
            $table->json('external_meta')->nullable();

            $table->timestamps();

            /* الشاشةُ ترتّب بالأحدث حديثًا، وتُرشّح بالحالة والمسؤول */
            $table->index(['status', 'last_message_at']);
            $table->index(['business_id', 'last_message_at']);
            $table->index(['assigned_to', 'status']);
            $table->index('channel');
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->constrained('support_conversations')->cascadeOnDelete();

            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * وجهةُ الرسالة حقلٌ واحد لا اثنان.
             *
             * `business` · `platform` · `system`. و«الاتّجاه» يُشتقّ منه، فلا
             * يُكتب عمودًا ثانيًا: حقلان يقولان الشيء نفسه يفترقان يومًا،
             * فتصير رسالةُ الدعم مكتوبةً «واردة».
             */
            $table->string('sender_scope', 12);

            $table->text('body')->nullable();

            /*
             * والملاحظةُ الداخليّة لا تبلغ صاحبَ المتجر بحال.
             *
             * لا في الشاشة، ولا في إشعار، ولا في قناةٍ خارجيّةٍ تُوصَل غدًا.
             * والترشيحُ في `SupportMessage::visibleToBusiness` موضعًا واحدًا:
             * شرطٌ مكرَّرٌ في خمسة استعلامات يُنسى في السادس.
             */
            $table->boolean('is_internal')->default(false);

            /*
             * وحدثُ النظام سطرٌ في الخيط لا رسالة.
             *
             * «عُيّنت إلى فلان» و«صارت بانتظار العميل» — تُقرأ في مكانها من
             * التسلسل لا في سجلٍّ آخر يُفتح بجانبه.
             */
            $table->string('event', 32)->nullable();
            $table->json('event_meta')->nullable();

            $table->string('external_message_id', 191)->nullable();
            $table->json('external_meta')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create('support_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')
                ->constrained('support_messages')->cascadeOnDelete();

            /*
             * القرصُ الخاصّ لا العامّ — كأخواتها في المالية.
             *
             * صورةُ شاشةٍ يرفعها تاجرٌ ليُرِي عطبًا قد يكون فيها أسماءُ
             * زبائنه ومبالغُهم. ورابطٌ على `public/storage` يُخدَم بلا أن
             * يُستدعى Laravel ولا يُسأل عن جلسة.
             */
            $table->string('disk', 20)->default('local');
            $table->string('path');

            // الاسمُ كما سمّاه صاحبه — والمخزَّن عشوائيّ فلا يُخمَّن
            $table->string('name');
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');

            $table->timestamps();

            /*
             * وفهرسٌ على أبيها صراحةً.
             *
             * PostgreSQL لا تُفهرس المفتاحَ الأجنبيَّ من تلقاء نفسها — تُنشئ
             * قيدًا لا فهرسًا. وكلُّ فتحةِ محادثةٍ تُحمّل مرفقاتِ رسائلها
             * بـ`where message_id in (…)`، فبلا فهرسٍ مسحٌ كاملٌ للجدول في
             * كلّ مرّة. ويعمل صامتًا حتّى يكبر الجدول.
             */
            $table->index('message_id');
        });

        Schema::create('support_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->constrained('support_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /* آخرُ ما قرأه هذا القارئ — وما بعده غيرُ مقروء */
            $table->unsignedBigInteger('last_read_message_id')->default(0);

            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_reads');
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_conversations');
    }
};
