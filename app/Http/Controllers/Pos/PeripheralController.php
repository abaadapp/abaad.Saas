<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * الأجهزة الملحقة بصندوق البيع.
 *
 * تُدار من الإعدادات مع الأجهزة، لأنها من عمل يوم التركيب لا من عمل الكاشير:
 * من يستطيع تبديل طابعة الصندوق يستطيع توجيه إيصالات فرعٍ إلى ورق فرعٍ آخر.
 */
class PeripheralController extends Controller
{
    public function store(Request $request, int $deviceId)
    {
        $device = $this->device($deviceId);
        $data = $this->validated($request);

        $peripheral = $device->peripherals()->create($data + ['business_id' => Demo::bid()]);
        Activity::log('created', 'أضاف ملحقًا: '.$peripheral->name.' — '.$device->name, [
            'subject_id' => $device->id,
        ]);

        return back()->with('toast', ['msg' => __('تمت إضافة الجهاز الملحق'), 'type' => 'success']);
    }

    public function update(Request $request, int $deviceId, int $id)
    {
        $peripheral = $this->peripheral($deviceId, $id);
        $peripheral->update($this->validated($request));
        Activity::log('updated', 'عدّل ملحقًا: '.$peripheral->name, ['subject_id' => $deviceId]);

        return back()->with('toast', ['msg' => __('تم حفظ الجهاز الملحق'), 'type' => 'success']);
    }

    public function destroy(int $deviceId, int $id)
    {
        $peripheral = $this->peripheral($deviceId, $id);
        $name = $peripheral->name;
        $peripheral->delete();
        Activity::log('deleted', 'حذف ملحقًا: '.$name, ['subject_id' => $deviceId]);

        return back()->with('toast', ['msg' => __('حُذف الجهاز الملحق'), 'type' => 'warning']);
    }

    /**
     * ما يُقبل من الواجهة — وما يُصفَّر منه.
     *
     * العنوان والمنفذ للشبكة وحدها، وعرض الورق والطباعة التلقائية للطابعة
     * وحدها. ولولا التصفير لبقيت قيمةٌ قديمة معلّقة بعد تبديل النوع: طابعةٌ
     * صارت ماسحًا وما زالت تحمل «طباعة تلقائية» فتُقرأ يومًا ولا أحد يعرف
     * من أين جاءت.
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(PosPeripheral::TYPES)],
            'connection' => ['required', Rule::in(PosPeripheral::CONNECTIONS)],
            'model' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:100', 'required_if:connection,network'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'paper_width' => ['nullable', Rule::in(PosPeripheral::PAPER_WIDTHS)],
            'auto_print' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'active' => ['boolean'],
        ], [], [
            'name' => __('اسم الجهاز'),
            'type' => __('النوع'),
            'connection' => __('طريقة التوصيل'),
            'address' => __('العنوان'),
        ]);

        $data['active'] = $request->boolean('active', true);

        if ($data['connection'] !== 'network') {
            $data['address'] = null;
            $data['port'] = null;
        }

        if ($data['type'] === PosPeripheral::PRINTER) {
            $data['paper_width'] = $data['paper_width'] ?? 80;
            $data['auto_print'] = $request->boolean('auto_print');
        } else {
            $data['paper_width'] = null;
            $data['auto_print'] = false;
        }

        return $data;
    }

    /** الجهاز داخل متجر المستخدم وحده — منعًا لتخطّي المستأجرين بالمعرّف */
    private function device(int $id): PosDevice
    {
        return PosDevice::where('business_id', Demo::bid())->findOrFail($id);
    }

    /** والملحق داخل جهازه: معرّفٌ صحيح تحت جهازٍ ليس لك لا يفتح شيئًا */
    private function peripheral(int $deviceId, int $id): PosPeripheral
    {
        return $this->device($deviceId)->peripherals()->findOrFail($id);
    }
}
