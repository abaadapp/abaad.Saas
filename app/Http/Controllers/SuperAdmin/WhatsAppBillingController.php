<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\WhatsAppBillingTrial;
use App\Models\WhatsAppConnection;
use App\Support\Activity;
use App\Support\WhatsAppBillingLock;
use App\Support\WhatsAppBillingTrials;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppPrices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ‏‏قفلُ الفوترة بيد مدير المنصّة — يراجع دليلَ متجر، ويفتح التجربةَ ويسجّل نتيجتها، ويعيد القفل.
 *
 * المسارُ تحت `role:super_admin`. ولا يُقبل ملفٌّ: صورةُ الدليل قد تحمل بطاقة — يُكتب نوعُها ومرجعُها وحدهما.
 * والقرارُ في `WhatsAppBillingLock` و`WhatsAppBillingTrials`: لا شرطَ هنا يفترق عنهما.
 */
class WhatsAppBillingController extends Controller
{
    /** ‏‏قراءةٌ عند Meta لا غير — وما نقضته يوقف */
    public function read(int $id): RedirectResponse
    {
        $c = $this->connection($id);

        if (! WhatsAppBillingLock::reviewable($c)) {
            return $this->refuse('whatsapp', __('لا وصلة مكتملة لهذا المتجر بعد.'));
        }

        $row = WhatsAppBillingLock::read($c, force: true);

        return $this->said(__('جهة الرسوم: :state', ['state' => WhatsAppBillingLock::label($row->state)]), $row->state === WhatsAppBillingLock::PROVEN ? 'success' : 'info');
    }

    public function reopen(Request $request, int $id): RedirectResponse
    {
        $why = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']], [], ['reason' => __('السبب')])['reason'];
        $c = $this->connection($id);

        if ($c === null || ! WhatsAppBillingLock::reopen($c, $request->user(), $why)) {
            return $this->refuse('reason', __('لا شيء يُعاد فتحه لهذا المتجر.'));
        }

        return $this->said(WhatsAppBillingLock::applies($c)
            ? __('يلزم إعادة التحقق — توقف إرسال هذا المتجر من رقمه حتى يُراجع دليل جديد.')
            : __('يلزم إعادة التحقق — وهذا الربط من قبل القفل فلم يتوقف إرساله.'));
    }

    public function retry(Request $request, int $id): RedirectResponse
    {
        $c = $this->connection($id);
        $r = $c === null ? ['ok' => false, 'message' => __('لا وصلة مكتملة لهذا المتجر بعد.')] : WhatsAppBillingLock::retry($c, $request->user());

        return $r['ok'] ? $this->said($r['message']) : $this->refuse('whatsapp', $r['message']);
    }

    /** ‏‏تقديرُ Meta لكلفة حساب المتجر — قراءةٌ لا غير */
    public function estimate(int $id): RedirectResponse
    {
        $c = $this->connection($id);
        $e = $c === null ? null : WhatsAppPrices::refresh($c);

        return $this->estimated($e);
    }

    /** ‏‏وتقديرُها لرقم أبعاد المشترك — ما يُحتسب على أبعاد */
    public function sharedCost(): RedirectResponse
    {
        $e = WhatsAppPrices::refreshShared();

        if ($e !== null) {
            Activity::log('settings', 'واتساب — قراءة تقدير Meta لكلفة رقم أبعاد المشترك: '.$e['state']);
        }

        return $this->estimated($e);
    }

    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_id' => ['required', 'integer', 'exists:businesses,id'],
            'messages' => ['required', 'integer', 'min:1', 'max:'.WhatsAppBillingTrials::MAX_MESSAGES],
            'hours' => ['required', 'integer', 'min:1', 'max:'.WhatsAppBillingTrials::MAX_HOURS],
            // ‏‏رسومٌ حقيقيّة على بطاقة حساب الاختبار — بموافقةٍ تُكتب لا تُفترض
            'charges_accepted' => ['accepted'],
            // ‏‏وأرقامُ الاختبار وحدها تصلها رسائلُ التجربة (`WhatsAppBillingTrials::recipient`)
            'phones' => ['required', 'string', 'max:500'],
        ], ['charges_accepted.accepted' => __('وافق أولًا على الرسوم الفعلية على بطاقة حساب الاختبار.')], [
            'business_id' => __('المتجر'), 'messages' => __('عدد الرسائل'), 'hours' => __('المدة بالساعات'), 'phones' => __('أرقام الاختبار'),
        ]);

        $phones = WhatsAppBillingTrials::phones($data['phones']);

        if (! $phones['ok']) {
            return $this->refuse('phones', $phones['message']);
        }

        $r = WhatsAppBillingTrials::open(Business::findOrFail($data['business_id']), $request->user(), (int) $data['messages'], (int) $data['hours'], $phones['phones']);

        return $r['ok'] ? $this->said($r['message']) : $this->refuse($r['field'], $r['message']);
    }

    public function close(Request $request, WhatsAppBillingTrial $trial): RedirectResponse
    {
        return WhatsAppBillingTrials::close($trial, $request->user())
            ? $this->said(__('أُغلقت التجربة — توقف إرسال المتجر الاختباري من رقمه.'))
            : $this->refuse('trial', __('التجربة ليست مفتوحة.'));
    }

    public function pass(Request $request, WhatsAppBillingTrial $trial): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(WhatsAppBillingTrials::PROOF_KINDS))],
            'price_id' => ['required', 'integer'],
            'waba_id' => ['required', 'string', 'max:40'],
            'reference' => ['required', 'string', 'min:3', 'max:120'],
            'abaad_reference' => ['required', 'string', 'min:3', 'max:120'],
            // ‏‏المبلغُ كما كُتب في فاتورة Meta — اختياريّ، ولا يُحسب عندنا
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/', 'required_with:amount'],
            'merchant_checked' => ['accepted'],
            'abaad_checked' => ['accepted'],
        ], [
            'merchant_checked.accepted' => __('أقرّ أنك رأيت الرسم على حساب التاجر.'),
            'abaad_checked.accepted' => __('أقرّ أنك راجعت حساب أبعاد ولم يظهر فيه الرسم.'),
        ], [
            'kind' => __('نوع الدليل'), 'price_id' => __('الرسالة'), 'waba_id' => __('معرّف الحساب'), 'reference' => __('مرجع الدليل'),
            'abaad_reference' => __('مرجع مراجعة حساب أبعاد'), 'amount' => __('المبلغ'), 'currency' => __('العملة'),
        ]);

        $r = WhatsAppBillingTrials::pass($trial, $request->user(), $data);

        return $r['ok'] ? $this->said($r['message']) : $this->refuse($r['field'], $r['message']);
    }

    public function relock(Request $request): RedirectResponse
    {
        $why = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']], [], ['reason' => __('السبب')])['reason'];

        return WhatsAppBillingTrials::relock($request->user(), $why)
            ? $this->said(__('أُعيد القفل — يعود التجّار الجدد إلى تطبيقاتهم. ما ثبت لمتجر يبقى.'))
            : $this->refuse('reason', __('النقل مقفل أصلًا.'));
    }

    private function connection(int $id): ?WhatsAppConnection
    {
        return WhatsAppConnections::forBusiness((int) Business::findOrFail($id)->id);
    }

    private function estimated(?array $e): RedirectResponse
    {
        return match ($e['state'] ?? null) {
            null => $this->refuse('whatsapp', __('لا حساب واتساب يُقرأ تقديره.')),
            'failed' => $this->refuse('whatsapp', __('لم تُعطِ Meta التقدير الآن (:code) — أعد المحاولة لاحقًا.', ['code' => $e['code'] ?? '—'])),
            'none' => $this->said(__('لا رسائل وصلت في هذه المدة.'), 'info'),
            WhatsAppPrices::COST_HIDDEN => $this->said(WhatsAppBillingLock::says(WhatsAppBillingLock::COST_HIDDEN)['text'], 'warning'),
            default => $this->said(__('قُرئ تقدير Meta — قد يختلف عن الفاتورة.')),
        };
    }

    private function said(string $msg, string $type = 'success'): RedirectResponse
    {
        return back()->with('toast', ['msg' => $msg, 'type' => $type]);
    }

    private function refuse(string $field, string $msg): RedirectResponse
    {
        return back()->withErrors([$field => $msg]);
    }
}
