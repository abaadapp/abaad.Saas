<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePreparation;
use App\Models\PosDevice;
use App\Models\PrintJob;
use App\Support\CustomerInvoicePreparations;
use App\Support\Demo;
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
 *
 * والصندوقُ صندوقُ متجر الجلسة: بصمةُ صندوقِ متجرٍ آخر في متصفّح موظّفٍ
 * من هذا لا تفتح له طابورَ ذاك (`BindPosBranch` يردّه قبلها — وهنا لا يُتّكل عليه).
 */
class PrintJobController extends Controller
{
    private function terminal(): ?PosDevice
    {
        $device = PosTerminal::current();

        return $device && (int) $device->business_id === (int) Demo::bid() ? $device : null;
    }

    /**
     * أهذا صندوقٌ معيَّن؟ وكم ورقةً تنتظره؟ وما فتح حوارَه ولم يُقل أخرج؟
     * — لا يحجز شيئًا.
     */
    public function index(): JsonResponse
    {
        $device = $this->terminal();
        $serves = $device !== null && PrintJobs::serves($device);

        /*
         * وحجزٌ انقضت مهلتُه يُقال «فشل» هنا أيضًا — لا عند الحجز التالي وحده.
         *
         * صندوقُ المتصفّح لا يحجز إلّا بضغطة، وزرُّها لا يظهر إلّا وفي الطابور
         * ورقة. فحجزٌ انقطع ردُّه (أُغلق اللسان، سقطت الشبكة) كان يبقى «يطبعها
         * الصندوق الآن» أبدًا، واللوحةُ لا تعيده ولا تؤكّده. وهذا السؤالُ يقع
         * كلَّ عشرين ثانية.
         */
        if ($serves) {
            PrintJobs::expireClaims((int) $device->business_id);
        }

        return response()->json([
            'designated' => $serves,
            'waiting' => $serves ? PrintJobs::waitingFor($device) : 0,
            'awaiting' => $serves
                ? PrintJobs::awaitingConfirmation($device)
                    ->map(fn (PrintJob $job) => ['id' => $job->id, 'number' => PrintJobs::numberOf($job)])
                    ->values()->all()
                : [],
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
            'number' => PrintJobs::numberOf($job),
            'url' => route('pos.printJobs.pdf', $job->id),
        ]]);
    }

    /**
     * الورقةُ نفسُها — لمن حجزها وحده، والصندوقُ يطبعها بعرض طابعته.
     *
     * والتجهيزُ مغلقٌ لا تُنزَّل: حجزٌ سبق الإغلاقَ بلحظة يسقط هنا، فيقول
     * الصندوقُ إنّه لم يُطبع منه شيء — فتُلغى ولا تعود (`acknowledge`).
     */
    public function pdf(int $id)
    {
        $device = $this->terminal();
        abort_unless($device && CustomerInvoicePreparations::on((int) $device->business_id), 404);

        $job = PrintJob::where('business_id', $device->business_id)->findOrFail($id);
        abort_unless(
            (int) $job->claimed_by_device_id === (int) $device->id
                && ($job->status === PrintJob::CLAIMED || self::justOpenedInATab($job)),
            404,
        );

        [$prep, $invoice] = $this->subject($job);

        return CustomerInvoicePreparations::internalPdf($prep, $invoice);
    }

    /**
     * لسانٌ فتحه الصندوقُ في الضغطة نفسِها، وردُّه سبق طلبَ الملفّ.
     *
     * ═══ ولمَ ═══
     *
     * في Safari وFirefox (لا طباعةَ من الصفحة) يُفتح لسانٌ فارغٌ في الضغطة، ثمّ
     * يُوجَّه إلى هذا الملفّ، ويقول الصندوقُ في اللحظة نفسِها «فُتح» (`via=browser`).
     * والطلبان يتسابقان: إن كُتب الردُّ أوّلًا صارت الورقةُ «فُتح حوارها» قبل أن
     * يُقرأ الملفّ — فيرى الكاشيرُ صفحةَ 404 والخادمُ يقول «فُتح»، ويجيب «لم
     * تخرج» فيضغط ثانيةً فيسبقه الردُّ ثانيةً.
     *
     * فيُنزَّل لصندوقه وحده، لهذا الطريق وحده، في مهلة الحجز وحدها. وتنزيلُ ملفٍّ
     * لا يطبع شيئًا بنفسه: الطباعةُ الثانية الوحيدة يد الكاشير — وهي له على أيّ
     * حال من «فتح PDF». وبعد المهلة يعود الجواب 404 كما كان.
     */
    private static function justOpenedInATab(PrintJob $job): bool
    {
        return $job->status === PrintJob::REQUESTED
            && $job->via === 'browser'
            && $job->requested_at !== null
            && $job->requested_at->gt(now()->subSeconds(PrintJobs::CLAIM_SECONDS));
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

    /**
     * «خرجت» أو «لم تخرج» — يقولها الكاشيرُ عن ورقةٍ فتح صندوقُه حوارَها.
     *
     * المتصفّحُ لا يقول أطَبع الكاشيرُ أم ألغى الحوار، فيُسأل هو. «خرجت»
     * ← `printed` باسمه. «لم تخرج» ← تعود إلى طابور صندوقه لتُطبع **بضغطة**
     * لا وحدها. ولا يقولها إلّا صندوقُها، والتجهيزُ مفتوح.
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $device = $this->terminal();
        abort_unless($device && CustomerInvoicePreparations::on((int) $device->business_id), 404);

        $data = $request->validate(['printed' => ['required', 'boolean']]);

        $job = PrintJob::where('business_id', $device->business_id)->findOrFail($id);

        $done = (bool) $data['printed']
            ? PrintJobs::confirmPrinted($job, (int) auth()->id(), $device)
            : PrintJobs::notPrinted($job, $device);

        return response()->json(['recorded' => $done, 'status' => $job->fresh()->status]);
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
