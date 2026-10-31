<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\WhatsAppMessage;
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

    /**
     * صفحةٌ من الدفتر.
     *
     * والمرشِّحُ يُقرأ من `WhatsAppStatus::BUCKETS` لا من قائمةٍ هنا: قائمتان
     * لسؤالٍ واحد تفترقان يوم تُضاف حالة.
     *
     * والدفترُ يُقرأ بالمتجر وحده — لا بالوصلة: رقمٌ فُكّ أو تبدّل أو انقطع
     * ربطُه تبقى رسائلُه مقروءةً هنا كما خرجت.
     *
     * @param  bool  $opens  أيُفتح من الصفّ محادثتُه في الصندوق؟ — لمن يرى الصندوق وحده
     */
    public static function page(int $businessId, ?string $bucket, ?string $q, ?string $source = null, bool $opens = false): LengthAwarePaginator
    {
        $statuses = $bucket === null ? [] : WhatsAppStatus::bucket($bucket);
        $mode = self::SOURCES[(string) $source] ?? null;

        $rows = WhatsAppMessage::query()
            ->where('business_id', $businessId)
            ->where('direction', 'outbound')
            ->when($statuses !== [], fn ($x) => $x->whereIn('status', $statuses))
            ->when($mode !== null, fn ($x) => $x->where('source_mode', $mode))
            /*
             * والمُعامِل من المحرّك لا من اليد: `like` في PostgreSQL تفرّق بين
             * الحرف الكبير والصغير، و`ilike` لا تفرّق — انظر `Search`.
             */
            ->when((string) $q !== '', fn ($x) => $x->where(function ($w) use ($q, $businessId) {
                $like = Search::like();
                $digits = (string) preg_replace('/\D+/', '', Digits::western((string) $q));

                $w->where('recipient_phone', $like, '%'.$q.'%')
                    ->orWhereHas('order', fn ($o) => $o->where('number', $like, '%'.$q.'%'))
                    ->orWhereHas('customerInvoice', fn ($i) => $i->where('number', $like, '%'.$q.'%'))
                    // والعميلُ باسمه — من متجره وحده
                    ->orWhereIn('customer_id', Customer::where('business_id', $businessId)->where('name', $like, '%'.$q.'%')->select('id'));

                if ($digits !== '' && $digits !== $q) {
                    $w->orWhere('recipient_phone', $like, '%'.$digits.'%');
                }
            }))
            ->with(['order:id,number', 'customerInvoice:id,number', 'customer:id,business_id,name'])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows->through(fn (WhatsAppMessage $m) => self::row($m) + [
            'open' => $opens && self::opensAChat($m) ? route('admin.whatsappInbox.open', $m->id) : null,
        ]);

        return $rows;
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
