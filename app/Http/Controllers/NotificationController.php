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
}
