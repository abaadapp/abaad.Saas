<?php

namespace App\Http\Controllers;

use App\Models\DismissedNotification;
use App\Models\Order;
use App\Support\Demo;
use Illuminate\Http\Request;

/**
 * تغذية الإشعارات (JSON) لاستطلاعها من المتصفح وإظهار إشعارات فورية للطلبات الجديدة.
 */
class NotificationController extends Controller
{
    public function feed()
    {
        $bid = auth()->user()->business_id ?? Demo::bid();

        $latest = Order::where('business_id', $bid)->where('is_held', false)
            ->when(Demo::currentBranchId(), fn ($q) => $q->where('branch_id', Demo::currentBranchId()))
            ->orderByDesc('id')->first();

        return response()->json([
            'count' => Demo::notificationsCount(),
            'items' => Demo::notifications(),
            'latest_order' => $latest ? [
                'id' => $latest->id,
                'number' => $latest->number,
                'customer' => $latest->customer_name ?? 'عميل نقدي',
                'total' => (float) $latest->total,
                'url' => route('admin.orders.show', $latest->number),
            ] : null,
        ]);
    }

    /** حذف (إخفاء) تنبيه واحد للمستخدم الحالي */
    public function dismiss(Request $request)
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:191']]);

        DismissedNotification::firstOrCreate([
            'user_id' => auth()->id(),
            'notif_key' => $data['key'],
        ]);

        return response()->json(['ok' => true]);
    }

    /** حذف جميع التنبيهات المعروضة حاليًا للمستخدم */
    public function clear()
    {
        foreach (Demo::allNotifications() as $n) {
            DismissedNotification::firstOrCreate([
                'user_id' => auth()->id(),
                'notif_key' => $n['key'],
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /** إرسال التنبيهات الذكية فورًا لصاحب النشاط الحالي (تشغيل يدوي من الإعدادات) */
    public function sendSmart()
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        $business = \App\Models\Business::find($bid);

        if (! $business || ! $business->email) {
            return response()->json(['ok' => false, 'message' => __('لا يوجد بريد إلكتروني للنشاط. أضِفه من بيانات النشاط أولًا.')], 422);
        }

        $alerts = Demo::smartAlertsFor($bid);
        if (empty($alerts)) {
            return response()->json(['ok' => true, 'count' => 0, 'message' => __('لا توجد تنبيهات حاليًا — كل المؤشّرات جيدة 👍')]);
        }

        try {
            \Illuminate\Support\Facades\Mail::to($business->email)->send(new \App\Mail\SmartAlertMail($business->name, $alerts));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => __('تعذّر إرسال البريد. تحقّق من إعدادات البريد.')], 500);
        }

        return response()->json([
            'ok' => true,
            'count' => count($alerts),
            'message' => __('تم إرسال :count تنبيه إلى :email', ['count' => count($alerts), 'email' => $business->email]),
        ]);
    }
}
