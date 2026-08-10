<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JobTitle;
use App\Models\User;
use App\Rules\StrongPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * رمز الدخول لا يكون من أوّل ما يُجرَّب.
 *
 * فضاء الرموز عشرة آلاف، لكن من يخمّن لا يعدّ من 0000: يبدأ بـ1234 ثم 1111
 * ثم سنة ميلاد. والحدّان — ٥ في الدقيقة و٣٠ في الساعة — يكفيان لمسح هذه
 * القائمة القصيرة في يوم، فيصير الحدّ الذي يحرس الفضاء كلّه بلا أثرٍ على
 * الرمز الذي يقع أوّلها.
 */
class StrongPinTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        JobTitle::create(['business_id' => $this->business->id, 'name' => 'كاشير', 'role' => 'cashier']);

        $this->owner = User::create([
            'business_id' => $this->business->id,
            'name' => 'المالك',
            'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'نشط',
        ]);
    }

    /** @return array<string, array{string}> */
    public static function weakPins(): array
    {
        return [
            'الأشهر على الإطلاق' => ['1234'],
            'الرقم نفسه أربعًا' => ['1111'],
            'أصفار' => ['0000'],
            'تسلسل هابط' => ['4321'],
            'نصفان متطابقان' => ['1212'],
            'سنة ميلاد قريبة' => ['1995'],
            'سنة ميلاد من هذا القرن' => ['2004'],
        ];
    }

    #[DataProvider('weakPins')]
    public function test_a_guessable_pin_is_refused_when_it_is_chosen(string $pin): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.employees.store'), [
                'name' => 'كاشير',
                'job_title' => 'كاشير',
                'pin' => $pin,
            ])
            ->assertSessionHasErrors('pin');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_reasonable_pin_passes(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.employees.store'), [
                'name' => 'كاشير',
                'job_title' => 'كاشير',
                'pin' => '4739',
            ])
            ->assertSessionHasNoErrors();

        $cashier = User::where('name', 'كاشير')->firstOrFail();
        $this->assertTrue(Hash::check('4739', $cashier->getRawOriginal('pin')));
    }

    public function test_the_refusal_says_which_weakness_it_found(): void
    {
        /*
         * رسالةٌ واحدة لكل الأسباب تترك المستخدم يجرّب 1111 بعد 1234 ثم 2222،
         * فيقرأ الرفض عطبًا في الحقل لا حكمًا على اختياره.
         */
        $this->assertStringContainsString('شيوعًا', (string) StrongPin::weakness('1234'));
        $this->assertStringContainsString('متتابعة', (string) StrongPin::weakness('2345'));
        $this->assertStringContainsString('شيوعًا', (string) StrongPin::weakness('7777'));
        $this->assertStringContainsString('متطابقان', (string) StrongPin::weakness('3838'));
        $this->assertStringContainsString('ميلاد', (string) StrongPin::weakness('1990'));
        $this->assertNull(StrongPin::weakness('4739'));
    }

    public function test_editing_an_employee_obeys_the_same_rule(): void
    {
        // الباب الثاني: من مرّ من الإنشاء برمزٍ قويّ لا يعود إلى ضعيفٍ بالتعديل
        $this->actingAs($this->owner)
            ->post(route('admin.employees.store'), [
                'name' => 'كاشير',
                'job_title' => 'كاشير',
                'pin' => '4739',
            ]);

        $cashier = User::where('name', 'كاشير')->firstOrFail();

        $this->actingAs($this->owner)
            ->put(route('admin.employees.update', $cashier->id), [
                'name' => 'كاشير',
                'job_title' => 'كاشير',
                'pin' => '1234',
            ])
            ->assertSessionHasErrors('pin');

        $this->assertTrue(Hash::check('4739', $cashier->fresh()->getRawOriginal('pin')));
    }

    public function test_the_year_test_reads_the_start_not_a_digit_anywhere(): void
    {
        // 1997 سنة فتُرفض، و3197 تحمل الأرقام نفسها وليست سنة فتُقبل
        $this->assertNotNull(StrongPin::weakness('1997'));
        $this->assertNull(StrongPin::weakness('3197'));
    }

    public function test_what_it_forbids_stays_a_sliver_of_the_space(): void
    {
        /*
         * حارسٌ على الحارس: توسيع القائمة سهلٌ ومغرٍ، وكل توسيعٍ يضيّق ما
         * يختار منه الموظّف. فإن تجاوز الممنوع خُمس الفضاء صار المنع هو
         * المشكلة — يُعاد التفكير لا تُعدَّل هذه العتبة.
         */
        $weak = 0;
        for ($i = 0; $i < 10000; $i++) {
            if (StrongPin::weakness(str_pad((string) $i, 4, '0', STR_PAD_LEFT)) !== null) {
                $weak++;
            }
        }

        // ٣١٦ اليوم — والعتبة عند العُشر: بعده يصير المنع هو المشكلة،
        // فيُعاد التفكير في القاعدة لا تُرفع هذه العتبة
        $this->assertLessThan(1000, $weak, "الممنوع {$weak} رمزًا من عشرة آلاف");
        $this->assertGreaterThan(100, $weak, 'القائمة لا تحرس شيئًا');
    }
}
