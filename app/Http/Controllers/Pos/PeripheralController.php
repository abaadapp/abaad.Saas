<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Rules\PrinterAddress;
use App\Support\CustomerInvoicePreparations;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\PrintJobs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $peripheral = DB::transaction(function () use ($device, $data) {
            $this->lockTill($device);

            /*
             * وأوّلُ طابعةٍ على صندوقٍ لا طابعةَ عليه هي طابعةُ إيصاله — لا لبسَ
             * فيها. وما بعدها لا يُسمّى إلّا بيد التاجر: الثانيةُ لا تأخذ مكان
             * الأولى صامتةً.
             */
            if ($data['type'] === PosPeripheral::PRINTER
                && ! $device->peripherals()->where('type', PosPeripheral::PRINTER)->exists()) {
                $data['is_receipt_printer'] = true;
            }

            $this->oneReceiptPrinter($device, $data);

            return $device->peripherals()->create($data + ['business_id' => Demo::bid(), 'is_receipt_printer' => false]);
        });
        Activity::log('created', 'أضاف ملحقًا: '.$peripheral->name.' — '.$device->name, [
            'subject_id' => $device->id,
        ]);

        return back()->with('toast', ['msg' => __('تمت إضافة الجهاز الملحق'), 'type' => 'success']);
    }

    public function update(Request $request, int $deviceId, int $id)
    {
        $peripheral = $this->peripheral($deviceId, $id);
        $data = $this->validated($request);

        DB::transaction(function () use ($peripheral, $data) {
            $this->lockTill($peripheral->device);
            $this->oneReceiptPrinter($peripheral->device, $data, $peripheral->id);
            $peripheral->update($data);
        });
        Activity::log('updated', 'عدّل ملحقًا: '.$peripheral->name, ['subject_id' => $deviceId]);
        // طابعةٌ رُفع عنها «فواتير العملاء» أو عُطّلت: ورقُ صندوقها المنتظِر لا يعلق عليه
        PrintJobs::release($this->device($deviceId));

        return back()->with('toast', ['msg' => __('تم حفظ الجهاز الملحق'), 'type' => 'success']);
    }

    public function destroy(int $deviceId, int $id)
    {
        $peripheral = $this->peripheral($deviceId, $id);
        $name = $peripheral->name;
        $peripheral->delete();
        Activity::log('deleted', 'حذف ملحقًا: '.$name, ['subject_id' => $deviceId]);
        PrintJobs::release($this->device($deviceId));

        return back()->with('toast', ['msg' => __('حُذف الجهاز الملحق'), 'type' => 'warning']);
    }

    /**
     * طابعةُ إيصالٍ واحدةٌ على الصندوق — من سُمّيت الآن تأخذ مكان السابقة.
     *
     * وفي القاعدة فهرسٌ فريدٌ جزئيّ يمنع اثنتين (`pos_peripherals_one_receipt_printer`)؛
     * وهذا الرفعُ قبل الكتابة يجعل التبديلَ خطوةً واحدة لا خطأً من القاعدة.
     */
    private function oneReceiptPrinter(PosDevice $device, array $data, ?int $except = null): void
    {
        if (! ($data['is_receipt_printer'] ?? false)) {
            return;
        }

        $device->peripherals()
            ->where('is_receipt_printer', true)
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->update(['is_receipt_printer' => false]);
    }

    /** حفظان معًا على صندوقٍ واحد يُصفّان — لا يسمّي كلٌّ منهما طابعتَه في اللحظة نفسِها */
    private function lockTill(PosDevice $device): void
    {
        PosDevice::whereKey($device->id)->lockForUpdate()->first();
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
            /*
             * وعنوانُ طابعة الشبكة يُفحص هنا بقاعدة التطبيق نفسِها (`PrinterAddress`):
             * ما يردّه التطبيقُ عند الطباعة يُردّ عند الحفظ، لا بعد أوّل بيع.
             */
            'address' => ['nullable', 'string', 'max:100', 'required_if:connection,network',
                Rule::when(
                    $request->input('connection') === 'network' && $request->input('type') === PosPeripheral::PRINTER,
                    [new PrinterAddress],
                )],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'paper_width' => ['nullable', Rule::in(PosPeripheral::PAPER_WIDTHS)],
            'auto_print' => ['boolean'],
            'auto_print_website_confirm' => ['boolean'],
            'auto_print_customer_invoices' => ['boolean'],
            'is_receipt_printer' => ['boolean'],
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
            /*
             * وطباعةُ طلب الموقع عند تأكيده خيارٌ مستقلّ عن «بعد البيع» —
             * لا يُشتقّ منه ولا يُغيّره. انظر `WebsiteConfirmPrint`.
             */
            $data['auto_print_website_confirm'] = $request->boolean('auto_print_website_confirm');
            /*
             * وفواتيرُ العملاء المسدَّدة — لنشاطٍ فُتح له التجهيزُ بعد السداد وحده
             * (`CustomerInvoicePreparations::on`). ومن لم يُفتح له يُكتب «لا»
             * أيًّا أرسل: خيارٌ لا يفتحه التاجرُ لنفسه.
             */
            $data['auto_print_customer_invoices'] = $request->boolean('auto_print_customer_invoices')
                && CustomerInvoicePreparations::on((int) Demo::bid());
            /*
             * و«طابعة الإيصال» اختيارٌ صريحٌ غيرُ «الطباعة التلقائية»: الأولى تقول
             * أيَّ طابعةٍ يطبع عليها الصندوقُ إيصالَ البيع، والثانية هل يطبعه وحده.
             */
            if ($request->has('is_receipt_printer')) {
                $data['is_receipt_printer'] = $request->boolean('is_receipt_printer');
            } else {
                // صفحةٌ أقدمُ من الحقل لا تمحو التسميةَ بغيابه — يبقى ما كان
                unset($data['is_receipt_printer']);
            }
        } else {
            $data['paper_width'] = null;
            $data['auto_print'] = false;
            // والماسحُ والدرجُ والشاشةُ والميزانُ لا يطبعون — فلا يحملون الخيار
            $data['auto_print_website_confirm'] = false;
            $data['auto_print_customer_invoices'] = false;
            $data['is_receipt_printer'] = false;
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
