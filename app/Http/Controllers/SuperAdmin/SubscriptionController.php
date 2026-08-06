<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * تعديل اشتراكٍ قائم.
 *
 * التجديد يُنشئ دورةً بسعر الباقة، وهو الحالة الغالبة. لكن الواقع لا يجري
 * كلّه على القاعدة: خصمٌ متّفق عليه، وتاريخُ بدءٍ خاطئ أُدخل، ودورةٌ جُدّدت
 * لشركةٍ بغير قصد. وبلا تعديلٍ لا يبقى إلا أن يُكتب الصواب في مكانٍ آخر —
 * ورقةٍ أو رسالة — فيفترق ما في النظام عمّا اتُّفق عليه.
 */
class SubscriptionController extends Controller
{
    public function update(Request $request, $id)
    {
        $subscription = Subscription::findOrFail($id);

        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'amount' => ['required', 'numeric', 'min:0'],
            'payment_status' => ['required', 'in:مدفوع,غير مدفوع'],
            'status' => ['required', 'in:نشط,منتهي,معطل'],
        ], [], [
            'starts_at' => __('تاريخ البداية'),
            'ends_at' => __('تاريخ الانتهاء'),
            'amount' => __('المبلغ'),
        ]);

        DB::transaction(function () use ($subscription, $data) {
            $subscription->update($data);
            $this->syncBusiness($subscription);
        });

        Activity::log('updated', 'عدّل اشتراك: '.($subscription->business?->name ?? '—'), [
            'business_id' => null,
            'subject_id' => $subscription->id,
        ]);

        return back()->with('toast', ['msg' => __('حُفظ الاشتراك'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $subscription = Subscription::findOrFail($id);
        $name = $subscription->business?->name ?? '—';

        DB::transaction(function () use ($subscription) {
            $business = $subscription->business;
            $subscription->delete();

            /*
             * بعد الحذف يعود المتجر إلى آخر دورةٍ باقية.
             *
             * وبلا هذا يبقى `ends_at` على التاريخ الذي كتبته الدورة المحذوفة:
             * تُحذف دورةٌ جُدّدت بالخطأ فيظلّ المتجر يعمل سنةً كاملة لم
             * يدفعها أحد — والسجلّ لا يقول من أين جاء التاريخ.
             */
            if ($business) {
                $last = Subscription::where('business_id', $business->id)->latest('ends_at')->first();
                $business->update(['ends_at' => $last?->ends_at]);
            }
        });

        Activity::log('deleted', 'حذف اشتراك: '.$name, ['business_id' => null]);

        return back()->with('toast', ['msg' => __('حُذف الاشتراك'), 'type' => 'warning']);
    }

    /**
     * المتجر يتبع أحدث دورةٍ له.
     *
     * الحارس يقرأ `businesses.ends_at`، والشاشة تعرض `subscriptions.ends_at`.
     * وتركُهما منفصلين يعني لوحةً تقول «نشط حتى ٢٠٢٧» وبابًا يُقفل اليوم —
     * والمصدران المفترقان أسوأ من مصدرٍ خاطئ، لأن أحدهما يبدو دائمًا صحيحًا.
     */
    private function syncBusiness(Subscription $subscription): void
    {
        $business = $subscription->business;
        if (! $business) {
            return;
        }

        $latest = Subscription::where('business_id', $business->id)->latest('ends_at')->first();
        if (! $latest || $latest->id !== $subscription->id) {
            return;
        }

        $business->update([
            'plan_id' => $subscription->plan_id ?: $business->plan_id,
            'starts_at' => $subscription->starts_at,
            'ends_at' => $subscription->ends_at,
        ]);
    }

    /** الباقات كما يقرؤها النموذج */
    public static function planOptions(): array
    {
        return Plan::orderBy('id')->get()
            ->map(fn ($p) => ['label' => $p->name, 'value' => $p->id, 'monthly' => (float) $p->monthly_price, 'yearly' => (float) $p->yearly_price])
            ->all();
    }
}
