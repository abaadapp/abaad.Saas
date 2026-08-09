<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\PosDevice;
use App\Models\PosPeripheral;
use App\Models\User;
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

    public function test_deleting_a_register_takes_its_peripherals_with_it(): void
    {
        // قيدٌ أجنبي cascade: ملحقٌ بلا صندوق صفٌّ يتيم لا يظهر في شاشة ولا يُحذف
        $this->device->peripherals()->create($this->payload() + ['business_id' => $this->business->id]);

        $this->device->delete();

        $this->assertDatabaseCount('pos_peripherals', 0);
    }
}
