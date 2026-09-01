<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Admin\WhatsAppController;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Loyalty;
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
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /* --------------------------- الموقع الإلكتروني --------------------------- */

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
        Activity::log('updated', 'حدّث إعدادات الموقع الإلكتروني');

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
            /*
             * نسبةُ اكتسابٍ لا تطبع مالًا.
             *
             * كان السقف ألفًا، والاستبدال مئةُ نقطةٍ للريال: فمنحُ ألف نقطةٍ
             * لكلّ ريالٍ يعيد إلى الزبون عشرة ريالاتٍ عن كلّ ريالٍ يدفعه.
             * وهذا ليس سخاءً يُترك لصاحبه — هو حلقةٌ لا تُغلق: يشتري بنقاطٍ
             * يكسب منها نقاطًا أكثر.
             */
            'loyalty_earn_rate' => ['required', 'numeric', 'min:0', 'max:'.Loyalty::maxEarnRate()],
            /*
             * سقف الاستبدال نسبةٌ من الفاتورة لا أكثر من مئة.
             *
             * تجاوزُها يجعل النقاط تُغطّي الفاتورة كلّها وزيادة، فيخرج البيع
             * بمبلغٍ سالب.
             */
            'loyalty_redeem_max_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'loyalty_redeem_min' => ['required', 'integer', 'min:0'],
        ], [
            'loyalty_earn_rate.max' => __(
                'كلّ :unit نقطة تساوي وحدةَ عملةٍ عند الاستبدال، فمنحُ :rate نقطةً لكل وحدةٍ يعيد للزبون :pct٪ من فاتورته.',
                [
                    'unit' => Loyalty::POINTS_PER_UNIT,
                    'rate' => (string) $request->input('loyalty_earn_rate'),
                    'pct' => Loyalty::cashbackPercent((float) $request->input('loyalty_earn_rate')),
                ],
            ),
        ]);

        MarketingSettings::save($this->bid(), 'loyalty', $data);
        Activity::log('updated', 'حدّث برنامج الولاء');

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
        Activity::log('updated', 'حدّث إعدادات محركات البحث');

        return back()->with('toast', ['msg' => __('حُفظت إعدادات البحث'), 'type' => 'success']);
    }

    /* -------------------------- إشعارات واتساب -------------------------- */

    public function whatsapp(): Response
    {
        $bid = $this->bid();

        $business = Business::findOrFail($bid);

        return Inertia::render('Admin/Marketing/Whatsapp', [
            'settings' => MarketingSettings::group($bid, 'whatsapp'),
            /*
             * حال الأتمتة كما تراها المنصّة — لا كما يظنّها التاجر.
             *
             * ولا يخرج منها رمزٌ ولا معرّف وصلة أبعاد: الوضع المشترك يقول
             * «يُرسل عبر أبعاد» ولا يقول بأيّ حسابٍ ولا بأيّ مفتاح.
             */
            'automation' => WhatsAppController::view($business),
        ]);
    }

    public function saveWhatsapp(Request $request)
    {
        /*
         * أربعةُ مقابضَ وحدها — انظر MarketingSettings::GROUPS.
         *
         * ولا رقمَ ولا نصَّ رسالةٍ ولا مفتاحَ تفعيلٍ ثانٍ: كانت تُقبل وتُحفظ
         * ولا يقرؤها مُرسِل الرسائل. والتفعيل من بطاقة الوصلة، والرقم رقمُها،
         * والنصّ قالبٌ معتمَدٌ عند ميتا.
         */
        $data = $request->validate([
            'wa_on_order' => ['nullable', 'boolean'],
            'wa_on_ready' => ['nullable', 'boolean'],
            'wa_on_out_for_delivery' => ['nullable', 'boolean'],
            'wa_on_delivered' => ['nullable', 'boolean'],
        ]);

        MarketingSettings::save($this->bid(), 'whatsapp', $data);
        Activity::log('updated', 'حدّث إشعارات واتساب');

        return back()->with('toast', ['msg' => __('حُفظت إعدادات واتساب'), 'type' => 'success']);
    }
}
