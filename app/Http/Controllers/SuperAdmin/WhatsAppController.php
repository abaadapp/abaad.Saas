<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessagePack;
use App\Support\Activity;
use App\Support\Addons\Catalog;
use App\Support\Addons\Entitlements;
use App\Support\Addons\PricingPolicy;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppInbox;
use App\Support\WhatsAppLinkCheck;
use App\Support\WhatsAppLinkHealth;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppPacks;
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
        $existing = WhatsAppConnection::query()->platform()
            ->where('purpose', WhatsAppMode::PURPOSE_NOTIFICATIONS)
            ->orderByDesc('id')->first();

        $attributes = [
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            /*
             * وغرضُه يُكتب صراحةً: هذا رقمُ الإشعارات لا رقمُ المبيعات.
             *
             * و«واحدةٌ لا اثنتان» أعلاه صارت «واحدةٌ لكلّ غرض»: رقمُ مبيعات
             * أبعاد وصلةٌ ثانية مشروعة، ولولا الشرطُ لَسحبها هذا الباب
             * وجعلها رقمَ الإشعارات — فتخرج إشعاراتُ كلّ المتاجر من رقمٍ
             * لا يعرفه زبائنُهم.
             */
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
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

        [$verified, $refused] = $this->verifiedLine($data, $existing);

        if ($refused) {
            return $refused;
        }

        $connection = $existing
            ? tap($existing)->update($verified + $attributes)
            : WhatsAppConnection::create($verified + $attributes);

        // قوالب أبعاد الأربعة تُهيَّأ مع الوصلة — وصلةٌ بلا قوالب لا تُرسل شيئًا
        WhatsAppTemplates::seedPlatformDefaults((string) config('whatsapp.language', 'ar'));

        Activity::log('settings', 'ربط رقم واتساب المشترك لأبعاد ('.($data['display_phone_number'] ?? $data['phone_number_id']).')');

        return back()->with('toast', $this->linkedToast(__('تم ربط رقم واتساب المشترك'), $verified));
    }

    /**
     * فصل الرقم المشترك — تعطيلٌ لا حذف.
     *
     * الحذف يُفقد تاريخ الرسائل صلتَه بوصلتها، ويجعل إشعارًا متأخّرًا من ميتا
     * يصل إلى رقمٍ لا نعرفه. والتعطيل يوقف الإرسال ويُبقي الأثر.
     */
    public function disconnectShared()
    {
        $connection = WhatsAppConnection::query()->platform()
            ->where('purpose', WhatsAppMode::PURPOSE_NOTIFICATIONS)
            ->orderByDesc('id')->first();

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
     * ربطُ رقم مبيعات أبعاد — وصلةٌ ثانيةٌ للمنصّة بغرضٍ آخر.
     *
     * ═══ ولمَ رقمٌ ثانٍ لا مقبضٌ على الأوّل ═══
     *
     * رقمُ الإشعارات يُرسل نيابةً عن المحلّات فيردّ عليه **زبائنُهم**، ولا
     * يُخزَّن من وارده إلّا ما طابق مستخدمًا له متجر. ورقمُ المبيعات يحتاج
     * عكسَ ذلك: أن يُقرأ الواردُ من **رقمٍ مجهول** — فالعميلُ المحتمَل ليس
     * مستخدمًا عندنا بعد.
     *
     * والشرطان لا يجتمعان: مقبضٌ يفتح المجهولَ على رقم الإشعارات يجعل كلَّ
     * زبونةِ محلِّ ورودٍ سألت عن هديّتها عميلًا محتملًا في دفتر مبيعاتنا،
     * ونصَّ رسالتها مقروءًا في لوحة المنصّة.
     *
     * والوارد على هذا الرقم يُقرأ بلا مقبض: رقمٌ لا غرضَ له غيرُ هذا.
     */
    public function connectSales(Request $request)
    {
        $data = $request->validate([
            'phone_number_id' => ['required', 'string', 'max:100'],
            'waba_id' => ['nullable', 'string', 'max:100'],
            'display_phone_number' => ['nullable', 'string', 'max:32'],
            'access_token' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'access_token.required' => __('الصق رمز الوصول الدائم من لوحة ميتا.'),
        ]);

        $existing = WhatsAppConnection::query()->platform()
            ->where('purpose', WhatsAppMode::PURPOSE_CRM_SALES)
            ->orderByDesc('id')->first();

        /*
         * ورقمُ المبيعات لا يكون رقمَ الإشعارات نفسَه.
         *
         * الفهرسُ الفريدُ على `phone_number_id` يردُّه أصلًا، لكنّ رسالةً
         * تُقرأ خيرٌ من خطأِ قاعدةٍ يصير صفحةَ خمسمئة. والسببُ يُقال: رقمٌ
         * واحدٌ لا يخدم الغرضين — لأنّه إمّا يقرأ المجهول أو لا يقرؤه.
         */
        $clash = WhatsAppConnection::where('phone_number_id', $data['phone_number_id'])
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists();

        if ($clash) {
            return back()->withErrors([
                'phone_number_id' => __('هذا الرقم مربوطٌ بوصلةٍ أخرى — ورقمُ المبيعات لا يكون رقمَ الإشعارات نفسَه.'),
            ]);
        }

        $attributes = [
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_CRM_SALES,
            'business_id' => null,
            'provider' => 'meta_cloud',
            'waba_id' => $data['waba_id'] ?? null,
            'phone_number_id' => $data['phone_number_id'],
            'display_phone_number' => $data['display_phone_number'] ?? null,
            'access_token' => $data['access_token'],
            /*
             * ولا يُشعَل `supports_inbox` هنا.
             *
             * ذاك مقبضُ مركز المحادثات على رقم الإشعارات. والوارد على رقم
             * المبيعات يُقرأ من غرضه لا من مقبضٍ ثانٍ — ومقبضان لبابٍ واحد
             * يُنسى أحدهما فيُظنّ الرقمُ موصولًا ولا يصل شيء.
             */
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
            'disconnected_at' => null,
        ];

        [$verified, $refused] = $this->verifiedLine($data, $existing);

        if ($refused) {
            return $refused;
        }

        $existing ? $existing->update($verified + $attributes) : WhatsAppConnection::create($verified + $attributes);

        Activity::log('settings', 'ربط رقم واتساب مبيعات أبعاد ('.($data['display_phone_number'] ?? $data['phone_number_id']).')');

        return back()->with('toast', $this->linkedToast(__('تم ربط رقم المبيعات'), $verified));
    }

    /**
     * رمزُ رقمٍ من أرقام أبعاد يُفحص كرمز أيّ متجر — بالباب نفسه.
     *
     * الويب هوك لا يقبل إلّا ما وُقّع بسرّ تطبيق أبعاد، فرمزٌ من تطبيقٍ آخر
     * على رقم أبعاد يُشرك ذلك التطبيق — وتسكت ردودُ الزبائن والتجّار معًا.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?array<string, mixed>, 1: ?\Illuminate\Http\RedirectResponse}
     */
    private function verifiedLine(array $data, ?WhatsAppConnection $existing): array
    {
        $token = (string) $data['access_token'];
        $check = WhatsAppLinkCheck::token($token, $data['waba_id'] ?? null);

        if (! $check['ok']) {
            WhatsAppLinkCheck::log('platform_link_refused', null, $check['meta'] + ['code' => $check['problem']]);
            $field = $check['problem'] === WhatsAppLinkCheck::WABA_NOT_GRANTED ? 'waba_id' : 'access_token';

            return [null, back()->withErrors([$field => (string) $check['message']])];
        }

        $number = WhatsAppLinkCheck::number((string) $check['waba'], $token, (string) $data['phone_number_id'], $existing?->id);

        if (! $number['ok']) {
            return [null, back()->withErrors(['phone_number_id' => (string) $number['message']])];
        }

        // رقمُ أبعاد رقمُ Cloud API مستقلّ — لا تعايش، ولا رقمٌ لم يُعرف نوعُه
        $kind = WhatsAppLinkCheck::numberType((array) $number['number'], $token, false);

        if ($kind['problem']) {
            WhatsAppLinkCheck::log('platform_link_refused', null, ['code' => $kind['problem'], 'message' => null]);

            return [null, back()->withErrors(['phone_number_id' => WhatsAppLinkCheck::say($kind['problem'])])];
        }

        $hook = WhatsAppLinkCheck::subscribe((string) $check['waba'], $token);

        if ($refusal = WhatsAppLinkCheck::subscriptionRefusal($hook, $existing)) {
            WhatsAppLinkCheck::log('platform_link_refused', null, $hook['meta'] + ['code' => $refusal]);

            return [null, back()->withErrors(['access_token' => WhatsAppLinkCheck::say($refusal)])];
        }

        return [['waba_id' => $check['waba'], 'token_expires_at' => $check['expires_at']]
            + WhatsAppLinkCheck::stamp($check['app_id'], $hook['state'], WhatsAppLinkCheck::routing((string) $data['phone_number_id'], $token)['state']), null];
    }

    /**
     * @param  array<string, mixed>  $verified
     * @return array{msg:string, type:string}
     */
    private function linkedToast(string $done, array $verified): array
    {
        return $verified['webhook_state'] === WhatsAppLinkCheck::SUBSCRIBED
            ? ['msg' => $done, 'type' => 'success']
            : ['msg' => $done.' — '.__('لكنّ اشتراك أبعاد في إشعارات الحساب لم يثبت، فالردود الواردة لن تصل.'), 'type' => 'warning'];
    }

    /** فصلُ رقم المبيعات — تعطيلٌ لا حذف، فالخيوطُ تبقى مقروءة */
    public function disconnectSales()
    {
        $connection = WhatsAppConnection::query()->platform()
            ->where('purpose', WhatsAppMode::PURPOSE_CRM_SALES)
            ->orderByDesc('id')->first();

        if ($connection) {
            $connection->update([
                'status' => WhatsAppConnection::INACTIVE,
                'disconnected_at' => now(),
            ]);
            Activity::log('settings', 'فصل رقم واتساب مبيعات أبعاد');
        }

        return back()->with('toast', ['msg' => __('فُصل رقم المبيعات'), 'type' => 'success']);
    }

    /**
     * فتحُ الوارد على الرقم المشترك — أو إغلاقُه.
     *
     * ═══ ولمَ هو مقبضٌ صريحٌ لا استنتاج ═══
     *
     * الرقمُ يُرسل إشعاراتِ الطلبات **نيابةً عن المحلّات**، فيردّ عليه
     * زبائنُهم. وفتحُ الوارد بلا قرارٍ يعني أنّ ردودَ الزبائن تصير مادّةً
     * تُقرأ — ولو أُسقط منها ما لا يُطابق مستخدمًا، فالقرارُ في فتح الباب
     * لا في ما يُصفّى بعده.
     *
     * وما يُخزَّن بعد الفتح محدودٌ في `SupportWhatsApp::receive`: رقمٌ
     * يُطابق مستخدمًا **واحدًا** له متجر، وما عداه يُسقَط ولا يُكتب.
     */
    public function supportInbox(Request $request)
    {
        $data = $request->validate(['supports_inbox' => ['required', 'boolean']]);

        /* ورقمُ الإشعارات وحده: بابُ المبيعات يُفتح من شاشة CRM بغرضه */
        $connection = WhatsAppConnection::query()->platform()
            ->where('purpose', WhatsAppMode::PURPOSE_NOTIFICATIONS)
            ->orderByDesc('id')->first();

        // ولا يُفتح بابٌ على رقمٍ لا وجود له
        if (! $connection) {
            return back()->withErrors([
                'supports_inbox' => __('اربط رقم أبعاد المشترك أوّلًا.'),
            ]);
        }

        $connection->update(['supports_inbox' => $data['supports_inbox']]);

        Activity::log('settings', $data['supports_inbox']
            ? 'فُتح استقبال رسائل الدعم على رقم أبعاد المشترك'
            : 'أُغلق استقبال رسائل الدعم على رقم أبعاد المشترك');

        return back()->with('toast', [
            'msg' => $data['supports_inbox']
                ? __('يُقرأ الواردُ الآن في مركز المحادثات')
                : __('أُغلق الوارد — لا رسالةَ تُخزَّن'),
            'type' => 'success',
        ]);
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
             * هل يُرسل من رقم أبعاد المشترك — وإغلاقُه يجعله على رقمه وحده.
             * انظر `WhatsAppFeature::canUseShared`.
             */
            'whatsapp_shared_allowed' => ['sometimes', 'boolean'],
            /*
             * الحدّ: فارغٌ يعني «افتراضيّ المنصّة»، و‎-1 بلا حدّ.
             *
             * ولا تُنسخ قيمة الافتراضيّ هنا: لو نُسخت لَما غيّرها تعديلُ
             * الافتراضيّ في مئة متجر.
             */
            'whatsapp_monthly_limit' => ['sometimes', 'nullable', 'integer', 'min:-1', 'max:1000000'],
            'whatsapp_mode' => ['sometimes', Rule::in(WhatsAppMode::ALL)],
            /*
             * وصندوقُ المحادثات — يفتحه فريقُ أبعاد وحده، ولا يفتحه التاجرُ
             * بنفسه. والأهليّةُ للرقم المستقلّ من الباقة والإضافات أدناه.
             */
            'whatsapp_inbox_enabled' => ['sometimes', 'boolean'],
        ]);

        $before = $business->only(['whatsapp_enabled', 'whatsapp_own_allowed', 'whatsapp_shared_allowed', 'whatsapp_monthly_limit', 'whatsapp_mode', 'whatsapp_inbox_enabled']);

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

        /*
         * ═══ و«API مستقلّ» لمتجر سياسة الإضافات إضافةٌ تُباع ═══
         *
         * لا يُختار له رقمُه قبل أن تسري إضافةُ «ربط واتساب برقم المتجر» —
         * بطلبٍ يُسدَّد أو بمنحةٍ مؤرّخة من «خدمات أبعاد». وباقةٌ تشمل واتساب
         * لا تُطلب فوقها الإضافة: شراءٌ واحدٌ يكفي (`needsOwnNumberAddon`).
         * ومتجرُ السياسة السابقة على حاله: صلاحيةُ الرقم الخاصّ وحدها.
         */
        if (($data['whatsapp_mode'] ?? null) === WhatsAppMode::BUSINESS_OWN
            && $business->whatsapp_mode !== WhatsAppMode::BUSINESS_OWN
            && WhatsAppFeature::needsOwnNumberAddon($business)) {
            return back()->withErrors([
                'whatsapp_mode' => __('API المستقلّ إضافةٌ لهذا المتجر — اطلبها له أو امنحها بتاريخ من «خدمات أبعاد» في صفحته.'),
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
            'whatsapp_shared_allowed' => 'الإرسال عبر رقم أبعاد',
            'whatsapp_monthly_limit' => 'حدّ الرسائل الشهري',
            'whatsapp_mode' => 'وضع الإرسال',
            'whatsapp_inbox_enabled' => 'صندوق محادثات واتساب',
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

    /**
     * اعتمادُ حزمةٍ طلبها تاجر — تُصدَر فاتورتُها ولا يُضاف رصيدٌ بعد.
     *
     * والرصيدُ يُضاف عند تسجيل السداد وحدَه (`Billing::markPaid`). ولو
     * أُضيف هنا لَصار زرُّ الاعتماد هديّةً: يُضغط، وتُرسَل خمسمئةُ رسالةٍ،
     * ثمّ يُطالَب من لا ينوي الدفع.
     */
    public function approvePack(int $id)
    {
        $pack = WhatsAppMessagePack::findOrFail($id);

        if ($pack->status !== WhatsAppPacks::REQUESTED) {
            return back()->withErrors(['pack' => __('هذا الطلب حُسم من قبل.')]);
        }

        $pack = WhatsAppPacks::approve($pack);

        return back()->with('toast', [
            'msg' => __('أُصدرت فاتورة :inv — ويُضاف الرصيد فور تسجيل سدادها.', [
                'inv' => $pack->invoice?->number ?? '—',
            ]),
            'type' => 'success',
        ]);
    }

    /** رفضُ طلبٍ أو إلغاؤه — والفاتورةُ المصدَّرة تبقى في دفترها */
    public function cancelPack(int $id)
    {
        $pack = WhatsAppMessagePack::findOrFail($id);

        if (! in_array($pack->status, WhatsAppPacks::OPEN, true)) {
            return back()->withErrors(['pack' => __('هذا الطلب حُسم من قبل.')]);
        }

        WhatsAppPacks::cancel($pack);

        return back()->with('toast', ['msg' => __('أُلغي الطلب'), 'type' => 'success']);
    }

    /** صورة استهلاك متجرٍ — تُقرأ في شاشة المتجر لدى المنصّة */
    /**
     * «فحص الربط» من لوحة المنصّة — قراءةٌ عند ميتا لا غير.
     *
     * يقول فريقُ أبعاد به أيُّ تطبيقٍ أصدر رمزَ المتجر وهل تطبيقُنا مشتركٌ في
     * إشعارات حسابه، دون أن ينتظر صاحبَ المتجر. ولا يُنادى `POST` ولا
     * `DELETE` ولا يُمسّ رمزٌ ولا رقمٌ ولا حال (`WhatsAppLinkCheck::recheck`).
     */
    public function checkBusinessLink(int $id)
    {
        $business = Business::findOrFail($id);
        $own = WhatsAppConnections::forBusiness($business->id);

        if (! $own || blank($own->phone_number_id) || blank($own->access_token)) {
            return back()->withErrors(['whatsapp' => __('لا وصلة مكتملة لهذا المتجر بعد.')]);
        }

        WhatsAppLinkCheck::recheck($own);
        $health = WhatsAppLinkHealth::state($own->refresh());

        Activity::log('settings', 'واتساب — فحص ربط «'.$business->name.'»: '.WhatsAppLinkHealth::label($health));

        return back()->with('toast', [
            'msg' => WhatsAppLinkHealth::label($health).' — '.WhatsAppLinkHealth::hint($health, $own),
            'type' => in_array($health, WhatsAppLinkHealth::HEALTHY, true) ? 'success' : 'warning',
        ]);
    }

    /**
     * يؤكّد فريقُ أبعاد أنّ فوترة ميتا على التاجر — بعد أن راجع دليلًا من ميتا نفسها.
     *
     * لا زرٌّ يُضغط: يُسمّى الدليلُ ومرجعُه، ويُقرّ المراجعُ أنّه رأى اسمَ نشاط
     * التاجر ومعرّفَ هذا الحساب فيه. ويُقرأ الحسابُ عند ميتا لحظتها، فوقائعُ
     * تنقضه تردّه (`WhatsAppOwnApp::confirmBilling`). ولا يمسّ رمزًا ولا رقمًا.
     */
    public function confirmBilling(Request $request, int $id)
    {
        $business = Business::findOrFail($id);
        $own = WhatsAppConnections::forBusiness($business->id);

        if (! \App\Support\WhatsAppOwnApp::mayConfirm($own)) {
            return back()->withErrors(['whatsapp' => __('لا ربطَ لهذا المتجر ينتظر إثبات الفوترة.')]);
        }

        $data = $request->validate([
            'evidence_kind' => ['required', Rule::in(array_keys(\App\Support\WhatsAppOwnApp::EVIDENCE))],
            'evidence_reference' => ['required', 'string', 'min:3', 'max:120'],
            'evidence_checked' => ['accepted'],
        ]);

        $result = \App\Support\WhatsAppOwnApp::confirmBilling($own, $request->user(), [
            'kind' => $data['evidence_kind'], 'reference' => $data['evidence_reference'],
        ]);

        if (! $result['ok']) {
            return back()->withErrors(['whatsapp' => $result['message']]);
        }

        Activity::log('settings', 'واتساب — مراجعة دليل أنّ فوترة ميتا على «'.$business->name.'» ('.$data['evidence_kind'].')',
            ['business_id' => $business->id]);

        return back()->with('toast', ['msg' => $result['message'], 'type' => 'success']);
    }

    /**
     * «فحص إعداد تطبيق أبعاد» — عنوانُ الإشعارات وحقلُ `messages` في التطبيق نفسه.
     *
     * واحدٌ لكلّ المتاجر: لو أُطفئ `messages` في لوحة التطبيق أو تبدّل العنوان
     * لَما وصل من أيّ متجرٍ حرف، وكلُّها تقرأ «مشترك». قراءةٌ لا غير
     * (`WhatsAppLinkCheck::appWebhook`) — ولا يُغيَّر من هنا إعدادٌ عند ميتا.
     */
    public function checkAppWebhook()
    {
        $app = WhatsAppLinkCheck::appWebhook();

        Activity::log('settings', 'واتساب — فحص إعداد إشعارات تطبيق أبعاد: '.$app['state']);

        return back()->with('toast', [
            'msg' => match ($app['state']) {
                WhatsAppLinkCheck::APP_LISTENING => __('تطبيق أبعاد يستقبل: عنوان الإشعارات عنوان أبعاد، وحقل messages مُفعّل.'),
                WhatsAppLinkCheck::APP_CALLBACK_ELSEWHERE => __('عنوان الإشعارات في تطبيق أبعاد ليس عنوان أبعاد — لا يصل من أيّ متجر شيء.'),
                WhatsAppLinkCheck::APP_MESSAGES_OFF => __('حقل messages غير مُفعّل في تطبيق أبعاد — رسائل الزبائن لا تصل من أيّ متجر.'),
                default => __('تعذّرت قراءة إعداد الإشعارات في تطبيق أبعاد من ميتا — أعد المحاولة.'),
            },
            'type' => $app['state'] === WhatsAppLinkCheck::APP_LISTENING ? 'success' : 'warning',
        ]);
    }

    public static function businessView(Business $business): array
    {
        $own = WhatsAppConnections::forBusiness($business->id);

        return [
            'enabled' => (bool) $business->whatsapp_enabled,
            'mode' => $business->whatsapp_mode,
            /* والفعّالُ ما يُقرأ: من أُغلق عنه رقمُ أبعاد على رقمه وإن بقي صفُّه `abaad_shared` */
            'effective_mode' => WhatsAppFeature::effectiveMode($business),
            'own_allowed' => (bool) $business->whatsapp_own_allowed,
            'shared_allowed' => WhatsAppFeature::canUseShared($business),
            'limit_override' => $business->whatsapp_monthly_limit,
            'platform_default' => WhatsAppQuota::platformDefault(),
            'usage' => WhatsAppQuota::snapshot($business),
            /* حزمُ الرسائل: ما طلبه هذا المتجر وما صار إليه */
            'packs' => WhatsAppPacks::forPlatform($business),
            'pack_offer' => ['size' => WhatsAppPacks::size(), 'price' => WhatsAppPacks::price()],
            // معرّفات الوصلة تُعرض لمدير المنصّة وحده — لا الرمز، هو لا يُقرأ أبدًا
            'own_connection' => WhatsAppConnections::publicView($own, withIds: true),
            /*
             * وصحّةُ الوصلة كما تقولها الوقائع: آخرُ إشعارٍ وصل منها، وآخرُ خطأٍ
             * قالته ميتا. وصمتُ الإشعارات لا يُصدر خطأً — يُقرأ من الختم وحده.
             */
            'own_health' => $own ? [
                'last_webhook_at' => optional($own->last_webhook_at)->format('Y-m-d H:i'),
                'last_error' => filled($own->last_error_message)
                    ? mb_strimwidth((string) $own->last_error_message, 0, 200, '…') : null,
                'last_error_at' => optional($own->last_error_at)->format('Y-m-d H:i'),
                // متّصلٌ أم مشتركٌ أم يستقبل فعلًا — بلا رمزٍ ولا معرّفِ تطبيقٍ آخر
                'link' => WhatsAppLinkHealth::view($own),
                'can_check' => filled($own->phone_number_id) && filled($own->access_token),
                // وربطُ التاجر بتطبيقه: على من تُحتسب رسائلُه، وأينتظر إثباتَها
                'own_app' => \App\Support\WhatsAppOwnApp::is($own) || \App\Support\WhatsAppOwnApp::mayConfirm($own)
                    ? \App\Support\WhatsAppOwnApp::reviewView($own) : null,
            ] : null,
            // أيُختار له «API مستقلّ» الآن؟ — الباقةُ والإضافات (`canUseOwnNumber`)
            'own_eligible' => WhatsAppFeature::canUseOwnNumber($business),
            'own_needs_addon' => PricingPolicy::isAddons($business) && ! Entitlements::has($business, Catalog::OWN_NUMBER),
            'inbox_enabled' => (bool) $business->whatsapp_inbox_enabled,
            'inbox_available' => WhatsAppInbox::available($business),
        ];
    }
}
