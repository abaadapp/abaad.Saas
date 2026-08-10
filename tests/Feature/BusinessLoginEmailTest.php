<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\MerchantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * عمود البريد في شاشة الشركات.
 *
 * للشركة عنوانا بريد: واحدٌ للتواصل يُكتب عند التسجيل، وآخرُ يدخل به التاجر
 * ويُبنى على نطاقنا. وكان العمود يعرض الأوّل بلا وصف — فيبدّل المشغّل حساب
 * الدخول ثم يعود إلى الجدول فيرى العنوان القديم ويظنّ أن التعديل لم يقع.
 */
class BusinessLoginEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $platform;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform = User::create([
            'name' => 'مدير المنصة',
            'email' => 'admin@abaadapp.om',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'status' => 'نشط',
        ]);

        $this->business = Business::create([
            'name' => 'متجري',
            'status' => 'نشط',
            // بريد التواصل — يُكتب عند التسجيل ولا يدخل به أحد
            'email' => 'contact@gmail.com',
        ]);

        MerchantAccount::create($this->business, 'shop', 'secret-pass');
    }

    /** أوّل صفٍّ في الجدول */
    private function firstRow(): array
    {
        return $this->actingAs($this->platform)
            ->get(route('super-admin.businesses.index'))
            ->assertOk()
            ->viewData('page')['props']['businesses'][0];
    }

    public function test_the_column_shows_the_address_the_merchant_logs_in_with(): void
    {
        $row = $this->firstRow();

        $this->assertSame('shop@abaadapp.om', $row['email']);
    }

    public function test_the_contact_address_is_kept_and_not_confused_with_it(): void
    {
        // لم يُحذف، بل فُصل: عنوانان لمعنيين، ولكلٍّ اسمه
        $row = $this->firstRow();

        $this->assertSame('contact@gmail.com', $row['contactEmail']);
    }

    public function test_changing_the_login_address_shows_at_once_in_the_table(): void
    {
        /*
         * هذا ما أوقع المستخدم: بدّل حساب الدخول فتأكّد التبديل في القاعدة،
         * ثم رأى الجدول يعرض بريد التواصل القديم — فبدا أن «التأكيد» لا يحدّث
         * شيئًا، والتبديل واقعٌ في مكانه.
         */
        $this->actingAs($this->platform)
            ->post(route('super-admin.businesses.account', $this->business->id), [
                'login_username' => 'newname',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('newname@abaadapp.om', $this->firstRow()['email']);
    }

    public function test_changing_the_password_takes_effect_on_confirm(): void
    {
        $this->actingAs($this->platform)
            ->post(route('super-admin.businesses.account', $this->business->id), [
                'login_password' => 'a-new-password',
            ])
            ->assertSessionHasNoErrors();

        $owner = MerchantAccount::owner($this->business->fresh());

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-new-password', $owner->password));
        // والقديمة لم تعد تفتح شيئًا
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('secret-pass', $owner->password));
    }

    public function test_saving_the_business_does_not_touch_the_login_account(): void
    {
        /*
         * العطب الذي وقع فعلًا على الإنتاج.
         *
         * كان نموذج الشركة يحمل اسم الدخول كما كان لحظة فتح الصفحة ويرسله مع
         * كل حفظ. فيبدّل المشغّل الاسم من بطاقة الحساب — ويقع التبديل — ثم
         * يضغط «حفظ التعديلات» فيصعد الاسم القديم معه ويعيده. لا رسالة ولا
         * فشل: تعديلٌ أُلغي بيد صاحبه، وسجلّ النشاط يقيّد الاثنين في الثانية
         * نفسها.
         *
         * فصار ما لا يُعرض لا يُرسَل، وهذا الاختبار يحرس العقد من جهة الخادم:
         * طلبُ تعديلِ شركةٍ بلا حقلَي الحساب لا يمسّ الحساب.
         */
        $this->actingAs($this->platform)
            ->post(route('super-admin.businesses.account', $this->business->id), [
                'login_username' => 'newname',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->platform)
            ->put(route('super-admin.businesses.update', $this->business->id), [
                'name' => 'متجري بعد التعديل',
                'type' => 'عام',
                'status' => 'نشط',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('متجري بعد التعديل', $this->business->fresh()->name);
        $this->assertSame('newname@abaadapp.om', MerchantAccount::owner($this->business->fresh())->email);
    }

    public function test_saving_the_business_does_not_reset_the_password_either(): void
    {
        $this->actingAs($this->platform)
            ->post(route('super-admin.businesses.account', $this->business->id), [
                'login_password' => 'a-new-password',
            ]);

        $this->actingAs($this->platform)
            ->put(route('super-admin.businesses.update', $this->business->id), [
                'name' => 'متجري',
                'type' => 'عام',
                'status' => 'نشط',
            ]);

        $owner = MerchantAccount::owner($this->business->fresh());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-new-password', $owner->password));
    }

    public function test_search_finds_a_merchant_by_the_address_support_knows(): void
    {
        $this->actingAs($this->platform)
            ->get(route('super-admin.businesses.index', ['q' => 'shop@abaadapp.om']))
            ->assertInertia(fn (Assert $page) => $page->has('businesses', 1));
    }

    public function test_a_business_with_no_account_yet_shows_a_dash_not_a_wrong_address(): void
    {
        // شركةٌ سُجّلت قبل إلزام الحساب: لا بريد دخول لها، فلا يُعرض بديلٌ مضلّل
        Business::create(['name' => 'بلا حساب', 'status' => 'نشط', 'email' => 'old@gmail.com']);

        $rows = $this->actingAs($this->platform)
            ->get(route('super-admin.businesses.index'))
            ->viewData('page')['props']['businesses'];

        $row = collect($rows)->firstWhere('name', 'بلا حساب');

        $this->assertNull($row['email']);
        $this->assertSame('old@gmail.com', $row['contactEmail']);
    }
}
