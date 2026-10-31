<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use App\Models\WhatsAppConnection;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Pagination;
use App\Support\Search;
use App\Support\WhatsAppAutoReply;
use App\Support\WhatsAppConnections;
use App\Support\WhatsAppEmbeddedSignup;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppFeature;
use App\Support\WhatsAppInbox;
use App\Support\WhatsAppLink;
use App\Support\WhatsAppLinkCheck;
use App\Support\WhatsAppLinkHealth;
use App\Support\WhatsAppLog;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppOnboarding;
use App\Support\WhatsAppPacks;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppStatus;
use App\Support\WhatsAppTemplates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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
            /*
             * خطواتُ الربط والتفعيل أوّلًا — وهي شرطُ كلّ ما بعدها.
             *
             * وتُشتقّ من الدوالّ التي يسألها المُرسِل نفسه، فلا تقول الشاشة
             * «جاهز» ويمتنع المُرسِل.
             */
            'readiness' => WhatsAppFeature::readiness($business),
            'global_enabled' => WhatsAppFeature::globallyEnabled(),
            'enabled' => (bool) $business->whatsapp_enabled,
            'mode' => $business->whatsapp_mode,
            'effective_mode' => $mode,
            'sending_via' => WhatsAppMode::label($mode),
            'own_allowed' => $ownAllowed,
            // معرّفات وصلة المحلّ تُعرض له: هي حسابه. والرمز لا يُعرض لأحد
            'own_connection' => WhatsAppConnections::publicView($own, withIds: true),
            /* ومن أُغلق عنه رقمُ أبعاد لا يُعرض له متاحًا — ولا يُعرض زرُّه */
            'shared_allowed' => WhatsAppFeature::canUseShared($business),
            'shared_active' => WhatsAppFeature::canUseShared($business) && WhatsAppConnections::platform() !== null,

            /*
             * ═══ التسجيل المدمج: ما تحتاجه الشاشة لتفتح نافذة ميتا ═══
             *
             * معرّفُ التطبيق ومعرّفُ الإعداد ليسا سرًّا — ميتا نفسُها تطلب
             * وضعَهما في صفحة التاجر، وكلاهما يُقرأ من أيّ متصفّح. وسرُّ
             * التطبيق ليس هنا ولا يمرّ بالشاشة قطّ: تبديلُ الكود برمزٍ يقع
             * في الخادم وحدَه (`WhatsAppEmbeddedSignup::exchange`).
             *
             * و`configured` تُطفئ الزرّ حين ينقص إعدادٌ على الخادم: زرٌّ
             * يفتح نافذةً تفشل عند ميتا أسوأ من زرٍّ لا يظهر.
             */
            'embedded' => [
                'configured' => WhatsAppEmbeddedSignup::configured(),
                'app_id' => (string) config('whatsapp.app_id'),
                'config_id' => (string) config('whatsapp.config_id'),
                'graph_version' => (string) config('whatsapp.api_version'),
                'feature_type' => (string) config('whatsapp.feature_type'),
                'session_info_version' => (string) config('whatsapp.session_info_version'),
                // نسخةُ التسجيل المدمج إن كُتبت — وبلا نسخةٍ تخدمها ميتا بـv4
                'version' => config('whatsapp.es_version'),
                // والتعايشُ يُعرض اختيارًا مطفأً بسببه ما لم يُفتح على الخادم
                'coexistence_open' => WhatsAppOnboarding::coexistenceOpen(),
            ],

            /* حالُ الربط بحالاتها الستّ — تُحسب في الخادم ولا تُستنتج في الشاشة */
            'link' => WhatsAppLink::view($own),

            /* وأيستقبل؟ — متّصلٌ ومشتركٌ ومُثبَت ثلاثةُ أشياء (`WhatsAppLinkHealth`) */
            'health' => $own ? WhatsAppLinkHealth::view($own) : null,

            /* والردُّ التلقائيّ: ما حُفظ، وفروعُ المتجر التي يُختار منها */
            'auto_reply' => $ownAllowed ? WhatsAppAutoReply::settings($business->id) : null,
            'branches' => $ownAllowed
                ? Branch::where('business_id', $business->id)->orderBy('name')->get(['id', 'name'])->all()
                : [],

            /* ومن يملك الربط: صاحبُ الحساب أو مديره — والشاشةُ تقوله ولا تُخفي الزرّ بلا سبب */
            'may_manage' => (bool) auth()->user()?->isAdmin(),
            // الحصّة تُعرض للمشترك وحده — رقمه الخاص لا حدَّ عليه منّا
            'usage' => $mode === WhatsAppMode::ABAAD_SHARED ? WhatsAppQuota::snapshot($business) : null,
            /*
             * وعرضُ الحزمة مع الحصّة: من نفدت حصّتُه يقرأ ثمنَ ما بعدها في
             * الموضع نفسِه. ولو كان في شاشةٍ أخرى لَما وصلها إلّا من بحث.
             */
            'pack' => $mode === WhatsAppMode::ABAAD_SHARED ? WhatsAppPacks::view($business) : null,
            'events' => array_map(
                fn ($e) => ['key' => $e, 'setting' => WhatsAppEvent::SETTING_KEYS[$e], 'label' => WhatsAppEvent::label($e)],
                WhatsAppEvent::eventsForBusiness((int) $business->id),
            ),
        ];
    }

    /**
     * سجلُّ رسائل المحلّ — الجدولُ الذي لم تكن تفتحه شاشة.
     *
     * ═══ ولمَ هو شاشةٌ لا سطرٌ في شاشةِ الربط ═══
     *
     * شاشةُ الربط تُفتح مرّةً ثمّ لا تُفتح، وهذا يُفتح يومَ يسأل زبونٌ «لم
     * تصلني رسالة». وجمعُهما يعني أن يمرّ من يبحث عن رسالةٍ واحدة على رمز
     * تفعيلٍ من ميتا لا شأن له به.
     *
     * ومعرّفُ المتجر من الجلسة لا من الطلب: لو قُرئ ممّا يصل لَقرأ تاجرٌ
     * دفترَ متجرٍ آخر بتبديل رقمٍ في العنوان — وفيه أرقامُ زبائنه.
     */
    public function log(Request $request): Response
    {
        $business = $this->business();

        /*
         * والمرشِّحُ يُقاس بقائمة الحِزَم لا يُصدَّق كما وصل.
         *
         * `?filter=<script>` لا يصل إلى استعلام، و`?filter=nonsense` لا
         * يُرجع صفحةً فارغةً تُقرأ «لا رسائل لك» — يُعاد إلى «الكلّ».
         */
        $filter = (string) $request->query('filter', 'all');

        if (! array_key_exists($filter, WhatsAppStatus::BUCKETS)) {
            $filter = 'all';
        }

        // ومرشِّحُ الرقم كذلك — من قائمته وحدها
        $source = (string) $request->query('source', 'all');

        if (! array_key_exists($source, WhatsAppLog::SOURCES)) {
            $source = 'all';
        }

        // ومرشِّحُ النوع: الإشعاراتُ أو ردودُ الموظّفين أو كلاهما
        $kind = (string) $request->query('kind', 'all');

        if (! in_array($kind, WhatsAppLog::KINDS, true)) {
            $kind = 'all';
        }

        /* و`%` تُنزع من نصّ البحث: من كتبها وحدها رأى كلَّ شيءٍ — انظر `Search::term` */
        $q = Search::term($request);

        // ومن يرى صندوقَ المحادثات يفتح من الصفّ محادثةَ زبونه — والصندوقُ يحرس نفسَه ثانيةً
        $opens = WhatsAppInbox::canView($request->user());

        $page = WhatsAppLog::page($business->id, $filter === 'all' ? null : $filter, $q, $source === 'all' ? null : $source, $opens, $kind === 'all' ? null : $kind);

        return Inertia::render('Admin/Marketing/WhatsappLog', [
            /* حالُ الربط أوّلًا: دفترٌ فارغٌ لمن لم يربط ليس عطبًا، وقولُه يمنع البحث عن عطب */
            'readiness' => WhatsAppFeature::readiness($business),
            /* وربطٌ انقطع لا يُخفي ما خرج قبله — البابُ لمن لا دفترَ له أصلًا */
            'hasHistory' => WhatsAppLog::hasHistory($business->id),
            'rows' => $page->items(),
            'pagination' => Pagination::meta($page),
            'summary' => WhatsAppLog::summary($business->id),
            'summaryDays' => WhatsAppLog::SUMMARY_DAYS,
            'buckets' => WhatsAppLog::filters(),
            'sources' => WhatsAppLog::sources(),
            'kinds' => WhatsAppLog::kinds(),
            'params' => ['filter' => $filter, 'source' => $source, 'kind' => $kind, 'q' => $q],
        ]);
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

        /*
         * ومن أُغلق عنه رقمُ أبعاد لا يفتحه بطلبٍ يكتبه بيده.
         *
         * الزرُّ لا يظهر له، والبابُ يُغلق هنا لا في الشاشة: من يعرف العنوان
         * يُرسل `abaad_shared` بلا زرّ.
         */
        if ($data['mode'] === WhatsAppMode::ABAAD_SHARED && ! WhatsAppFeature::canUseShared($business)) {
            return back()->withErrors(['mode' => __('الإرسال عبر رقم أبعاد غير متاحٍ لحسابك — اربط رقم متجرك.')]);
        }

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
     * يطلب التاجر حزمةَ رسائلَ إضافيّة — ولا يشحن رصيدَه بنفسه.
     *
     * ═══ ولمَ طلبٌ لا شراء ═══
     *
     * لا بوّابةَ دفعٍ في المستودع. فزرٌّ مكتوبٌ عليه «اشترِ الآن» يأخذ
     * ضغطةً ولا يأخذ مالًا، ثمّ يقف التاجر أمام رصيدٍ لم يزد. والطلبُ يقول
     * ما يقع فعلًا: تصل الفاتورة، ويُحوِّل، فيُضاف الرصيد.
     *
     * والحارسان: صاحبُ الحساب أو مديره وحدَهما — هذا مالٌ يُلتزَم به؛ ومن
     * على رقمه الخاصّ لا يُباع له ما لا يستعمله.
     */
    public function requestPack()
    {
        $business = $this->business();

        if (! auth()->user()?->isAdmin()) {
            return back()->withErrors(['pack' => __('طلب الرسائل الإضافيّة لصاحب الحساب أو مديره.')]);
        }

        if (WhatsAppFeature::effectiveMode($business) !== WhatsAppMode::ABAAD_SHARED) {
            return back()->withErrors(['pack' => __('متجرك يُرسل من رقمه الخاص — رسائلُه على حسابه، ولا حصّة تُشترى.')]);
        }

        $result = WhatsAppPacks::request($business, auth()->user()?->name);

        return back()->with('toast', [
            'msg' => $result['created']
                ? __('وصلنا طلبُك — تصلك فاتورة :n رسالة، ويُضاف الرصيد فور تسجيل السداد.', [
                    'n' => $result['pack']->messages,
                ])
                : __('لك طلبٌ قائمٌ لم يُحسم بعد — لا نُصدر له فاتورةً ثانية.'),
            'type' => $result['created'] ? 'success' : 'warning',
        ]);
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
        $token = (string) $data['access_token'];

        /*
         * ═══ الرمزُ الملصوق يُفحص كما يُفحص رمزُ التسجيل المدمج ═══
         *
         * هذا البابُ قبل يومًا رمزًا لصقه متجرٌ من تطبيقه هو عند ميتا: رقمٌ
         * يُرسل ولا يصلنا منه شيء، لأنّ ميتا تُشرك في الإشعارات **التطبيقَ
         * الذي أصدر الرمز**. فلا يُقبل رمزٌ إلّا من تطبيق
         * أبعاد بصلاحياته ولحسابٍ مُنحه، والرقمُ من أرقام ذلك الحساب — وكلُّ
         * ذلك قبل أن يُكتب حرفٌ، فالوصلةُ القائمة لا تُمسّ إن رُدّ.
         */
        $check = WhatsAppLinkCheck::token($token, $data['waba_id'] ?? null);

        if (! $check['ok']) {
            WhatsAppLinkCheck::log('manual_link_refused', $business->id, $check['meta'] + ['code' => $check['problem']]);
            $field = $check['problem'] === WhatsAppLinkCheck::WABA_NOT_GRANTED ? 'waba_id' : 'access_token';

            return back()->withErrors([$field => (string) $check['message']]);
        }

        $number = WhatsAppLinkCheck::number((string) $check['waba'], $token, (string) $data['phone_number_id'], $existing?->id);

        if (! $number['ok']) {
            WhatsAppLinkCheck::log('manual_link_refused', $business->id, $number['meta'] + ['code' => $number['problem']]);

            return back()->withErrors(['phone_number_id' => (string) $number['message']]);
        }

        // والتعايشُ لا يُربط من هنا: يُختار صراحةً في التسجيل المدمج وحده — ورقمٌ لم يُعرف نوعُه لا يُربط
        $kind = WhatsAppLinkCheck::numberType((array) $number['number'], $token, false);

        if ($kind['problem']) {
            WhatsAppLinkCheck::log('manual_link_refused', $business->id, ['code' => $kind['problem'], 'message' => null]);

            return back()->withErrors(['phone_number_id' => WhatsAppLinkCheck::say($kind['problem'])]);
        }

        $hook = WhatsAppLinkCheck::subscribe((string) $check['waba'], $token);

        // ولا يُحفظ على اشتراكٍ لم يُقرأ، ولا تُستبدل وصلةٌ تُرسل بوصلةٍ لم يثبت اشتراكُها
        if ($refusal = WhatsAppLinkCheck::subscriptionRefusal($hook, $existing)) {
            WhatsAppLinkCheck::log('manual_link_refused', $business->id, $hook['meta'] + ['code' => $refusal]);

            return back()->withErrors(['access_token' => WhatsAppLinkCheck::say($refusal)]);
        }

        $attributes = [
            'owner_type' => WhatsAppMode::OWNER_BUSINESS,
            'business_id' => $business->id,
            'provider' => 'meta_cloud',
            'waba_id' => $check['waba'],
            'phone_number_id' => $data['phone_number_id'],
            'display_phone_number' => $data['display_phone_number'] ?? ($number['number']['display_phone_number'] ?? null),
            'coexistence' => false,
            'access_token' => $token,
            'token_expires_at' => $check['expires_at'],
            'status' => WhatsAppConnection::ACTIVE,
            'connected_at' => now(),
            'connected_by_user_id' => $request->user()->id,
            'disconnected_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'last_error_at' => null,
        ] + WhatsAppLinkCheck::stamp($check['app_id'], $hook['state'], WhatsAppLinkCheck::routing((string) $data['phone_number_id'], $token)['state'])
          + ($existing && $existing->phone_number_id !== $data['phone_number_id'] ? WhatsAppLinkHealth::UNHEARD : []);

        $connection = $existing ? tap($existing)->update($attributes) : WhatsAppConnection::create($attributes);

        // قوالب المحلّ تُهيَّأ بأسمائها الافتراضية — تُصحَّح إن اعتُمدت بأسماءٍ أخرى
        WhatsAppTemplates::seedBusinessDefaults($business->id);

        Activity::log('settings', 'واتساب — ربط رقم المتجر ('.($attributes['display_phone_number'] ?? $data['phone_number_id']).')');

        return back()->with('toast', [
            'msg' => WhatsAppOnboarding::linkedMessage($hook['state']),
            'type' => $hook['state'] === WhatsAppLinkCheck::SUBSCRIBED ? 'success' : 'warning',
        ]);
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

        // ومن أُغلق عنه رقمُ أبعاد يبقى على رقمه غيرَ مربوط — لا يُرجَع إليه
        if ($business->whatsapp_mode === WhatsAppMode::BUSINESS_OWN && WhatsAppFeature::canUseShared($business)) {
            $business->update(['whatsapp_mode' => WhatsAppMode::ABAAD_SHARED]);
        }

        Activity::log('settings', 'واتساب — فصل رقم المتجر');

        return back()->with('toast', ['msg' => __('فُصل رقم المتجر'), 'type' => 'success']);
    }
}
