<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StorePaymentIntent;
use App\Models\User;
use App\Support\Store\WebCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RibbonShopAtCheckout;
use Tests\TestCase;

/**
 * الصنفُ يُباع حيث يختار صاحبُه: نقطةُ البيع، والمتجرُ على الإنترنت، أو كلاهما، أو لا شيء.
 *
 * ═══ ما يُحرس ═══
 *
 *   - كلُّ تركيبةٍ من المفاتيح الثلاثة (`active` فوق `pos_enabled` و`published`)
 *     تبيع في قناتها وحدها — في الخادم: البيعُ والتعليقُ على الصندوق، وصفحةُ
 *     المتجر، وإتمامُ طلبه — لا في الشاشة وحدها.
 *   - الرفُّ واحدٌ للقناتين: يُخصم مرّةً من أيّهما بِيع، وإغلاقُ قناةٍ لا يمسّه.
 *   - النموذجُ يحفظ القناتين، ويُعاد فتحُه بهما، وحفظةٌ لا ترسلهما لا تمسّهما،
 *     ولا تُحيي صنفًا موقوفًا. والقسمُ يُحفظ كما اختير.
 *   - الاستيرادُ: الجديدُ في القناتين ما لم يقل الملفُّ «لا»، والتحديثُ لا يمسّ
 *     ما لم يذكره.
 *   - دفعةٌ قُبضت يُكتب طلبُها ولو أُخفي صنفُها أو أُوقف والزبونُ عند البنك؛
 *     والدفعةُ الجديدة لا تُفتح لصنفٍ لا يُباع في الموقع.
 *   - الهجرةُ لا تغيّر ظهورَ صنفٍ قديم، ولا متجرٌ يمسّ صنفَ غيره.
 *
 * وما تعرضه شاشةُ الصندوق والماسحُ ونموذجُ المنتج: `a-product-sells-only-where-its-owner-chooses.test.tsx`.
 */
class AProductSellsOnlyWhereItsOwnerChoosesTest extends TestCase
{
    use RefreshDatabase;
    use RibbonShopAtCheckout;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        $this->openRibbon();
        // اسمٌ لا يقع في غير بطاقته، فيُسأل عنه في الصفحة بلا لبس
        $this->rose->update(['name' => 'باقة القناتين']);
        $this->branch = Branch::where('business_id', $this->shop->id)->firstOrFail();
    }

    private function state(bool $active, bool $pos, bool $online): void
    {
        $this->rose->update(['active' => $active, 'pos_enabled' => $pos, 'published' => $online]);
    }

    private function atTheTill(string $route = 'pos.checkout')
    {
        return $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson(route($route), [
                'items' => [['id' => $this->rose->id, 'name' => 'باقة القناتين', 'qty' => 1]],
                'payment_method' => 'نقدي',
                'client_uuid' => uniqid('c', true),
            ]);
    }

    private function onTheSite(): bool
    {
        $shop = $this->get('/s/ribbon/shop')->assertOk()->getContent();
        $page = $this->get('/s/ribbon/p/'.$this->rose->id);

        $this->assertSame(str_contains($shop, 'باقة القناتين'), $page->status() === 200, 'القائمةُ وصفحةُ الصنف تختلفان');

        return str_contains($shop, 'باقة القناتين');
    }

    private function save(array $data, ?Product $product = null)
    {
        $base = ['name' => 'باقة القناتين', 'price' => 20, 'cost' => 8, 'quantity' => 50, 'alert_qty' => 1];

        return $product
            ? $this->actingAs($this->owner)->put(route('admin.products.update', $product->id), $data + $base)
            : $this->actingAs($this->owner)->post(route('admin.products.store'), $data + $base);
    }

    /* ═════════════ كلُّ تركيبة ═════════════ */

    /** @return array<string, array{bool, bool, bool, bool, bool}> */
    public static function states(): array
    {
        // [نشط، نقطة البيع، المتجر الإلكتروني] ← [يُباع على الصندوق، يُباع في الموقع]
        return [
            'نشطٌ في القناتين' => [true, true, true, true, true],
            'نقطة البيع وحدها' => [true, true, false, true, false],
            'المتجر الإلكتروني وحده' => [true, false, true, false, true],
            'نشطٌ بلا قناة' => [true, false, false, false, false],
            'موقوفٌ وقناتاه مفتوحتان' => [false, true, true, false, false],
            'موقوفٌ بلا قناة' => [false, false, false, false, false],
        ];
    }

    #[DataProvider('states')]
    public function test_each_state_sells_in_its_channels_and_nowhere_else(bool $active, bool $pos, bool $online, bool $tillSells, bool $siteSells): void
    {
        $this->state($active, $pos, $online);

        $till = $this->atTheTill();
        $tillSells ? $till->assertOk() : $till->assertStatus(422)->assertJsonValidationErrors('items.0.id');
        // والتعليقُ بابٌ إلى الصندوق كالبيع — سلّةٌ تُعلَّق لتُدفع بعد قليل
        $hold = $this->atTheTill('pos.hold');
        $tillSells ? $hold->assertOk() : $hold->assertStatus(422);

        $this->assertSame($siteSells, $this->onTheSite(), 'عُرض الصنفُ في غير ما اختير له');
        $site = $this->checkout();
        // والموقوفُ يُردّ بنده (`items.0.id`)، والمخفيُّ السلّةُ كلُّها (`items`) — وكلاهما ردّ
        $siteSells ? $site->assertOk() : $site->assertStatus(422)->assertJsonPath('ok', false);

        $sold = (int) $tillSells + (int) $siteSells;
        // بيعةٌ وسلّةٌ معلّقةٌ على الصندوق، وطلبٌ في الموقع — وما رُدّ لم يكتب شيئًا
        $this->assertSame(2 * (int) $tillSells + (int) $siteSells, Order::where('business_id', $this->shop->id)->count());
        // والرفُّ واحد: ما بِيع خرج مرّةً، والتعليقُ لا يُخرج شيئًا
        $this->assertSame(50 - $sold, (int) $this->rose->fresh()->quantity, 'خرج من الرفّ غيرُ ما بِيع');
    }

    public function test_a_till_refusal_names_the_channel_not_a_stop(): void
    {
        $this->state(true, false, true);

        $this->atTheTill()->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.id' => '«باقة القناتين» غير متاح للبيع في نقطة البيع.']);

        // والموقوفُ يُقال موقوفًا كما كان
        $this->state(false, false, true);
        $this->atTheTill()->assertJsonValidationErrors(['items.0.id' => '«باقة القناتين» موقوف عن البيع.']);
    }

    public function test_closing_a_channel_leaves_the_shelf_and_the_books_alone(): void
    {
        $this->atTheTill()->assertOk();
        $orders = Order::count();
        $before = $this->rose->fresh()->only(['quantity', 'price', 'cost', 'tax', 'discount']);

        $this->state(true, false, false);
        $this->state(true, true, true);

        $this->assertSame($before, $this->rose->fresh()->only(['quantity', 'price', 'cost', 'tax', 'discount']));
        $this->assertSame($orders, Order::count());
    }

    /* ═════════════ النموذج ═════════════ */

    public function test_a_new_product_sells_in_both_channels_unless_its_owner_says_otherwise(): void
    {
        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'باقات']);

        $this->save(['category_id' => $cat->id])->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('toast.type', 'success');
        $both = Product::latest('id')->firstOrFail();
        $this->assertTrue($both->active && $both->pos_enabled && $both->published, 'صنفٌ جديدٌ وُلد خارج قناةٍ لم يُغلقها صاحبه');
        $this->assertSame($cat->id, (int) $both->category_id);

        $this->save(['category_id' => $cat->id, 'pos_enabled' => '0', 'published' => '1', 'name' => 'باقة الموقع']);
        $online = Product::latest('id')->firstOrFail();
        $this->assertFalse($online->pos_enabled);
        $this->assertTrue($online->published);
        $this->assertSame($cat->id, (int) $online->category_id, 'ضاع قسمُ الصنف مع قناته');

        // ويظهر تحت قسمه في الموقع، ولا يعرفه الصندوق
        $this->assertStringContainsString('باقة الموقع', $this->get('/s/ribbon/shop?cat='.$cat->id)->assertOk()->getContent());
    }

    public function test_the_edit_screen_reopens_with_the_channels_that_were_saved(): void
    {
        $this->save(['pos_enabled' => '0', 'published' => '1'], $this->rose)->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('admin.products.edit', $this->rose->id))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('product.pos_enabled', false)
                ->where('product.published', true)
                ->where('product.active', true));
    }

    public function test_a_save_that_does_not_send_the_channels_keeps_them_and_wakes_nothing(): void
    {
        $this->state(false, false, true);

        // سعرٌ وقسمٌ وكميّة — بلا مفاتيح
        $cat = Category::create(['business_id' => $this->shop->id, 'name' => 'ورد']);
        $this->save(['price' => 25, 'category_id' => $cat->id], $this->rose)->assertSessionHasNoErrors();
        $fresh = $this->rose->fresh();
        $this->assertSame([false, false, true], [$fresh->active, $fresh->pos_enabled, $fresh->published], 'حفظةٌ جزئيّة بدّلت قنواتِ الصنف أو أحيته');
        $this->assertSame(25.0, (float) $fresh->price);

        // والإجراءاتُ الجماعيّةُ لا تمسّ القناة: القسمُ والسعرُ والتنشيط
        $this->actingAs($this->owner)->post(route('admin.products.bulk'), ['ids' => [$this->rose->id], 'action' => 'price', 'percent' => 10])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.products.bulk'), ['ids' => [$this->rose->id], 'action' => 'activate'])->assertSessionHasNoErrors();
        $fresh = $this->rose->fresh();
        $this->assertTrue($fresh->active);
        $this->assertSame([false, true], [$fresh->pos_enabled, $fresh->published], 'التنشيطُ فتح قناةً أغلقها صاحبُها');
    }

    /* ═════════════ الاستيراد ═════════════ */

    private function import(string $csv)
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$csv);

        $this->actingAs($this->owner)->post(route('admin.products.import.upload'), [
            'file' => new UploadedFile($path, 'products.csv', 'text/csv', null, true),
            'branch_id' => $this->branch->id,
        ])->assertRedirect(route('admin.products.import.preview'));

        return $this->actingAs($this->owner)->post(route('admin.products.import.confirm'));
    }

    public function test_an_import_opens_both_channels_unless_the_file_says_no_and_leaves_unsaid_ones_alone(): void
    {
        $this->import("الاسم,السعر,الكمية\nصنفٌ قديم,5,3\n");
        $old = Product::where('name', 'صنفٌ قديم')->firstOrFail();
        $this->assertTrue($old->pos_enabled && $old->published, 'ملفٌّ بلا عمودَي القناتين أغلق قناة');

        $this->import("الاسم,السعر,الكمية,نقطة البيع,المتجر الإلكتروني\nصنف الموقع,5,3,لا,نعم\nصنف الصندوق,5,3,نعم,لا\n");
        $this->assertSame([false, true], [Product::where('name', 'صنف الموقع')->value('pos_enabled'), Product::where('name', 'صنف الموقع')->value('published')]);
        $this->assertSame([true, false], [Product::where('name', 'صنف الصندوق')->value('pos_enabled'), Product::where('name', 'صنف الصندوق')->value('published')]);

        // قائمةُ أسعارٍ لا تذكر القناتين لا تفتح ما أُغلق
        $this->import("الاسم,السعر\nصنف الموقع,7\n");
        $site = Product::where('name', 'صنف الموقع')->firstOrFail();
        $this->assertSame(7.0, (float) $site->price);
        $this->assertFalse($site->pos_enabled, 'تحديثٌ لم يذكر نقطةَ البيع فتحها');

        // و«نقطة البيع» قبل «السعر» لا يلتقطها السعر («البيع» من أسمائه)
        $this->import("الاسم,نقطة البيع,السعر\nصنف الترتيب,لا,6\n");
        $order = Product::where('name', 'صنف الترتيب')->firstOrFail();
        $this->assertSame([false, 6.0], [$order->pos_enabled, (float) $order->price]);
    }

    public function test_the_export_writes_each_channel_as_it_is(): void
    {
        $this->state(true, false, true);

        $res = $this->actingAs($this->owner)->get($this->exportRoute('admin.products.export.xlsx'))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();

        $this->assertSame(['نقطة البيع', 'المتجر الإلكتروني'], [$sheet[0][12], $sheet[0][13]]);
        $this->assertSame(['لا', 'نعم'], [$sheet[1][12], $sheet[1][13]]);
    }

    /* ═════════════ الدفعةُ والزبونُ عند البنك ═════════════ */

    public function test_a_paid_card_writes_its_order_even_if_the_product_left_the_site_meanwhile(): void
    {
        $this->bankOpens();
        $this->checkout(['pay' => 'card'])->assertOk()->assertJsonPath('ok', true);
        $intent = StorePaymentIntent::latest('id')->firstOrFail();

        $this->state(false, true, false);
        $this->paid($intent);

        $this->assertSame(1, Order::count(), 'قُبض المالُ ورُدّ الطلبُ لأنّ الصنفَ أُخفي بعد فتح الدفع');
        $this->assertFalse($intent->fresh()->strayPayment());
        $this->assertSame(49, (int) $this->rose->fresh()->quantity, 'الرفُّ يُخصم مرّةً واحدة');

        // والإشعارُ مكرَّرًا لا يكتب طلبًا ثانيًا ولا يُخرج قطعةً ثانية
        $this->paid($intent);
        $this->assertSame(1, Order::count());
        $this->assertSame(49, (int) $this->rose->fresh()->quantity);
    }

    public function test_a_new_payment_is_not_opened_for_a_product_off_the_site_and_an_unpaid_intent_cannot_force_one(): void
    {
        $this->bankOpens();
        $this->checkout(['pay' => 'card'])->assertOk();
        $intent = StorePaymentIntent::latest('id')->firstOrFail();

        $this->state(true, true, false);
        $this->checkout(['pay' => 'card'])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertSame(1, StorePaymentIntent::count(), 'فُتحت صفحةُ دفعٍ لصنفٍ أُخفي من الموقع');

        // ونيّةٌ لم يُقبض مالُها لا تمرّ بصنفٍ مخفيّ
        try {
            WebCheckout::place($this->shop->fresh(), (array) $intent->payload, 'ar', paid: true, intent: $intent);
            $this->fail('كُتب طلبٌ بصنفٍ مخفيّ بنيّةٍ لم تُدفع');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
        $this->assertSame(0, Order::count());
    }

    /* ═════════════ القديمُ والمتاجرُ الأخرى ═════════════ */

    public function test_the_migration_keeps_every_old_product_exactly_where_it_was_sold(): void
    {
        $migration = require database_path('migrations/2026_10_22_100000_a_product_chooses_where_it_is_sold.php');
        $migration->down();

        $rows = [
            'نشط منشور' => [true, true],
            'نشط مخفي' => [true, false],
            'موقوف منشور' => [false, true],
            'موقوف مخفي' => [false, false],
        ];
        foreach ($rows as $name => [$active, $published]) {
            DB::table('products')->insert([
                'business_id' => $this->shop->id, 'name' => $name, 'price' => 3, 'cost' => 1,
                'quantity' => 4, 'alert_qty' => 1, 'active' => $active, 'published' => $published,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration->up();

        foreach ($rows as $name => [$active, $published]) {
            $p = Product::where('name', $name)->firstOrFail();
            $this->assertSame([$active, $published, true, 4], [$p->active, $p->published, $p->pos_enabled, (int) $p->quantity], $name);
            $this->assertSame($active, $p->sellsAtPos(), "{$name}: تغيّر حالُه في نقطة البيع");
            $this->assertSame($active && $published, $p->sellsOnline(), "{$name}: تغيّر حالُه في الموقع");
        }
    }

    public function test_one_shop_cannot_reach_another_shops_product_or_channels(): void
    {
        $other = Business::create(['name' => 'متجرٌ آخر', 'type' => 'عام', 'status' => 'نشط']);
        $foreign = Product::create([
            'business_id' => $other->id, 'name' => 'صنف الجار', 'price' => 9, 'cost' => 1,
            'quantity' => 5, 'alert_qty' => 1, 'active' => true, 'published' => true, 'pos_enabled' => true,
        ]);

        // لا يُعدَّل من غير متجره ولا يُباع على صندوقٍ غيرِ صندوقه
        $this->save(['pos_enabled' => '0'], $foreign)->assertNotFound();
        $this->assertTrue($foreign->fresh()->pos_enabled);

        $this->actingAs($this->owner)->withSession(['current_branch' => $this->branch->id])
            ->postJson(route('pos.checkout'), [
                'items' => [['id' => $foreign->id, 'name' => 'صنف الجار', 'qty' => 1]],
                'payment_method' => 'نقدي', 'client_uuid' => uniqid('f', true),
            ])->assertStatus(422);
        $this->assertSame(5, (int) $foreign->fresh()->quantity);

        // وجارٌ لا يرى صنفَ متجرٍ آخر ولو أُغلقت قناتُه هنا
        $neighbour = User::create([
            'business_id' => $other->id, 'name' => 'الجار', 'email' => 'n@channels.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->actingAs($neighbour)->get(route('admin.products.edit', $this->rose->id))->assertNotFound();
    }
}
