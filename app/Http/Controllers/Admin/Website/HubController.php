<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Order;
use App\Models\Product;
use App\Support\Permissions;
use App\Support\SalesChannel;
use App\Support\Store\StoreReadiness;
use App\Support\Storefront;
use App\Support\Website\Blueprints;
use App\Support\Website\Commerce;
use App\Support\Website\Domains;
use App\Support\Website\Readiness;
use App\Support\Website\Shelf;
use Inertia\Inertia;
use Inertia\Response;

/**
 * لوحةُ تشغيل الموقع — شاشةُ الموظّف اليوميّة، لا شاشةُ إعدادات.
 *
 * ═══ الفرقُ الذي بُنيت له ═══
 *
 * «الموقع الإلكتروني» في الشريط الجانبيّ كان يفتح على عائلةٍ من ستّ شاشات:
 * الصفحات والتصميم والمتجر والسيو والدومين. وكلُّها **تُضبط مرّةً ثمّ
 * تُترك** — وموضعُ ما يُضبط مرّةً هو «الإعدادات».
 *
 * والموظّفُ الذي يفتح القسم كلَّ يوم لا يريد شيئًا من ذلك: يريد أن يعرف أنّ
 * الموقع يعمل، وكم صنفًا يراه الزبون، وأيُّها نفد فاختفى، وأن يصلح ذلك في
 * نقرتين. وكان عليه أن يمرّ بـ«التصميم» و«الدومين» ليبلغ منتجًا ينقصه رقم.
 *
 * ═══ ولا قاعدةَ منتجاتٍ ثانية ═══
 *
 * ما يُعرض هنا هو صفوفُ `products` نفسُها، وما يُكتب يُكتب بمسارات المنتجات
 * القائمة: `admin.products.quick` للسعر والكمّية (بقفلها وتوزيعها على الفرع)،
 * و`admin.marketing.store.products` للإظهار والإخفاء، و«فتح المنتج» لِما
 * سواهما. فلا منطقَ تحريرٍ يُنسخ إلى هنا، ولا عمودَ يُكتب من بابين.
 *
 * ═══ والصلاحيات لا تُوسَّع ═══
 *
 * الشاشةُ خلف قسم `website`. وما تعرضه من أفعال المنتجات يُعرض لمن يملك
 * `products` وحده — ومن لا يملكه يقرأ ولا يكتب. وزرُّ «إعدادات الموقع»
 * لمن يملك `website.configure` (انظر `Permissions`).
 */
class HubController extends Controller
{
    use Concerns;

    /** كم صفًّا يُعرض في القائمة العملية — وما بعده في «المنتجات» */
    private const ROWS = 24;

    public function index(): Response
    {
        $site = $this->siteOrFail();
        $bid = (int) $site->business_id;
        $user = auth()->user();

        return Inertia::render('Admin/Website/Hub', $this->shell($site) + [
            'readiness' => Readiness::check($site),
            'channel' => Commerce::channel($bid),
            'sells' => Blueprints::hasCatalogue($site->goal()),
            'counts' => $this->counts($bid),
            'products' => $this->rows($bid),
            'may' => [
                // ولا يُستنتج من القسم: من يفتح الموقع لا يعدّل المنتجات بالضرورة
                'products' => (bool) $user?->allows('products'),
                'configure' => (bool) $user?->may(Permissions::WEBSITE_CONFIGURE),
            ],
        ]);
    }

    /**
     * لوحةُ تشغيلِ الواجهة الخاصّة — RIBBON — وهي الموقعُ كلُّه لمن لبسها.
     *
     * ═══ لا صفَّ في `websites` ولا قالبَ ═══
     *
     * الواجهةُ الخاصّة ليست موقعًا يُبنى من أقسامٍ ويُنشر لقطةً: هي Blade
     * بتصميم صاحبها، تقرأ منتجاتِه وإعداداتِه مباشرةً (انظر `Store\RibbonController`).
     * فلا مسوّدةَ لها ولا «تغييراتٌ لم تُنشر» ولا قالبٌ يُبدَّل — وحالُها
     * واحدةٌ من اثنتين: تُخدم على عنوانها أو لا تُخدم، ويحكمها مفتاحُ «نشر
     * المتجر» في الإعدادات كما يحكم الصفحةَ البسيطة (`Storefront::serves`).
     *
     * ولوحتُها لوحةُ البانِي نفسُها في الشاشة: الأرقامُ الثلاثة وصفوفُ
     * المنتجات وأفعالُها — فما يراه الموظّف كلَّ صباح لا يتبدّل بتبدّل ما
     * يُخدم على العنوان. وما يختلف يُقال في `theme`: لا زرَّ «إعدادات الموقع»
     * يقود إلى شاشات البانِي، بل إلى بطاقة المتجر في الإعدادات حيث النشرُ
     * والدفعُ والتوصيل.
     *
     * والطلباتُ هنا حقيقيّة: إتمامُ الطلب في الواجهة يكتب طلبًا في أبعاد
     * بقناة «الموقع» (`Store\WebCheckout`)، فيُعدّ ما وصل اليوم ويُشار إلى
     * بابه — لا «تصلك على واتساب».
     */
    public function themed(string $theme): Response
    {
        $bid = $this->bid();
        $business = Business::findOrFail($bid);
        $user = auth()->user();

        $published = Storefront::serves($business) === Storefront::SERVES_THEME;

        return Inertia::render('Admin/Website/Hub', [
            'site' => [
                'id' => 0,
                'name' => $business->name,
                'goal' => Blueprints::STORE,
                'goal_label' => __(Blueprints::GOALS[Blueprints::STORE]['label'] ?? ''),
                'template' => $theme,
                'state' => $published ? 'published' : 'draft',
                'sells' => true,
                'maintenance' => false,
                'published_at' => null,
                'saved_at' => null,
                'changes' => false,
                // والعنوانُ ما يُخدم فعلًا — نطاقُه إن نشط، وإلّا عنوانُ أبعاد
                'url' => $published ? Domains::canonical($bid) : null,
                'tokens' => [],
            ],
            'theme' => $theme,
            /*
             * وما ينقص يُقال بلسان الواجهة لا بلسان البانِي: `Readiness` تقرأ
             * صفَّ `websites` الذي لا وجود له هنا، و`StoreReadiness` تقرأ
             * حالَ هذا المتجر بعينه.
             *
             * ═══ وموضعُها هنا لا في شاشةِ ضبط ═══
             *
             * كانت في شاشة «عام» — وهي شاشةٌ لا يُضبط فيها شيء: قائمةُ
             * جاهزيةٍ وسبعُ بطاقاتٍ تكرّر شريطَ التبويبات. فلمّا صار الضبطُ
             * صفحةً واحدة لم يبقَ لتلك الشاشة عمل، وانتقلت قائمتُها إلى
             * حيث تُقرأ: لوحةُ التشغيل هي «أين متجري الآن».
             */
            'readiness' => collect(StoreReadiness::steps($business))->map(fn ($step) => [
                'key' => $step['key'],
                'label' => $step['label'],
                'ok' => (bool) $step['done'],
                'optional' => ! $step['required'],
                'detail' => $step['why'],
            ])->all(),
            'channel' => Commerce::channel($bid),
            'sells' => true,
            'counts' => $this->counts($bid),
            'products' => $this->rows($bid),
            'orders' => [
                'today' => Order::where('business_id', $bid)
                    ->where('channel', SalesChannel::WEBSITE)
                    ->whereDate('created_at', now()->toDateString())
                    ->count(),
            ],
            'may' => [
                'products' => (bool) $user?->allows('products'),
                'configure' => (bool) $user?->may(Permissions::WEBSITE_CONFIGURE),
            ],
        ]);
    }

    /**
     * ثلاثةُ أرقامٍ تُقرأ في لمحة — ولا رابعَ لها.
     *
     * @return array{shown: int, hidden: int, out: int}
     */
    private function counts(int $bid): array
    {
        $active = Product::where('business_id', $bid)->where('active', true)
            ->get(['id', 'published']);

        $have = Shelf::availability($bid, $active->pluck('id')->all());

        $shown = 0;
        $out = 0;

        foreach ($active as $p) {
            if (! $p->published) {
                continue;
            }

            // والمخفيُّ لا يُعدّ نافدًا: هو غائبٌ باختيار صاحبه لا بنفاد الرفّ
            if ($have[(int) $p->id] ?? false) {
                $shown++;
            } else {
                $out++;
            }
        }

        return [
            'shown' => $shown,
            'hidden' => $active->where('published', false)->count(),
            'out' => $out,
        ];
    }

    /**
     * صفوفُ العمل — وما يحتاج انتباهًا أوّلًا.
     *
     * الترتيبُ ليس زينة: من يفتح الشاشة يفتحها لأنّ شيئًا ينقص. فالنافدُ
     * أوّلًا، ثمّ المخفيّ، ثمّ الباقي بالأحدث. وقائمةٌ مرتّبةٌ بالمعرّف تجعله
     * يمرّ على أربعين صفًّا سليمًا ليبلغ الصفّ الذي جاء له.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $bid): array
    {
        $products = Product::where('business_id', $bid)->where('active', true)
            ->orderByDesc('id')->get(['id', 'name', 'price', 'quantity', 'image', 'published', 'category_id']);

        $have = Shelf::availability($bid, $products->pluck('id')->all());

        return $products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'price' => (float) $p->price,
            'quantity' => (int) $p->quantity,
            'image' => $p->image,
            'published' => (bool) $p->published,
            /*
             * و«متوفّر» تُحسب ولا تُقرأ من الكمّية وحدها: ذو الوصفة مخزونُه
             * مكوّناته، ومتجرٌ يأذن بالبيع تحت الصفر لا نفادَ عنده. انظر
             * `Shelf` — والقاعدة نفسُها التي يقرؤها الموقع.
             */
            'in_stock' => $have[(int) $p->id] ?? false,
        ])
            ->sortBy(fn ($r) => match (true) {
                $r['published'] && ! $r['in_stock'] => 0,
                ! $r['published'] => 1,
                default => 2,
            })
            ->take(self::ROWS)->values()->all();
    }
}
