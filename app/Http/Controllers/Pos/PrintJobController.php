<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\PosDevice;
use App\Models\PrintJob;
use App\Support\CustomerInvoicePreparations;
use App\Support\PosTerminal;
use App\Support\PrintJobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * الصندوقُ يسأل ورقتَه — طابورُ الطباعة من جهة الصندوق.
 *
 * كلُّ بابٍ هنا يعرف الصندوقَ من بصمته (`PosTerminal::current`) لا من رقمٍ
 * يُرسل: متصفّحٌ غيرُ مربوطٍ بصندوقٍ لا يرى طابورًا ولا يحجز منه. والورقةُ
 * تُنزَّل لمن حجزها وحده — لا لكلّ من عرف رقمها.
 */
class PrintJobController extends Controller
{
    private function terminal(): ?PosDevice
    {
        return PosTerminal::current();
    }

    /** أهذا صندوقٌ معيَّن؟ وكم ورقةً تنتظره؟ — لا يحجز شيئًا */
    public function index(): JsonResponse
    {
        $device = $this->terminal();

        return response()->json([
            'designated' => $device !== null && PrintJobs::printerOf($device) !== null,
            'waiting' => $device ? PrintJobs::waitingFor($device) : 0,
        ]);
    }

    /** يحجز الورقةَ التالية — أو لا شيء */
    public function claim(): JsonResponse
    {
        $device = $this->terminal();
        $job = $device ? PrintJobs::claim($device) : null;

        if (! $job) {
            return response()->json(['job' => null]);
        }

        return response()->json(['job' => [
            'id' => $job->id,
            'kind' => $job->kind,
            'number' => $this->numberOf($job),
            'url' => route('pos.printJobs.pdf', $job->id),
        ]]);
    }

    /** الورقةُ نفسُها — لمن حجزها وحده، والصندوقُ يطبعها بعرض طابعته */
    public function pdf(int $id)
    {
        $device = $this->terminal();
        abort_unless($device, 404);

        $job = PrintJob::where('business_id', $device->business_id)->findOrFail($id);
        abort_unless(
            $job->status === PrintJob::CLAIMED && (int) $job->claimed_by_device_id === (int) $device->id,
            404,
        );

        [$prep, $invoice] = $this->subject($job);

        return CustomerInvoicePreparations::internalPdf($prep, $invoice);
    }

    /** يقول الصندوقُ ما جرى — ولا يكتب على حجز غيره */
    public function ack(Request $request, int $id): JsonResponse
    {
        $device = $this->terminal();
        abort_unless($device, 404);

        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'via' => ['nullable', 'string', 'max:20'],
            'error' => ['nullable', 'string', 'max:255'],
            'may_have_printed' => ['nullable', 'boolean'],
        ]);

        $job = PrintJob::where('business_id', $device->business_id)->findOrFail($id);

        $done = PrintJobs::acknowledge(
            $job,
            $device,
            (bool) $data['ok'],
            $data['via'] ?? null,
            $data['error'] ?? null,
            (bool) ($data['may_have_printed'] ?? false),
        );

        return response()->json(['recorded' => $done, 'status' => $job->fresh()->status]);
    }

    /** رقمُ الفاتورة لورقتها — ما يُقرأ في تنبيه الصندوق */
    private function numberOf(PrintJob $job): ?string
    {
        $prep = CustomerInvoicePreparation::where('business_id', $job->business_id)->find($job->subject_id);

        return $prep ? CustomerInvoice::whereKey($prep->customer_invoice_id)->value('number') : null;
    }

    /** @return array{0: CustomerInvoicePreparation, 1: CustomerInvoice} */
    private function subject(PrintJob $job): array
    {
        abort_unless($job->kind === PrintJob::KIND_INVOICE_INTERNAL, 404);

        $prep = CustomerInvoicePreparation::where('business_id', $job->business_id)->findOrFail($job->subject_id);
        $invoice = CustomerInvoice::where('business_id', $job->business_id)->with('items')->findOrFail($prep->customer_invoice_id);

        return [$prep, $invoice];
    }
}
