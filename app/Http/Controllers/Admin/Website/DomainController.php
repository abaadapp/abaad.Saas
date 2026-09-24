<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Product;
use App\Support\Activity;
use App\Support\MarketingSettings;
use App\Support\Storefront;
use App\Support\Website\Domains;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الدومين — عنوانٌ يعمل من اليوم الأوّل، وآخرُ يُربط متى شاء.
 *
 * والتجربة الأساسية أنّ التاجر **لا يفعل شيئًا**: يحجز اسمه فيصير له
 * `متجري.abaadapp.om` يعمل، وينشر ويعطي العنوان لزبائنه. ولا تُسأله الشاشة
 * عن DNS ولا عن شهادة — هذه ليست معرفتَه ولا يجب أن تصير شرطًا لأن يكون له
 * موقع.
 *
 * ثمّ لمن يملك نطاقًا: يكتبه، ويُعرض له **سجلٌّ واحد** ينسخه إلى لوحة
 * مسجّله، ويضغط «تحقّق». لا عشرةُ مصطلحاتٍ ولا شرحُ أنواع السجلّات — سجلٌّ
 * واحد وزرُّ نسخٍ وجواب.
 *
 * وما وراء ذلك — التحقّق وإعادة المحاولة وحالُ الشهادة — عملُ الخادم، ولا
 * يُعرض منه للتاجر إلا كلمةٌ تقول أين وقف.
 */
class DomainController extends Controller
{
    use Concerns;

    public function index(): Response
    {
        /*
         * وعنوانُ صاحب الواجهة الخاصّة ليس نطاقًا وحده.
         *
         * عنوانُه `‎{اسمه}.abaadapp.om‎` يسكن عمودًا في `businesses`، ونشرُه
         * مفتاحٌ في إعداداته — وكلاهما كان في بطاقة «الإعدادات». فيُجمعان
         * هنا مع اختيار الطريق: سؤالُ «أين يُفتح متجري؟» واحدٌ لا سؤالان في
         * شاشتين.
         */
        if (($theme = $this->theme()) !== null) {
            return $this->themed($theme);
        }

        $site = $this->siteOrFail();

        return Inertia::render('Admin/Website/Domain', $this->shell($site) + [
            'domain' => $this->domainState(),
            /*
             * وعنوانُ الوصل يُعرض ولو لم يُربط نطاقٌ بعد.
             *
             * التاجر يريد أن يعرف ما الذي سيُطلب منه قبل أن يبدأ — لا أن
             * يكتب نطاقه ثمّ يُفاجأ بأنّ عليه دخولَ لوحة مسجّله.
             */
            'connect' => Domains::connectHost(),
            // أيخدم هذا الخادمُ نطاقاتِ التجّار أصلًا؟ — تُقال ولا تُخفى
            'serves' => (bool) config('storefront.custom_domains'),
        ]);
    }

    /**
     * «العنوان والنشر» لصاحب الواجهة الخاصّة.
     *
     * وثلاثةُ أشياءَ في شاشةٍ واحدة لأنّها سؤالٌ واحد: أيَّ طريقٍ اخترتَ إلى
     * عنوانك، وما اسمُك عليه، وأمفتوحٌ هو للزبائن. ومن فرّقها على شاشتين
     * نشر متجرًا بلا عنوان — وهي الحالُ التي يرفض `saveStore` إنشاءها.
     */
    private function themed(string $theme): Response
    {
        $bid = $this->bid();
        $business = Business::findOrFail($bid);

        return Inertia::render('Admin/Website/ThemeDomain', $this->themeShell($theme) + [
            'path' => Storefront::path($business),
            'pricing' => Storefront::pricing(),
            'suggestion' => Storefront::suggest((string) $business->name),
            'storeOn' => (MarketingSettings::group($bid, 'website')['store_on'] ?? '0') === '1',
            /*
             * وعددُ المعروض يُقال عند زرّ النشر لا في شاشةٍ أخرى.
             *
             * صفحةٌ فارغةٌ تُفقد الزبونَ ثقتَه ولا يعود إليها بعد أن رآها
             * خالية — والتحذيرُ قبل الضغط أنفعُ من تقريرٍ بعده.
             */
            'productCount' => Product::where('business_id', $bid)
                ->where('active', true)->where('published', true)->count(),
        ]);
    }

    /** ربطُ نطاقٍ يملكه التاجر — أو فكُّه بحقلٍ فارغ */
    public function save(Request $request)
    {
        $this->siteOrFail();

        $data = $request->validate([
            'hostname' => ['nullable', 'string', 'max:255'],
        ]);

        $business = Business::findOrFail($this->bid());
        $raw = trim((string) ($data['hostname'] ?? ''));

        if ($raw === '') {
            Domains::detach($business);
            Activity::log('updated', 'فكّ نطاقه الخاصّ عن موقعه');

            return back()->with('toast', [
                'msg' => __('فُكّ نطاقك — وموقعك ما زال يعمل على عنوان أبعاد'),
                'type' => 'success',
            ]);
        }

        /*
         * والعنوانُ يُطبَّع قبل أن يُفحص — لا بعد.
         *
         * التاجر يلصق `https://myshop.om/` كما نسخه من متصفّحه. ورفضُ ذلك
         * برسالة «اكتب النطاق وحده» صحيحٌ تقنيًّا وسيّئٌ عمليًّا: نحن نعرف
         * ما يقصد، فنقبله ونحفظ ما يُطابَق.
         */
        if (Domains::normalize($raw) === null) {
            return back()->withErrors(['hostname' => __('هذا ليس نطاقًا — اكتبه هكذا: mystore.om')]);
        }

        $result = Domains::attach($business, $raw);

        if (! ($result['ok'] ?? false)) {
            return back()->withErrors(['hostname' => $result['error']]);
        }

        Activity::log('updated', 'ربط نطاقه الخاصّ: '.$raw);

        return back()->with('toast', [
            'msg' => __('حُفظ نطاقك — أضف السجلّ المعروض ثمّ اضغط «تحقّق من الربط»'),
            'type' => 'success',
        ]);
    }

    /**
     * «تحقّق من الربط» — السؤال الوحيد الذي يخرج إلى الشبكة.
     *
     * وبزرٍّ لا عند فتح الشاشة: صفحةٌ تنتظر جوابَ DNS تفتح في ثوانٍ أو لا
     * تفتح، والتاجر لا يعرف أنّ بطأها من نطاقٍ ربطه.
     */
    public function check()
    {
        $this->siteOrFail();

        $domain = Domains::custom($this->bid());

        if (! $domain) {
            return back()->with('toast', ['msg' => __('لا نطاقَ مربوط'), 'type' => 'warning']);
        }

        $domain = Domains::check($domain);

        return back()->with('toast', match ($domain->status) {
            Domains::ACTIVE => ['msg' => __('تمّ الربط — نطاقك يفتح موقعك'), 'type' => 'success'],
            Domains::FAILED => ['msg' => (string) $domain->failure_reason, 'type' => 'warning'],
            default => ['msg' => (string) ($domain->failure_reason ?: __('ما زال الربط جاريًا — أعد المحاولة بعد قليل')), 'type' => 'info'],
        });
    }
}
