<?php

namespace Tests\Feature;

use App\Http\Controllers\Pos\DeviceController;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PosDevice;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Bank;
use App\Support\Books;
use App\Support\Ledger;
use App\Support\PosTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * جهازُ الشبكة يقول أيَّ بنكٍ يُغذّي — ولا يذهب المالُ إلى الرئيسيّ دائمًا.
 *
 * ═══ العطب ═══
 *
 * `Books::recordSale` كانت تُدين المفتاح `'bank'`، وهو يقصد ورقةَ الحساب
 * **الرئيسيّ** أيًّا كان الجهاز الذي وقعت عليه البيعة. ومتجرٌ له حسابان
 * وجهازا شبكةٍ لكلٍّ بنكُه — وهو حالٌ قائمٌ على الإنتاج — يقرأ رصيدَ أحدهما
 * لا يتحرّك مهما بِيع عليه، ومالَه كلَّه في ورقة الآخر.
 *
 * وهو نصفُ العطب الذي عُولج يوم صار لكلّ حسابٍ ورقة: الورقةُ صارت لكلّ
 * حساب، والوجهةُ بقيت واحدة.
 *
 * ═══ ولماذا على الجهاز لا على الشاشة ═══
 *
 * جهازُ الشبكة موصولٌ ببنكٍ بعينه في العتاد — لا يختاره الكاشير ولا يبدّله
 * في منتصف اليوم. وسؤالُ الكاشير عنه يفتح بابًا لخطأ لا يُكتشف إلا في
 * المطابقة بعد شهر.
 *
 * ═══ ولماذا تُختم البيعة ═══
 *
 * المدير ينقل الجهاز إلى بنكٍ آخر، فتصير قراءةُ الجهاز اليوم تقول عن بيعةٍ
 * قديمة غيرَ ما وقع — ولانتقل رصيدٌ في الميزانية من حسابٍ إلى حساب بلا قيد.
 */
class TheTerminalSaysWhichBankItFeedsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $cashier;

    private Product $product;

    private BankAccount $primary;

    private BankAccount $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create(['name' => 'متجري', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->business->id, 'name' => 'الرئيسي']);
        Currency::create([
            'business_id' => $this->business->id, 'code' => 'OMR', 'name' => 'ريال عماني',
            'symbol' => 'ر.ع', 'rate' => 1, 'is_base' => true, 'active' => true,
        ]);
        Ledger::seedChart($this->business->id);
        Setting::create(['business_id' => $this->business->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->cashier = User::create([
            'business_id' => $this->business->id, 'name' => 'كاشير', 'email' => 'c@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
        $this->product = Product::create([
            'business_id' => $this->business->id, 'name' => 'باقة',
            'price' => 10, 'cost' => 4, 'quantity' => 100, 'active' => true,
        ]);

        $this->primary = BankAccount::create([
            'business_id' => $this->business->id, 'label' => 'بنك مسقط',
            'active' => true, 'is_primary' => true, 'opening_balance' => 0,
            'account_id' => Ledger::account($this->business->id, 'bank')->id,
        ]);
        $this->second = BankAccount::create([
            'business_id' => $this->business->id, 'label' => 'بنك ظفار',
            'active' => true, 'is_primary' => false, 'opening_balance' => 0,
            'account_id' => $this->sibling()->id,
        ]);
    }

    private function sibling(): Account
    {
        $main = Ledger::account($this->business->id, 'bank');

        return Account::create([
            'business_id' => $this->business->id, 'parent_id' => $main->parent_id,
            'code' => '1220', 'name' => 'البنك: بنك ظفار', 'type' => 'أصل', 'normal_side' => 'debit',
        ]);
    }

    private function device(?BankAccount $bank, ?string $token = null): PosDevice
    {
        return PosDevice::create([
            'business_id' => $this->business->id,
            'branch_id' => Branch::where('business_id', $this->business->id)->value('id'),
            'name' => 'صندوق '.fake()->unique()->numerify('##'),
            'status' => PosDevice::ACTIVE,
            'activated_at' => now(),
            'token_hash' => hash('sha256', $token ?? Str::random(64)),
            'bank_account_id' => $bank?->id,
        ]);
    }

    /**
     * يقف الاختبارُ على جهازٍ بعينه — كما يقف الكاشير.
     *
     * والكوكي مشفَّرة كما يرسلها المتصفّح: وسيطُ فكّ التشفير يفكّ كلَّ كوكي
     * لا استثناءَ لها، فخامٌ تصل الصندوقَ فارغة — ويبدو الجهازُ غائبًا وهو
     * حاضر.
     */
    private function standOn(?BankAccount $bank): PosDevice
    {
        $token = Str::random(64);
        $device = $this->device($bank, $token);

        $this->withCookie(PosTerminal::COOKIE, $device->id.'|'.$token);

        return $device;
    }

    /**
     * بيعةٌ من الصندوق — بكوكي الجهاز.
     *
     * و`withCredentials` ليست زينة: طلبُ JSON في مِحرابِ الاختبار لا يرسل
     * كوكيًّا بدونها، فيصل الصندوقَ بلا جهاز ويبدو الحارسُ ساقطًا وهو قائم.
     */
    private function sell(string $method = 'بطاقة'): Order
    {
        $this->withCredentials()->actingAs($this->cashier)->postJson('/pos/checkout', [
            'items' => [['id' => $this->product->id, 'name' => 'باقة', 'qty' => 1, 'price' => 10]],
            'payment_method' => $method,
        ])->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    private function balance(BankAccount $account): float
    {
        return Account::findOrFail($account->account_id)->balance();
    }

    /* ───────────────────────── الوجهة ───────────────────────── */

    /** بلا جهازٍ مُسنَد: الرئيسيّ كما كان الحالُ دائمًا */
    public function test_with_no_terminal_the_primary_takes_it(): void
    {
        $this->assertSame($this->primary->id, Bank::depositFor($this->business->id, null));
    }

    /** وجهازٌ بلا بنكٍ مختار يسقط إلى الرئيسيّ */
    public function test_an_unassigned_terminal_falls_back_to_the_primary(): void
    {
        $device = $this->device(null);

        $this->assertSame($this->primary->id, Bank::depositFor($this->business->id, $device->id));
    }

    /** وجهازٌ أُسنِد يقول حسابه */
    public function test_an_assigned_terminal_names_its_bank(): void
    {
        $device = $this->device($this->second);

        $this->assertSame($this->second->id, Bank::depositFor($this->business->id, $device->id));
    }

    /**
     * وجهازُ متجرٍ آخر لا يُقرأ — يسقط إلى رئيسيّ متجرنا.
     *
     * وجهازُ الجار مُسنَدٌ إلى بنك الجار: بلا ذلك يردّ الاستعلامُ فارغًا في
     * الحالين، فيمرّ نزعُ حصر المتجر بلا أن يمسكه شيء.
     */
    public function test_another_shops_terminal_is_not_read(): void
    {
        $other = Business::create(['name' => 'جارنا', 'type' => 'عام', 'status' => 'نشط']);
        $branch = Branch::create(['business_id' => $other->id, 'name' => 'الرئيسي']);
        $theirBank = BankAccount::create([
            'business_id' => $other->id, 'label' => 'بنكهم',
            'active' => true, 'is_primary' => true, 'opening_balance' => 0,
        ]);
        $theirs = PosDevice::create([
            'business_id' => $other->id, 'branch_id' => $branch->id,
            'name' => 'صندوقهم', 'status' => PosDevice::ACTIVE,
            'token_hash' => hash('sha256', Str::random(64)),
            'bank_account_id' => $theirBank->id,
        ]);

        $this->assertSame(
            $this->primary->id,
            Bank::depositFor($this->business->id, $theirs->id),
            'مالُنا يُرحَّل إلى حسابٍ يخصّ جارنا'
        );
    }

    /* ───────────────────────── الدفتر ───────────────────────── */

    /**
     * والبيعُ بالبطاقة يدخل ورقةَ بنك الجهاز — لا ورقةَ الرئيسيّ.
     *
     * وهو الفرق كلُّه: قبلها كان الرئيسيُّ يأخذ ١٠ والثاني يقرأ صفرًا.
     */
    public function test_a_card_sale_lands_in_the_terminals_bank(): void
    {
        $this->standOn($this->second);

        $order = $this->sell();

        $this->assertSame($this->second->id, (int) $order->bank_account_id, 'البيعةُ لم تُختم ببنكها');
        $this->assertSame(10.0, $this->balance($this->second), 'المالُ لم يدخل بنك الجهاز');
        $this->assertSame(0.0, $this->balance($this->primary), 'المالُ ذهب إلى الرئيسيّ رغم أنف الجهاز');
        $this->assertTrue(Ledger::trialBalance($this->business->id)['balanced']);
    }

    /** والنقدُ لا يحمل بنكًا مهما كان الجهاز — مالٌ في الدرج لا يطابقه كشف */
    public function test_a_cash_sale_carries_no_bank(): void
    {
        $this->standOn($this->second);

        $order = $this->sell('نقدي');

        $this->assertNull($order->bank_account_id, 'بيعةٌ نقديّة تحمل اسم بنك');
        $this->assertSame(10.0, Ledger::account($this->business->id, 'cash')->balance());
        $this->assertSame(0.0, $this->balance($this->second));
    }

    /** وطلبٌ بلا ختمٍ — ما قبل هذا العمود — يسقط إلى الرئيسيّ كما كان */
    public function test_an_unstamped_order_still_posts_to_the_primary(): void
    {
        $order = Order::create([
            'business_id' => $this->business->id, 'number' => 'INV-OLD',
            'customer_name' => 'عميل', 'status' => 'مكتمل',
            'payment_method' => 'بطاقة', 'payment_status' => 'مدفوع',
            'subtotal' => 10, 'discount' => 0, 'tax' => 0, 'delivery_fee' => 0, 'total' => 10,
            'ordered_at' => now(),
        ]);

        Books::recordSale($order->fresh('items'));

        $this->assertSame(10.0, $this->balance($this->primary));
        $this->assertSame(0.0, $this->balance($this->second));
    }

    /**
     * وتبديلُ بنك الجهاز لا يحرّك ما رُحّل.
     *
     * لو قُرئ الجهازُ يومَ الترحيل لانتقل رصيدٌ في الميزانية من حسابٍ إلى
     * حسابٍ بلا قيدٍ يقول إنّه انتقل.
     */
    public function test_moving_the_terminal_does_not_move_posted_money(): void
    {
        $device = $this->standOn($this->second);

        $order = $this->sell();
        $device->update(['bank_account_id' => $this->primary->id]);

        $this->assertSame($this->second->id, (int) $order->fresh()->bank_account_id);
        $this->assertSame(10.0, $this->balance($this->second), 'مالٌ رُحّل انتقل بتبديل إعدادٍ');
        $this->assertSame(0.0, $this->balance($this->primary));
    }

    /* ───────────────────────── المطابقة ───────────────────────── */

    /** والمعاملةُ تُختم بالبنك نفسه: بها تُرشَّح مطابقةُ الكشف */
    public function test_the_transaction_carries_the_same_bank(): void
    {
        $this->standOn($this->second);

        $order = $this->sell();
        $transaction = Transaction::where('order_id', $order->id)->sole();

        $this->assertSame($this->second->id, (int) $transaction->bank_account_id);
    }

    /** وكشفُ حسابٍ لا يرى حركةً دخلت غيرَه */
    public function test_a_statement_does_not_see_another_banks_movement(): void
    {
        $this->standOn($this->second);
        $this->sell();

        $this->assertSame(1, Bank::transactions($this->business->id, $this->second->id)->count());
        $this->assertSame(
            0,
            Bank::transactions($this->business->id, $this->primary->id)->count(),
            'كشفُ بنكٍ يعرض حركةً لم تمرّ به — فتُطابَق ويُكتب «مطابق» عن شيئين لم يلتقيا'
        );
    }

    /** وحركةٌ بلا ختمٍ تمرّ مع الجميع: ما قبل العمود لا يُحجب عن كشفه */
    public function test_an_unstamped_movement_is_a_candidate_everywhere(): void
    {
        Transaction::create([
            'business_id' => $this->business->id, 'reference' => 'TRX-OLD',
            'description' => 'بيعة قديمة', 'method' => 'بطاقة', 'type' => 'دخل',
            'amount' => 10, 'occurred_at' => now(),
        ]);

        $this->assertSame(1, Bank::transactions($this->business->id, $this->primary->id)->count());
        $this->assertSame(1, Bank::transactions($this->business->id, $this->second->id)->count());
    }

    /* ───────────────────────── الباب ───────────────────────── */

    private function save(PosDevice $device, array $over = []): TestResponse
    {
        return $this->actingAs($this->cashier)
            ->put(route('admin.devices.update', $device->id), $over + [
                'name' => $device->name,
                'branch_id' => $device->branch_id,
            ]);
    }

    /** والمديرُ يُسند الجهاز إلى بنكه من شاشته */
    public function test_the_screen_assigns_the_bank(): void
    {
        $device = $this->device(null);

        $this->save($device, ['bank_account_id' => $this->second->id])->assertRedirect();

        $this->assertSame($this->second->id, (int) $device->fresh()->bank_account_id);
    }

    /** ويُعيده إلى الرئيسيّ بتفريغ الحقل */
    public function test_clearing_the_field_returns_it_to_the_primary(): void
    {
        $device = $this->device($this->second);

        $this->save($device, ['bank_account_id' => ''])->assertRedirect();

        $this->assertNull($device->fresh()->bank_account_id);
        $this->assertSame($this->primary->id, Bank::depositFor($this->business->id, $device->id));
    }

    /** وحسابُ متجرٍ آخر يُردّ على حقله — لا يُقبل صامتًا */
    public function test_a_foreign_account_is_refused(): void
    {
        $other = Business::create(['name' => 'جارنا', 'type' => 'عام', 'status' => 'نشط']);
        $theirs = BankAccount::create([
            'business_id' => $other->id, 'label' => 'بنكهم', 'active' => true, 'opening_balance' => 0,
        ]);
        $device = $this->device(null);

        $this->save($device, ['bank_account_id' => $theirs->id])->assertSessionHasErrors('bank_account_id');

        $this->assertNull($device->fresh()->bank_account_id);
    }

    /**
     * والشاشةُ تحمل الحسابات لتُعرض — وإلا فحقلٌ بلا خيارات.
     *
     * والرئيسيُّ هنا أحدثُ الصفّين عمدًا: لو رُتّبت القائمةُ بالمعرّف وحده
     * لَبدت مرتّبةً صحيحًا ما دام الرئيسيُّ أوّلَ ما أُنشئ — وهو ليس كذلك عند
     * من بدّل حسابه الرئيسيّ.
     */
    public function test_the_screen_carries_the_accounts(): void
    {
        $device = $this->device($this->second);
        $this->primary->update(['is_primary' => false]);
        $this->second->update(['is_primary' => true]);

        $data = $this->actingAs($this->cashier)->app->call([DeviceController::class, 'panelData']);

        $this->assertSame(
            [$this->second->id, $this->primary->id],
            array_column($data['bankAccounts'], 'value'),
            'الرئيسيُّ أوّلًا — وإلا تبدّل ترتيبُ القائمة بترتيب الصفوف'
        );
        $this->assertSame(
            $this->second->id,
            collect($data['devices'])->firstWhere('id', $device->id)['bankAccountId'],
            'الشاشةُ لا تعرف بنك الجهاز فتعرضه فارغًا وتقول «الرئيسيّ»'
        );
    }
}
