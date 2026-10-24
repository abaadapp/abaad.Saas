<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\User;
use App\Support\TillHealth;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الأجهزة الملحقة بصندوق البيع.
 *
 * وأخطرها ليس الإضافة بل الحدود: ملحقٌ يتبع صندوقًا يتبع متجرًا، فمعرّفٌ
 * صحيح تحت جهاز الجار يجب ألّا يفتح شيئًا. والكاشير لا يبدّل طابعة صندوقه:
 * من يفعل يوجّه إيصالات فرعٍ إلى ورق فرعٍ آخر.
 */
class PosPeripheralTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private PosDevice $device;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'مدير', 'role' => 'admin']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'سالم', 'email' => 'salem@abaad.om',
            'role' => 'admin', 'job_title' => 'مدير', 'status' => 'نشط', 'password' => 'x',
        ]);

        $this->device = $this->activatePosDevice($this->business->id);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'طابعة الكاشير',
            'type' => PosPeripheral::PRINTER,
            'connection' => 'usb',
        ], $over);
    }

    public function test_a_manager_adds_a_printer_to_a_register(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.devices.peripherals.store', $this->device->id), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('pos_peripherals', [
            'pos_device_id' => $this->device->id,
            'business_id' => $this->business->id,
            'name' => 'طابعة الكاشير',
            'paper_width' => 80,
        ]);
    }

    /**
     * و«طباعة فواتير العملاء المسددة» لا يُشعلها التاجرُ لنفسه — تتبع مفتاحَ المنصّة.
     */
    public function test_a_printer_takes_paid_customer_invoices_only_where_the_platform_opened_it(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.devices.peripherals.store', $this->device->id), $this->payload(['auto_print_customer_invoices' => true]))
            ->assertRedirect();
        $this->assertFalse((bool) PosPeripheral::latest('id')->value('auto_print_customer_invoices'), 'مغلقًا يُكتب «لا» أيًّا أُرسل');

        $this->business->forceFill(['customer_invoice_preparation_enabled' => true])->save();
        $this->actingAs($this->owner)
            ->post(route('admin.devices.peripherals.store', $this->device->id), $this->payload(['name' => 'طابعة التجهيز', 'auto_print_customer_invoices' => true]))
            ->assertRedirect();
        $this->assertTrue((bool) PosPeripheral::latest('id')->value('auto_print_customer_invoices'));

        // والماسحُ لا يطبع — فلا يحمل الخيار
        $this->actingAs($this->owner)
            ->post(route('admin.devices.peripherals.store', $this->device->id), $this->payload(['name' => 'ماسح', 'type' => PosPeripheral::TYPES[1], 'auto_print_customer_invoices' => true]))
            ->assertRedirect();
        $this->assertFalse((bool) PosPeripheral::latest('id')->value('auto_print_customer_invoices'));
    }

    public function test_a_cashier_cannot_touch_the_registers_hardware(): void
    {
        /*
         * صلاحية «نقطة البيع» تفتح الشاشة ولا تكفي لتبديل عتاد الصندوق:
         * من يبدّل الطابعة يوجّه إيصالات فرعٍ إلى ورق فرعٍ آخر.
         */
        $cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'أحمد', 'email' => 'ahmad@abaad.om',
            'role' => 'cashier', 'job_title' => 'كاشير', 'status' => 'نشط', 'password' => 'x',
        ]);

        $this->actingAs($cashier)
            ->post(route('admin.devices.peripherals.store', $this->device->id), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('pos_peripherals', 0);
    }

    public function test_a_neighbours_register_is_not_reachable_by_id(): void
    {
        $neighbour = Business::create(['name' => 'متجر الجار', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $neighbour->id, 'name' => 'الرئيسي']);
        $theirs = $this->activatePosDevice($neighbour->id);

        $this->actingAs($this->owner)
            ->post(route('admin.devices.peripherals.store', $theirs->id), $this->payload())
            ->assertNotFound();

        $this->assertDatabaseCount('pos_peripherals', 0);
    }

    public function test_a_peripheral_of_another_register_is_not_reachable_through_mine(): void
    {
        // معرّف ملحقٍ صحيح، لكن تحت جهازٍ ليس جهازه: القيد على الاثنين معًا
        $other = $this->activatePosDevice($this->business->id);
        $p = $other->peripherals()->create($this->payload() + ['business_id' => $this->business->id]);

        $this->actingAs($this->owner)
            ->delete(route('admin.devices.peripherals.destroy', [$this->device->id, $p->id]))
            ->assertNotFound();

        $this->assertDatabaseCount('pos_peripherals', 1);
    }

    public function test_a_network_peripheral_must_carry_an_address(): void
    {
        // «شبكة» بلا عنوان سطرٌ لا يفيد من يأتي يصلح العطل
        $this->actingAs($this->owner)
            ->post(route('admin.devices.peripherals.store', $this->device->id),
                $this->payload(['connection' => 'network']))
            ->assertSessionHasErrors('address');
    }

    public function test_switching_away_from_network_clears_the_stale_address(): void
    {
        /*
         * طابعة شبكة نُقلت إلى USB: لو بقي العنوان القديم لقرأه أحدهم يومًا
         * وذهب يبحث عن جهازٍ على عنوانٍ لم يعد له معنى.
         */
        $this->actingAs($this->owner)
            ->post(route('admin.devices.peripherals.store', $this->device->id),
                $this->payload(['connection' => 'network', 'address' => '192.168.1.50', 'port' => 9100]));

        $p = PosPeripheral::first();
        $this->assertSame('192.168.1.50', $p->address);

        $this->actingAs($this->owner)
            ->put(route('admin.devices.peripherals.update', [$this->device->id, $p->id]),
                $this->payload(['connection' => 'usb']));

        $this->assertNull($p->fresh()->address);
        $this->assertNull($p->fresh()->port);
    }

    public function test_printer_only_settings_do_not_survive_a_type_change(): void
    {
        // طابعةٌ صارت ماسحًا وما زالت تحمل «طباعة تلقائية» تُقرأ يومًا بلا معنى
        $this->actingAs($this->owner)
            ->post(route('admin.devices.peripherals.store', $this->device->id),
                $this->payload(['auto_print' => true, 'paper_width' => 58]));

        $p = PosPeripheral::first();
        $this->assertTrue($p->auto_print);

        $this->actingAs($this->owner)
            ->put(route('admin.devices.peripherals.update', [$this->device->id, $p->id]),
                $this->payload(['type' => PosPeripheral::SCANNER]));

        $this->assertFalse($p->fresh()->auto_print);
        $this->assertNull($p->fresh()->paper_width);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        // قائمة مغلقة: «طابعه» بهاء لا تُقبل، وإلا تعدّدت التسميات ولم تُقرأ آليًّا
        $this->actingAs($this->owner)
            ->post(route('admin.devices.peripherals.store', $this->device->id),
                $this->payload(['type' => 'طابعه']))
            ->assertSessionHasErrors('type');
    }

    public function test_the_pos_only_receives_the_active_peripherals_of_its_own_register(): void
    {
        /*
         * ملحقٌ عُطِّل لأنه معطوب يجب ألّا تطبع عليه نقطة البيع، وملحق صندوقٍ
         * آخر في الفرع نفسه ليس ملحق هذا الصندوق.
         */
        $this->device->peripherals()->create($this->payload() + ['business_id' => $this->business->id]);
        $this->device->peripherals()->create($this->payload([
            'name' => 'طابعة معطوبة', 'active' => false,
        ]) + ['business_id' => $this->business->id]);

        // صندوقٌ آخر يُنشأ مباشرةً: activatePosDevice يبدّل كوكي الجهاز،
        // فيصير الطلب صادرًا عنه هو، وهو عكس ما نقيسه
        $another = PosDevice::create([
            'business_id' => $this->business->id,
            'branch_id' => $this->device->branch_id,
            'name' => 'صندوق التغليف',
            'token_hash' => hash('sha256', 'other-token'),
            'status' => PosDevice::ACTIVE,
            'activated_at' => now(),
        ]);
        $another->peripherals()->create($this->payload(['name' => 'طابعة صندوق آخر'])
            + ['business_id' => $this->business->id]);

        $this->actingAs($this->owner)
            ->get(route('admin.devices.index'))
            ->assertInertia(fn ($page) => $page
                ->where('context.peripherals', fn ($list) => collect($list)->pluck('name')->all() === ['طابعة الكاشير']));
    }

    /**
     * تطبيقُ أبعاد يطبع على طابعة الشبكة مباشرةً — فيحتاج طريقَها كاملًا.
     *
     * كان الصندوقُ يُعطى عرضَ الورق والطباعةَ التلقائية وحدهما، فلا يعرف
     * التطبيقُ أين الطابعة. والملاحظاتُ لا تُرسل: ليست ممّا يحتاجه الصندوق.
     */
    public function test_the_pos_receives_the_printers_full_network_route(): void
    {
        $printer = $this->device->peripherals()->create($this->payload([
            'connection' => 'network', 'model' => 'TM-80', 'address' => '192.168.1.50', 'port' => 9101,
            'paper_width' => 58, 'auto_print' => true, 'auto_print_website_confirm' => true,
            'notes' => 'خلف المنضدة — كلمة سرّ الراوتر 1234',
        ]) + ['business_id' => $this->business->id]);

        $this->actingAs($this->owner)
            ->get(route('admin.devices.index'))
            ->assertInertia(fn ($page) => $page->where('context.peripherals', fn ($list) => collect($list)->all() === [[
                'id' => $printer->id,
                // طابعتُه الوحيدة — فهي طابعةُ الإيصال (`PosPeripheral::receiptAmong`)
                'receipt' => true,
                'type' => PosPeripheral::PRINTER,
                'name' => 'طابعة الكاشير',
                'model' => 'TM-80',
                'connection' => 'network',
                'address' => '192.168.1.50',
                'port' => 9101,
                'paperWidth' => 58,
                'autoPrint' => true,
                'autoPrintWebsite' => true,
                // وفواتيرُ العملاء المسدَّدة — مطفأةٌ ما لم تُشعَل (`PrintJobs`)
                'autoPrintCustomerInvoices' => false,
            ]]));
    }

    public function test_a_usb_printer_carries_no_network_route_even_if_one_lingers_in_the_row(): void
    {
        // صفٌّ قديمٌ كُتب قبل أن يُصفِّر المتحكّمُ العنوان — لا يصل منه شيء
        $this->device->peripherals()->create($this->payload([
            'connection' => 'usb', 'address' => '192.168.1.50', 'port' => 9100,
        ]) + ['business_id' => $this->business->id]);

        $this->actingAs($this->owner)
            ->get(route('admin.devices.index'))
            ->assertInertia(fn ($page) => $page
                ->where('context.peripherals.0.connection', 'usb')
                ->where('context.peripherals.0.address', null)
                ->where('context.peripherals.0.port', null));
    }

    public function test_deleting_a_register_takes_its_peripherals_with_it(): void
    {
        // قيدٌ أجنبي cascade: ملحقٌ بلا صندوق صفٌّ يتيم لا يظهر في شاشة ولا يُحذف
        $this->device->peripherals()->create($this->payload() + ['business_id' => $this->business->id]);

        $this->device->delete();

        $this->assertDatabaseCount('pos_peripherals', 0);
    }

    /* ═══════════ طابعةُ الإيصال — اختيارٌ صريحٌ واحد لكلّ صندوق ═══════════ */

    private function printerRow(array $over): PosPeripheral
    {
        return $this->device->peripherals()->create($this->payload($over) + ['business_id' => $this->business->id]);
    }

    private function receipt(): ?PosPeripheral
    {
        return PosPeripheral::receiptAmong($this->device->peripherals()->get());
    }

    /**
     * طابعةٌ أُضيفت مرّتين («USB» ثمّ «شبكة»): يُطبع على ما سمّاه التاجر — لا
     * على «أوّل صفّ» يقرؤه PostgreSQL، ولا على ما عليه الطباعةُ التلقائية.
     */
    public function test_the_named_receipt_printer_wins_over_an_older_duplicate(): void
    {
        $this->printerRow(['name' => 'قديمة USB', 'connection' => 'usb']);
        $network = $this->printerRow(['name' => 'شبكة', 'connection' => 'network', 'address' => '192.168.1.50', 'is_receipt_printer' => true]);

        $this->assertTrue($network->is($this->receipt()));

        $this->actingAs($this->owner)->get(route('admin.devices.index'))
            ->assertInertia(fn ($page) => $page
                ->where('context.peripherals', fn ($list) => collect($list)->where('receipt', true)->pluck('name')->all() === ['شبكة'])
                ->where('devices.0.peripherals', fn ($list) => collect($list)->where('receipt', true)->pluck('name')->all() === ['شبكة']));
    }

    /**
     * ولم يُسمِّ — طابعتان مفعّلتان: يُطبع على الأقدم كما كان «أوّلُ صفّ»،
     * ولا تأخذ الطباعةُ التلقائيةُ مكانَ الاختيار. والالتباسُ يُقال في الشاشة.
     */
    public function test_an_unnamed_pair_prints_on_the_oldest_and_says_it_is_ambiguous(): void
    {
        $old = $this->printerRow(['name' => 'USB', 'connection' => 'usb']);
        $this->printerRow(['name' => 'شبكة', 'connection' => 'network', 'address' => '10.0.0.7', 'auto_print' => true]);

        $this->assertTrue($old->is($this->receipt()));

        $this->actingAs($this->owner)->get(route('admin.devices.index'))
            ->assertInertia(fn ($page) => $page
                ->where('devices.0.issues', fn ($issues) => collect($issues)->pluck('code')->sort()->values()->all()
                    === [TillHealth::AMBIGUOUS_RECEIPT, TillHealth::AUTO_PRINT_ELSEWHERE]));
    }

    public function test_a_disabled_named_printer_falls_back_and_another_kind_never_prints(): void
    {
        $this->printerRow(['name' => 'المسمّاة', 'is_receipt_printer' => true, 'active' => false]);
        $this->printerRow(['name' => 'ماسح', 'type' => PosPeripheral::SCANNER]);
        $this->assertNull($this->receipt());

        $usb = $this->printerRow(['name' => 'USB']);
        $this->assertTrue($usb->is($this->receipt()));
    }

    /** والأولى على صندوقٍ بلا طابعة تُسمّى وحدَها — والثانيةُ لا تأخذ مكانها صامتة */
    public function test_the_first_printer_is_named_and_a_second_is_not(): void
    {
        $post = fn (array $over) => $this->actingAs($this->owner)
            ->post(route('admin.devices.peripherals.store', $this->device->id), $this->payload($over));

        $post(['name' => 'الأولى'])->assertSessionHasNoErrors();
        $post(['name' => 'الثانية'])->assertSessionHasNoErrors();

        $this->assertSame(['الأولى' => true, 'الثانية' => false],
            PosPeripheral::orderBy('id')->pluck('is_receipt_printer', 'name')->map(fn ($v) => (bool) $v)->all());
    }

    /** وتسميةُ أخرى ترفعها عن السابقة — واحدةٌ لكلّ صندوق */
    public function test_naming_another_printer_moves_the_mark(): void
    {
        $first = $this->printerRow(['name' => 'الأولى', 'is_receipt_printer' => true]);
        $second = $this->printerRow(['name' => 'الثانية']);

        $this->actingAs($this->owner)
            ->put(route('admin.devices.peripherals.update', [$this->device->id, $second->id]), $this->payload([
                'name' => 'الثانية', 'is_receipt_printer' => true,
            ]))->assertSessionHasNoErrors();

        $this->assertFalse((bool) $first->fresh()->is_receipt_printer);
        $this->assertTrue((bool) $second->fresh()->is_receipt_printer);
    }

    /** وصفحةٌ أقدمُ من الحقل لا تمحو التسميةَ بغيابه */
    public function test_a_save_without_the_field_keeps_the_mark(): void
    {
        $named = $this->printerRow(['name' => 'المسمّاة', 'is_receipt_printer' => true]);

        $this->actingAs($this->owner)
            ->put(route('admin.devices.peripherals.update', [$this->device->id, $named->id]), $this->payload(['name' => 'اسمٌ جديد']))
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $named->fresh()->is_receipt_printer);
    }

    /** والماسحُ لا يكون طابعةَ إيصال — ولو أُرسل */
    public function test_only_a_printer_can_be_named(): void
    {
        $this->actingAs($this->owner)->post(route('admin.devices.peripherals.store', $this->device->id), $this->payload([
            'type' => PosPeripheral::SCANNER, 'is_receipt_printer' => true,
        ]))->assertSessionHasNoErrors();

        $this->assertFalse((bool) PosPeripheral::sole()->is_receipt_printer);
    }

    /** والقاعدةُ نفسُها تمنع طابعتَي إيصالٍ على صندوقٍ واحد — لا الكودُ وحده */
    public function test_the_database_refuses_two_receipt_printers_on_one_register(): void
    {
        $this->printerRow(['name' => 'الأولى', 'is_receipt_printer' => true]);

        $this->expectException(QueryException::class);
        $this->printerRow(['name' => 'الثانية', 'is_receipt_printer' => true]);
    }

    /** وحذفُ الملحق يُسأل عنه في الواجهة؛ والخادمُ يحذف ملحقَ صندوقه وحده */
    public function test_deleting_a_printer_leaves_the_register_and_its_other_hardware(): void
    {
        $gone = $this->printerRow(['name' => 'مكرّرة']);
        $kept = $this->printerRow(['name' => 'الأصل']);

        $this->actingAs($this->owner)
            ->delete(route('admin.devices.peripherals.destroy', [$this->device->id, $gone->id]))
            ->assertSessionHas('toast.type', 'warning');

        $this->assertModelMissing($gone);
        $this->assertModelExists($kept);
        $this->assertModelExists($this->device);
    }
}
