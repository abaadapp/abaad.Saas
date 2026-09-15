<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\Plan;
use App\Models\User;
use App\Support\DemoStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * كلّ شاشةٍ تُفتح — على متجرٍ ممتلئ لا على قاعدةٍ فارغة.
 *
 * أرخص فحصٍ يكشف أكثر: صفحةٌ تنكسر بخمسمئة لا يراها اختبارٌ يقيس منطقًا في
 * دالّة. وعلى متجرٍ ممتلئ عمدًا — الشاشات تنكسر على البيانات لا على غيابها:
 * علاقةٌ فارغة، أو قسمةٌ على صفر، أو حقلٌ null في صفٍّ قديم.
 */
class EveryScreenOpensTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        Plan::updateOrCreate(['name' => 'الباقة الاحترافية'], [
            'monthly_price' => 30, 'yearly_price' => 300,
            'max_branches' => 3, 'max_employees' => 15, 'max_products' => 100000,
        ]);

        $business = DemoStore::create('متجر الفحص', 'صغير');
        $this->owner = $business->users()->where('role', 'admin')->firstOrFail();

        $platform = Business::create(['name' => 'المنصّة', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $platform->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $platform->id, 'name' => 'مدير', 'role' => 'admin']);
        $this->super = User::create([
            'business_id' => $platform->id, 'name' => 'مدير المنصة', 'email' => 'root@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط',
        ]);
    }

    /** @return list<array{string, string}> */
    private function screens(string $prefix): array
    {
        $out = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, $prefix) || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            // ما يحمل معرّفًا يُفحص بمعرّفٍ حقيقيّ في اختباره الخاص، لا هنا
            if (str_contains($route->uri(), '{')) {
                continue;
            }
            $out[] = [$name, $route->uri()];
        }

        return $out;
    }

    private function sweep(User $as, string $prefix): void
    {
        $broken = [];

        foreach ($this->screens($prefix) as [$name, $uri]) {
            $response = $this->actingAs($as)->get('/'.ltrim($uri, '/'));
            $status = $response->getStatusCode();

            // ٢٠٠ أو تحويلٌ مقصود — وما عداهما عطب
            if ($status >= 500 || $status === 404) {
                $broken[] = $name.' → '.$status;
            }
        }

        $this->assertSame([], $broken, 'شاشاتٌ لا تُفتح');
    }

    /**
     * والشاشات التي تحمل معرّفًا — وهي الأخطر.
     *
     * تُفتح بمعرّفٍ حقيقيّ من المتجر نفسه: صفحةٌ تنكسر على علاقةٍ فارغة أو
     * حقلٍ null لا تظهر إلا حين يُفتح صفٌّ فعليّ.
     */
    public function test_every_screen_that_carries_an_id_opens(): void
    {
        $bid = $this->owner->business_id;

        /*
         * طلبٌ من الفرع الذي تربط عليه نقطة البيع، وبترتيبٍ صريح.
         *
         * كان `firstOrFail()` بلا ترتيب: يُعيد ما يُعطيه المخطّط، وهو يتبدّل
         * بتبدّل الفهارس. فحين أُضيف فهرسٌ على الطلبات تبدّل الصفّ المختار إلى
         * طلبٍ في فرعٍ آخر، فردّت شاشة إيصالات الصندوق ٤٠٤ — وهي محقّة: الصندوق
         * لا يقرأ إيصالات فرعٍ لا يقف فيه. فاختبارٌ يتبدّل مُدخلُه من تحته لا
         * يقيس شيئًا.
         */
        $posBranch = \App\Models\Branch::where('business_id', $bid)->orderBy('id')->value('id');
        $order = \App\Models\Order::where('business_id', $bid)->sold()
            ->where('branch_id', $posBranch)->orderBy('id')->firstOrFail();
        $targets = [
            'admin.customers.show' => \App\Models\Customer::where('business_id', $bid)->value('id'),
            'admin.customers.statement' => \App\Models\Customer::where('business_id', $bid)->value('id'),
            'admin.employees.show' => \App\Models\User::where('business_id', $bid)->value('id'),
            'admin.employees.edit' => \App\Models\User::where('business_id', $bid)->value('id'),
            'admin.products.show' => \App\Models\Product::where('business_id', $bid)->value('id'),
            'admin.products.edit' => \App\Models\Product::where('business_id', $bid)->value('id'),
            'admin.orders.show' => $order->number,
            'admin.orders.pdf' => $order->number,
            'admin.orders.taxInvoice' => $order->number,
            'admin.branch.switch' => \App\Models\Branch::where('business_id', $bid)->value('id'),
            'admin.currency.switch' => 'OMR',
            'admin.finance.statement' => \App\Models\BankAccount::where('business_id', $bid)->value('id'),
            'pos.order-details' => $order->number,
            'pos.receipts.show' => $order->number,
            'pos.receipt.pdf' => $order->number,
            'pos.currency.switch' => 'OMR',
        ];

        $broken = [];

        foreach ($targets as $name => $param) {
            if ($param === null) {
                $broken[] = $name.' → لا صفَّ لفحصه';

                continue;
            }

            $status = $this->actingAs($this->owner)->get(route($name, $param))->getStatusCode();

            if ($status >= 500 || $status === 404) {
                $broken[] = $name.' → '.$status;
            }
        }

        // ولوحة المنصّة
        foreach ([
            'super-admin.businesses.show', 'super-admin.businesses.edit', 'super-admin.users.show',
        ] as $name) {
            $id = str_contains($name, 'users') ? $this->owner->id : $this->owner->business_id;
            $status = $this->actingAs($this->super)->get(route($name, $id))->getStatusCode();
            if ($status >= 500 || $status === 404) {
                $broken[] = $name.' → '.$status;
            }
        }

        $this->assertSame([], $broken, 'شاشاتٌ لا تُفتح بمعرّفٍ حقيقيّ');
    }

    public function test_every_merchant_screen_opens(): void
    {
        $this->sweep($this->owner, 'admin.');
    }

    public function test_every_platform_screen_opens(): void
    {
        $this->sweep($this->super, 'super-admin.');
    }

    public function test_every_pos_screen_opens(): void
    {
        $this->sweep($this->owner, 'pos.');
    }

    /**
     * ولا شاشتان في لوحة التاجر تحملان الاسمَ نفسَه.
     *
     * ═══ وكيف وُجد هذا ═══
     *
     * كانت «الظهور في البحث» اسمَ شاشتين: واحدةٌ في «التسويق» تربط Google
     * Analytics وتفحص الصفحة، وأخرى في «الموقع الإلكتروني» يُكتب فيها عنوانُ
     * الصفحة ووصفُها. ومن سأل «لماذا لا أظهر في Google» يفتح إحداهما
     * بالصدفة — وقد تكون التي لا تكتب في صفحته حرفًا.
     *
     * وأسوأُ منه: نصائحُ الأولى تقول «اكتبه في الموقع الإلكتروني ‹ الظهور في
     * البحث»، فيقرؤها وهو واقفٌ على صفحةٍ تحمل الاسمَ نفسَه.
     *
     * ولوحةُ المنصّة تُستثنى: شاشاتُها لا يفتحها تاجرٌ قطّ، و«التقارير» عنده
     * غيرُ «التقارير» عندنا — واسمان متطابقان لا يلتقيان لا يُخلطان.
     */
    public function test_no_two_merchant_screens_share_a_name(): void
    {
        $titles = [];

        foreach ($this->pages(resource_path('js/Pages/Admin')) as $file) {
            preg_match('/<PageHeader\s[^>]*?title="([^"]+)"/s', (string) file_get_contents($file), $m);

            if (($m[1] ?? '') !== '') {
                /*
                 * والمسارُ لا الاسمُ المجرّد.
                 *
                 * الشاشتان المتصادمتان كلتاهما `Seo.tsx` — واحدةٌ تحت
                 * `Website` وأخرى تحت `Marketing`. و`basename` تجعلهما
                 * ملفًّا واحدًا في عين الحارس، فيمرّ التصادمُ الذي وُجد
                 * الحارسُ لأجله. وقد نجت طفرةٌ أعادت الاسمَ المكرَّر قبل أن
                 * يُصلَح هذا السطر.
                 */
                $titles[$m[1]][] = str_replace(resource_path('js/Pages/'), '', (string) $file);
            }
        }

        $clashes = [];

        foreach ($titles as $title => $files) {
            if (count(array_unique($files)) > 1) {
                $clashes[] = $title.' → '.implode('، ', array_unique($files));
            }
        }

        $this->assertSame([], $clashes, 'شاشتان في لوحة التاجر تحملان الاسم نفسه');
    }

    /** @return list<string> */
    private function pages(string $dir): array
    {
        $out = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            /* والرسّامُ المستعارُ خارجَ القياس — ليس شاشةً في القائمة */
            if ($file->isFile() && $file->getExtension() === 'tsx'
                && ! str_contains($file->getPathname(), 'preview/renderer')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
