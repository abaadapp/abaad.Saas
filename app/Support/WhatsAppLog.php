<?php

namespace App\Support;

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

    /**
     * صفحةٌ من الدفتر.
     *
     * والمرشِّحُ يُقرأ من `WhatsAppStatus::BUCKETS` لا من قائمةٍ هنا: قائمتان
     * لسؤالٍ واحد تفترقان يوم تُضاف حالة.
     */
    public static function page(int $businessId, ?string $bucket, ?string $q): LengthAwarePaginator
    {
        $statuses = $bucket === null ? [] : WhatsAppStatus::bucket($bucket);

        $rows = WhatsAppMessage::query()
            ->where('business_id', $businessId)
            ->where('direction', 'outbound')
            ->when($statuses !== [], fn ($x) => $x->whereIn('status', $statuses))
            /*
             * والمُعامِل من المحرّك لا من اليد: `like` في PostgreSQL تفرّق بين
             * الحرف الكبير والصغير، و`ilike` لا تفرّق — انظر `Search`.
             */
            ->when((string) $q !== '', fn ($x) => $x->where(function ($w) use ($q) {
                $like = Search::like();

                $w->where('recipient_phone', $like, '%'.$q.'%')
                    ->orWhereHas('order', fn ($o) => $o->where('number', $like, '%'.$q.'%'))
                    ->orWhereHas('customerInvoice', fn ($i) => $i->where('number', $like, '%'.$q.'%'));
            }))
            ->with(['order:id,number', 'customerInvoice:id,number'])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $rows->through(fn (WhatsAppMessage $m) => self::row($m));

        return $rows;
    }

    /**
     * صفٌّ واحد — ولا حقلَ فيه لا تقرؤه الشاشة.
     *
     * @return array<string, mixed>
     */
    public static function row(WhatsAppMessage $m): array
    {
        $failed = $m->status === WhatsAppStatus::FAILED;

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
            'status' => $m->status,
            'status_label' => WhatsAppStatus::label($m->status),
            'reason' => WhatsAppStatus::reason($m->error_code),
            'error' => $failed ? $m->error_message : null,
            'ours' => $failed ? WhatsAppHealth::isOurs($m->error_code) : null,
            'subject' => self::subject($m),
        ];
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
