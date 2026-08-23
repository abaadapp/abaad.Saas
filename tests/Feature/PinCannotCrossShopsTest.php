<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\JobTitle;
use App\Models\PosDevice;
use App\Models\User;
use App\Support\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * رمز الموظّف لا يفتح متجرًا غير متجره.
 *
 * الرمز أربعة أرقام، وهو فريدٌ داخل المتجر لا في المنصّة كلّها — وهو الصواب:
 * فرضُ التفرّد على عشرة آلاف احتمالٍ بين كلّ التجّار يعني أن يُمنع تاجرٌ من
 * رمزٍ لأن جارًا لا يعرفه سبقه إليه. ويقابله أن باب الرمز يجب أن يعرف متجره
 * قبل أن يفتح: «1234» موجودٌ في متجرين، فبحثٌ في المستخدمين كلّهم يُدخل
 * صاحبه إلى أوّل ما يُصادف.
 */
class PinCannotCrossShopsTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;
    private Business $theirs;
    private User $myCashier;
    private User $theirCashier;
    private Branch $myBranch;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        RateLimiter::clear('pin-login:ip127.0.0.1');

        [$this->mine, $this->myBranch, $this->myCashier] = $this->shop('متجري', 'me');
        [$this->theirs, , $this->theirCashier] = $this->shop('متجر الجار', 'them');
    }

    /** @return array{Business, Branch, User} */
    private function shop(string $name, string $slug): array
    {
        $business = Business::create(['name' => $name, 'type' => 'عام', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $business->id, 'name' => 'الرئيسي']);
        JobTitle::create(['business_id' => $business->id, 'name' => 'كاشير', 'role' => 'cashier']);

        // الرمز نفسه في المتجرين — وهو مسموحٌ عمدًا
        $cashier = User::create([
            'business_id' => $business->id, 'name' => 'كاشير '.$name,
            'email' => $slug.'@abaadapp.om', 'password' => bcrypt('password'),
            'role' => 'cashier', 'status' => 'نشط', 'pin' => '7391', 'branch' => 'الرئيسي',
        ]);

        return [$business, $branch, $cashier];
    }

    /** يفعّل هذا المتصفّح كجهازٍ على فرعٍ ويُعيد الكوكي الخام */
    private function device(Branch $branch): string
    {
        $raw = bin2hex(random_bytes(16));
        $device = PosDevice::create([
            'business_id' => $branch->business_id,
            'branch_id' => $branch->id,
            'name' => 'صندوق',
            'token_hash' => hash('sha256', $raw),
            'status' => PosDevice::ACTIVE,
            'activated_at' => now(),
            'last_seen_at' => now(),
        ]);

        return $device->id.'|'.$raw;
    }

    private function enter(string $pin, ?string $cookie = null)
    {
        $test = $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        if ($cookie !== null) {
            // تُشفَّر كما تُشفَّر في الإنتاج، فيمرّ الطلب بالحارس نفسه
            $test = $test->withCookie(PosTerminal::COOKIE, $cookie);
        }

        return $test->post(route('pin.attempt'), ['pin' => $pin]);
    }

    /** أهمّ ما في الملفّ: الرمز نفسه في متجرين، والجهاز يقرّر أيّهما */
    public function test_the_same_pin_opens_only_the_device_shop(): void
    {
        $this->enter('7391', $this->device($this->myBranch));

        $this->assertTrue(Auth::check(), 'رُفض رمزٌ صحيح على جهاز متجره');
        $this->assertSame($this->myCashier->id, Auth::id(), 'دخل حساب كاشير المتجر الآخر');
        $this->assertSame($this->mine->id, Auth::user()->business_id);
    }

    /** وجهازُ الجار يفتح كاشيرَ الجار لا كاشيري */
    public function test_the_neighbours_device_opens_the_neighbours_cashier(): void
    {
        $theirBranch = Branch::where('business_id', $this->theirs->id)->firstOrFail();

        $this->enter('7391', $this->device($theirBranch));

        $this->assertSame($this->theirCashier->id, Auth::id());
    }

    /** ومتصفّحٌ بلا جهاز لا يفتح شيئًا — والمنصّة فيها متجران */
    public function test_a_browser_with_no_device_opens_nothing(): void
    {
        $this->enter('7391');

        $this->assertFalse(Auth::check(), 'دخل رمزٌ من متصفّحٍ غير مفعَّل');
    }

    /** وجهازٌ أُلغي تفعيله يموت رمزُه */
    public function test_a_revoked_device_opens_nothing(): void
    {
        $cookie = $this->device($this->myBranch);
        PosDevice::query()->update(['status' => 'ملغى']);

        $this->enter('7391', $cookie);

        $this->assertFalse(Auth::check(), 'جهازٌ مُلغى ما زال يفتح');
    }

    /**
     * وموظّفٌ قُيّد بفرعٍ لا يدخل من جهاز فرعٍ آخر — ولو كان رمزه صحيحًا.
     *
     * والقيد اختيار: موظّفٌ بلا فروعٍ محدّدة يعمل في فروع متجره كلّها، وهو
     * الغالب. فالفحص على المقيَّد لا على المطلق.
     */
    public function test_a_cashier_tied_to_one_branch_cannot_enter_from_another(): void
    {
        $second = Branch::create(['business_id' => $this->mine->id, 'name' => 'الثاني']);
        $this->myCashier->branches()->sync([$this->myBranch->id]);

        $this->enter('7391', $this->device($second));

        $this->assertFalse(Auth::check(), 'دخل موظّفٌ من جهاز فرعٍ قُيّد خارجه');
    }

    /** وغيرُ المقيَّد يدخل من أيّ فرعٍ في متجره — وهو المقصود */
    public function test_an_unrestricted_cashier_enters_from_any_branch_of_his_shop(): void
    {
        $second = Branch::create(['business_id' => $this->mine->id, 'name' => 'الثاني']);

        $this->enter('7391', $this->device($second));

        $this->assertSame($this->myCashier->id, Auth::id());
    }

    /** والرمز لا يتكرّر داخل المتجر الواحد — وإلا دخل موظّفٌ حساب زميله */
    public function test_a_pin_cannot_be_reused_inside_one_shop(): void
    {
        $owner = User::create([
            'business_id' => $this->mine->id, 'name' => 'المالك', 'email' => 'owner@abaadapp.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        JobTitle::create(['business_id' => $this->mine->id, 'name' => 'مندوب', 'role' => 'sales']);

        $this->actingAs($owner)->post(route('admin.employees.store'), [
            'name' => 'زميل', 'role' => 'sales', 'status' => 'نشط',
            'job_title' => 'مندوب', 'branch' => 'الرئيسي', 'pin' => '7391',
        ])->assertSessionHasErrors('pin');
    }
}
