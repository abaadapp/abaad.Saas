<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حسابٌ أُغلق لا يُقفل على مستندٍ مُرحَّل.
 *
 * ═══ العطب ═══
 *
 * `Ledger::post` تشترط أن يكون الحسابُ قابلًا للترحيل — مفتوحًا وبلا فروع.
 * وهو الصواب لكلّ قيدٍ **جديد**: لا يدخل حسابًا أُغلق مالٌ لم يكن فيه.
 *
 * و`Ledger::reverse` تمرّ من الباب نفسِه. وهي لا تُدخل شيئًا: تُخرج ما دخل،
 * وتردّ الرصيدَ إلى ما كان. فصار الإغلاقُ — وشاشةُ الشجرة تدعو إليه بنصّها:
 * «أغلقه إن لم تعد تستعمله» — بابًا يُقفل على قيدٍ لا يُعكس أبدًا، وعلى سندٍ
 * لا يُلغى، وعلى شيكٍ لا يرتدّ، وعلى تحصيلٍ لا يُبطَل. وكلُّها تمرّ من
 * `Ledger::reverse`.
 *
 * ولا رسالةَ في شيءٍ من ذلك: `JournalController::reverse` لا تلتقط
 * `RuntimeException`، فيخرج خطأُ خادمٍ في وجه من ضغط «عكس القيد».
 */
class AClosedAccountDoesNotLockWhatWasPostedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    private Account $mine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('ar');

        $this->shop = Business::create(['name' => 'متجر', 'type' => 'عام', 'status' => 'نشط']);
        Branch::create(['business_id' => $this->shop->id, 'name' => 'الرئيسي']);
        Setting::create(['business_id' => $this->shop->id, 'key' => 'vat_enabled', 'value' => '0']);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'مالك', 'email' => 'o@abaadapp.om',
            'password' => bcrypt('password12345'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        Ledger::seedChart($this->shop->id);

        $this->mine = Account::create([
            'business_id' => $this->shop->id, 'code' => '5950',
            'name' => 'بترول', 'type' => 'مصروف', 'normal_side' => 'debit',
        ]);
    }

    private function entry(): JournalEntry
    {
        return Ledger::post($this->shop->id, 'قيد يدوي', [
            ['account' => $this->mine, 'debit' => 10],
            ['account' => 'cash', 'credit' => 10],
        ]);
    }

    /* ══════════ ١ · العكسُ يمرّ بعد الإغلاق ══════════ */

    public function test_a_posted_entry_is_reversed_after_its_account_is_closed(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->owner)
            ->post(route('admin.finance.chart.toggle', $this->mine->id))
            ->assertRedirect();

        $this->assertFalse($this->mine->fresh()->active, 'المقدّمةُ خاطئة — لم يُغلق');

        $reversal = Ledger::reverse($entry->fresh());

        $this->assertNotNull($reversal, 'العكسُ رُدّ عن حسابٍ أُغلق');
        $this->assertNotNull($entry->fresh()->reversed_at);
        $this->assertSame(0.0, Ledger::balance($this->shop->id, 'cash'), 'الرصيدُ لم يعد إلى ما كان');
    }

    /** وكذلك بعد أن يصير الحسابُ أبًا — شجرةٌ بُنيت قبل حارس الأبوّة */
    public function test_and_after_its_account_became_a_parent(): void
    {
        $entry = $this->entry();

        Account::create([
            'business_id' => $this->shop->id, 'parent_id' => $this->mine->id,
            'code' => '5951', 'name' => 'بنزين', 'type' => 'مصروف', 'normal_side' => 'debit',
        ]);

        $this->assertFalse($this->mine->fresh()->isPostable(), 'المقدّمةُ خاطئة — ما زال ورقة');

        $this->assertNotNull(Ledger::reverse($entry->fresh()), 'العكسُ رُدّ عن حسابٍ صار أبًا');
    }

    /** والشاشةُ تعكسه كما تفعل يدُ التاجر — لا خطأَ خادمٍ في وجهها */
    public function test_the_screen_reverses_it_too(): void
    {
        $entry = $this->entry();
        $this->mine->update(['active' => false]);

        $this->actingAs($this->owner)
            ->post(route('admin.finance.journal.reverse', $entry->id), ['reason' => 'تصحيح'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotNull($entry->fresh()->reversed_at);
    }

    /* ══════════ ٢ · والبابُ لم يتّسع: القيدُ الجديد يُردّ ══════════ */

    /**
     * العلَمُ لـ`reverse` وحدها. ولولا هذا الحارس لَصار الإصلاحُ فتحةً:
     * يُرحَّل إلى حسابٍ أُغلق مالٌ لم يكن فيه، فيُقرأ رصيدٌ لحسابٍ يقول
     * صاحبُه إنّه لم يعد يستعمله.
     */
    public function test_a_new_entry_is_still_refused_by_a_closed_account(): void
    {
        $this->mine->update(['active' => false]);

        $this->expectExceptionMessage('لا يُرحَّل إلى «بترول»');

        Ledger::post($this->shop->id, 'قيد جديد', [
            ['account' => $this->mine->fresh(), 'debit' => 10],
            ['account' => 'cash', 'credit' => 10],
        ]);
    }

    /** ولا يُعكس العكسُ مرّتين — الختمُ يبقى حارسًا */
    public function test_a_reversal_is_not_reversed_twice(): void
    {
        $entry = $this->entry();
        $this->mine->update(['active' => false]);

        $this->assertNotNull(Ledger::reverse($entry->fresh()));
        $this->assertNull(Ledger::reverse($entry->fresh()), 'عُكس القيدُ مرّتين');
    }

    /* ══════════ ٣ · وورقةُ حسابٍ بنكيّ لا تُنتزَع من تحته ══════════ */

    /**
     * ═══ العطب ═══
     *
     * ورقةُ الحساب البنكيّ الثاني ليست نظاميّة، ولا قيودَ عليها ما لم يُحصَّل
     * فيه بعد — فتمرّ من حارسَي الحذف وتُمحى، ويصير `bank_accounts.account_id`
     * فارغًا. فتردّ `Bank::leaf` عدمًا، ويسقط كلُّ ترحيلٍ بنكيٍّ إلى الورقة
     * النظاميّة: يبيع الكاشير بالبطاقة فيدخل المالُ صفحةَ بنكٍ آخر.
     */
    public function test_a_bank_accounts_leaf_is_not_deleted_from_the_tree(): void
    {
        foreach ([['بنك مسقط', '111'], ['بنك ظفار', '222']] as [$name, $number]) {
            $this->actingAs($this->owner)->post(route('admin.finance.banks.store'), [
                'name' => $name, 'bank_name' => $name, 'account_number' => $number, 'opening_balance' => 0,
            ]);
        }

        $second = BankAccount::where('business_id', $this->shop->id)->orderBy('id')->skip(1)->first();
        $leaf = $second?->account;

        $this->assertNotNull($leaf, 'المقدّمةُ خاطئة — لا ورقةَ للحساب الثاني');
        $this->assertNull($leaf->system_key, 'المقدّمةُ خاطئة — ورقتُه نظاميّة فتُحرس بحارسٍ آخر');

        $this->actingAs($this->owner)
            ->delete(route('admin.finance.chart.destroy', $leaf->id))
            ->assertRedirect();

        $this->assertNotNull(Account::find($leaf->id), 'حُذفت ورقةُ حسابٍ بنكيّ من الشجرة');
        $this->assertSame((int) $leaf->id, (int) $second->fresh()->account_id, 'انقطع رابطُ الحساب البنكيّ بورقته');
    }

    /** وورقةٌ لا يملكها بنكٌ تبقى تُحذف — القيدُ على المملوكة وحدها */
    public function test_an_unowned_empty_account_is_still_deleted(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('admin.finance.chart.destroy', $this->mine->id))
            ->assertRedirect();

        $this->assertNull(Account::find($this->mine->id), 'رُدّ حذفُ حسابٍ لا يملكه أحد');
    }
}
