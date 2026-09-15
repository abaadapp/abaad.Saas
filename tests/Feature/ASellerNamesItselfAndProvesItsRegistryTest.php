<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Document\PaperSize;
use App\Support\Document\Snapshot;
use App\Support\DocumentRenderer;
use App\Support\DocumentTemplates;
use App\Support\Paper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * البائعُ يقول من هو ويُثبته — باسمٍ بلغة ورقته، وبسجلٍّ تجاريّ.
 *
 * ═══ ما يحرسه هذا الملفّ ═══
 *
 *  • السجلُّ يُطبع إن أُدخل، ولا يُطبع سطرًا فارغًا إن لم يُدخل.
 *  • ويُجمَّد يومَ تُختم الورقة: ورقةُ أمسٍ لا تحمل سجلَّ اليوم.
 *  • وورقةٌ خُتمت **قبل** وجود الحقل تبقى على حالها ولا تُختلق لها قيمة —
 *    وهذا هو الحارسُ الذي يُقتل إن رُفع `Snapshot::VERSION` بلا احتمالِ
 *    الأشكال القديمة.
 *  • والاسمُ يتبع لغةَ الورقة لا لغةَ من ضغط الزرّ.
 */
class ASellerNamesItselfAndProvesItsRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->shop = Business::create([
            'name' => 'زهور الخليج', 'name_en' => 'Gulf Flowers LLC',
            'type' => 'محل ورود', 'city' => 'مسقط', 'status' => 'نشط',
            'address' => 'شارع السلطان قابوس', 'phone' => '+968 9123 4567', 'email' => 'shop@x.om',
        ]);
        $this->branch = Branch::create(['business_id' => $this->shop->id, 'name' => 'الفرع الرئيسي']);
        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Setting::create(['business_id' => $this->shop->id, 'key' => 'paper', 'value' => 'A4']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '1']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_number', 'value' => 'OM1100234567']);
    }

    private function registry(string $value): void
    {
        Setting::updateOrCreate(
            ['business_id' => $this->shop->id, 'key' => 'cr_number'],
            ['value' => $value],
        );
    }

    private function order(string $number, bool $stamp = false, ?array $snapshot = null): Order
    {
        $attrs = [
            'business_id' => $this->shop->id, 'branch_id' => $this->branch->id,
            'branch' => $this->branch->name, 'number' => $number, 'status' => 'مكتمل',
            'customer_name' => 'شركة الواحة', 'employee_name' => 'سالم', 'payment_method' => 'نقدي',
            'subtotal' => 12.5, 'tax' => 0.625, 'total' => 13.125, 'ordered_at' => now(),
        ];

        if ($snapshot !== null) {
            $attrs[Snapshot::COLUMN] = $snapshot;
        } elseif ($stamp) {
            $attrs[Snapshot::COLUMN] = Snapshot::capture((int) $this->shop->id);
        }

        $order = Order::create($attrs);
        OrderItem::create([
            'order_id' => $order->id, 'name' => 'باقة ورد', 'price' => 12.5, 'quantity' => 1, 'total' => 12.5,
        ]);

        return $order->load('items');
    }

    private function sheet(Order $order): string
    {
        $v = DocumentTemplates::settings((int) $this->shop->id, 'sale');
        $v['paper'] = PaperSize::A4;
        $v['show_vat_no'] = true;

        return DocumentRenderer::saleSheet((int) $this->shop->id, $order, $v);
    }

    /* ═══════════ السجلُّ التجاريّ ═══════════ */

    /** ما أُدخل يُطبع — بالرقم وبتسميةٍ تُقرأ، لا رقمًا عائمًا */
    public function test_the_paper_prints_the_registry_number_the_shop_entered(): void
    {
        $this->actingAs($this->owner);
        $this->registry('1031484');

        $html = $this->sheet($this->order('INV-000001'));

        $this->assertStringContainsString('1031484', $html);
        $this->assertStringContainsString('السجل التجاري', $html);
    }

    /**
     * ومن لم يُدخله لا يُطبع له سطرٌ فارغ.
     *
     * حقلٌ مسمّى بلا قيمةٍ على فاتورةٍ رسميّة يقول للقارئ «هنا رقمٌ ولم
     * يُكتب» — وهو أسوأُ من ألّا يُذكر: يُقرأ نقصًا في الورقة لا نقصًا في
     * الإعداد.
     */
    public function test_a_shop_with_no_registry_gets_no_empty_row(): void
    {
        $this->actingAs($this->owner);

        $html = $this->sheet($this->order('INV-000002'));

        $this->assertStringNotContainsString('السجل التجاري', $html);
        // والرقمُ الضريبيّ يبقى: غيابُ أحدهما لا يُسقط الآخر
        $this->assertStringContainsString('OM1100234567', $html);
    }

    /** ورقةٌ خُتمت تحمل سجلَّ يومها لا سجلَّ اليوم */
    public function test_the_registry_is_frozen_the_day_the_paper_issues(): void
    {
        $this->actingAs($this->owner);
        $this->registry('1031484');

        $stamped = $this->order('INV-000003', stamp: true);
        $this->assertStringContainsString('1031484', $this->sheet($stamped));

        $this->registry('9999999');

        $after = $this->sheet($stamped->fresh('items'));
        $this->assertStringContainsString('1031484', $after);
        $this->assertStringNotContainsString('9999999', $after);
    }

    /**
     * وورقةٌ خُتمت قبل أن يوجد الحقلُ لا يُختلق لها سجلّ.
     *
     * لقطتُها بالشكل الأوّل: فيها بائعٌ وضريبةٌ ولا `cr`. والصوابُ أن تُطبع
     * بلا سجلّ — لا أن يُقرأ سجلُّ المتجر اليوم فيُوضع على ورقةٍ لم تحمله.
     */
    public function test_a_paper_stamped_before_the_field_existed_prints_no_registry(): void
    {
        $this->actingAs($this->owner);
        $this->registry('1031484');

        $old = $this->order('INV-000004', snapshot: $this->legacySnapshot());

        $html = $this->sheet($old);

        $this->assertStringNotContainsString('1031484', $html);
        $this->assertStringNotContainsString('السجل التجاري', $html);
    }

    /**
     * ولقطتُها تبقى مقروءةً بعد رفع رقم الشكل.
     *
     * ═══ وهذا الحارسُ يُقتل بسطرٍ واحد ═══
     *
     * كانت `Snapshot::of` تقبل `v === VERSION` وحدها. فرفعُ الرقم إلى ٢
     * كان يجعل كلَّ لقطةٍ بالشكل الأوّل «مجهولة»، فتُقرأ من المتجر الحيّ —
     * وتعود فاتورةُ أمسٍ تحمل اسمَ اليوم ورقمَه. وهو العطبُ الذي وُجدت
     * اللقطةُ لتمنعه، يعود من بابها هي.
     */
    public function test_a_paper_stamped_before_the_field_existed_keeps_its_own_seller(): void
    {
        $this->actingAs($this->owner);

        $old = $this->order('INV-000005', snapshot: $this->legacySnapshot());

        $this->shop->update(['name' => 'زهور الخليج الدولية']);
        Setting::where('business_id', $this->shop->id)
            ->where('key', 'vat_number')->update(['value' => 'OM9999999999']);

        $html = $this->sheet($old->fresh('items'));

        $this->assertStringContainsString('زهور الخليج', $html);
        $this->assertStringNotContainsString('الدولية', $html);
        $this->assertStringContainsString('OM1100234567', $html);
        $this->assertStringNotContainsString('OM9999999999', $html);
    }

    /** لقطةٌ بالشكل الأوّل — كما كانت تُكتب قبل أن يوجد حقلُ السجلّ */
    private function legacySnapshot(): array
    {
        $now = Snapshot::capture((int) $this->shop->id);
        $now['v'] = 1;
        unset($now['cr'], $now['seller']['name_en']);

        return $now;
    }

    /* ═══════════ الاسمُ بلغة الورقة ═══════════ */

    /** ورقةٌ إنجليزيّة تحمل الاسمَ الإنجليزيّ */
    public function test_an_english_paper_carries_the_english_name(): void
    {
        $this->actingAs($this->owner);
        app()->setLocale('en');

        $html = $this->sheet($this->order('INV-000006'));

        $this->assertStringContainsString('Gulf Flowers LLC', $html);
        $this->assertStringNotContainsString('زهور الخليج', $html);
    }

    /** ومن لم يُسجّل اسمًا إنجليزيًّا يُطبع اسمُه العربيّ — لا فراغ ولا ترجمة */
    public function test_an_english_paper_falls_back_to_the_arabic_name(): void
    {
        $this->actingAs($this->owner);
        $this->shop->update(['name_en' => null]);
        app()->setLocale('en');

        $html = $this->sheet($this->order('INV-000007'));

        $this->assertStringContainsString('زهور الخليج', $html);
    }

    /** وورقةٌ عربيّة تبقى على الاسم العربيّ وإن وُجد الإنجليزيّ */
    public function test_an_arabic_paper_keeps_the_arabic_name(): void
    {
        $this->actingAs($this->owner);

        $html = $this->sheet($this->order('INV-000008'));

        $this->assertStringContainsString('زهور الخليج', $html);
        $this->assertStringNotContainsString('Gulf Flowers LLC', $html);
    }

    /* ═══════════ البابُ الذي يُدخلهما ═══════════ */

    /**
     * الشاشةُ تكتب الاثنين في موضعيهما: الاسمُ عمودًا والسجلُّ إعدادًا.
     *
     * والاسمُ المُرسَل يخالف ما في `setUp` عمدًا: لو ساواه لَمرّ التأكيدُ
     * على قيمةٍ كتبها التجهيزُ لا البابُ — واختبارٌ يمرّ بلا أن يمرّ به
     * المُختبَر حارسٌ لا يحرس. أثبتته طفرةٌ نجت.
     */
    public function test_the_settings_door_writes_both_fields(): void
    {
        $this->assertSame('Gulf Flowers LLC', $this->shop->name_en);

        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), [
                'shop_name' => 'زهور الخليج',
                'shop_name_en' => 'Gulf Flowers International LLC',
                'cr_number' => '1031484',
            ])->assertRedirect();

        $this->assertSame('Gulf Flowers International LLC', $this->shop->fresh()->name_en);
        $this->assertSame('1031484', Paper::crNumber((int) $this->shop->id));
    }

    /** وما يجاوز العمودَ يُردّ برسالةٍ باسم الحقل لا بانكسارٍ في القاعدة */
    public function test_a_registry_number_beyond_the_column_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.settings.update'), [
                'shop_name' => 'زهور الخليج',
                'cr_number' => str_repeat('9', 31),
            ])->assertSessionHasErrors('cr_number');

        $this->assertSame('', Paper::crNumber((int) $this->shop->id));
    }

    /**
     * والاسمُ الثاني يُنسخ مع ملفّ المتجر ويُستعاد.
     *
     * `BACKUP_FIELDS` قائمةٌ تُكتب باليد، والقائمةُ المكتوبةُ باليد تنسى
     * التاليَ دائمًا. فحقلٌ يُضاف إلى الجدول ولا يُضاف إليها يُنسخ ملفُّ
     * المتجر بدونه صامتًا — ويُكتشف يومَ الاستعادة.
     */
    public function test_the_shop_profile_copy_carries_the_english_name(): void
    {
        $this->assertContains('name_en', Business::BACKUP_FIELDS);
    }
}
