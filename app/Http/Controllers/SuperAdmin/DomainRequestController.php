<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\DomainRequest;
use App\Support\Activity;
use App\Support\DomainOptions;
use App\Support\MarketingSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * طلبات النطاقات كما يراها المشغّل.
 *
 * هي الطرف الثاني من زرٍّ في لوحة التاجر: «اطلب من أبعاد تجهيز نطاق». وبدون
 * هذه الشاشة يكون الزرّ مقبضًا لا يُمسك — يضغطه التاجر فيُقال له «وصلنا
 * طلبك» ولا يصل إلى أحد.
 *
 * ولا تُغلق الشاشةُ الطلبَ صامتةً: الإغلاق يحمل ردًّا يقرؤه التاجر في لوحته،
 * لأنّ «مرفوض» بلا سببٍ تترك صاحبها يعيد الطلب نفسه.
 */
class DomainRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $status = in_array($status, DomainRequest::STATUSES, true) ? $status : null;

        $rows = DomainRequest::with('business:id,name')
            ->when($status, fn ($q) => $q->where('status', $status))
            /*
             * المعلّق أوّلًا ثم الأحدث.
             *
             * الترتيب بالتاريخ وحده يدفن طلبًا معلّقًا من الأمس تحت عشرة
             * أُغلقت اليوم — وهو الوحيد الذي ينتظر أحدًا.
             */
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [DomainRequest::PENDING])
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'business' => $r->business?->name ?? '—',
                'business_id' => $r->business_id,
                'domain' => $r->domain,
                'note' => $r->note,
                'status' => $r->status,
                'at' => optional($r->created_at)->format('Y-m-d'),
                'handled_at' => optional($r->handled_at)->format('Y-m-d'),
            ])->all();

        return Inertia::render('Platform/Domains/Index', [
            'requests' => $rows,
            'filters' => ['status' => $status],
            'statuses' => DomainRequest::STATUSES,
            // العدد المعلّق — الشاشة تقوله في ترويستها، فلا يُعدّ بالعين
            'pending' => DomainRequest::where('status', DomainRequest::PENDING)->count(),
        ]);
    }

    public function status(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([DomainRequest::DONE, DomainRequest::REJECTED])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        /*
         * والرفض يلزمه سبب.
         *
         * «مرفوض» وحدها لا تقول للتاجر أالنطاق محجوز أم الاسم ممنوع أم
         * السعر لم يُدفع — فيعيد الطلب نفسه، ويُرفض ثانيةً بالصمت نفسه.
         */
        if ($data['status'] === DomainRequest::REJECTED && blank($data['note'] ?? null)) {
            return back()->withErrors(['note' => __('اكتب سبب الرفض — يقرؤه التاجر في لوحته.')]);
        }

        $req = DomainRequest::findOrFail($id);

        /*
         * ولا يُغلق طلبٌ أُغلق.
         *
         * الشاشة لا تعرض زرَّي الإغلاق إلا على المعلّق، لكنّ المسار كان يقبل
         * أيّ حال: طلبٌ «مكتمل» — اشتُري نطاقُه ونُزّل في إعدادات المتجر —
         * يصير «مرفوضًا» بطلبٍ واحد، فيقرأ التاجر رفضًا لنطاقٍ يعمل على
         * فواتيره. وتصحيحُ إغلاقٍ خاطئ بابٌ آخر يُفتح عند الحاجة، لا أثرٌ
         * جانبيّ لزرٍّ لا يُعرض.
         */
        if ($req->status !== DomainRequest::PENDING) {
            return back()->withErrors([
                'status' => __('هذا الطلب أُغلق من قبل — لا يُغلق مرّتين.'),
            ]);
        }

        $req->update([
            'status' => $data['status'],
            'note' => $data['note'] ?? $req->note,
            'handled_at' => now(),
            'handled_by' => auth()->id(),
        ]);

        /*
         * و«تمّ التجهيز» تكتب النطاق في إعدادات المتجر.
         *
         * بدونها يُغلق الطلب ويبقى حقل النطاق عند التاجر فارغًا: يقرأ «مكتمل»
         * ولا يجد نطاقه في فاتورته ولا في شاشة السيو، فيكتبه بيده — أو لا
         * ينتبه أنّ عليه أن يكتبه، فيبقى ما اشتريناه له بلا أثر في نظامه.
         *
         * ولا يُكتب فوق نطاقٍ قائم: تاجرٌ يعمل على `mystore.om` ويطلب
         * `mystore.com` لا يجوز أن يتبدّل عنوانه في فواتيره لأنّ المشغّل ضغط
         * زرًّا. والقرار في هذه الحال قرارُه هو من شاشته.
         *
         * والنشر يبقى مطفأً: فتحُ موقعٍ على الزوّار قرارُ صاحبه لا قرارُنا.
         */
        if ($data['status'] === DomainRequest::DONE) {
            $site = MarketingSettings::group($req->business_id, 'website');

            if (trim((string) $site['site_domain']) === '') {
                MarketingSettings::save($req->business_id, 'website', [
                    'site_domain' => $req->domain,
                    'site_domain_mode' => DomainOptions::OWN,
                ]);
            }
        }

        Activity::log('status', 'أغلق طلب نطاق '.$req->domain.' بـ'.$data['status']);

        return back()->with('toast', ['msg' => __('حُدّث الطلب'), 'type' => 'success']);
    }
}
