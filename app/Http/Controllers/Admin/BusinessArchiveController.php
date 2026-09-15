<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessArchive;
use App\Models\User;
use App\Support\Activity;
use App\Support\Archive\Archives;
use App\Support\Archive\Period;
use App\Support\Archive\Policy;
use App\Support\Demo;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * الأرشيفُ الشهريّ في لوحة صاحب النشاط — عرضٌ وطلبٌ وتنزيل.
 *
 * ═══ والباب يسأل عن المتجر في كلّ دالّة ═══
 *
 * لا `findOrFail($id)` في هذا الملفّ. كلُّ قراءةٍ مقيَّدةٌ بـ`business_id`
 * أوّلًا، فرقمُ أرشيفِ الجار يردّ ٤٠٤ لا ملفًّا. وهو الفرقُ بين «لا تجده»
 * و«تجده ثمّ نمنعك»: الثاني يُخبر أنّه موجود.
 *
 * والفعلُ يُحرَس في المسار (`may:business.export`) لا في أوّل كلّ دالّة:
 * دالّةٌ تُضاف غدًا وينسى كاتبُها سطرَ الفحص تفتح البابَ كلَّه.
 */
class BusinessArchiveController extends Controller
{
    /** كم شهرًا يُعرض في الشاشة — سنتان تكفيان لأطول احتفاظٍ افتراضيّ */
    private const SHOWN = 24;

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /**
     * ما يُرسَل إلى تبويب «النسخ الاحتياطي» — يُنادى من `SettingController`.
     *
     * ولا شاشةَ مستقلّة: التبويبُ قائمٌ منذ أوّل يوم («تنزيل نسخة من بياناتك
     * واستعادتها»)، والأرشيفُ قسمٌ ثالثٌ فيه. وشاشةٌ ثانيةٌ اسمُها «البيانات
     * والنسخ الاحتياطية» بجانب تبويبٍ اسمُه «النسخ الاحتياطي» تجعل التاجر
     * يبحث في أيّهما.
     */
    public static function panel(int $bid, ?User $user): array
    {
        $rows = BusinessArchive::where('business_id', $bid)
            ->orderByDesc('year')->orderByDesc('month')
            ->limit(self::SHOWN)
            ->get();

        return [
            'enabled' => Policy::enabled(),
            'may_generate' => Policy::manualAllowed() && (bool) $user?->may(Permissions::BUSINESS_EXPORT),
            'may_download' => (bool) $user?->may(Permissions::BUSINESS_EXPORT),
            'retention_months' => Policy::retentionMonths(),
            'months' => self::selectable($bid),
            'items' => $rows->map(fn (BusinessArchive $a) => [
                'id' => $a->id,
                'period' => $a->periodKey(),
                'label' => $a->periodStart()->translatedFormat('F Y'),
                'status' => $a->status,
                'created_at' => optional($a->completed_at ?? $a->created_at)->format('Y-m-d'),
                /*
                 * والحجمُ بالميجابايت لا بالبايت: «26011238» لا يقرؤه أحد.
                 * و`null` حين لا ملفَّ — فلا يُطبع «0.0 MB» عن شيءٍ لا وجود له.
                 */
                'size_mb' => $a->file_size ? round($a->file_size / 1048576, 1) : null,
                'downloadable' => $a->downloadable(),
                'failure_reason' => $a->failure_reason,
            ])->all(),
        ];
    }

    /**
     * الشهورُ المغلقةُ التي لا أرشيفَ لها بعد — تُعرض في القائمة.
     *
     * وما له أرشيفٌ لا يُعرض: خيارٌ يُختار فيردّ «موجودٌ أصلًا» مقبضٌ لا
     * يُدير شيئًا.
     */
    private static function selectable(int $bid): array
    {
        $taken = BusinessArchive::where('business_id', $bid)
            ->get(['year', 'month'])
            ->map(fn ($a) => sprintf('%04d-%02d', $a->year, $a->month))
            ->all();

        $months = [];
        $cursor = Period::previous();

        for ($i = 0; $i < 12; $i++) {
            $key = $cursor->key();

            if (! in_array($key, $taken, true)) {
                $months[] = ['value' => $key, 'label' => $cursor->label()];
            }

            $cursor = Period::of(
                $cursor->month === 1 ? $cursor->year - 1 : $cursor->year,
                $cursor->month === 1 ? 12 : $cursor->month - 1,
            );
        }

        return $months;
    }

    /**
     * «إنشاء أرشيف الآن» — يضع صفًّا ويدفع وظيفةً، ولا ينتظر.
     *
     * ولا يبني في الطلب: متجرٌ بألف فاتورةٍ يقضي دقائق، والطلبُ يموت عند
     * حدّ المهلة بينما الوظيفةُ ماضية — فيرى التاجر خطأً ويضغط ثانية.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], [], ['period' => __('فترة الأرشيف')]);

        if (! Policy::manualAllowed()) {
            return back()->with('toast', [
                'msg' => __('الإنشاء اليدويّ للأرشيف مُطفأ في هذه المنصّة.'),
                'type' => 'danger',
            ]);
        }

        [$year, $month] = array_map('intval', explode('-', $data['period']));

        try {
            $archive = Archives::request($this->bid(), Period::of($year, $month), auth()->id());
        } catch (\Throwable $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'danger']);
        }

        /*
         * والرسالةُ تقول ما وقع فعلًا لا «تمّ» عامّة.
         *
         * صفٌّ كان جاهزًا يردّ «موجودٌ من قبل» — فمن ضغط مرّتين يفهم لماذا
         * لم يتغيّر شيء، ولا يضغط ثالثة.
         */
        $msg = $archive->status === BusinessArchive::READY
            ? __('أرشيف :period جاهزٌ من قبل.', ['period' => $data['period']])
            : __('يُجهَّز أرشيف :period الآن — سيظهر «جاهز» حين يكتمل.', ['period' => $data['period']]);

        return back()->with('toast', ['msg' => $msg, 'type' => 'success']);
    }

    /**
     * تنزيلُ الـZIP — من خلف المصادقة، لا برابطٍ عامّ.
     *
     * ═══ ولا رابطَ عامّ بحال ═══
     *
     * القرصُ العامّ يُخدَم من `public/storage` مباشرةً بلا أن يُستدعى
     * Laravel. وهذا الملفُّ فيه مبيعاتُ الشهر كلِّها وأسعارُ الشراء وأسماءُ
     * العملاء وأرقامُهم — ورابطٌ واحد يُسرَّب يفتحه كلُّ من يعرفه إلى الأبد.
     *
     * والبابُ يسأل ثلاثةً: أمُصادَقٌ (الوسيط)، أمأذونٌ بالتصدير (الوسيط)،
     * أهذا أرشيفُ متجره (الاستعلام هنا).
     */
    public function download(int $archive)
    {
        $row = BusinessArchive::where('business_id', $this->bid())->find($archive);

        abort_if(! $row, 404);

        /*
         * والمنتهي لا يُنزَّل — ولو بقي ملفُّه على القرص.
         *
         * `downloadable()` هي السؤالُ الذي ترسم به الشاشةُ الزرَّ نفسُه —
         * فلا يبقى زرٌّ مرسومٌ على بابٍ أُغلق، ولا يُغلق بابٌ يدعو إليه زرّ.
         */
        abort_if(! $row->downloadable(), 404);

        $disk = Storage::disk($row->storage_disk ?? 'local');

        abort_if(! $disk->exists($row->storage_path), 404);

        Activity::log('backup', __('نزّل أرشيف بيانات :period', ['period' => $row->periodKey()]), [
            'business_id' => $row->business_id,
            'subject_type' => BusinessArchive::class,
            'subject_id' => $row->id,
        ]);

        return $disk->download($row->storage_path, basename($row->storage_path));
    }
}
