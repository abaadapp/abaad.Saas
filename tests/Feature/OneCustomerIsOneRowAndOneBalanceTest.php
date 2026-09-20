<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PointTransaction;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * العميلُ الواحد صفٌّ واحد ورصيدٌ واحد — وما تقوله بطاقتُه تقوله صفوفُه.
 *
 * ═══ ثلاثةُ أعطابٍ في قسمٍ واحد ═══
 *
 *  • **الهاتف كان يُقابَل نصًّا لا رقمًا.** والقيدُ على تكراره مكتوبٌ لأنّ
 *    «سجلّين بالهاتف نفسه يعنيان شخصًا واحدًا برصيدَي نقاط». والناسُ يكتبون
 *    أرقامهم كما اعتادوا، فمرّ «9123 4567» و«91234567» و«+968 91234567»
 *    ثلاثةَ عملاءَ لشخصٍ واحد بلا رسالةٍ واحدة.
 *
 *  • **الاستيراد كان يكتب النقاط بلا حركة.** والنقطةُ مالٌ، وكلُّ بابٍ آخر
 *    يمسّها يكتب حركتَها. فكانت شاشةُ الولاء تعرض «مجموع النقاط» و«المكتسبة»
 *    و«المستبدَلة» — ثلاثةَ أرقامٍ لا يمكن أن تصدُق معًا.
 *
 *  • **بطاقاتُ العملاء كانت تقرأ جدولًا غيرَ جدول صفوفها.** حذفُ عميلٍ أنفق
 *    يُنقص العدّاد ولا يُنقص المشتريات، فيقفز «متوسط الإنفاق» فوق ما أنفقه
 *    كلُّ من تعرضهم الشاشة مجتمعين.
 */
class OneCustomerIsOneRowAndOneBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create(['name' => 'زهور مسقط', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function add(string $name, string $phone)
    {
        return $this->actingAs($this->owner)
            ->post(route('admin.customers.store'), ['language' => 'ar', 'name' => $name, 'phone' => $phone]);
    }

    /* ═════════════ الرقمُ رقمٌ مهما كُتب ═════════════ */

    /** @return list<array{0: string, 1: string}> */
    public static function sameNumberWrittenOtherwise(): array
    {
        return [
            'بمسافة' => ['9123 4567'],
            'بمفتاح الدولة' => ['+968 91234567'],
            'بصفرين دوليّين' => ['0096891234567'],
            'بشرطة' => ['9123-4567'],
            'بأرقام عربية' => ['٩١٢٣٤٥٦٧'],
        ];
    }

    #[DataProvider('sameNumberWrittenOtherwise')]
    public function test_the_same_number_written_otherwise_is_refused(string $other): void
    {
        $this->add('سالم', '91234567')->assertSessionHasNoErrors();

        $this->add('سالم مرّةً أخرى', $other)->assertSessionHasErrors('phone');

        $this->assertSame(1, Customer::where('business_id', $this->shop->id)->count(),
            'شخصٌ واحد صار صفّين — ورصيدُ نقاطه انقسم');
    }

    public function test_a_different_number_is_still_accepted(): void
    {
        $this->add('سالم', '91234567')->assertSessionHasNoErrors();
        $this->add('نورة', '99887766')->assertSessionHasNoErrors();

        $this->assertSame(2, Customer::where('business_id', $this->shop->id)->count(),
            'التطبيع خلط رقمين مختلفين فمنع عميلًا صحيحًا');
    }

    public function test_the_message_names_the_customer_when_the_shape_differs(): void
    {
        $this->add('سالم بن علي', '91234567');

        $this->add('سالم', '+968 91234567')->assertSessionHasErrors('phone');

        $said = session()->get('errors')->getBag('default')->first('phone');

        $this->assertStringContainsString('سالم بن علي', $said,
            'رسالةٌ تقول «مسجَّل لعميل آخر» عن رقمٍ يراه الكاتبُ مختلفًا لا تدلّ على شيء');
        $this->assertStringContainsString('91234567', $said);
    }

    public function test_a_customer_keeps_his_own_number_on_edit(): void
    {
        $this->add('سالم', '9123 4567');
        $customer = Customer::where('business_id', $this->shop->id)->firstOrFail();

        $this->actingAs($this->owner)->put(route('admin.customers.update', $customer->id), [
            'language' => 'ar',
            'name' => 'سالم بن علي', 'phone' => '91234567',
        ])->assertSessionHasNoErrors();

        $this->assertSame('91234567', $customer->fresh()->phone,
            'العميلُ مُنع من تصحيح كتابة رقمه لأنّه رقمُه');
    }

    public function test_a_number_that_does_not_normalise_is_still_guarded(): void
    {
        $this->add('تحويلة', '101')->assertSessionHasNoErrors();

        $this->add('تحويلة ثانية', '101')->assertSessionHasErrors('phone');

        $this->assertSame(1, Customer::where('business_id', $this->shop->id)->count(),
            'حراسةٌ كانت قائمةً أمسِ سقطت اليوم');
    }

    public function test_a_deleted_customer_is_still_found_by_his_number_however_written(): void
    {
        $this->add('سالم', '91234567');
        $customer = Customer::where('business_id', $this->shop->id)->firstOrFail();
        $this->actingAs($this->owner)->delete(route('admin.customers.destroy', $customer->id));

        $this->add('سالم', '+968 9123 4567')->assertSessionHasErrors('phone');

        $this->assertStringContainsString('محذوف', session()->get('errors')->getBag('default')->first('phone'),
            'رقمُ عميلٍ محذوف مرّ بشكلٍ آخر — وتُستعاد نسختُه يومًا فيصير شخصٌ واحد صفّين');
    }

    /** والمتجرُ الجار لا يُقيَّد برقمٍ عند جاره */
    public function test_another_shop_may_use_the_same_number(): void
    {
        $this->add('سالم', '91234567')->assertSessionHasNoErrors();

        $other = Business::create(['name' => 'متجر آخر', 'type' => 'عام', 'status' => 'نشط']);
        $neighbour = User::create([
            'business_id' => $other->id, 'name' => 'جار', 'email' => 'n@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($neighbour)->post(route('admin.customers.store'), [
            'language' => 'ar',
            'name' => 'سالم', 'phone' => '+968 91234567',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Customer::where('business_id', $other->id)->count());
    }

    /* ═════════════ رصيدُ نقاطٍ يتغيّر يقول من غيّره ═════════════ */

    private function import(string $csv): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($this->owner)->post(route('admin.customers.import.upload'), [
            'file' => new UploadedFile($path, 'عملاء.csv', 'text/csv', null, true),
        ])->assertRedirect();

        $this->actingAs($this->owner)->post(route('admin.customers.import.confirm'))->assertRedirect();
    }

    /** مجموعُ الحركات يجب أن يُعيد بناء الأرصدة */
    private function pointsGap(): int
    {
        $balances = (int) Customer::where('business_id', $this->shop->id)->sum('points');
        $moved = (int) PointTransaction::where('business_id', $this->shop->id)->sum('points');

        return $balances - $moved;
    }

    public function test_points_imported_for_a_new_customer_are_explained(): void
    {
        $this->import("الاسم,الهاتف,النقاط\nنورة,99887766,5000\n");

        $customer = Customer::where('business_id', $this->shop->id)->firstOrFail();
        $this->assertSame(5000, (int) $customer->points, 'الاستيراد لم يعد يكتب الرصيد');
        $this->assertSame(0, $this->pointsGap(), 'رصيدٌ لا تُفسّره حركةٌ واحدة');

        $move = PointTransaction::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('earn', $move->type);
        $this->assertSame(5000, (int) $move->points);
        $this->assertSame(5000, (int) $move->balance_after);
        $this->assertStringContainsString('عملاء.csv', (string) $move->note, 'الحركةُ لا تقول من أين جاءت');
    }

    public function test_raising_a_balance_by_import_records_only_the_difference(): void
    {
        $customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'نورة', 'phone' => '99887766', 'points' => 100,
        ]);
        PointTransaction::record($customer, 'earn', 100, 100, null, 'بيع');

        $this->import("الاسم,الهاتف,النقاط\nنورة,99887766,450\n");

        $this->assertSame(450, (int) $customer->fresh()->points);
        $this->assertSame(0, $this->pointsGap());
        $this->assertSame(350, (int) PointTransaction::where('customer_id', $customer->id)
            ->orderByDesc('id')->value('points'), 'كُتب الرصيدُ كلُّه حركةً بدل الفارق');
    }

    public function test_lowering_a_balance_by_import_is_recorded_as_a_withdrawal(): void
    {
        $customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'نورة', 'phone' => '99887766', 'points' => 900,
        ]);
        PointTransaction::record($customer, 'earn', 900, 900, null, 'بيع');

        $this->import("الاسم,الهاتف,النقاط\nنورة,99887766,200\n");

        $this->assertSame(200, (int) $customer->fresh()->points);
        $this->assertSame(0, $this->pointsGap());

        $last = PointTransaction::where('customer_id', $customer->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame('redeem', $last->type);
        $this->assertSame(-700, (int) $last->points);
    }

    public function test_a_file_that_never_mentions_points_moves_nothing(): void
    {
        $customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => 'نورة', 'phone' => '99887766', 'points' => 300,
        ]);
        PointTransaction::record($customer, 'earn', 300, 300, null, 'بيع');

        $this->import("الاسم,الهاتف\nنورة,99887766\n");

        $this->assertSame(300, (int) $customer->fresh()->points, 'ملفٌّ لا يذكر النقاط محاها');
        $this->assertSame(1, PointTransaction::where('customer_id', $customer->id)->count(),
            'حركةٌ كُتبت بلا تغيّرٍ في الرصيد');
    }

    /* ═════════════ البطاقةُ تقرأ ما تقرؤه صفوفُها ═════════════ */

    private function spender(string $name, string $phone, float $total): Customer
    {
        $customer = Customer::create([
            'business_id' => $this->shop->id, 'name' => $name, 'phone' => $phone,
        ]);

        Order::create([
            'business_id' => $this->shop->id, 'customer_id' => $customer->id,
            'number' => 'ORD-'.$customer->id, 'status' => 'مكتمل', 'payment_status' => 'مدفوع',
            'subtotal' => $total, 'total' => $total, 'ordered_at' => now(),
        ]);

        return $customer;
    }

    public function test_deleting_a_spender_leaves_the_cards_agreeing_with_the_rows(): void
    {
        $big = $this->spender('كبير', '91111111', 1000);
        $this->spender('صغير', '92222222', 200);

        $this->actingAs($this->owner)->delete(route('admin.customers.destroy', $big->id));

        $props = $this->actingAs($this->owner)
            ->get(route('admin.customers.index'))->viewData('page')['props'];

        $rows = round(collect($props['customers'])->sum('total_spent'), 3);
        $stats = Demo::customerStats();

        $this->assertSame(200.0, $rows);
        $this->assertSame(200.0, round((float) $stats['total_purchases'], 3),
            'البطاقةُ تقرأ جدولًا غيرَ جدول صفوفها');
        $this->assertSame(200.0, round((float) $stats['avg_spend'], 3),
            'متوسّطٌ للفرد أكبرُ ممّا أنفقه كلُّ من تعرضهم الشاشة مجتمعين');
    }

    public function test_nothing_changes_while_nobody_is_deleted(): void
    {
        $this->spender('كبير', '91111111', 1000);
        $this->spender('صغير', '92222222', 200);

        $this->actingAs($this->owner);
        $stats = Demo::customerStats();

        $this->assertSame(1200.0, round((float) $stats['total_purchases'], 3));
        $this->assertSame(600.0, round((float) $stats['avg_spend'], 3));
    }

    public function test_a_walk_in_sale_is_not_counted_as_a_customers_spending(): void
    {
        $this->spender('صغير', '92222222', 200);

        Order::create([
            'business_id' => $this->shop->id, 'customer_id' => null, 'customer_name' => 'عميل نقدي',
            'number' => 'ORD-CASH', 'status' => 'مكتمل', 'payment_status' => 'مدفوع',
            'subtotal' => 5000, 'total' => 5000, 'ordered_at' => now(),
        ]);

        $this->actingAs($this->owner);

        $this->assertSame(200.0, round((float) Demo::customerStats()['total_purchases'], 3),
            'بيعةٌ نقديّة بلا عميل حُسبت إنفاقَ العملاء');
    }
}
