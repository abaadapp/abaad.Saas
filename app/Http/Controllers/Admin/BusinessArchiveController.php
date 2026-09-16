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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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
    /** كم صفًّا يُعرض من كلّ نوع — سنتان شهريّةً، وربعُ سنةٍ أسبوعيّةً */
    private const SHOWN = [Period::MONTHLY => 24, Period::WEEKLY => 16];

    /** كم فترةً مغلقةً تُعرض في قائمة «أنشئ الآن» */
    private const OFFERED = [Period::MONTHLY => 12, Period::WEEKLY => 8];

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /**
     * ما يُرسَل إلى تبويب «النسخ الاحتياطي» — يُنادى من `PageController`.
     *
     * ولا شاشةَ مستقلّة: التبويبُ قائمٌ منذ أوّل يوم («تنزيل نسخة من بياناتك
     * واستعادتها»)، والأرشيفُ قسمٌ ثالثٌ فيه. وشاشةٌ ثانيةٌ اسمُها «البيانات
     * والنسخ الاحتياطية» بجانب تبويبٍ اسمُه «النسخ الاحتياطي» تجعل التاجر
     * يبحث في أيّهما.
     *
     * ═══ والنوعان مفصولان في الرسالة لا في الشاشة وحدها ═══
     *
     * قائمةٌ واحدةٌ مختلطة تجعل التاجر يقرأ «7 – 13 سبتمبر» فوق «أغسطس
     * 2026» ولا يعرف أيُّهما يُغني عن الآخر. والفصلُ هنا لا في الواجهة:
     * لو فرزت الواجهةُ لَبقي الخادمُ يرسل ستّةً وأربعين صفًّا لتُعرض
     * أربعون.
     */
    public static function panel(int $bid, ?User $user): array
    {
        $may = (bool) $user?->may(Permissions::BUSINESS_EXPORT);

        return [
            'enabled' => Policy::enabled(),
            'weekly_enabled' => Policy::weeklyEnabled(),
            'may_generate' => Policy::manualAllowed() && $may,
            'may_download' => $may,
            'retention_months' => Policy::retentionMonths(),
            'retention_weeks' => Policy::retentionWeeks(),
            'monthly' => self::rows($bid, Period::MONTHLY),
            'weekly' => Policy::weeklyEnabled() ? self::rows($bid, Period::WEEKLY) : [],
            'offer_monthly' => self::selectable($bid, Period::MONTHLY),
            'offer_weekly' => Policy::weeklyEnabled() ? self::selectable($bid, Period::WEEKLY) : [],
        ];
    }

    /** صفوفُ نوعٍ واحد، من الأحدث */
    private static function rows(int $bid, string $type): array
    {
        return BusinessArchive::where('business_id', $bid)
            ->where('archive_type', $type)
            ->orderByDesc('period_start')
            ->limit(self::SHOWN[$type])
            ->get()
            ->map(fn (BusinessArchive $a) => [
                'id' => $a->id,
                'period' => $a->periodKey(),
                /*
                 * والعنوانُ يُبنى هنا بلغة الطلب لا يُخزَّن في القاعدة.
                 *
                 * `translatedFormat` تقرأ لغةَ الطلب الحاليّة — فالتاجرُ
                 * الذي بدّل لغتَه يرى «7 – 13 September» في اللحظة نفسِها،
                 * ولا تُعاد كتابةُ صفٍّ واحد.
                 */
                'label' => $a->periodLabel(),
                'status' => $a->status,
                'created_at' => optional($a->completed_at ?? $a->created_at)->format('Y-m-d'),
                /*
                 * والحجمُ بالميجابايت لا بالبايت: «26011238» لا يقرؤه أحد.
                 * و`null` حين لا ملفَّ — فلا يُطبع «0.0 MB» عن شيءٍ لا وجود له.
                 */
                'size_mb' => $a->file_size ? round($a->file_size / 1048576, 1) : null,
                'downloadable' => $a->downloadable(),
                'failure_reason' => $a->failure_reason,
            ])->all();
    }

    /**
     * الفتراتُ المغلقةُ التي لا أرشيفَ لها بعد — تُعرض في القائمة.
     *
     * وما له أرشيفٌ لا يُعرض: خيارٌ يُختار فيردّ «موجودٌ أصلًا» مقبضٌ لا
     * يُدير شيئًا.
     *
     * والقيمةُ المرسَلة تاريخُ البداية لا المفتاح: الخادمُ يعيد بناء المدى
     * منها بـ`Period::stored`، فلا يُكتب مُحلِّلٌ ثانٍ لـ«2026-W37» يفترق
     * عن الذي بناه.
     */
    private static function selectable(int $bid, string $type): array
    {
        $taken = BusinessArchive::where('business_id', $bid)
            ->where('archive_type', $type)
            ->pluck('period_start')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        $out = [];
        $cursor = Period::previous($type);

        for ($i = 0; $i < self::OFFERED[$type]; $i++) {
            $value = $cursor->start()->toDateString();

            if (! in_array($value, $taken, true)) {
                $out[] = ['value' => $value, 'label' => $cursor->label()];
            }

            $cursor = Period::stored($type, $cursor->start()->sub(
                $type === Period::WEEKLY ? '1 week' : '1 month'
            ));
        }

        return $out;
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
            'type' => ['required', 'string', Rule::in(Period::TYPES)],
            'period' => ['required', 'date_format:Y-m-d'],
        ], [], [
            'type' => __('نوع الأرشيف'),
            'period' => __('فترة الأرشيف'),
        ]);

        if (! Policy::manualAllowed()) {
            return back()->with('toast', [
                'msg' => __('الإنشاء اليدويّ للأرشيف مُطفأ في هذه المنصّة.'),
                'type' => 'danger',
            ]);
        }

        /*
         * والمدى يُعاد بناؤه من النوع لا يُؤخذ كما أُرسل.
         *
         * `Period::stored` تُنزل التاريخَ إلى أوّل يومٍ في مداه: من أرسل
         * يومًا في وسط أسبوعٍ يحصل على أسبوعه، ومن أرسل يومًا في وسط شهرٍ
         * يحصل على شهره. فلا يُكتب صفٌّ ببدايةٍ لا تطابق الفهرسَ الفريد —
         * وإلّا صار للأسبوع الواحد سبعةُ أرشيفات.
         */
        $period = $data['type'] === Period::WEEKLY
            ? Period::week(Carbon::parse($data['period']))
            : Period::month((int) Carbon::parse($data['period'])->year, (int) Carbon::parse($data['period'])->month);

        try {
            $archive = Archives::request($this->bid(), $period, auth()->id());
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
            ? __('أرشيف :period جاهزٌ من قبل.', ['period' => $period->label()])
            : __('يُجهَّز أرشيف :period الآن — سيظهر «جاهز» حين يكتمل.', ['period' => $period->label()]);

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
