<?php

namespace App\Support;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\SupportConversation;
use App\Models\SupportMessage;

/**
 * خيطٌ وصل بابًا وهو لبابٍ آخر — يُنقل بيدٍ، ولا يُحزر.
 *
 * ═══ لماذا يُحتاج هذا أصلًا ═══
 *
 * الواردُ على رقمٍ واحد يُوزَّع بـ**من كتب** لا بـ**ما كتب**: رقمٌ يُطابق
 * تاجرًا عندنا يذهب إلى الدعم، وغريبٌ لم نراسله قطّ يصير عميلًا محتمَلًا.
 * وهذا فاصلٌ يُقاس ولا يُخطئ — ولذلك بُني.
 *
 * لكنّه لا يقرأ النيّة. فتاجرٌ مسجَّلٌ عندنا يكتب «كم سعر الباقة الأكبر؟»
 * يذهب إلى الدعم، وهو سؤالُ مبيعاتٍ لا سؤالُ عطب. والعكسُ كذلك: غريبٌ
 * يكتب «تطبيقكم لا يفتح عندي» يصير عميلًا محتمَلًا وهو صاحبُ شكوى.
 *
 * ═══ ولمَ لا يُحلّ بقراءة النصّ ═══
 *
 * لأنّ أوّلَ ما يُكسَر حينها هو الفاصلُ نفسُه: تصير زبونةُ محلِّ ورودٍ سألت
 * عن سعر باقةٍ من الورد «عميلًا محتمَلًا» في دفتر مبيعاتنا. والحدسُ يخطئ
 * صامتًا؛ والعمودُ يخطئ ظاهرًا ويُصحَّح بضغطة.
 *
 * فالموزِّعُ يبقى كما هو، ويُعطى الإنسانُ زرَّ تصحيحٍ للحالة النادرة.
 *
 * ═══ وما يُنقل وما يبقى ═══
 *
 * يُنقل: الرقمُ، والرسائلُ بنصّها ومعرّفاتها عند ميتا، **وختمُ نافذة
 * الأربعٍ وعشرين ساعة**. وبلا الختم يفتح موظّفُ المبيعات صندوقَ ردٍّ لا
 * يُرسل — وصندوقٌ لا يُرسل أسوأ من غياب الصندوق.
 *
 * ويبقى: خيطُ الدعم نفسُه مقفلًا لا محذوفًا. الحذفُ يُفقد الأثر، ويجعل
 * إشعارَ حالٍ متأخّرًا من ميتا يصل إلى صفٍّ لا وجود له.
 */
final class ConversationHandover
{
    /**
     * انقل خيطَ دعمٍ إلى دفتر المبيعات.
     *
     * @return array{ok:bool, lead:?CrmLead, moved:int, reason:?string}
     */
    public static function toSales(SupportConversation $conversation): array
    {
        /*
         * وخيطٌ من داخل التطبيق لا يُنقل.
         *
         * تذكرةُ الدعم في اللوحة لا رقمَ لها ولا نافذةَ ردّ. ونقلُها يصنع
         * «عميلًا محتمَلًا» لا نملك أن نكتب إليه حرفًا — وصفٌّ في دفترٍ لا
         * يُفتح على شيء.
         */
        if ($conversation->channel !== 'whatsapp') {
            return self::refuse(__('لا يُنقل إلّا خيطُ واتساب — تذكرةُ اللوحة لا رقمَ لها ولا نافذةَ ردّ.'));
        }

        $phone = WhatsAppPhone::normalize((string) $conversation->contact_phone);

        if ($phone === null) {
            return self::refuse(__('لا رقمَ صالحًا على هذا الخيط — فلا عميلَ يُفتح به.'));
        }

        $result = CrmLeads::findOrCreateByPhone($phone, Crm::SOURCE_WHATSAPP, null);
        $lead = $result['lead'];

        $moved = 0;

        /*
         * والرسائلُ بترتيبها، بلا الداخليّة وبلا أحداث النظام.
         *
         * الملاحظةُ الداخلية كُتبت ليقرأها فريقُ الدعم وحدَه، وحدثُ «أُقفلت
         * المحادثة» ليس كلامًا قاله أحد. ونقلُهما يجعل موظّفَ المبيعات يقرأ
         * ما لم يُكتب له، ويظنّ حدثًا رسالةً من العميل.
         */
        $messages = SupportMessage::where('conversation_id', $conversation->id)
            ->where('is_internal', false)
            ->whereNull('event')
            ->orderBy('id')
            ->get();

        foreach ($messages as $message) {
            /*
             * ومعرّفُ ميتا يُحفظ كما هو — ولا يُكرَّر.
             *
             * حفظُه يُبقي إشعارَ الحال المتأخّر قادرًا على أن يجد صفَّه. وهو
             * فريدٌ في القاعدة، فنقلٌ ثانٍ لخيطٍ نُقل لا يُسقط شيئًا: يُتخطّى
             * ما نُقل ويُنقل ما استُجدّ.
             */
            if (filled($message->external_message_id)
                && CrmMessage::where('external_message_id', $message->external_message_id)->exists()) {
                continue;
            }

            CrmMessage::create([
                'lead_id' => $lead->id,
                'direction' => $message->sender_scope === 'business' ? CrmMessage::IN : CrmMessage::OUT,
                'body' => (string) $message->body,
                'external_message_id' => $message->external_message_id,
                'created_at' => $message->created_at,
                'updated_at' => $message->created_at,
            ]);

            $moved++;
        }

        /*
         * وختمُ النافذة يُنقل ولا يُجدَّد بـ`now()`.
         *
         * النافذةُ تبدأ من آخر واردٍ من العميل لا من لحظة النقل. وتجديدُها
         * هنا يجعل الشاشة تقول «الباب مفتوح» بعد أن أُغلق — فيكتب موظّفُ
         * المبيعات ردًّا تردُّه ميتا بالخطأ ١٣١٠٤٧.
         */
        $window = $conversation->whatsapp_window_at
            ?? $messages->where('sender_scope', 'business')->last()?->created_at;

        $lead->forceFill(array_filter([
            'whatsapp_window_at' => $window,
            'last_contact_at' => $window,
        ]))->save();

        /* والخيطُ يُقفل ويُقيَّد سببُه — لا يُحذف، فالأثرُ يبقى مقروءًا */
        $conversation->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
        ])->save();

        /* والحدثُ يُكتب من الباب الذي تُكتب منه كلُّ أحداث الدعم — لا بيدٍ ثانية */
        Support::say(
            $conversation, null, 'system',
            null, true, 'moved_to_crm', ['lead_id' => $lead->id],
        );

        Activity::log('settings', 'نُقل خيطُ دعمٍ إلى دفتر المبيعات: '
            .$conversation->reference.' ← عميل #'.$lead->id);

        return ['ok' => true, 'lead' => $lead, 'moved' => $moved, 'reason' => null];
    }

    /** @return array{ok:bool, lead:?CrmLead, moved:int, reason:string} */
    private static function refuse(string $reason): array
    {
        return ['ok' => false, 'lead' => null, 'moved' => 0, 'reason' => $reason];
    }
}
