<?php

namespace App\Support;

use App\Models\WhatsAppMessage;

/**
 * إشعاراتُ الطلبات كما تُقرأ في محادثة — ما أرسله النظامُ إلى الزبون.
 *
 * ═══ قراءةٌ محضة ═══
 *
 * صفوفُ `whatsapp_messages` الصادرةُ نفسُها، تُعرض في خيط الصندوق بين رسائل
 * الزبون وردود الموظّفين (`WhatsAppInbox::thread`) — لا نسخةٌ منها ولا شاشةٌ
 * ثالثةٌ لها. ولا يمسّ هذا الصنفُ مسارَ الإرسال — يقرأ ما كتبه.
 *
 * وكانت له شاشةٌ وحده («محادثات واتساب» للصادر وحده) فصار الصندوقُ يحملها:
 * من وصلته إشعاراتٌ ولم يكتب له محادثةٌ فيه (`WhatsAppInbox::page`)، والدفترُ
 * كاملًا في السجلّ (`WhatsAppLog`).
 */
final class WhatsAppConversations
{
    /**
     * ما يُعرض في المحادثة — ما خرج إلى واتساب أو ينتظر الخروج إليه.
     *
     * و`skipped` و`quota_exceeded` لم تصل واتساب أصلًا فليست من المحادثة —
     * وتبقى في السجلّ لمن يسأل عنها.
     */
    public const SHOWN = [
        WhatsAppStatus::QUEUED, WhatsAppStatus::SENT, WhatsAppStatus::DELIVERED,
        WhatsAppStatus::READ, WhatsAppStatus::FAILED,
    ];

    /**
     * رسالةٌ واحدة في المحادثة — بحقول السجلّ نفسِها ونصِّها.
     *
     * الحالُ وسببُ الفشل والورقةُ والنصُّ أو وصفُه من `WhatsAppLog::row` لا
     * من نسخةٍ ثانية — والوصفُ لا يُقرأ على أنّه النصّ الحرفيّ.
     *
     * @return array<string, mixed>
     */
    public static function bubble(WhatsAppMessage $m): array
    {
        $row = WhatsAppLog::row($m);

        return [
            'id' => $row['id'],
            'at' => $row['at'],
            'time' => optional($m->created_at)->format('H:i'),
            'event' => $row['event'],
            'status' => $row['status'],
            'status_label' => $row['status_label'],
            'reason' => $row['reason'],
            'error' => $row['error'],
            'ours' => $row['ours'],
            'subject' => $row['subject'],
            // ليقول الزرُّ «عرض الطلب» أو «عرض الفاتورة» — والرابطُ من السجلّ نفسِه
            'subject_kind' => $m->order !== null ? 'order' : ($m->customerInvoice !== null ? 'invoice' : null),
            'body' => $row['body'],
            'summary' => $row['summary'],
        ];
    }
}
