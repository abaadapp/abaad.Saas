<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Support\CreditSales;
use App\Support\Permissions;
use App\Support\Receivables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حارسٌ يُتجاوَز بطلبٍ واحد ليس حارسًا.
 *
 * ═══ ما كان ═══
 *
 * `credit.override` — تجاوزُ حدّ ائتمان العميل في البيع الآجل — فعلٌ يُمنح
 * بالاسم، وبدوره للمالك ومدير الفرع وحدهما: «الكاشير لا يتجاوز حدًّا وضعه
 * صاحبُ المتجر — يطلبه ممّن وضعه».
 *
 * وشروطُ الائتمان نفسُها كانت تُكتب بقسم «العملاء» وحده، بلا فعلٍ يُمنح
 * باسمه. فمن لا يملك التجاوز:
 *
 * ١) يفتح «السماح بالبيع الآجل» لزبونٍ لم يُؤذن له بالدَّين — والقاعدةُ
 *    الأصل «لا آجلَ إلّا بإذن»؛
 * ٢) ويمحو حدَّ الائتمان، والفراغُ يُقرأ «بلا حدّ» في
 *    `Receivables::creditHeadroom`؛
 * ٣) ثمّ يبيع آجلًا بلا سقفٍ ولا سببٍ مكتوبٍ ولا سطرٍ في السجلّ.
 *
 * والبائعُ (`sales`) من هؤلاء: يملك «العملاء» ولا يملك التجاوز.
 *
 * ═══ وبابٌ في قسمٍ وشاشتُه في آخر ═══
 *
 * والنموذجُ الذي يرسل هذه الشروط يعيش في شاشة كشف الحساب، وهي تحت
 * «المالية». فاسمُ المسار كان `customers.credit` ويشتقّ الحارسُ منه قسم
 * «العملاء»: بابٌ وغرفةٌ بمفتاحين.
 *
 * فمن مُنح «المالية» ولا يملك «العملاء» يرى النموذج على شاشته ويُردّ عند
 * الحفظ بـ٤٠٣ — مقبضٌ مرسومٌ لا يُدير شيئًا، والموظّف يظنّ العطبَ في النظام
 * فيعيد المحاولة.
 */
class WhoSetsTheCeilingIsWhoExceedsItTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $owner;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);

        $this->owner = User::create([
            'business_id' => $this->business->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'وزارة', 'phone' => '96890000000',
            'allow_credit_sales' => false, 'credit_limit' => 100,
        ]);
    }

    private function staff(string $role, ?array $permissions = null): User
    {
        return User::create([
            'business_id' => $this->business->id, 'name' => $role, 'email' => $role.'@abaad.om',
            'password' => bcrypt('password'), 'role' => $role, 'status' => 'نشط',
            'permissions' => $permissions,
        ]);
    }

    /** @param array<string, mixed> $over */
    private function saveCredit(User $actor, array $over = [])
    {
        return $this->actingAs($actor)->put(route('admin.finance.customerCredit', $this->customer->id), array_merge([
            'allow_credit_sales' => true,
            'credit_limit' => '',
        ], $over));
    }

    /* ═══════════ من لا يتجاوز الحدَّ لا يضعه ═══════════ */

    public function test_a_seller_cannot_lift_the_ceiling_he_may_not_exceed(): void
    {
        $seller = $this->staff('sales');

        // ولا يملك التجاوز — وهو أصلُ الحكاية
        $this->assertFalse($seller->may(CreditSales::OVERRIDE));

        $this->saveCredit($seller)->assertForbidden();

        $this->customer->refresh();
        $this->assertFalse((bool) $this->customer->allow_credit_sales, 'فُتح الآجلُ لمن لم يُؤذن له');
        $this->assertSame(100.0, (float) $this->customer->credit_limit, 'مُحي الحدُّ بطلبٍ مباشر');
    }

    public function test_an_erased_limit_reads_as_no_limit_at_all(): void
    {
        /*
         * ولهذا كان المحوُ أخطرَ من الرفع: `null` ليست صفرًا ولا حدًّا كبيرًا
         * — هي غيابُ السقف كلِّه، ولا يطلب `CreditSales` معها إذنًا.
         */
        $this->saveCredit($this->owner)->assertSessionHasNoErrors();

        $this->customer->refresh();
        $this->assertNull($this->customer->credit_limit);
        $this->assertNull(Receivables::creditHeadroom($this->customer), 'الفراغُ يُقرأ «بلا حدّ»');
    }

    public function test_the_owner_still_sets_what_he_owns(): void
    {
        $this->saveCredit($this->owner, ['credit_limit' => 750, 'payment_terms_days' => 30])
            ->assertSessionHasNoErrors();

        $this->customer->refresh();
        $this->assertTrue((bool) $this->customer->allow_credit_sales);
        $this->assertSame(750.0, (float) $this->customer->credit_limit);
    }

    public function test_the_action_may_be_granted_by_name(): void
    {
        /*
         * والتضييقُ لا يُغلق البابَ نهائيًّا: المحاسبُ الذي يدير الذمم
         * يُمنح الفعلَ باسمه من شاشة صلاحياته — وهو معروضٌ فيها.
         */
        $accountant = $this->staff('accountant', ['finance', 'customers', CreditSales::OVERRIDE]);

        $this->assertTrue($accountant->may(CreditSales::OVERRIDE));
        $this->saveCredit($accountant, ['credit_limit' => 300])->assertSessionHasNoErrors();

        $this->assertSame(300.0, (float) $this->customer->fresh()->credit_limit);
    }

    public function test_an_accountant_without_the_action_is_refused(): void
    {
        // المحاسبُ بدوره وحده لا يملكه — ولم يكن يُسأل عنه أصلًا
        $accountant = $this->staff('accountant');

        $this->assertFalse($accountant->may(CreditSales::OVERRIDE));
        $this->saveCredit($accountant)->assertForbidden();
    }

    /* ═══════════ الشاشةُ والبابُ في قسمٍ واحد ═══════════ */

    public function test_the_screen_and_its_save_ask_for_the_same_section(): void
    {
        $screen = Permissions::sectionFromRoute('admin.finance.customerStatement');

        $this->assertSame($screen, Permissions::sectionFromRoute('admin.finance.customerCredit'));
        $this->assertSame($screen, Permissions::sectionFromRoute('admin.finance.customerBill'));
        $this->assertSame('finance', $screen);
    }

    public function test_whoever_opens_the_screen_is_not_refused_by_a_second_section(): void
    {
        /*
         * ماسكُ دفترٍ مُنح «المالية» و«ضبط الائتمان» ولم يُمنح «العملاء».
         * كان يفتح الشاشة ويُردّ عند الحفظ — لا لعجزٍ في ثقته بل لأنّ اسم
         * المسار كان تحت قسمٍ آخر.
         */
        $keeper = $this->staff('accountant', ['finance', CreditSales::OVERRIDE]);

        $this->assertFalse($keeper->allows('customers'));
        $this->actingAs($keeper)->get(route('admin.finance.customerStatement', $this->customer->id))->assertOk();
        $this->saveCredit($keeper, ['credit_limit' => 220])->assertSessionHasNoErrors();

        $this->assertSame(220.0, (float) $this->customer->fresh()->credit_limit);
    }

    /* ═══════════ وما لا يُحفظ لا يُرسم ═══════════ */

    public function test_the_screen_only_draws_the_handles_that_turn(): void
    {
        $keeper = $this->staff('accountant', ['finance']);

        $props = $this->actingAs($keeper)
            ->get(route('admin.finance.customerStatement', $this->customer->id))
            ->assertOk()->viewData('page')['props'];

        $this->assertFalse($props['may']['credit'], 'رُسم نموذجٌ يُردّ صاحبُه عند الحفظ');
        $this->assertFalse($props['may']['bill']);

        $ownerProps = $this->actingAs($this->owner)
            ->get(route('admin.finance.customerStatement', $this->customer->id))
            ->viewData('page')['props'];

        $this->assertTrue($ownerProps['may']['credit']);
        $this->assertTrue($ownerProps['may']['bill']);
    }

    public function test_the_form_is_drawn_only_behind_the_flag(): void
    {
        // وعلى شكل الشفرة: تعليقي في الملفّ يذكر الحقول شرحًا لما تغيّر
        $screen = file_get_contents(resource_path('js/Pages/Admin/Finance/CustomerStatement.tsx'));

        $this->assertStringContainsString('{may.credit ? (', $screen);
        $this->assertStringContainsString('{mayBill && (', $screen);
        // ولا عنوانَ مكتوبًا بيد: هو ما أخفى اختلافَ القسمين حتى اليوم
        $this->assertStringNotContainsString('/admin/customers/', $screen);
    }

    /* ═══════════ وكلُّ فعلٍ يُقرأ بلغة قارئه ═══════════ */

    public function test_every_action_the_owner_grants_is_readable_in_english(): void
    {
        /*
         * شاشةُ الصلاحيات تعرض أسماء الأفعال بـ`__()`. وأحدَ عشرَ اسمًا من
         * عشرين لم يكن له مقابلٌ إنجليزيّ — فالتاجرُ الذي يعمل بالإنجليزية
         * يقرأ نصفَ الشاشة بالعربية وهو يقرّر من يصرف الرواتب.
         */
        $en = json_decode(file_get_contents(base_path('lang/en.json')), true);

        $missing = array_filter(
            Permissions::ACTIONS,
            fn ($label) => ! isset($en[$label]),
        );

        $this->assertSame([], $missing, 'أفعالٌ تُعرض بلا ترجمة: '.implode('، ', $missing));
    }
}
