<?php

namespace App\Support;

use App\Models\Business;
use App\Models\Customer;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * «محادثات واتساب» — ما أرسله النظامُ من رقم المحلّ، مقروءًا محادثاتٍ.
 *
 * ═══ قراءةٌ محضة ═══
 *
 * لا إرسالَ يدويًّا ولا ردّ ولا واردَ ولا مزامنةَ تاريخٍ من واتساب: صفوفُ
 * `whatsapp_messages` الصادرةُ نفسُها، مجموعةً برقم الزبون. ولا يمسّ هذا
 * الصنفُ مسارَ الإرسال — يقرأ ما كتبه.
 *
 * ═══ ونطاقُ ما يُعرض — `scope` ═══
 *
 * - المتجرُ من الجلسة، لا ممّا يصل في الطلب.
 * - الصادرُ وحده، ومن «رقم المحلّ» (`BUSINESS_OWN`) — فرسائلُ رقم أبعاد
 *   المشترك لا تظهر، ولو كانت لهذا المتجر.
 * - على وصلة المحلّ الحاليّة، **ومن رقمها الحاليّ** (`sender_phone_number_id`):
 *   الوصلةُ صفٌّ واحدٌ يُحدَّث عند كلّ ربط ولو برقمٍ آخر، فمعرّفُها وحده
 *   يُدخل رسائلَ رقمٍ سبق. وإعادةُ تفويض الرقم نفسِه لا تُسقط رسائله.
 * - وما خرج أو حاول الخروج: `skipped` و`quota_exceeded` لم تصل واتساب
 *   أصلًا فليست من المحادثة — وتبقى في السجلّ القديم لمن يسأل عنها.
 *
 * ═══ وهويّةُ المحادثة رقمُ الزبون ═══
 *
 * المتجر + الوصلة الحاليّة + `recipient_phone` (مطبَّعًا عند الكتابة —
 * `WhatsAppPhone::normalize`). لا اسمُ العميل: عميلٌ حُذف تبقى محادثتُه
 * برقمها، وعميلٌ غيّر اسمه يظهر باسمه الجديد على المحادثة نفسِها.
 */
final class WhatsAppConversations
{
    public const PER_PAGE = 30;

    public const THREAD_PAGE = 50;

    /** ما يُعرض في المحادثة — ما خرج إلى واتساب أو ينتظر الخروج إليه */
    public const SHOWN = [
        WhatsAppStatus::QUEUED, WhatsAppStatus::SENT, WhatsAppStatus::DELIVERED,
        WhatsAppStatus::READ, WhatsAppStatus::FAILED,
    ];

    /** أمفتوحةٌ لهذا النشاط؟ — العمودُ وحده، لا اسمٌ ولا معرّف */
    public static function enabled(int|Business $business): bool
    {
        if ($business instanceof Business) {
            return (bool) $business->whatsapp_conversations_enabled;
        }

        return (bool) Business::whereKey($business)->value('whatsapp_conversations_enabled');
    }

    /**
     * رقمُ المحلّ الحاليّ — أو `null` إن لم يُربط.
     *
     * وصلةُ المحلّ وحدها (`forBusiness`): رقمُ أبعاد المشترك لا يفتح هذه
     * الشاشة. وتُقبل ما دام لها رقمٌ ولم تُفصل: رمزٌ انتهى أو ينتظر إعادة
     * تفويض لا يمحو ما أُرسل — ويُقال حالُه في شاشة الربط.
     */
    public static function ownNumber(Business $business): ?WhatsAppConnection
    {
        $connection = WhatsAppConnections::forBusiness($business->id);

        if (! $connection || blank($connection->phone_number_id)
            || in_array($connection->status, [WhatsAppConnection::INACTIVE, WhatsAppConnection::PENDING], true)) {
            return null;
        }

        return $connection;
    }

    /** صفوفُ المحادثات كلّها — المتجرُ والرقمُ الحاليّ والصادر */
    public static function scope(Business $business, WhatsAppConnection $own): Builder
    {
        return WhatsAppMessage::query()
            ->where('business_id', $business->id)
            ->where('direction', 'outbound')
            ->where('source_mode', WhatsAppMode::BUSINESS_OWN)
            ->where('whatsapp_connection_id', $own->id)
            ->where('sender_phone_number_id', $own->phone_number_id)
            ->whereNotNull('recipient_phone')
            ->whereIn('status', self::SHOWN);
    }

    /**
     * صفحةٌ من قائمة المحادثات — آخرُ رسالةٍ أوّلًا.
     *
     * والتجميعُ في القاعدة: رقمٌ ← أحدثُ معرّف، ثمّ تُقرأ تلك الرسائلُ
     * وعملاؤها دفعةً — لا كلُّ الرسائل إلى الذاكرة.
     */
    public static function page(Business $business, WhatsAppConnection $own, ?string $q): LengthAwarePaginator
    {
        $base = self::scope($business, $own);

        if ((string) $q !== '') {
            $like = Search::like();
            $digits = preg_replace('/\D+/', '', (string) $q);
            // العملاءُ بالاسم من متجره وحده — ثمّ رسائلُهم بمعرّفاتهم
            $customers = Customer::where('business_id', $business->id)
                ->where('name', $like, '%'.$q.'%')->pluck('id');

            $base->where(function ($w) use ($like, $q, $digits, $customers) {
                $w->where('recipient_phone', $like, '%'.$q.'%');
                if ($digits !== '' && $digits !== $q) {
                    $w->orWhere('recipient_phone', $like, '%'.$digits.'%');
                }
                if ($customers->isNotEmpty()) {
                    $w->orWhereIn('customer_id', $customers);
                }
            });
        }

        $groups = $base->selectRaw('recipient_phone, MAX(id) as last_id, COUNT(*) as messages')
            ->groupBy('recipient_phone')
            ->orderByDesc('last_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $last = WhatsAppMessage::whereIn('id', collect($groups->items())->pluck('last_id'))
            ->with(['order:id,number', 'customerInvoice:id,number'])
            ->get()->keyBy('id');

        // واسمُ العميل من آخر رسالةٍ تُسمّيه في المحادثة — ومن متجره وحده
        $named = self::scope($business, $own)
            ->whereIn('recipient_phone', collect($groups->items())->pluck('recipient_phone'))
            ->whereNotNull('customer_id')
            ->selectRaw('recipient_phone, MAX(id) as id')
            ->groupBy('recipient_phone')
            ->pluck('id', 'recipient_phone');

        $customerOf = WhatsAppMessage::whereIn('id', $named->values())->pluck('customer_id', 'recipient_phone');

        $names = Customer::where('business_id', $business->id)
            ->whereIn('id', $customerOf->values())->pluck('name', 'id');

        $groups->through(function ($g) use ($last, $customerOf, $names) {
            $m = $last[(int) $g->last_id] ?? null;
            $name = $names[(int) ($customerOf[$g->recipient_phone] ?? 0)] ?? null;

            return [
                'key' => (int) $g->last_id,
                'name' => filled($name) ? (string) $name : null,
                'phone' => (string) $g->recipient_phone,
                'messages' => (int) $g->messages,
                'at' => optional($m?->created_at)->format('Y-m-d H:i'),
                'preview' => $m ? self::preview($m) : '',
                'status' => $m?->status,
                'status_label' => $m ? WhatsAppStatus::label($m->status) : null,
            ];
        });

        return $groups;
    }

    /**
     * المحادثةُ التي تحمل هذه الرسالة — أو `null` إن لم تكن في النطاق.
     *
     * والمعرّفُ مفتاحٌ لا عنوان: رسالةٌ من متجرٍ آخر أو من رقم أبعاد أو من
     * رقمٍ سبق لا تُوجد هنا، فلا يُعرف منها رقمُ زبونٍ ولا يُفتح بها شيء.
     */
    public static function anchor(Business $business, WhatsAppConnection $own, int $messageId): ?WhatsAppMessage
    {
        return self::scope($business, $own)->whereKey($messageId)->first();
    }

    /**
     * من في رأس المحادثة: اسمُ العميل إن سمّته رسالةٌ فيها، والرقمُ دائمًا.
     *
     * @return array{name: ?string, phone: string}
     */
    public static function contact(Business $business, WhatsAppConnection $own, string $phone): array
    {
        $customerId = self::scope($business, $own)
            ->where('recipient_phone', $phone)
            ->whereNotNull('customer_id')
            ->orderByDesc('id')
            ->value('customer_id');

        $name = $customerId
            ? Customer::where('business_id', $business->id)->whereKey($customerId)->value('name')
            : null;

        return ['name' => filled($name) ? (string) $name : null, 'phone' => $phone];
    }

    /**
     * رسائلُ المحادثة — الأحدثُ خمسون، ثمّ ما قبلها بالطلب.
     *
     * تُقرأ تنازليًّا بحدّ (`before`) ثمّ تُعرض تصاعديًّا: الأقدمُ أعلى كما
     * تُقرأ المحادثة، و«الأقدم» يُحمَّل فوقها.
     *
     * @return array{messages: list<array<string, mixed>>, has_more: bool, before: ?int}
     */
    public static function thread(Business $business, WhatsAppConnection $own, string $phone, ?int $before = null): array
    {
        $rows = self::scope($business, $own)
            ->where('recipient_phone', $phone)
            ->when($before !== null, fn ($q) => $q->where('id', '<', $before))
            ->with(['order:id,number', 'customerInvoice:id,number'])
            ->orderByDesc('id')
            ->limit(self::THREAD_PAGE + 1)
            ->get();

        $more = $rows->count() > self::THREAD_PAGE;
        $rows = $rows->take(self::THREAD_PAGE)->reverse()->values();

        return [
            'messages' => $rows->map(fn (WhatsAppMessage $m) => self::bubble($m))->all(),
            'has_more' => $more,
            'before' => $more ? (int) $rows->first()->id : null,
        ];
    }

    /**
     * رسالةٌ واحدة في المحادثة — بحقول السجلّ نفسِها ونصِّها.
     *
     * الحالُ وسببُ الفشل والورقةُ من `WhatsAppLog::row` لا من نسخةٍ ثانية،
     * والنصُّ من `body_snapshot` — أو وصفٌ لا يُقرأ على أنّه النصّ الحرفيّ.
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
            'body' => filled($m->body_snapshot) ? (string) $m->body_snapshot : null,
            // وصفٌ لا نصّ: «إشعار …» — والشاشةُ تكتبه بغير شكل النصّ
            'summary' => filled($m->body_snapshot) ? null : self::summary($m, $row['subject']['label'] ?? null),
        ];
    }

    /** سطرُ المعاينة في القائمة — بدايةُ النصّ، أو الوصف */
    private static function preview(WhatsAppMessage $m): string
    {
        $text = filled($m->body_snapshot)
            ? (string) $m->body_snapshot
            : self::summary($m, $m->order?->number ? '#'.$m->order->number : ($m->customerInvoice?->number ? __('فاتورة').' '.$m->customerInvoice->number : null));

        return mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 90, '…');
    }

    /** «إشعار جاهزية الطلب — #1234»: الحدثُ والورقة، لا نصٌّ مخترَع */
    private static function summary(WhatsAppMessage $m, ?string $subject): string
    {
        $event = WhatsAppEvent::label((string) $m->event_type);

        return $subject ? $event.' — '.$subject : $event;
    }
}
