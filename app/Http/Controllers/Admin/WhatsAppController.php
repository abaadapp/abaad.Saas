<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\WhatsAppConnection;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppTemplates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ما يملكه صاحب المحلّ — ولا شيء غيره.
 *
 * يرى استهلاكه، ويُطفئ ما لا يريد من الأحداث، ويربط رقمه إن مُنح ذلك. ولا
 * يرى رمز أبعاد، ولا يرفع حدَّه، ولا يقرأ استهلاك متجرٍ آخر — ومعرّف متجره
 * يُقرأ من جلسته لا ممّا يصل في الطلب.
 */
class WhatsAppController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    private function business(): Business
    {
        return Business::findOrFail($this->bid());
    }

    /**
     * ما يُعرض للتاجر في شاشة إشعارات واتساب.
     *
     * ولا يخرج منه رمزٌ ولا معرّف وصلة أبعاد: الوضع المشترك يقول «يُرسل عبر
     * أبعاد» ولا يقول بأيّ حساب ولا بأيّ مفتاح — لا حاجة له بها، وكلّ ما
     * يصل الشاشة يقرؤه من يفتح أدوات المتصفّح.
     */
    public static function view(Business $business): array
    {
        $mode = WhatsAppFeature::effectiveMode($business);
        $ownAllowed = WhatsAppFeature::canUseOwnNumber($business);
        $own = $ownAllowed ? WhatsAppConnections::forBusiness($business->id) : null;

        return [
            'global_enabled' => WhatsAppFeature::globallyEnabled(),
            'enabled' => (bool) $business->whatsapp_enabled,
            'mode' => $business->whatsapp_mode,
            'effective_mode' => $mode,
            'sending_via' => WhatsAppMode::label($mode),
            'own_allowed' => $ownAllowed,
            // معرّفات وصلة المحلّ تُعرض له: هي حسابه. والرمز لا يُعرض لأحد
            'own_connection' => WhatsAppConnections::publicView($own, withIds: true),
            'shared_active' => WhatsAppConnections::platform() !== null,
            // الحصّة تُعرض للمشترك وحده — رقمه الخاص لا حدَّ عليه منّا
            'usage' => $mode === WhatsAppMode::ABAAD_SHARED ? WhatsAppQuota::snapshot($business) : null,
            'events' => array_map(
                fn ($e) => ['key' => $e, 'setting' => WhatsAppEvent::SETTING_KEYS[$e], 'label' => WhatsAppEvent::label($e)],
                WhatsAppEvent::ALL,
            ),
        ];
    }

    /**
     * تبديل الرقم الذي يُرسل منه.
     *
     * ولا يُقبل «رقم المتجر» بلا إذنٍ ولا بلا وصلةٍ صالحة: الوضع الذي لا
     * يُرسل منه شيء أسوأ من الوضع المشترك — التاجر يظنّ رسائله تخرج وهي
     * تقف، ولا شيء في الشاشة يقول ذلك.
     */
    public function mode(Request $request)
    {
        $business = $this->business();

        $data = $request->validate([
            'mode' => ['required', Rule::in(WhatsAppMode::ALL)],
        ]);

        if ($data['mode'] === WhatsAppMode::BUSINESS_OWN) {
            if (! WhatsAppFeature::canUseOwnNumber($business)) {
                return back()->withErrors(['mode' => __('ربط رقم المتجر ميزة غير مفعّلة لحسابك — راجع أبعاد.')]);
            }

            $own = WhatsAppConnections::forBusiness($business->id);

            if (! $own || ! $own->isUsable()) {
                return back()->withErrors(['mode' => __('اربط رقم متجرك أولًا — لا وصلة صالحة بعد.')]);
            }
        }

        $from = $business->whatsapp_mode;
        $business->update(['whatsapp_mode' => $data['mode']]);

        Activity::log('settings', 'واتساب — بدّل وضع الإرسال: '
            .WhatsAppMode::label($from).' ← '.WhatsAppMode::label($data['mode']));

        return back()->with('toast', ['msg' => __('حُفظ وضع الإرسال'), 'type' => 'success']);
    }

    /**
     * ربط رقم المحلّ.
     *
     * ولا يُقرأ معرّف المتجر ممّا يصل: يُقرأ من الجلسة. ولو قُبل من الطلب
     * لَاستطاع تاجرٌ أن يربط رقمًا لمتجر غيره — أو يسحب رقم غيره إلى نفسه.
     */
    public function connect(Request $request)
    {
        $business = $this->business();

        if (! WhatsAppFeature::canUseOwnNumber($business)) {
            return back()->withErrors(['access_token' => __('ربط رقم المتجر ميزة غير مفعّلة لحسابك — راجع أبعاد.')]);
        }

        $data = $request->validate([
            'phone_number_id' => ['required', 'string', 'max:100'],
            'waba_id' => ['nullable', 'string', 'max:100'],
            'display_phone_number' => ['nullable', 'string', 'max:32'],
            'access_token' => ['required', 'string', 'min:20', 'max:1000'],
        ]);

        $existing = WhatsAppConnections::forBusiness($business->id);

        // رقمٌ يملكه غيره لا يُنتزع منه — والفهرس الفريد يرفض على كلّ حال
        $clash = WhatsAppConnection::where('phone_number_id', $data['phone_number_id'])
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists();

        if ($clash) {
            return back()->withErrors(['phone_number_id' => __('هذا الرقم مربوطٌ بحسابٍ آخر.')]);
        }

        $attributes = [
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $business->id,
            'provider' => 'meta_cloud',
            'waba_id' => $data['waba_id'] ?? null,
            'phone_number_id' => $data['phone_number_id'],
            'display_phone_number' => $data['display_phone_number'] ?? null,
            'access_token' => $data['access_token'],
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
            'disconnected_at' => null,
        ];

        $connection = $existing ? tap($existing)->update($attributes) : WhatsAppConnection::create($attributes);

        // قوالب المحلّ تُهيَّأ بأسمائها الافتراضية — تُصحَّح إن اعتُمدت بأسماءٍ أخرى
        WhatsAppTemplates::seedBusinessDefaults($business->id);

        Activity::log('settings', 'واتساب — ربط رقم المتجر ('.($data['display_phone_number'] ?? $data['phone_number_id']).')');

        return back()->with('toast', ['msg' => __('تم ربط رقم المتجر'), 'type' => 'success']);
    }

    /**
     * فصل رقم المحلّ — والعودة إلى الرقم المشترك صراحةً لا بصمت.
     *
     * الوصلة تُعطَّل ولا تُحذف: إعادة الربط لاحقًا لا تُفقد التاريخ، والإشعار
     * المتأخّر من ميتا يجد رقمَه معروفًا.
     */
    public function disconnect()
    {
        $business = $this->business();
        $connection = WhatsAppConnections::forBusiness($business->id);

        if ($connection) {
            $connection->update([
                'status' => WhatsAppConnection::INACTIVE,
                'disconnected_at' => now(),
            ]);
        }

        if ($business->whatsapp_mode === WhatsAppMode::BUSINESS_OWN) {
            $business->update(['whatsapp_mode' => WhatsAppMode::ABAAD_SHARED]);
        }

        Activity::log('settings', 'واتساب — فصل رقم المتجر');

        return back()->with('toast', ['msg' => __('فُصل رقم المتجر'), 'type' => 'success']);
    }
}
