<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppInvoice;
use App\Models\Business;
use App\Models\CustomerInvoice;
use App\Models\WhatsAppMessage;
use App\Support\Activity;
use App\Support\PublicDocument;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppPhone;
use App\Support\WhatsAppStatus;
use Illuminate\Support\Str;

/** إرسالُ فاتورة العميل من رقم المتجر نفسه عبر WhatsApp Cloud API. */
class WhatsAppInvoiceController extends Controller
{
    public function send(int|string $id)
    {
        $businessId = (int) auth()->user()?->business_id;
        $business = Business::findOrFail($businessId);
        $invoice = CustomerInvoice::where('business_id', $businessId)
            ->whereKey($id)
            ->with('customer')
            ->firstOrFail();

        if ($invoice->status !== CustomerInvoice::ISSUED) {
            return back()->with('toast', [
                'msg' => __('أصدر الفاتورة أولًا قبل إرسالها عبر واتساب.'),
                'type' => 'danger',
            ]);
        }

        if (WhatsAppFeature::blockReason($business) !== null) {
            return back()->with('toast', [
                'msg' => __('واتساب غير جاهز لهذا الحساب — راجع «التطبيقات التكاملية ‹ واتساب».'),
                'type' => 'danger',
            ]);
        }

        /*
         * هذه الميزة مقصودة لرقم سعود نفسه، لا لرقم أبعاد المشترك: الفاتورة
         * يجب أن تظهر للعميل من الرقم الذي يعرفه، ويبقى الرقم على تطبيق
         * WhatsApp Business في الهاتف بواسطة Coexistence.
         */
        if (WhatsAppFeature::effectiveMode($business) !== WhatsAppMode::BUSINESS_OWN) {
            return back()->with('toast', [
                'msg' => __('اختر «رقم متجرك» في إعدادات واتساب قبل إرسال الفواتير.'),
                'type' => 'danger',
            ]);
        }

        $connection = WhatsAppConnections::resolve($business);

        if (! $connection) {
            return back()->with('toast', [
                'msg' => __('رقم واتساب غير متصل الآن — أعد ربطه ثم حاول مرة أخرى.'),
                'type' => 'danger',
            ]);
        }

        if (! $connection->coexistence) {
            return back()->with('toast', [
                'msg' => __('اربط الرقم بخيار WhatsApp Business على الهاتف (Coexistence) حتى يبقى يعمل في الجوال والـAPI معًا.'),
                'type' => 'danger',
            ]);
        }

        $phone = WhatsAppPhone::normalize(
            $invoice->customer?->contact_phone ?: $invoice->customer?->phone
        );

        if (! $phone) {
            return back()->with('toast', [
                'msg' => __('لا رقم واتساب صالح لهذا العميل — أضِفه في صفحته.'),
                'type' => 'danger',
            ]);
        }

        $url = PublicDocument::url($invoice);
        if (! $url) {
            return back()->with('toast', [
                'msg' => __('تعذّر إنشاء رابط الفاتورة الآمن.'),
                'type' => 'danger',
            ]);
        }

        $template = (string) config('whatsapp.invoice_template', 'abaad_customer_invoice');
        $language = (string) config('whatsapp.invoice_template_language', config('whatsapp.language', 'ar'));

        $message = WhatsAppMessage::create([
            'business_id' => $businessId,
            'order_id' => null,
            'customer_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'whatsapp_connection_id' => $connection->id,
            'source_mode' => WhatsAppMode::BUSINESS_OWN,
            'event_type' => 'invoice_document',
            'direction' => 'outbound',
            'recipient_phone' => $phone,
            'template_name' => $template,
            'language_code' => $language,
            /* الإرسال اليدوي يجوز تكراره؛ كل ضغطة محاولة مستقلة قابلة للتدقيق. */
            'dedupe_key' => 'invoice-send:'.$businessId.':'.$invoice->id.':'.Str::ulid(),
            'status' => WhatsAppStatus::QUEUED,
            'quota_consumed' => false,
            'queued_at' => now(),
            'metadata' => [
                'kind' => 'customer_invoice',
                'public_url' => $url,
                'requested_by' => auth()->id(),
            ],
        ]);

        SendWhatsAppInvoice::dispatch($message->id)
            ->onQueue((string) config('whatsapp.queue', 'whatsapp'));

        Activity::log('updated', 'أرسل فاتورة العميل عبر واتساب: '.$invoice->number, [
            'subject_id' => $invoice->id,
            'subject_type' => 'customer_invoice',
            'whatsapp_message_id' => $message->id,
        ]);

        return back()->with('toast', [
            'msg' => __('أُدرجت الفاتورة :n للإرسال عبر واتساب.', ['n' => $invoice->number]),
            'type' => 'success',
        ]);
    }
}
