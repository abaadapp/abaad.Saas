<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\InvoiceBranding;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    /**
     * ما يُرتَّب في قائمة الأنشطة.
     *
     * والمالك والباقة عمودان هنا لا في جدولٍ آخر (`owner_name` و`plan_id`)،
     * فيُرتَّبان بلا ضمّ. و«آخر بيع» محسوبٌ من الطلبات فلا يُرتَّب به.
     */
    private const SORTS = \App\Support\Lists\BusinessesList::SORTS;

    public function index(Request $request)
    {
        /*
         * «آخر بيعة» عمودٌ يقلب الجدول من سجلّ إلى أداة.
         *
         * شركةٌ «نشطة» باشتراكٍ سارٍ إلى ٢٠٢٧ وصفر طلبات منذ ثلاثة أسابيع
         * مشتركٌ سيلغي ولا تدري — واللوحة تعدّه في «النشطة». ومن مضى عليه
         * أسبوع يُتّصل به قبل أن يتصل هو ليلغي.
         *
         * وبفرعٍ استعلاميّ لا بعلاقة: صفٌّ واحد لكل شركة، لا استعلامٌ لكلٍّ منها.
         */
        // الاستعلامُ نفسُه الذي تقرؤه ملفّاتُ التصدير — انظر `BusinessesList`
        $q = \App\Support\Lists\BusinessesList::query($request);

        $businesses = $q->paginate(10)->withQueryString()->through(fn ($b) => [
            'id' => $b->id, 'name' => $b->name, 'type' => $b->type, 'owner' => $b->owner_name,
            'phone' => $b->phone, 'email' => $b->owner_email, 'contactEmail' => $b->email,
            'plan' => $b->plan?->name ?? '—',
            'status' => $b->status, 'registered' => optional($b->starts_at)->format('Y-m-d') ?? '—',
            'tier' => $b->tier,
            'expires' => optional($b->ends_at)->format('Y-m-d'),
            /*
             * الأيّام الباقية — الواجهة تلوّن بها ولا تحسب.
             *
             * وسالبها يعني انقضاءً: بينه وبين الإقفال مهلةُ السماح، فالرقم
             * وحده لا يقول إن المتجر واقف — انظر Tenancy::locked.
             */
            'daysLeft' => \App\Support\Tenancy::daysLeft($b),
            'branches' => $b->branches_count, 'city' => $b->city, 'country' => $b->country,
            // مسار مخزَّن أو رابط مطلق — المحوّل يميّز بينهما
            'logo' => PageController::logoUrl($b->logo),
            'lastSale' => $b->last_sale ? \Illuminate\Support\Carbon::parse($b->last_sale)->format('Y-m-d') : null,
            // الأيام منذ آخر بيعة — الواجهة تلوّن بها ولا تحسب
            'silentDays' => $b->last_sale
                ? (int) \Illuminate\Support\Carbon::parse($b->last_sale)->startOfDay()->diffInDays(now()->startOfDay())
                : null,
        ]);

        return \Inertia\Inertia::render('Platform/Businesses/Index', [
            'businesses' => $businesses->items(),
            'pagination' => \App\Support\Pagination::meta($businesses),
            'filters' => $request->only('q', 'type', 'plan', 'status')
                + \App\Support\Sort::params($request, self::SORTS),
            'sorts' => \App\Support\Sort::keys(self::SORTS),
            'options' => PageController::filterOptions($request),
            /* بابُ أرشيفات الحذف — يُرسم إن كان المفتاح مفتوحًا على هذا الخادم */
            'purge' => \App\Support\BusinessPurge::enabled(),
            /*
             * قسمُ «التسجيلات من الموقع» فوق الجدول — صفوفُ الشركات نفسُها.
             *
             * لا يتبع بحثَ الجدول ولا ترشيحَه: قسمٌ ثابتٌ يقول ما وصل من الموقع،
             * و«عرض الكل» يفتح صفحتَه بترشيحها هي.
             */
            'selfSignups' => [
                'stats' => \App\Support\Lists\SelfSignupsList::stats(),
                'recent' => \App\Support\Lists\SelfSignupsList::recent(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        // ومتجرٌ جديدٌ لا يُفتح له السجلّ: ضريبتُه لم تُضبط بعد
        self::assertComponentsMayOpen(null, $data);
        $account = $this->validateAccount($request);
        $subscription = $this->validateSubscription($request);

        /*
         * ═══ القطاعُ أوّلًا — ثمّ باقةٌ من قطاعه وحده ═══
         *
         * قطاعٌ نشطٌ واحد يُختار وحده؛ وأكثرُ من واحدٍ يُختار صراحةً. والباقةُ —
         * المختارةُ، أو الافتراضيّةُ، أو أساسيّةُ التجربة — من قطاعه أو يُردّ الطلب
         * (`Sectors::assertPlanFits`): إخفاؤها في الشاشة ليس حراسة.
         */
        $data['sector_id'] = $data['sector_id'] ?? \App\Support\Sectors::only()?->id;

        if (empty($data['sector_id']) || ! \App\Models\Sector::whereKey($data['sector_id'])->where('active', true)->exists()) {
            return back()->withInput()->withErrors(['sector_id' => __('اختر القطاع.')]);
        }

        $data['logo'] = $request->hasFile('logo') ? $request->file('logo')->store('logos', 'public') : null;

        /*
         * مدّة التجربة — من إعدادات المنصة.
         *
         * `trial_days` كان حقلًا يُملأ ولا يقرؤه شيء: شركةٌ تُضاف بلا تاريخ
         * انتهاء تعمل إلى الأبد، فلا تجربةَ تنتهي ولا مطالبةَ تحلّ. ولا
         * يُطبَّق إن حدّد المشغّل التاريخين بنفسه: اختيارُه أولى من الافتراضي.
         */
        /*
         * الباقة الافتراضية — بالاسم كما يكتبه المشغّل في الإعدادات.
         *
         * كانت شركةٌ تُضاف بلا باقة فتبقى بلا سعرٍ ولا فاتورة، والحقل في
         * الإعدادات يُملأ ولا يقرؤه شيء. واسمٌ لا يطابق باقةً قائمة يُترك
         * كما كان: لا نخترع باقة.
         */
        if (empty($data['plan_id'])) {
            $name = trim((string) \App\Support\Tenancy::platform('default_plan', ''));
            if ($name !== '') {
                // ومن قطاع الشركة وحده: باقةٌ افتراضيّةٌ من قطاعٍ آخر لا تُسنَد صامتةً
                $data['plan_id'] = \App\Models\Plan::where('name', $name)->where('sector_id', $data['sector_id'])->value('id') ?: null;
            }
        }

        /*
         * والاشتراكُ بطريقةٍ صريحة (`NewMerchantSubscription`): تجربةُ الأساسيّة، أو
         * باقةٌ مدفوعةٌ تنتظر سدادَ فاتورتها، أو منحةٌ بتاريخٍ وسبب. فالباقةُ والحالةُ
         * والتاريخان يكتبها الخادم بحسبها — لا ما أرسلته الشاشة.
         */
        if ($subscription['subscription_mode'] !== \App\Support\NewMerchantSubscription::TRIAL && empty($data['plan_id'])) {
            return back()->withInput()->withErrors(['plan_id' => __('اختر الباقة.')]);
        }

        try {
            $data = array_merge($data, \App\Support\NewMerchantSubscription::shape($subscription, $data['plan_id'] ?? null));
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['subscription_mode' => $e->getMessage()]);
        }

        // والتجربةُ على الأساسيّة — فإن لم تكن من قطاعه رُدّت تحت طريقة الاشتراك
        \App\Support\Sectors::assertPlanFits($data['sector_id'], $data['plan_id'] ?? null,
            $subscription['subscription_mode'] === \App\Support\NewMerchantSubscription::TRIAL ? 'subscription_mode' : 'plan_id');

        // بابُ الإنشاء يختمه الخادم — `validateData` لا يقبله من الطلب
        $data['registration_source'] = Business::SOURCE_PLATFORM_MANUAL;
        // ويبدأ على رقمه هو — رقمُ أبعاد يختاره التاجرُ بنفسه (`WhatsAppMode::DEFAULT`)
        $data['whatsapp_mode'] = \App\Support\WhatsAppMode::DEFAULT;

        try {
            /*
             * ضغطتان معًا، أو طلبٌ أُعيد إرسالُه: كلاهما فحص الاسمَ قبل أن يُكتب
             * الأوّل، والفريدُ في القاعدة (`users.email`) يردّ الثاني — فتُرجَع
             * الكتابةُ كلُّها في نقطة حفظها (`Contention`): لا شركةٌ ثانية ولا فاتورة.
             */
            $created = \App\Support\Contention::attempt(function () use ($data, $account, $subscription, $request) {
                $business = Business::create($data);
                $owner = \App\Support\MerchantAccount::create($business, $account['login_username'], $account['login_password']);
                // بذرة تصنيفات حسب النوع — لئلا يفتح التاجر لوحته على صفحة بيضاء
                \App\Support\BusinessTypes::provision($business);
                \App\Support\Activity::log('created', 'أضاف شركة: ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

                // فاتورةُ الباقة وفواتيرُ الإضافات — وفشلُ أيٍّ منها يُرجع الشركةَ كلَّها
                $started = \App\Support\NewMerchantSubscription::start($business->fresh('plan'), $subscription, $request->user());

                return [$business, $owner, $started];
            });
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['subscription_mode' => $e->getMessage()]);
        }

        if ($created === null) {
            return back()->withInput()->withErrors(['login_username' => __('أُضيفت شركةٌ بهذا الحساب للتوّ — لم يُكرَّر شيء.')]);
        }

        [$business, $owner, $started] = $created;

        $msg = __('تم إضافة الشركة · حساب الدخول: :email', ['email' => $owner->email]);
        if ($started['invoices'] !== []) {
            $msg .= ' · '.__('الفواتير: :list', ['list' => implode('، ', $started['invoices'])]);
        }
        if ($business->status === Business::AWAITING_PAYMENT) {
            $msg .= ' · '.__('يُفتح الحساب بتسجيل سداد فاتورة الباقة.');
        }

        return redirect()->route('super-admin.businesses.index')->with('toast', ['msg' => $msg, 'type' => 'success']);
    }

    /**
     * طريقةُ فتح الاشتراك — وما تحتاجه كلُّ طريقة.
     *
     * المدفوعُ بدورةٍ تُفوتَر؛ والمنحةُ بتاريخٍ لا يسبق اليوم وسببٍ مكتوب؛ والإضافاتُ
     * للمدفوع والممنوح وحدهما، بدورة الباقة نفسِها — والتجربةُ لا فاتورةَ لها.
     */
    private function validateSubscription(Request $request): array
    {
        // ومن لم يذكر طريقةً فتجربة: أضيقُ الطرق — لا باقةَ مدفوعةً ولا أيّامَ بلا سداد
        $request->mergeIfMissing(['subscription_mode' => \App\Support\NewMerchantSubscription::TRIAL]);

        $data = $request->validate([
            'subscription_mode' => ['required', Rule::in(\App\Support\NewMerchantSubscription::MODES)],
            'cycle' => ['nullable', 'required_if:subscription_mode,paid', Rule::in(\App\Support\Billing::CYCLES)],
            'grant_until' => ['nullable', 'required_if:subscription_mode,grant', 'date', 'after_or_equal:today'],
            'grant_reason' => ['nullable', 'required_if:subscription_mode,grant', 'string', 'min:5', 'max:255'],
            'addons' => ['nullable', 'array', 'max:20'],
            'addons.*.addon_id' => ['required', 'integer', 'distinct', 'exists:subscription_addons,id'],
            'addons.*.quantity' => ['nullable', 'integer', 'min:1', 'max:'.\App\Support\Addons\Catalog::MAX_QUANTITY],
            'addons.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'addons.*.note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'subscription_mode' => __('طريقة الاشتراك'),
            'cycle' => __('مدة الاشتراك'),
            'grant_until' => __('نهاية المنحة'),
            'grant_reason' => __('سبب المنحة'),
            'addons' => __('الإضافات'),
        ]);

        if (! empty($data['addons'])) {
            if ($data['subscription_mode'] === \App\Support\NewMerchantSubscription::TRIAL) {
                throw \Illuminate\Validation\ValidationException::withMessages(['addons' => __('التجربة بلا إضافات — تُطلب بعد الاشتراك.')]);
            }
            if (empty($data['cycle'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['cycle' => __('اختر مدّة الاشتراك — الإضافات تُفوتَر بها.')]);
            }
        }

        return $data;
    }

    public function update(Request $request, $id)
    {
        $business = Business::findOrFail($id);
        $data = $this->validateData($request);
        self::assertComponentsMayOpen($business, $data);

        // والباقةُ — الجديدةُ أو القائمة — من قطاع الشركة، الجديدِ أو القائم (`Sectors`)
        if (empty($data['sector_id'])) {
            unset($data['sector_id']);
        }
        \App\Support\Sectors::assertPlanFits(
            $data['sector_id'] ?? $business->sector_id,
            array_key_exists('plan_id', $data) ? $data['plan_id'] : $business->plan_id,
        );

        /*
         * لا تُنزَّل باقةٌ يتجاوزها المتجر أصلًا.
         *
         * كان التغيير يمرّ بلا فحص: متجرٌ بثلاثة فروع يُنقَل إلى «الأساسية»
         * (فرعٌ واحد) فتبقى الثلاثة تعمل — و`PlanLimits` تحرس الإنشاء لا
         * القائم، فلا شيء يُقفل الزائد ولا شيء يقول إنّه زائد. فالباقة
         * تصير ورقةً في الفاتورة لا حدًّا في النظام.
         *
         * والمنع هنا لا الإقفال التلقائيّ: إقفالُ فرعٍ فيه بيعُ اليوم
         * وموظّفوه قرارٌ لا يُتَّخذ بتغيير قائمةٍ منسدلة. يُقال للمشغّل ما
         * الزائد، ويُرتَّب قبل التنزيل.
         */
        if (array_key_exists('plan_id', $data) && (int) ($data['plan_id'] ?? 0) !== (int) $business->plan_id) {
            $target = $data['plan_id'] ? \App\Models\Plan::find($data['plan_id']) : null;

            if ($target && $over = \App\Support\PlanLimits::exceededBy($business, $target)) {
                return back()->withInput()->withErrors([
                    'plan_id' => __('المتجر يتجاوز باقة «:plan»: :over. أنقص الزائد قبل التنزيل.', [
                        'plan' => $target->name,
                        'over' => implode('، ', $over),
                    ]),
                ]);
            }
        }

        /*
         * ولا يفتح هذا البابُ ما يُشترى بفاتورة (`AdminGrants`).
         *
         * باقةٌ أعلى، أو مدّةٌ أطول، أو حسابٌ بانتظار السداد — تُرقّى وتُجدَّد من
         * صفحة المتجر بفاتورتها، أو تُكتب منحةً بسببٍ وتاريخ. والإهداءُ لمتجر
         * السياسة الجديدة إضافةٌ تُطلب أو تُمنح مؤرّخةً من «خدمات أبعاد»، لا
         * مفتاحٌ بلا تاريخ يفتحها أبدًا.
         */
        $grant = $request->validate(['grant_reason' => ['nullable', 'string', 'min:5', 'max:500']]);
        \App\Support\Addons\AdminGrants::authorize($business, $data, $grant['grant_reason'] ?? null);

        if (! empty($data['gift_orders_enabled']) && ! $business->gift_orders_enabled && \App\Support\Addons\PricingPolicy::isAddons($business)) {
            return back()->withInput()->withErrors([
                'gift_orders_enabled' => __('طلبات الإهداء إضافة لهذا المتجر: اطلبها له أو امنحها بتاريخ من «خدمات أبعاد» في صفحته.'),
            ]);
        }

        $giftingBefore = (bool) $business->gift_orders_enabled;
        $notesBefore = (bool) $business->order_notes_and_edits_enabled;
        $thermalBefore = (bool) $business->thermal_delivery_note_enabled;
        $preparationBefore = (bool) $business->customer_invoice_preparation_enabled;

        $business->update($data);

        // وملاحظاتُ العميل وتعديلُ أصناف الفاتورة كذلك (`NotesAndEdits`)
        if ((bool) $business->order_notes_and_edits_enabled !== $notesBefore) {
            \App\Support\Activity::log('updated', ($business->order_notes_and_edits_enabled ? 'فتح' : 'أغلق').' ملاحظات العميل وتعديل أصناف الفاتورة: '.$business->name, ['business_id' => null, 'subject_id' => $business->id]);
        }

        // وسندُ التسليم الحراريّ لطلبات الموقع كذلك (`DeliveryPaper::thermalOn`)
        if ((bool) $business->thermal_delivery_note_enabled !== $thermalBefore) {
            \App\Support\Activity::log('updated', ($business->thermal_delivery_note_enabled ? 'فتح' : 'أغلق').' سند التسليم الحراري لطلبات الموقع: '.$business->name, ['business_id' => null, 'subject_id' => $business->id]);
        }

        /*
         * وتجهيزُ فواتير العملاء بعد الدفع كذلك (`CustomerInvoicePreparations::on`).
         *
         * - فتحُه يُكتب وقتُه: دفعٌ وصل والمفتاحُ مغلق لا يُفعِّل وحده حين يُفتح
         *   (`CustomerInvoicePreparations::linkCounts`) — يُرسَل بيدٍ إن لزم.
         * - وإغلاقُه يُلغي ورقَ الصناديق المنتظِر (`PrintJobs::cancelPending`): لا
         *   يُطبع ورقُ أسبوعٍ مضى حين يُفتح من جديد. ولا يمسّ دفعًا ولا فاتورة.
         */
        if ((bool) $business->customer_invoice_preparation_enabled !== $preparationBefore) {
            if ($business->customer_invoice_preparation_enabled) {
                $business->forceFill(['customer_invoice_preparation_enabled_at' => now()])->save();
            } else {
                \App\Support\PrintJobs::cancelPending((int) $business->id);
            }

            \App\Support\Activity::log('updated', ($business->customer_invoice_preparation_enabled ? 'فتح' : 'أغلق').' تجهيز فواتير العملاء بعد الدفع: '.$business->name, ['business_id' => null, 'subject_id' => $business->id]);
        }

        // وفتحُ الإهداء أو إغلاقُه يُقيَّد باسمه — إذنٌ يُسأل عنه لاحقًا
        if ((bool) $business->gift_orders_enabled !== $giftingBefore) {
            \App\Support\Activity::log('updated', ($business->gift_orders_enabled ? 'فتح' : 'أغلق').' الإهداء في المتجر الإلكتروني: '.$business->name, ['business_id' => null, 'subject_id' => $business->id]);
        }

        /*
         * ═══ الشعار: من البابِ الواحد لا من هنا ═══
         *
         * ملفٌّ جديد يحلّ محلّ القديم، وطلبُ الحذف يمسحه. وبلا أيٍّ منهما لا
         * يُمسّ العمود — الحقل غائبٌ عن الطلب حين لا يُختار ملف، فتمريرُه
         * إلى `update` كان يمسح الشعار عند كلّ تعديلٍ لحقلٍ آخر لا صلة له به
         * (ولهذا يُنزَع في `validateData`).
         *
         * وكان هذا البابُ يكتب العمودَ بيده فيترك الملفَّ القديم على القرص:
         * من بدّل شعارَ متجرٍ عشر مرّاتٍ من لوحة المنصّة ترك عشرًا لا يشير
         * إليها شيء، ومن ضغط «إزالة» قرأ «حُذف» والصورةُ تُخدَم برابطها.
         * وهو العطبُ الذي أُغلق في بابَي التاجر — فافترق البابُ الثالث عنهما.
         *
         * و`InvoiceBranding::storeLogo` هي القاعدةُ في موضعٍ واحد: تقرأ
         * العمودَ الخام (لا المُلحَق الذي يردّ رابطًا)، وتحفظ، ثمّ تمحو
         * القديمَ بعد الحفظ لا قبله.
         *
         * وبعد `update` لا قبله: حارسُ الباقة أعلاه يردّ الحفظَ كلَّه
         * بـ`back()`، فشعارٌ يُبدَّل قبله يُكتب على متجرٍ رُدَّ تعديلُه.
         */
        if ($request->hasFile('logo') || $request->boolean('remove_logo')) {
            InvoiceBranding::storeLogo($business, $request->file('logo'), $request->boolean('remove_logo'));
        }

        $extra = $this->syncAccount($request, $business);
        \App\Support\Activity::log('updated', 'عدّل الشركة: ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

        return redirect()->route('super-admin.businesses.index')->with('toast', ['msg' => __('تم تحديث الشركة بنجاح') . $extra, 'type' => 'success']);
    }

    public function destroy($id)
    {
        $business = Business::findOrFail($id);

        // مقفلٌ أصلًا حتى السداد — وتعطيلُه ثمّ إعادةُ تشغيله تفتحه بلا سداد (`activate`)
        if ($business->status === Business::AWAITING_PAYMENT) {
            return back()->with('toast', ['msg' => __('هذا الحساب مقفل حتى سداد فاتورته — ألغِ الفاتورة إن لم يعد مطلوبًا.'), 'type' => 'warning']);
        }

        $business->update(['status' => 'معطل']);
        \App\Support\Activity::log('deleted', 'عطّل الشركة: ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

        return redirect()->route('super-admin.businesses.index')->with('toast', ['msg' => __('تم تعطيل الشركة'), 'type' => 'warning']);
    }

    /**
     * الحذفُ النهائيّ — بابٌ آخرُ غيرُ بابِ التعطيل.
     *
     * ═══ ولمَ مسارٌ مستقلّ ═══
     *
     * `destroy` أعلاه يكتب كلمةً في عمود، ويُردّ بـ`activate`. وهذا يمحو
     * متجرًا ومَن فيه وملفّاتِه، ولا يُردّ بشيء. وفعلان متباعدان هكذا لا
     * يُجمعان في مسارٍ واحدٍ يُفرَّق بينهما بحقلٍ في الطلب — فطلبٌ يُساء
     * تكوينُه يمحو ما كان يُراد تعطيلُه.
     *
     * ═══ وثلاثةُ حرّاسٍ قبل أن يقع شيء ═══
     *
     * المفتاحُ على الخادم (`config/purge.php`) — مغلقٌ افتراضيًّا فيُردّ
     * المسارُ ٤٠٤ ولو عُرف عنوانُه. ثمّ الدور: المجموعةُ خلف
     * `role:super_admin`، ويُسأل هنا ثانيةً فلا يُعتمد على حارسٍ بعيد. ثمّ
     * الاسمُ يُكتب كاملًا ويُقارَن **في الخادم** — إخفاءُ زرٍّ ليس حراسة.
     */
    public function purge(Request $request, $id)
    {
        abort_unless(\App\Support\BusinessPurge::enabled(), 404);
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $business = Business::findOrFail($id);

        /*
         * والاسمُ يُقارَن بعد تسويةِ المسافات وحدَها.
         *
         * من ينسخ الاسمَ من الشاشة قد يلتقط مسافةً في طرفه، وليست تلك خطأً
         * يستحقّ أن يُردّ به. وما عدا ذلك يُطابَق حرفًا بحرف: اسمٌ مقاربٌ
         * ليس هذا الاسم.
         */
        $typed = preg_replace('/\s+/u', ' ', trim((string) $request->input('confirm')));
        $real = preg_replace('/\s+/u', ' ', trim((string) $business->name));

        if ($typed === '' || $typed !== $real) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'confirm' => __('اكتب اسم الشركة كما هو مكتوب أعلاه — لم يُحذف شيء.'),
            ]);
        }

        /*
         * وبوّاباتُ التخزين والطابور تُسأل قبل أن يُصفَّ شيء.
         *
         * لا نسخةَ بعيدةً مهيَّأةً، أو لا مفتاحَ تشفير، أو لا عاملَ طابور:
         * يُردّ الطلبُ بكلمةٍ تُقرأ تحت الحقل، ولا يُنشأ صفٌّ ولا تُصفّ
         * مهمّةٌ تنتظر ما لا يأتي.
         */
        try {
            \App\Support\BusinessPurge::gate();
        } catch (\RuntimeException $e) {
            return back()->withErrors(['confirm' => $e->getMessage()]);
        }

        /*
         * صفٌّ واحدٌ لكلّ شركة — والفريدُ في القاعدة هو الحارس.
         *
         * ضغطتان متلاحقتان تصلان معًا: الأولى تُنشئ الصفَّ، والثانية تسقط
         * على الفهرس الفريد فتُقرأ «جارٍ الآن». ولا يُعتمد على قراءةٍ ثمّ
         * كتابةٍ: بينهما نافذةٌ يمرّ منها الطلبُ الثاني.
         */
        $run = \App\Models\PurgeRun::firstOrNew(['business_id' => (int) $business->id]);

        if ($run->exists && $run->isActive()) {
            return back()->withErrors(['confirm' => __('حذفُ هذه الشركة جارٍ الآن — انتظر حتّى ينتهي.')]);
        }

        try {
            $run->fill([
                'business_name' => (string) $business->name,
                'requested_by' => $request->user()->id,
                'requested_by_name' => (string) $request->user()->name,
                'status' => \App\Models\PurgeRun::PENDING,
                'stage' => \App\Models\PurgeRun::QUEUED,
                'error' => null,
                'started_at' => null,
                'finished_at' => null,
            ])->save();
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->withErrors(['confirm' => __('حذفُ هذه الشركة جارٍ الآن — انتظر حتّى ينتهي.')]);
        }

        \App\Jobs\PurgeBusiness::dispatch($run->id);

        return back()->with('toast', [
            'msg' => __('بدأ الحذف النهائي في الخلفية — تابع حالته في هذه الصفحة.'),
            'type' => 'info',
        ]);
    }

    /**
     * حالُ الحذف — تُقرأ من الشاشة كلَّ ثوانٍ.
     *
     * ولا تحمل مسارًا داخليًّا ولا بصمةً ولا اسمَ قرص: الشاشةُ تحتاج «أين
     * وصل» لا «أين الملفّ». ومن أراد الملفَّ يمرّ ببابِ التنزيل وحدَه.
     */
    public function purgeStatus(Request $request, $id)
    {
        abort_unless(\App\Support\BusinessPurge::enabled(), 404);
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $run = \App\Models\PurgeRun::where('business_id', (int) $id)->first();

        if ($run === null) {
            return response()->json(['status' => null]);
        }

        return response()->json([
            'status' => $run->status,
            'stage' => $run->stage,
            'label' => \App\Support\Purge\Stages::label($run),
            'rows' => $run->rows_total,
            'users' => $run->users_deleted,
            'files' => $run->files_deleted,
            'verified' => $run->verified_at !== null,
            'failures' => count($run->failures ?? []),
            'error' => $run->error,
            'done' => $run->status === \App\Models\PurgeRun::DONE,
        ]);
    }

    /**
     * تنزيلُ أرشيفِ شركةٍ محذوفة — لمدير المنصّة وحدَه، ويُقيَّد في السجلّ.
     *
     * ═══ ولمَ لا رابطَ مباشرٌ للتخزين ═══
     *
     * رابطٌ موقَّعٌ من S3 يخرج من النظام فلا يُقيَّد من فتحه ولا متى، ويُنسخ
     * فيُقرأ بعد ذلك بلا تخويل. فالملفُّ يمرّ من هنا: يُنزَّل إلى ملفٍّ
     * مؤقّت، ويُفكّ تشفيرُه، ويُسلَّم، ويُمحى المؤقّتُ بعد التسليم.
     *
     * والاسمُ المعروضُ من معرّف الصفّ لا من مسار التخزين — فلا يُكشف مسارٌ
     * داخليٌّ ولا اسمُ دلو.
     */
    public function purgeDownload(Request $request, $runId)
    {
        abort_unless(\App\Support\BusinessPurge::enabled(), 404);
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $run = \App\Models\PurgeRun::findOrFail($runId);

        try {
            $tmp = \App\Support\Purge\Vault::open($run);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['archive' => $e->getMessage()]);
        }

        \App\Support\Activity::log('downloaded', __('نزّل أرشيف الحذف النهائي: :name (#:id)', [
            'name' => $run->business_name,
            'id' => $run->business_id,
        ]), [
            'business_id' => null,
            'subject_type' => \App\Models\PurgeRun::class,
            'subject_id' => $run->id,
            'icon' => 'download',
            'color' => 'warning',
        ]);

        return response()
            ->download($tmp, 'purge-'.$run->business_id.'-'.$run->created_at->format('Ymd').'.zip')
            ->deleteFileAfterSend(true);
    }

    /**
     * إعادة تشغيل شركةٍ معطَّلة — الطرف الآخر من زرّ التعطيل.
     *
     * كان التعطيل بابًا يُغلق ولا يُفتح: المسار الوحيد يكتب «معطل» ولا مسار
     * يردّها. فمن عطّل شركةً بالخطأ، أو عطّلها لتأخّر دفعةٍ ثم وصلت، كان
     * سبيله الوحيد نموذجَ التعديل الكامل — يعيد كتابة الاسم والنوع والباقة
     * لتغيير كلمة، وأيّ حقلٍ يسقط من الطلب يمحو ما في القاعدة.
     *
     * والحالة الجديدة تُحسب ولا تُفترض: شركةٌ انتهى اشتراكها تعود «منتهي» لا
     * «نشط». وإلا لقالت الشاشة «نشط» ولم يستطع التاجر الدخول — يقرأ الحارس
     * تاريخ الانتهاء لا الكلمة المكتوبة — ثم يقلبها المجدول ليلًا فيظنّ
     * المشغّل أن أحدًا عطّلها ثانيةً.
     */
    public function activate($id)
    {
        $business = Business::findOrFail($id);

        if (! in_array((string) $business->status, ['معطل', 'معطّل'], true)) {
            return back()->with('toast', ['msg' => __('هذه الشركة ليست معطَّلة'), 'type' => 'info']);
        }

        $expired = \App\Support\Tenancy::expired($business);
        $business->update(['status' => $expired ? 'منتهي' : 'نشط']);

        \App\Support\Activity::log('status', 'أعاد تشغيل الشركة: ' . $business->name, [
            'business_id' => null,
            'subject_id' => $business->id,
        ]);

        return back()->with('toast', [
            'msg' => $expired
                // لا نقول «عاد يعمل» لمن لن يستطيع الدخول: السبب يُقال في موضعه
                ? __('أُعيد تشغيل الشركة، لكنّ اشتراكها منتهٍ — جدّده ليتمكّن التاجر من الدخول')
                : __('تمت إعادة تشغيل الشركة'),
            'type' => $expired ? 'warning' : 'success',
        ]);
    }

    /**
     * تعديل حساب الدخول وحده — من صفحة الشركة.
     *
     * مسارٌ مستقلّ لا تمريرٌ عبر نموذج الشركة: إعادةُ كتابة الاسم والنوع
     * والحالة لتغيير كلمة مرورٍ نسيها تاجر عملٌ زائد يُخطئ فيه المشغّل، وأيّ
     * حقلٍ يسقط من الطلب يمحو ما في القاعدة.
     *
     * وكلمة المرور تُعاد في الرسالة مرّةً واحدة: هي مجزَّأة في القاعدة فلا
     * تُقرأ بعدها أبدًا — وبلا عرضها هنا لا سبيل لإبلاغ التاجر بها.
     */
    public function account(Request $request, $id)
    {
        $business = Business::findOrFail($id);
        $extra = $this->syncAccount($request, $business);

        if ($extra === '') {
            return back()->with('toast', ['msg' => __('لم يتغيّر شيء'), 'type' => 'info']);
        }

        $shown = filled($request->input('login_password'))
            ? __(' · كلمة المرور: :password', ['password' => $request->input('login_password')])
            : '';

        return back()->with('toast', [
            'msg' => __('تم تحديث حساب الدخول').$extra.$shown,
            'type' => 'success',
        ]);
    }

    /**
     * حساب الدخول عند التعديل: يُنشأ إن لم يكن، وتُبدَّل كلمته إن طُلب.
     *
     * الشركات المسجّلة قبل إلزام الحساب بقيت بلا مستخدم، وصفحة التعديل كانت
     * تعرض «—» بلا حقلٍ ولا زرّ: عطبٌ لا مخرج منه إلا بفتح قاعدة البيانات.
     * ومَن يفقد كلمته كان لا سبيل إلى إعادتها — «نسيت كلمة المرور» محذوفة،
     * وصفحة الموظفين لا يفتحها من لا يدخل أصلًا.
     *
     * @return string لاحقةُ رسالةٍ تُقال للمشغّل، أو '' إن لم يتغيّر شيء
     */
    private function syncAccount(Request $request, Business $business): string
    {
        $owner = \App\Support\MerchantAccount::owner($business);

        // بلا حساب: الحقول إلزامية كما في الإنشاء
        if (! $owner) {
            $account = $this->validateAccount($request);
            $owner = \App\Support\MerchantAccount::create($business, $account['login_username'], $account['login_password']);
            \App\Support\Activity::log('created', 'أنشأ حساب دخول لـ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

            return __(' · حساب الدخول: :email', ['email' => $owner->email]);
        }

        /*
         * بحساب: الحقلان اختياريان — الفارغ يعني «لا تغيّره».
         *
         * لو كانا إلزاميين لصار كل تعديلٍ لمدينةٍ أو باقة يطالب بإعادة كتابة
         * كلمة المرور، فتُخترع كلمةٌ جديدة كل مرّة ويخرج التاجر من حسابه.
         */
        $request->validate([
            'login_username' => array_merge(['nullable'], \App\Support\MerchantAccount::usernameRules()),
            'login_password' => ['nullable', 'string', 'min:8'],
        ], \App\Support\MerchantAccount::messages(), [
            'login_username' => __('اسم المستخدم'),
            'login_password' => __('كلمة المرور'),
        ]);

        $changed = [];

        if (filled($username = $request->input('login_username'))) {
            $email = \App\Support\MerchantAccount::email($username);

            if ($email !== $owner->email) {
                // التفرّد يستثني صاحبَ الحساب نفسه، وإلا اصطدم بنفسه
                if (\App\Support\MerchantAccount::taken($username, $owner->id)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'login_username' => __('اسم المستخدم محجوز — اختر غيره.'),
                    ]);
                }

                $owner->email = $email;
                $changed[] = __(' · حساب الدخول: :email', ['email' => $email]);
            }
        }

        if (filled($request->input('login_password'))) {
            $owner->password = $request->input('login_password');
            $changed[] = __(' · تم تغيير كلمة المرور');
        }

        if (! $changed) {
            return '';
        }

        $owner->save();
        \App\Support\Activity::log('updated', 'عدّل حساب دخول ' . $business->name, ['business_id' => null, 'subject_id' => $business->id]);

        return implode('', $changed);
    }

    /**
     * حساب دخول المالك — إلزاميّ عند الإنشاء.
     *
     * شركةٌ بلا حساب سجلٌّ في جدول لا يفتحه أحد: لا التاجر يدخل، ولا الدعم
     * يستطيع «الدخول كتاجر» لأنه لا يجد من ينتحله. وتأجيلُه إلى «لاحقًا»
     * يعني أنه يُنسى حتى يتصل صاحب الشركة يسأل عن كلمة مروره.
     *
     * والبريد يُبنى من اسمٍ ونطاقٍ ثابت (MerchantAccount::DOMAIN)، فلا
     * يُكتب النطاق يدويًّا ولا يفترق على أشكال.
     */
    private function validateAccount(Request $request): array
    {
        $account = $request->validate([
            'login_username' => array_merge(['required'], \App\Support\MerchantAccount::usernameRules()),
            'login_password' => ['required', 'string', 'min:8'],
        ], \App\Support\MerchantAccount::messages(), [
            'login_username' => __('اسم المستخدم'),
            'login_password' => __('كلمة المرور'),
        ]);

        // التفرّد يُفحص على البريد الكامل: القاعدة تخزّنه لا الاسم وحده
        if (\App\Support\MerchantAccount::taken($account['login_username'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'login_username' => __('اسم المستخدم محجوز — اختر غيره.'),
            ]);
        }

        return $account;
    }

    /**
     * مستحقّاتُ مكوّنات البوتيك لا تُفتح إلّا لمتجرٍ يُؤوي بوتيكات ولا يحتسب الضريبة.
     *
     * والإطفاءُ مسموحٌ دائمًا: ما كُتب من مستحقّاتٍ يبقى ويُسوّى ويُسدَّد،
     * وما يُباع بعده يعود إلى ما كان قبل المفتاح.
     */
    private static function assertComponentsMayOpen(?Business $business, array $data): void
    {
        if (! ($data['boutique_components_enabled'] ?? false) || ($business?->boutique_components_enabled ?? false)) {
            return;
        }

        $why = match (true) {
            $business === null => __('افتح مستحقّات مكوّنات البوتيك بعد إنشاء المتجر وضبط ضريبته.'),
            ! ($data['boutiques_enabled'] ?? $business->boutiques_enabled) => __('افتح «يُؤوي بوتيكات» أوّلًا.'),
            \App\Support\Vat::enabled((int) $business->id) => __('هذا المتجر يحتسب الضريبة — مستحقّات مكوّنات البوتيك لا تُفتح له حتى تُعتمد معالجتُها الضريبيّة.'),
            default => null,
        };

        if ($why !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['boutique_components_enabled' => $why]);
        }
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // النوع والحالة عمودان NOT NULL لهما قيم افتراضية؛ إرسال null صراحةً
            // يتخطّى الافتراضي ويكسر القيد بخطأ 500 بدل رسالة حقل مفقود.
            'type' => ['required', 'string', 'max:100'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            /*
             * الباقة تُتحقَّق من وجودها.
             *
             * `integer` وحدها كانت تقبل رقمًا لا باقةَ له، فيصير
             * `$business->plan` فارغًا — و`PlanLimits::cap` تقرأ الفراغ
             * «لا سقف». فمتجرٌ بباقةٍ وهميّة يفتح ما شاء من الفروع
             * والموظفين، وهو عكس ما يُقصَد من إسناد باقة.
             */
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            // والقطاعُ — تصنيفٌ إداريٌّ فوق نوع النشاط، والباقةُ من قطاعه (`Sectors`)
            'sector_id' => ['nullable', 'integer', 'exists:sectors,id'],
            'logo' => ['nullable', 'image', 'max:2048'],
            // لا يُحفظ في العمود — يُقرأ في update ويُستبعد هنا
            'remove_logo' => ['nullable', 'boolean'],
            // من القائمة التي تعرضها الشاشة لا نصًّا حرًّا — انظر `PageController::STATUSES`
            'status' => ['required', Rule::in(PageController::STATUSES)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            // الفئةُ والواجهةُ الخاصّة — من قائمةٍ مغلقة، وفراغُهما «كسائر المتاجر»
            'tier' => ['nullable', Rule::in(\App\Models\Business::TIERS)],
            'storefront_theme' => ['nullable', Rule::in(\App\Models\Business::THEMES)],
            /*
             * وأيُؤوي بوتيكاتٍ تبيع تحت سقفه؟
             *
             * مفتاحٌ يفتحه مديرُ المنصّة لمن طلبه — وليس قدرةَ باقةٍ تُشترى:
             * محلٌّ يُؤجّر أركانَه صفةُ محلٍّ لا درجةُ اشتراك. ومن لم يُفتح
             * له لا يرى تبويبًا ولا حقلًا، ولا يُكتب على بنوده شيء.
             */
            'boutiques_enabled' => ['nullable', 'boolean'],
            /*
             * ومستحقّاتُ البوتيك عن مكوّنات الباقات — مفتاحٌ ثانٍ تحت الأوّل،
             * مطفأٌ لكلّ متجر. لا يُفتح لمتجرٍ يحتسب الضريبة حتى تُعتمد
             * معالجتُها (`BoutiqueDues::refusal`)، ولا لمن لا يُؤوي بوتيكات.
             */
            'boutique_components_enabled' => ['nullable', 'boolean'],
            /*
             * والإهداءُ في المتجر الإلكترونيّ — مفتاحٌ يفتحه مديرُ المنصّة لمن
             * طلبه، كالبوتيكات (`Store\GiftOrders::on`). لا يفتحه التاجرُ من
             * إعدادات موقعه، ولا يُكتب إلّا من هذا الباب.
             */
            'gift_orders_enabled' => ['nullable', 'boolean'],
            // وملاحظاتُ العميل وتعديلُ أصناف الفاتورة بعد صدورها (`NotesAndEdits::on`)
            'order_notes_and_edits_enabled' => ['nullable', 'boolean'],
            /*
             * وسندُ التسليم على شريط الصندوق لطلبات الموقع (`DeliveryPaper::thermalOn`)
             * — يفتحه مديرُ المنصّة لمن طلبه، ولا يفتحه التاجرُ من إعداداته.
             */
            'thermal_delivery_note_enabled' => ['nullable', 'boolean'],
            /*
             * وتجهيزُ فواتير العملاء بعد الدفع (`CustomerInvoicePreparations::on`)
             * — يفتحه مديرُ المنصّة لنشاطٍ بعينه، ولا يفتحه التاجرُ لنفسه.
             */
            'customer_invoice_preparation_enabled' => ['nullable', 'boolean'],
        ]);

        /*
         * علامةُ النيّة لا عمودٌ في الجدول — وحقلُ الملفّ مثلها.
         *
         * الشاشة تُجبر النموذج على FormData (فيه ملفّ)، وFormData يكتب
         * قيمةَ null نصًّا فارغًا، ثمّ يعيدها `ConvertEmptyStringsToNull`
         * إلى null. فمفتاح `logo` **حاضرٌ فارغ** في كلّ حفظ لا غائب حين لا
         * يُختار ملف — و`nullable` تقبله، فيدخل المصفوفة المُتحقَّقة ويمحو
         * العمود عند `update`.
         *
         * فكان الشعار يُرفع ويُحفظ، ثمّ يختفي عند أوّل تعديلٍ لاسمٍ أو
         * هاتف: بلا زرٍّ ضُغط وبلا رسالة، ويبقى ملفُّه على القرص يتيمًا.
         *
         * فالعمودُ لا يُؤخذ من المُدخل أبدًا: `store` و`update` يكتبانه من
         * الملفّ المرفوع أو من علامة الحذف، وهما وحدهما.
         */
        unset($data['remove_logo'], $data['logo']);
        /*
         * ═══ والمفتاحُ يُكتب لمن أرسله وحدَه ═══
         *
         * منطقٌ لا نصّ: FormData ترفعه «0»/«1»، فيُقرأ بـ`boolean` لا كما جاء.
         *
         * ولا يُكتب حين لا يُرسَل. كان يُكتب دائمًا، فحمولةٌ لا تحمله تُطفئه —
         * وهذا ما وقع فعلًا: شاشةُ التعديل أسقطته من قيمها الابتدائيّة، فكان
         * كلُّ حفظٍ لاسمٍ أو هاتفٍ يُطفئ البوتيكات، ويفقد التاجرُ تبويبَه
         * بلا أن يمسّه أحد. ولا يشي به شيء: الفئةُ والواجهةُ تبقيان لأنّهما
         * `nullable` تخرجان من المصفوفة حين تغيبان — فيُقرأ الحفظُ سليمًا.
         *
         * فصار كسائر الحقول: الغائبُ لا يُمسّ، والمرسَلُ يُكتب كما أُرسل.
         */
        foreach (['boutiques_enabled', 'boutique_components_enabled', 'gift_orders_enabled', 'order_notes_and_edits_enabled', 'thermal_delivery_note_enabled', 'customer_invoice_preparation_enabled'] as $flag) {
            if ($request->has($flag)) {
                $data[$flag] = $request->boolean($flag);
            } else {
                unset($data[$flag]);
            }
        }
        // والفراغُ فراغٌ لا نصٌّ فارغ: `''` يسقط في `Rule::in` ويُقرأ «فئةً» في كلّ فحص
        foreach (['tier', 'storefront_theme'] as $k) {
            if (array_key_exists($k, $data) && (string) $data[$k] === '') {
                $data[$k] = null;
            }
        }

        return $data;
    }
}
