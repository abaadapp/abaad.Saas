<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Support\Demo;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'branch' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'scheduled_at' => ['required', 'date'],
        ]);
        Appointment::create([
            'business_id' => $this->bid(),
            'title' => $data['title'],
            'customer_name' => $data['customer_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'branch' => $data['branch'] ?? 'الفرع الرئيسي',
            'notes' => $data['notes'] ?? null,
            'scheduled_at' => $data['scheduled_at'],
            'status' => 'مجدول',
        ]);
        \App\Support\Activity::log('created', 'أضاف موعدًا مجدولًا: ' . $data['title']);

        return back()->with('toast', ['msg' => 'تمت جدولة الموعد بنجاح', 'type' => 'success']);
    }

    private function find($id): Appointment
    {
        return Appointment::where('business_id', $this->bid())->findOrFail($id);
    }

    public function updateStatus(Request $request, $id)
    {
        $appt = $this->find($id);
        $data = $request->validate(['status' => ['required', 'in:مجدول,مؤكد,مكتمل,ملغي']]);
        $appt->update(['status' => $data['status']]);
        \App\Support\Activity::log('status', 'حدّث حالة الموعد «' . $appt->title . '» إلى ' . $data['status'], ['subject_id' => $appt->id]);

        return back()->with('toast', ['msg' => 'تم تحديث حالة الموعد', 'type' => 'success']);
    }

    /** تحويل موعد مجدول إلى طلب POS بضغطة واحدة */
    public function convert($id)
    {
        $appt = $this->find($id);

        $order = \App\Models\Order::create([
            'business_id' => $this->bid(),
            'number' => 'ORD-' . random_int(10000, 99999),
            'customer_name' => $appt->customer_name ?: 'عميل نقدي',
            'employee_name' => auth()->user()->name,
            'branch' => $appt->branch ?: 'الفرع الرئيسي',
            'status' => 'جديد',
            'payment_method' => 'غير محدد',
            'payment_status' => 'غير مدفوع',
            'subtotal' => 0,
            'total' => 0,
            'notes' => 'محوّل من موعد مجدول: ' . $appt->title . ($appt->notes ? ' — ' . $appt->notes : ''),
            'ordered_at' => now(),
        ]);
        $order->items()->create([
            'product_id' => null,
            'name' => $appt->title,
            'price' => 0,
            'quantity' => 1,
            'total' => 0,
        ]);

        $appt->update(['status' => 'مكتمل']);
        \App\Support\Activity::log('checkout', 'حوّل موعدًا إلى طلب ' . $order->number . ': ' . $appt->title, ['subject_id' => $order->id]);

        return redirect()->route('admin.orders.show', $order->number)
            ->with('toast', ['msg' => 'تم إنشاء الطلب ' . $order->number . ' — أضِف الأصناف والدفع', 'type' => 'success']);
    }

    public function destroy($id)
    {
        $appt = $this->find($id);
        $title = $appt->title;
        $appt->delete();
        \App\Support\Activity::log('deleted', 'حذف الموعد: ' . $title);

        return back()->with('toast', ['msg' => 'تم حذف الموعد', 'type' => 'warning']);
    }
}
