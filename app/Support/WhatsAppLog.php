<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\WhatsAppChatMessage;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * دفترُ رسائل المحلّ كما يقرؤه صاحبُه.
 *
 * ═══ العطب الذي جاءت منه ═══
 *
 * الجدولُ قائمٌ منذ أوّل يوم، ومكتوبٌ في رأسه أنّه «دفتر الحقيقة الذي
 * يُدقَّق». ولم تكن تفتحه شاشةٌ واحدة. فكان جوابُ «لماذا لم تصل رسالةُ
 * زبوني؟» يمرّ بي أنا: أفتح القاعدة بيدي وأقرأ الصفّ وأخبر التاجر. وما
 * يحتاج إنسانًا ليُقرأ لا يُقرأ.
 *
 * وكان في اللوحة جرسٌ يقول «لم تصل ٣ رسائل إلى زبائنك» ويقود إلى شاشة
 * الربط — وهي شاشةٌ لا تعرف أيَّ الثلاث ولا لمن. فيُقال للتاجر إنّ شيئًا
 * انكسر ولا يُقال ماذا. وبابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض.
 *
 * ═══ وحدُّ ما يُعرض ═══
 *
 * صفوفُ متجرِه وحدَه — ومعرّفُ المتجر يُقرأ من الجلسة في المتحكّم لا ممّا
 * يصل في الطلب. ولا نصَّ رسالةٍ هنا: القوالبُ تخرج بمتغيّراتها، والمعروضُ
 * هو الحدثُ والوجهةُ والحال. ولا رمزَ ولا وصلة: تلك شأنُ أبعاد.
 */
final class WhatsAppLog
{
    public const PER_PAGE = 30;

    /** نافذةُ الملخّص — ثلاثون يومًا، وما قبلها يُقرأ بالتصفّح لا بالعدّاد */
    public const SUMMARY_DAYS = 30;

    /** من أيّ رقمٍ خرجت — مرشِّحٌ يُقاس بقائمته لا يُصدَّق كما وصل */
    public const SOURCES = ['own' => WhatsAppMode::BUSINESS_OWN, 'shared' => WhatsAppMode::ABAAD_SHARED];

    /** ما يُعرض: إشعاراتُ النظام، أو ردودُ الموظّفين من المحادثات — أو كلاهما */
    public const KINDS = ['notices', 'replies'];

    /**
     * صفحةٌ من الدفتر — كلُّ ما خرج من المتجر إلى زبائنه.
     *
     * والمرشِّحُ يُقرأ من `WhatsAppStatus::BUCKETS` لا من قائمةٍ هنا: قائمتان
     * لسؤالٍ واحد تفترقان يوم تُضاف حالة.
     *
     * ═══ مصدران، ولا نسخةٌ ثالثة ═══
     *
     * الإشعاراتُ في `whatsapp_messages`، وردودُ الموظّفين وقالبُ المتابعة في
     * `whatsapp_chat_messages` حيث كُتبت. يُقرآن معًا بمعرّفاتهما مرتّبَين
     * بالوقت ثمّ يُملأ كلٌّ من جدوله — فلا تُنسخ رسالةٌ إلى جدولٍ آخر، ولا
     * يتغيّر على سجلّ الإشعارات حرف: صفُّه كما كان (`row`).
     *
     * والدفترُ يُقرأ بالمتجر وحده — لا بالوصلة: رقمٌ فُكّ أو تبدّل أو انقطع
     * ربطُه تبقى رسائلُه مقروءةً هنا كما خرجت.
     *
     * @param  bool  $opens  أيُفتح من الصفّ محادثتُه في الصندوق؟ — لمن يرى الصندوق وحده
     */
    public static function page(int $businessId, ?string $bucket, ?string $q, ?string $source = null, bool $opens = false, ?string $kind = null): LengthAwarePaginator
    {
        $statuses = $bucket === null ? [] : WhatsAppStatus::bucket($bucket);
        $mode = self::SOURCES[(string) $source] ?? null;
        $q = (string) $q;
        $like = Search::like();
        $digits = $q === '' ? '' : (string) preg_replace('/\D+/', '', Digits::western($q));
        // والعميلُ باسمه — من متجره وحده
        $named = $q === '' ? collect() : Customer::where('business_id', $businessId)->where('name', $like, '%'.$q.'%')->pluck('id');

        $notices = WhatsAppMessage::query()
            ->where('business_id', $businessId)
            ->where('direction', 'outbound')
            ->when($statuses !== [], fn ($x) => $x->whereIn('status', $statuses))
            ->when($mode !== null, fn ($x) => $x->where('source_mode', $mode))
            /*
             * والمُعامِل من المحرّك لا من اليد: `like` في PostgreSQL تفرّق بين
             * الحرف الكبير والصغير، و`ilike` لا تفرّق — انظر `Search`.
             */
            ->when($q !== '', fn ($x) => $x->where(function ($w) use ($q, $like, $digits, $named) {
                $w->where('recipient_phone', $like, '%'.$q.'%')
                    ->orWhere('body_snapshot', $like, '%'.$q.'%')
                    ->orWhereHas('order', fn ($o) => $o->where('number', $like, '%'.$q.'%'))
                    ->orWhereHas('customerInvoice', fn ($i) => $i->where('number', $like, '%'.$q.'%'));

                if ($named->isNotEmpty()) {
                    $w->orWhereIn('customer_id', $named);
                }

                if ($digits !== '' && $digits !== $q) {
                    $w->orWhere('recipient_phone', $like, '%'.$digits.'%');
                }
            }))
            ->selectRaw("'n' as src, id, created_at");

        // وردُّ الموظّف من محادثةٍ في متجره — والمحادثةُ تُسأل بالمتجر نفسِه ثانيةً
        $replies = WhatsAppChatMessage::query()
            ->join('whatsapp_chats as c', 'c.id', '=', 'whatsapp_chat_messages.chat_id')
            ->where('whatsapp_chat_messages.business_id', $businessId)
            ->where('c.business_id', $businessId)
            ->where('whatsapp_chat_messages.direction', WhatsAppChatMessage::OUT)
            ->when($statuses !== [], fn ($x) => $x->whereIn('whatsapp_chat_messages.status', $statuses))
            ->when($mode !== null, fn ($x) => $x->where('c.source_mode', $mode))
            ->when($q !== '', fn ($x) => $x->where(function ($w) use ($q, $like, $digits, $named) {
                $w->where('c.customer_phone', $like, '%'.$q.'%')
                    ->orWhere('whatsapp_chat_messages.body', $like, '%'.$q.'%')
                    ->orWhere('c.profile_name', $like, '%'.$q.'%');

                if ($named->isNotEmpty()) {
                    $w->orWhereIn('c.customer_id', $named);
                }

                if ($digits !== '' && $digits !== $q) {
                    $w->orWhere('c.customer_phone', $like, '%'.$digits.'%');
                }
            }))
            ->selectRaw("'r' as src, whatsapp_chat_messages.id as id, whatsapp_chat_messages.created_at as created_at");

        $union = match ($kind) {
            'notices' => $notices,
            'replies' => $replies,
            default => $notices->unionAll($replies),
        };

        $page = DB::query()->fromSub($union, 'outgoing')
            // والمعرّفان من جدولين قد يتساويان — والمصدرُ يحسم الترتيبَ بينهما فلا يقفز صفٌّ بين صفحتين
            ->orderByDesc('created_at')->orderByDesc('id')->orderBy('src')
            ->paginate(self::PER_PAGE)->withQueryString();

        $keys = collect($page->items());
        $noticeRows = WhatsAppMessage::where('business_id', $businessId)
            ->whereIn('id', $keys->where('src', 'n')->pluck('id'))
            ->with(['order:id,number', 'customerInvoice:id,number', 'customer:id,business_id,name'])
            ->get()->keyBy('id');
        $replyRows = WhatsAppChatMessage::where('business_id', $businessId)
            ->whereIn('id', $keys->where('src', 'r')->pluck('id'))
            ->with(['chat', 'attachments'])
            ->get()->keyBy('id');
        $customers = Customer::where('business_id', $businessId)
            ->whereIn('id', $replyRows->pluck('chat.customer_id')->filter()->unique())
            ->pluck('name', 'id');

        $page->setCollection($keys->map(function ($k) use ($noticeRows, $replyRows, $customers, $opens) {
            if ($k->src === 'n' && ($m = $noticeRows[(int) $k->id] ?? null)) {
                return self::row($m) + [
                    'key' => 'n'.$m->id,
                    'kind' => 'notice',
                    'sender' => null,
                    'open' => $opens && self::opensAChat($m) ? route('admin.whatsappInbox.open', $m->id) : null,
                ];
            }

            $r = $k->src === 'r' ? ($replyRows[(int) $k->id] ?? null) : null;

            return $r === null ? null : self::replyRow($r, $customers[(int) $r->chat?->customer_id] ?? null) + [
                'open' => $opens ? route('admin.whatsappInbox.index', ['c' => $r->chat_id]) : null,
            ];
        })->filter()->values());

        return $page;
    }

    /**
     * ردُّ موظّفٍ من المحادثات — بحقول صفّ الإشعار نفسِها، ومن كتبه.
     *
     * والنصُّ ما خرج حرفًا؛ وردٌّ بملفٍّ وحده يُوصف بأسماء ملفّاته لا يُخترع له نصّ.
     *
     * @return array<string, mixed>
     */
    public static function replyRow(WhatsAppChatMessage $m, ?string $customer): array
    {
        $failed = $m->status === WhatsAppStatus::FAILED;
        $files = $m->attachments->pluck('name')->filter()->implode('، ');
        $own = $m->chat?->source_mode === WhatsAppMode::BUSINESS_OWN;

        return [
            'id' => $m->id,
            'key' => 'r'.$m->id,
            'kind' => 'reply',
            'at' => optional($m->created_at)->format('Y-m-d H:i'),
            'event' => __('ردّ من المحادثات'),
            // اسمُ من ردّ كما كُتب ساعتَها
            'sender' => $m->sender_name,
            'phone' => $m->chat?->customer_phone,
            'customer' => $customer,
            'body' => filled($m->body) ? (string) $m->body : null,
            'summary' => filled($m->body) ? null : ($files !== '' ? __('مرفق: :names', ['names' => $files]) : null),
            'source' => $own ? WhatsAppMode::BUSINESS_OWN : WhatsAppMode::ABAAD_SHARED,
            'source_label' => $own ? __('رقم متجرك') : __('رقم أبعاد المشترك'),
            'status' => $m->status,
            'status_label' => WhatsAppStatus::label((string) $m->status),
            'times' => array_filter([
                'sent' => optional($m->sent_at)->format('Y-m-d H:i'),
                'delivered' => optional($m->delivered_at)->format('Y-m-d H:i'),
                'read' => optional($m->read_at)->format('Y-m-d H:i'),
                'failed' => optional($m->failed_at)->format('Y-m-d H:i'),
            ]),
            'reason' => null,
            'error' => $failed ? $m->error_message : null,
            'ours' => $failed ? WhatsAppHealth::isOurs($m->error_code) : null,
            'subject' => null,
        ];
    }

    /** أخرجت إلى واتساب برقمٍ معروف — فلها محادثةٌ تُفتح؟ (`WhatsAppInbox::open`) */
    private static function opensAChat(WhatsAppMessage $m): bool
    {
        return filled($m->recipient_phone) && filled($m->sender_phone_number_id)
            && in_array($m->status, WhatsAppConversations::SHOWN, true);
    }

    /** أفي الدفتر رسالةٌ صادرةٌ لهذا المتجر أصلًا؟ — فلا يُخفى دفترٌ قائمٌ خلف بابِ «اربط أوّلًا» */
    public static function hasHistory(int $businessId): bool
    {
        return WhatsAppMessage::where('business_id', $businessId)->where('direction', 'outbound')->exists();
    }

    /**
     * صفٌّ واحد — ولا حقلَ فيه لا تقرؤه الشاشة.
     *
     * @return array<string, mixed>
     */
    public static function row(WhatsAppMessage $m): array
    {
        $failed = $m->status === WhatsAppStatus::FAILED;
        $subject = self::subject($m);
        $customer = $m->customer && (int) $m->customer->business_id === (int) $m->business_id ? $m->customer : null;

        /*
         * والعطبُ يُنسب إلى صاحبه — بالقائمة التي يقرؤها جرسُ اللوحة نفسِها.
         *
         * قولُ «راجع رقم الزبون» لتاجرٍ تطبيقُنا محجوب لومٌ في غير محلّه،
         * ويجعله يلاحق ما لا يملك إصلاحه. و`WhatsAppHealth::isOurs` هي
         * الفاصلُ هناك، فهي الفاصلُ هنا — لا نسخةٌ ثانيةٌ منها.
         */
        return [
            'id' => $m->id,
            'at' => optional($m->created_at)->format('Y-m-d H:i'),
            'event' => WhatsAppEvent::label($m->event_type),
            'phone' => $m->recipient_phone,
            'customer' => $customer ? (string) $customer->name : null,
            /*
             * والنصُّ كما قرأه الزبون إن حُفظ (`body_snapshot`) — وإلّا وصفٌ لا
             * يُقرأ على أنّه النصّ: الحدثُ والورقة. ولا يُخترع نصٌّ لرسالةٍ قديمة.
             */
            'body' => filled($m->body_snapshot) ? (string) $m->body_snapshot : null,
            'summary' => filled($m->body_snapshot) ? null : self::describe($m, $subject['label'] ?? null),
            'source' => $m->source_mode,
            'source_label' => $m->source_mode === WhatsAppMode::BUSINESS_OWN ? __('رقم متجرك') : __('رقم أبعاد المشترك'),
            'status' => $m->status,
            'status_label' => WhatsAppStatus::label($m->status),
            // ومتى وقع كلٌّ — مرّةً كما كتبه إشعارُ ميتا الأوّل
            'times' => array_filter([
                'sent' => optional($m->sent_at)->format('Y-m-d H:i'),
                'delivered' => optional($m->delivered_at)->format('Y-m-d H:i'),
                'read' => optional($m->read_at)->format('Y-m-d H:i'),
                'failed' => optional($m->failed_at)->format('Y-m-d H:i'),
            ]),
            'reason' => WhatsAppStatus::reason($m->error_code),
            'error' => $failed ? $m->error_message : null,
            'ours' => $failed ? WhatsAppHealth::isOurs($m->error_code) : null,
            'subject' => $subject,
        ];
    }

    /** «إشعار جاهزية الطلب — #1234»: الحدثُ والورقة، لا نصٌّ مخترَع */
    public static function describe(WhatsAppMessage $m, ?string $subject): string
    {
        $event = WhatsAppEvent::label((string) $m->event_type);

        return $subject ? $event.' — '.$subject : $event;
    }

    /**
     * الورقةُ التي تتحدّث عنها الرسالة — طلبٌ أو فاتورة.
     *
     * وتُقرأ من الصلة لا من `metadata`: الرسالة تُسمّي ورقتَها بعمودٍ منذ
     * ترحيل `a_message_names_the_paper_it_speaks_of`.
     *
     * @return array{label:string, url:string}|null
     */
    private static function subject(WhatsAppMessage $m): ?array
    {
        if ($m->order !== null) {
            return [
                'label' => '#'.$m->order->number,
                'url' => route('admin.orders.show', $m->order->number),
            ];
        }

        if ($m->customerInvoice !== null) {
            return [
                'label' => __('فاتورة').' '.$m->customerInvoice->number,
                'url' => route('admin.customerInvoices.show', $m->customerInvoice->id),
            ];
        }

        return null;
    }

    /**
     * عدّادُ الثلاثين يومًا — حزمةٌ حزمة.
     *
     * ولا يُعدّ هنا إلّا ما في `BUCKETS`، فمجموعُ الحِزَم هو مجموعُ الصفوف
     * تمامًا — لا صفَّ يسقط من العدّ لأنّ حالتَه لم تُصنَّف.
     *
     * @return array<string, int>
     */
    public static function summary(int $businessId): array
    {
        $counts = WhatsAppMessage::query()
            ->where('business_id', $businessId)
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subDays(self::SUMMARY_DAYS))
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $out = [];

        foreach (WhatsAppStatus::BUCKETS as $name => $statuses) {
            $out[$name] = array_sum(array_map(
                fn (string $s) => (int) ($counts[$s] ?? 0),
                $statuses,
            ));
        }

        return $out;
    }

    /**
     * مرشِّحُ النوع كما يُرسم — مع «الكلّ» أوّلًا.
     *
     * @return list<array{key:string, label:string}>
     */
    public static function kinds(): array
    {
        return [
            ['key' => 'all', 'label' => __('كلّ الرسائل')],
            ['key' => 'notices', 'label' => __('الإشعارات')],
            ['key' => 'replies', 'label' => __('ردود الموظفين')],
        ];
    }

    /**
     * مرشِّحُ الرقم كما يُرسم — مع «الكلّ» أوّلًا.
     *
     * @return list<array{key:string, label:string}>
     */
    public static function sources(): array
    {
        return [
            ['key' => 'all', 'label' => __('كلّ الأرقام')],
            ['key' => 'own', 'label' => __('رقم متجرك')],
            ['key' => 'shared', 'label' => __('رقم أبعاد المشترك')],
        ];
    }

    /**
     * الحِزَمُ كما تُرسم في الشاشة — مع «الكلّ» أوّلًا.
     *
     * @return list<array{key:string, label:string}>
     */
    public static function filters(): array
    {
        $out = [['key' => 'all', 'label' => __('الكلّ')]];

        foreach (WhatsAppStatus::BUCKET_LABELS as $key => $label) {
            $out[] = ['key' => $key, 'label' => __($label)];
        }

        return $out;
    }
}
