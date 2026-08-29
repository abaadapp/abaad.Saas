<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\WhatsAppConnection;
use App\Support\Activity;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppTemplates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ما يملكه مدير المنصّة وحده: الرقم المشترك، والحدود، والأذونات.
 *
 * ولا يصل إليه تاجر: الحارس هو `role:super_admin` على مجموعة المسارات، فلا
 * فحصَ ثانٍ هنا يفترق عنه. ولو كُتب فحصٌ في الشاشة وحدها لَكفى أن يُكتب
 * العنوان بيدٍ ليُرفع الحدّ.
 */
class WhatsAppController extends Controller
{
    /**
     * ربط رقم أبعاد المشترك.
     *
     * الرمز يصل هنا مرّةً ثمّ لا يُقرأ ثانية: يُشفَّر في العمود ولا يُعاد إلى
     * شاشة، ولا يُكتب في سجلّ نشاط، ولا يُرَدّ في رسالة خطأ.
     */
    public function connectShared(Request $request)
    {
        $data = $request->validate([
            'phone_number_id' => ['required', 'string', 'max:100'],
            'waba_id' => ['nullable', 'string', 'max:100'],
            'display_phone_number' => ['nullable', 'string', 'max:32'],
            'access_token' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'access_token.required' => __('الصق رمز الوصول الدائم من لوحة ميتا.'),
        ]);

        /*
         * وصلةٌ مشتركة واحدة لا اثنتان.
         *
         * وصلتان نشطتان تعنيان أنّ الرسائل تخرج من رقمين بلا قاعدةٍ تحكم
         * أيّهما — ويقرأ الزبون رقمًا في رسالة ورقمًا آخر في التالية.
         */
        $existing = WhatsAppConnection::query()->platform()->orderByDesc('id')->first();

        $attributes = [
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'business_id' => null,
            'provider' => 'meta_cloud',
            'waba_id' => $data['waba_id'] ?? null,
            'phone_number_id' => $data['phone_number_id'],
            'display_phone_number' => $data['display_phone_number'] ?? null,
            'access_token' => $data['access_token'],
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
            'disconnected_at' => null,
        ];

        // رقمٌ مربوطٌ بمتجرٍ آخر لا يُسحب منه هنا: الفهرس الفريد يرفض، ويُقال لماذا
        $clash = WhatsAppConnection::where('phone_number_id', $data['phone_number_id'])
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists();

        if ($clash) {
            return back()->withErrors([
                'phone_number_id' => __('هذا الرقم مربوطٌ بوصلةٍ أخرى في النظام.'),
            ]);
        }

        $connection = $existing
            ? tap($existing)->update($attributes)
            : WhatsAppConnection::create($attributes);

        // قوالب أبعاد الأربعة تُهيَّأ مع الوصلة — وصلةٌ بلا قوالب لا تُرسل شيئًا
        WhatsAppTemplates::seedPlatformDefaults((string) config('whatsapp.language', 'ar'));

        Activity::log('settings', 'ربط رقم واتساب المشترك لأبعاد ('.($data['display_phone_number'] ?? $data['phone_number_id']).')');

        return back()->with('toast', [
            'msg' => __('تم ربط رقم واتساب المشترك'),
            'type' => 'success',
        ]);
    }

    /**
     * فصل الرقم المشترك — تعطيلٌ لا حذف.
     *
     * الحذف يُفقد تاريخ الرسائل صلتَه بوصلتها، ويجعل إشعارًا متأخّرًا من ميتا
     * يصل إلى رقمٍ لا نعرفه. والتعطيل يوقف الإرسال ويُبقي الأثر.
     */
    public function disconnectShared()
    {
        $connection = WhatsAppConnection::query()->platform()->orderByDesc('id')->first();

        if ($connection) {
            $connection->update([
                'status' => WhatsAppConnection::INACTIVE,
                'disconnected_at' => now(),
            ]);
            Activity::log('settings', 'فصل رقم واتساب المشترك لأبعاد');
        }

        return back()->with('toast', ['msg' => __('فُصل الرقم المشترك'), 'type' => 'success']);
    }

    /**
     * ضبط واتساب لمتجرٍ بعينه — التفعيل والحدّ وإذن الرقم الخاص.
     *
     * والحقول تُكتب حين تُرسل وحدها: نموذجٌ يُرسل حقلًا واحدًا لا يمحو الثلاثة
     * الأخرى.
     */
    public function updateBusiness(Request $request, int $id)
    {
        $business = Business::findOrFail($id);

        $data = $request->validate([
            'whatsapp_enabled' => ['sometimes', 'boolean'],
            'whatsapp_own_allowed' => ['sometimes', 'boolean'],
            /*
             * الحدّ: فارغٌ يعني «افتراضيّ المنصّة»، و‎-1 بلا حدّ.
             *
             * ولا تُنسخ قيمة الافتراضيّ هنا: لو نُسخت لَما غيّرها تعديلُ
             * الافتراضيّ في مئة متجر.
             */
            'whatsapp_monthly_limit' => ['sometimes', 'nullable', 'integer', 'min:-1', 'max:1000000'],
            'whatsapp_mode' => ['sometimes', Rule::in(WhatsAppMode::ALL)],
        ]);

        $before = $business->only(['whatsapp_enabled', 'whatsapp_own_allowed', 'whatsapp_monthly_limit', 'whatsapp_mode']);

        /*
         * وضعٌ خاصٌّ بلا إذنٍ لا يُكتب.
         *
         * ولو كُتب لَبقي المتجر في وضعٍ لا يُرسل منه شيء ولا يقول أحدٌ لماذا.
         */
        if (($data['whatsapp_mode'] ?? null) === WhatsAppMode::BUSINESS_OWN
            && ! ($data['whatsapp_own_allowed'] ?? $business->whatsapp_own_allowed)) {
            return back()->withErrors([
                'whatsapp_mode' => __('لا يمكن اختيار رقم المتجر قبل منحه صلاحية الرقم الخاص.'),
            ]);
        }

        $business->update($data);

        $this->logChanges($business, $before, $business->only(array_keys($before)));

        return back()->with('toast', ['msg' => __('حُفظت إعدادات واتساب للمتجر'), 'type' => 'success']);
    }

    /** ما تغيّر يُقيَّد بقيمته القديمة والجديدة — والأذونات أوّل ما يُسأل عنه لاحقًا */
    private function logChanges(Business $business, array $before, array $after): void
    {
        $labels = [
            'whatsapp_enabled' => 'تفعيل واتساب',
            'whatsapp_own_allowed' => 'صلاحية الرقم الخاص',
            'whatsapp_monthly_limit' => 'حدّ الرسائل الشهري',
            'whatsapp_mode' => 'وضع الإرسال',
        ];

        foreach ($labels as $field => $label) {
            if (($before[$field] ?? null) == ($after[$field] ?? null)) {
                continue;
            }

            $show = fn ($v) => $v === null ? __('افتراضي المنصّة') : (is_bool($v) ? ($v ? __('مفعّل') : __('مطفأ')) : (string) $v);

            Activity::log('settings', 'واتساب — '.__($label).' للمتجر «'.$business->name.'»: '
                .$show($before[$field] ?? null).' ← '.$show($after[$field] ?? null), [
                    'subject_id' => $business->id,
                    'subject_type' => 'business',
                ]);
        }
    }

    /** صورة استهلاك متجرٍ — تُقرأ في شاشة المتجر لدى المنصّة */
    public static function businessView(Business $business): array
    {
        $own = WhatsAppConnections::forBusiness($business->id);

        return [
            'enabled' => (bool) $business->whatsapp_enabled,
            'mode' => $business->whatsapp_mode,
            'own_allowed' => (bool) $business->whatsapp_own_allowed,
            'limit_override' => $business->whatsapp_monthly_limit,
            'platform_default' => WhatsAppQuota::platformDefault(),
            'usage' => WhatsAppQuota::snapshot($business),
            // معرّفات الوصلة تُعرض لمدير المنصّة وحده — لا الرمز، هو لا يُقرأ أبدًا
            'own_connection' => WhatsAppConnections::publicView($own, withIds: true),
        ];
    }
}
