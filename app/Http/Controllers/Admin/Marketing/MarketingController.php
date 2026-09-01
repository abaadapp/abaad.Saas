<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Support\Demo;
use App\Support\MarketingSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * أدوات التسويق — أربع شاشات إعداد، والتقييمات والكوبونات لهما متحكّماهما.
 *
 * كلّها تقرأ وتكتب من `MarketingSettings` وحدها: مفتاحٌ يُقرأ بحرفٍ ويُكتب
 * بآخر لا يُخطئ أحدًا — تُقرأ القيمة الافتراضية بهدوء وتبدو الشاشة سليمة،
 * والإعداد الذي حفظه التاجر لا أثر له.
 */
class MarketingController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /* --------------------------- الموقع الإلكتروني --------------------------- */

    /**
     * زرّ «الموقع الإلكتروني» في الترويسة — وجهةٌ واحدة لحالتين.
     *
     * المنشورُ يُفتح، وغيرُ المنشور يُقال عنه إنّه لم يُنشر. وكان الزرّ يقود
     * إلى صفحة الإعدادات مباشرةً حين لا موقع: فمن ضغطه ليرى متجره يجد نفسه
     * في نموذجٍ لا يفهم لماذا فُتح له.
     *
     * والوجهةُ واحدةٌ عمدًا: الترويسة تفرّق بين الحالتين لتفتح المنشور في
     * تبويبٍ جديد، لكنّ من يصل إلى هذا المسار مباشرةً — برابطٍ محفوظ أو
     * بعودةٍ في التاريخ — يجب أن يصل إلى الشيء نفسه.
     */
    public function websiteStatus()
    {
        if ($url = Demo::websiteUrl()) {
            return redirect()->away($url);
        }

        $site = MarketingSettings::group($this->bid(), 'website');
        $request = \App\Models\DomainRequest::where('business_id', $this->bid())
            ->orderByDesc('id')->first();

        /*
         * أين وقف التاجر على طريق العنوان — لا «لا يوجد» وحدها.
         *
         * بلا نطاقٍ مضبوط أحوالٌ خمسة لا حالٌ واحدة: لم يختر بعد، أو اختار
         * نطاقه ولم يكتبه، أو حجز اسمًا فرعيًّا ينتظر الاستضافة، أو طلب من
         * أبعاد وينتظر ردًّا، أو رُدّ عليه بالرفض. وجملةٌ واحدة تصفها جميعًا
         * لا تقول لأحدٍ منهم ما خطوته التالية.
         */
        return Inertia::render('Admin/Marketing/WebsiteInactive', [
            'mode' => \App\Support\DomainOptions::mode($site),
            'subdomain' => trim((string) $site['site_subdomain']) !== ''
                ? \App\Support\DomainOptions::host(trim((string) $site['site_subdomain']))
                : null,
            'request' => $request?->only(['domain', 'note', 'status']),
        ]);
    }

    public function saveWebsite(Request $request)
    {
        $data = $request->validate([
            'site_enabled' => ['nullable', 'boolean'],
            /*
             * النطاق اسمٌ لا رابط.
             *
             * لصقُ «https://» أو مسارٍ بعده يبني روابط معطوبة في كل صفحة
             * (https://https://…)، ولا يظهر العطب إلا حين يفتحها زبون.
             */
            'site_domain' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*\.[a-zA-Z]{2,}$/'],
            'site_tagline' => ['nullable', 'string', 'max:255'],
            'site_about' => ['nullable', 'string', 'max:2000'],
            'site_whatsapp' => ['nullable', 'string', 'max:30'],
            'site_instagram' => ['nullable', 'string', 'max:100'],
            'site_show_prices' => ['nullable', 'boolean'],
            'site_allow_orders' => ['nullable', 'boolean'],
        ], [
            'site_domain.regex' => __('اكتب النطاق وحده بلا https:// ولا مسار — مثل: mystore.om'),
        ]);

        MarketingSettings::save($this->bid(), 'website', $data);
        \App\Support\Activity::log('updated', 'حدّث إعدادات الموقع الإلكتروني');

        return back()->with('toast', ['msg' => __('حُفظت إعدادات الموقع'), 'type' => 'success']);
    }

    /* ----------------------------- برنامج الولاء ----------------------------- */

    public function loyalty(): Response
    {
        $bid = $this->bid();

        return Inertia::render('Admin/Marketing/Loyalty', [
            'settings' => MarketingSettings::group($bid, 'loyalty'),
            'summary' => [
                'members' => Customer::where('business_id', $bid)->where('points', '>', 0)->count(),
                'points' => (int) Customer::where('business_id', $bid)->sum('points'),
                'earned' => (int) PointTransaction::where('business_id', $bid)->where('type', 'earn')->sum('points'),
                // المستبدَل مخزَّنٌ سالبًا — والقيمة المطلقة أوضح في بطاقة
                'redeemed' => (int) abs(PointTransaction::where('business_id', $bid)->where('type', 'redeem')->sum('points')),
            ],
            'top' => Customer::where('business_id', $bid)->where('points', '>', 0)
                ->orderByDesc('points')->limit(10)
                ->get(['id', 'name', 'phone', 'points'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'phone' => $c->phone,
                    'points' => (int) $c->points,
                ])->all(),
            'recent' => PointTransaction::where('business_id', $bid)->with('customer')
                ->orderByDesc('id')->limit(20)->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'customer' => $t->customer?->name ?? '—',
                    'type' => $t->type,
                    'points' => (int) $t->points,
                    'balance_after' => (int) $t->balance_after,
                    'note' => $t->note,
                    'at' => optional($t->created_at)->format('Y-m-d'),
                ])->all(),
        ]);
    }

    public function saveLoyalty(Request $request)
    {
        $data = $request->validate([
            'loyalty_enabled' => ['nullable', 'boolean'],
            'loyalty_earn_rate' => ['required', 'numeric', 'min:0', 'max:1000'],
            /*
             * سقف الاستبدال نسبةٌ من الفاتورة لا أكثر من مئة.
             *
             * تجاوزُها يجعل النقاط تُغطّي الفاتورة كلّها وزيادة، فيخرج البيع
             * بمبلغٍ سالب.
             */
            'loyalty_redeem_max_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'loyalty_redeem_min' => ['required', 'integer', 'min:0'],
        ]);

        MarketingSettings::save($this->bid(), 'loyalty', $data);
        \App\Support\Activity::log('updated', 'حدّث برنامج الولاء');

        return back()->with('toast', ['msg' => __('حُفظ برنامج الولاء'), 'type' => 'success']);
    }

    /* ------------------------- الكوبونات والعروض ------------------------- */

    public function coupons(): Response
    {
        return Inertia::render('Admin/Marketing/Coupons', [
            'stats' => Demo::couponStats(),
            'coupons' => Demo::coupons(),
            'segments' => Demo::marketingSegment(),
        ]);
    }

    /* ------------------------ تحسين محركات البحث ------------------------ */

    public function seo(): Response
    {
        $bid = $this->bid();
        $business = Business::find($bid);
        $website = MarketingSettings::group($bid, 'website');

        return Inertia::render('Admin/Marketing/Seo', [
            'settings' => MarketingSettings::group($bid, 'seo'),
            'domain' => $website['site_domain'],
            'siteEnabled' => $website['site_enabled'] === '1',
            'storeName' => $business?->name,
        ]);
    }

    public function saveSeo(Request $request)
    {
        $data = $request->validate([
            /*
             * حدود العنوان والوصف ليست تجميلًا: ما زاد عنها تقصّه محرّكات
             * البحث في منتصف الجملة، فيظهر الوصف مبتورًا لكل من يبحث.
             */
            'seo_title' => ['nullable', 'string', 'max:60'],
            'seo_description' => ['nullable', 'string', 'max:160'],
            'seo_keywords' => ['nullable', 'string', 'max:255'],
            'seo_index' => ['nullable', 'boolean'],
            'seo_ga_id' => ['nullable', 'string', 'max:40', 'regex:/^(G-[A-Z0-9]+|UA-\d+-\d+)?$/'],
        ], [
            'seo_title.max' => __('العنوان يُقصّ بعد ٦٠ محرفًا في نتائج البحث'),
            'seo_description.max' => __('الوصف يُقصّ بعد ١٦٠ محرفًا في نتائج البحث'),
            'seo_ga_id.regex' => __('معرّف Google Analytics يبدأ بـG- أو UA-'),
        ]);

        MarketingSettings::save($this->bid(), 'seo', $data);
        \App\Support\Activity::log('updated', 'حدّث إعدادات محركات البحث');

        return back()->with('toast', ['msg' => __('حُفظت إعدادات البحث'), 'type' => 'success']);
    }

    /* -------------------------- إشعارات واتساب -------------------------- */

    public function whatsapp(): Response
    {
        $bid = $this->bid();

        $business = Business::findOrFail($bid);

        return Inertia::render('Admin/Marketing/Whatsapp', [
            'settings' => MarketingSettings::group($bid, 'whatsapp'),
            'storeName' => $business->name,
            /*
             * حال الأتمتة كما تراها المنصّة — لا كما يظنّها التاجر.
             *
             * ولا يخرج منها رمزٌ ولا معرّف وصلة أبعاد: الوضع المشترك يقول
             * «يُرسل عبر أبعاد» ولا يقول بأيّ حسابٍ ولا بأيّ مفتاح.
             */
            'automation' => \App\Http\Controllers\Admin\WhatsAppController::view($business),
            // ما يقبله القالب من متغيّرات — تُعرض للتاجر بدل أن يخمّنها
            'variables' => [
                ':store' => __('اسم المتجر'),
                ':number' => __('رقم الطلب'),
                ':total' => __('إجمالي الطلب'),
                ':customer' => __('اسم العميل'),
            ],
        ]);
    }

    public function saveWhatsapp(Request $request)
    {
        $data = $request->validate([
            'wa_enabled' => ['nullable', 'boolean'],
            /*
             * الرقم بصيغة دولية بلا رموز.
             *
             * واتساب لا يفتح محادثةً على رقمٍ بمسافاتٍ أو شرطات، ويردّ بصفحةٍ
             * بيضاء لا برسالة — فيظنّ التاجر أن الإشعارات تعمل وهي لا تُرسل.
             */
            'wa_number' => ['nullable', 'string', 'regex:/^\d{8,15}$/'],
            'wa_on_order' => ['nullable', 'boolean'],
            'wa_on_ready' => ['nullable', 'boolean'],
            'wa_on_out_for_delivery' => ['nullable', 'boolean'],
            'wa_on_delivered' => ['nullable', 'boolean'],
            'wa_template_order' => ['nullable', 'string', 'max:500'],
            'wa_template_ready' => ['nullable', 'string', 'max:500'],
            'wa_template_delivered' => ['nullable', 'string', 'max:500'],
        ], [
            'wa_number.regex' => __('الرقم بصيغة دولية بلا + ولا مسافات — مثل: 96890000000'),
        ]);

        MarketingSettings::save($this->bid(), 'whatsapp', $data);
        \App\Support\Activity::log('updated', 'حدّث إشعارات واتساب');

        return back()->with('toast', ['msg' => __('حُفظت إعدادات واتساب'), 'type' => 'success']);
    }
}
