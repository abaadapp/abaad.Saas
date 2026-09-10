<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * القيدُ المُرحَّل يُصحَّح ولا يُمحى — وبابُه كان مفقودًا.
 *
 * ═══ ما وقع ═══
 *
 * `Ledger::reverse` موجودةٌ منذ زمن، ويستدعيها إلغاءُ سند المورّد وإلغاءُ
 * التحصيل وإلغاءُ فاتورة العميل. أمّا القيدُ الذي يكتبه التاجر **بيده**
 * فلم يكن له بابٌ البتّة.
 *
 * ووقع أثرُه في متجرٍ حقيقيّ: قيدان بالبنك مدينًا والمصروف دائنًا — وهو
 * اتّجاهٌ لا يصحّ لمصروفٍ بحال. فصار «مصروفات أخرى» **سالبًا ستّين ريالًا**
 * والبنكُ أكثرَ ممّا فيه بستّين. وبقي كذلك لأنّ لا شاشةَ تُصلحه.
 *
 * ═══ ولا يُعدَّل في مكانه ═══
 *
 * من قرأ الميزان أمس قرأ رقمًا، ومن يقرؤه اليوم يقرأ غيره، ولا شيء يقول
 * إنّ شيئًا كان. فالعكسُ قيدٌ ثانٍ والاثنان يبقيان.
 */
class APostedEntryIsCorrectedNotErasedTest extends TestCase
{
    use RefreshDatabase;

    private Business $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Business::create([
            'name' => 'محل الورد', 'type' => 'محل ورود', 'status' => 'نشط',
        ]);
        Ledger::seedChart($this->shop->id);

        $this->owner = User::create([
            'business_id' => $this->shop->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    /** حسابٌ بكوده */
    private function account(string $code)
    {
        return Account::where('business_id', $this->shop->id)
            ->where('code', $code)->firstOrFail();
    }

    /** رصيدُ حسابٍ = مدينه − دائنه */
    private function balance(string $code): float
    {
        $a = $this->account($code);

        return round(
            (float) JournalLine::where('account_id', $a->id)->sum('debit')
            - (float) JournalLine::where('account_id', $a->id)->sum('credit'),
            3,
        );
    }

    /** قيدٌ يدويٌّ مصروفُه مدينٌ والبنكُ دائن — الاتّجاه الصحيح */
    private function expense(float $amount = 50, ?string $date = null): JournalEntry
    {
        return Ledger::post(
            $this->shop->id,
            'حملة تسويقية',
            [
                ['account' => $this->account('5900'), 'debit' => $amount, 'credit' => 0.0],
                ['account' => $this->account('1200'), 'debit' => 0.0, 'credit' => $amount],
            ],
            Carbon::parse($date ?? '2026-08-22'),
            'يدوي',
            null,
            $this->owner->id,
        );
    }

    /* ═══════════════ البابُ يُفتح ═══════════════ */

    public function test_a_manual_entry_can_be_reversed_from_the_screen(): void
    {
        $entry = $this->expense();

        $this->assertSame(50.0, $this->balance('5900'));
        $this->assertSame(-50.0, $this->balance('1200'));

        $this->actingAs($this->owner)
            ->post(route('admin.finance.journal.reverse', $entry->id), ['reason' => 'اتجاه معكوس'])
            ->assertRedirect();

        /* والرصيدُ يعود إلى ما كان — بقيدٍ ثانٍ لا بمحوِ الأوّل */
        $this->assertSame(0.0, $this->balance('5900'));
        $this->assertSame(0.0, $this->balance('1200'));
    }

    /**
     * والأصلُ يبقى — لا يُحذف ولا تُغيَّر سطورُه.
     *
     * وهذا أثقلُ ما في الملفّ: دفترٌ تُعدَّل سطورُه في مكانها لا يُقرأ
     * تاريخُه، ومن راجعه أمس لا يجد اليوم ما رآه.
     */
    public function test_the_original_survives_untouched(): void
    {
        $entry = $this->expense();
        $lines = $entry->lines()->orderBy('id')->get(['account_id', 'debit', 'credit'])->toArray();

        $this->actingAs($this->owner)->post(route('admin.finance.journal.reverse', $entry->id));

        $entry->refresh();

        $this->assertNotNull($entry->reversed_at, 'الأصلُ يُختم بتاريخ عكسه');
        $this->assertSame(
            $lines,
            $entry->lines()->orderBy('id')->get(['account_id', 'debit', 'credit'])->toArray(),
            'سطورُ الأصل لم تُمسّ',
        );
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
    }

    /** وقيدُ العكس يشير إلى أصله — فيُقرأ الاثنان معًا */
    public function test_the_reversal_points_back_at_what_it_undid(): void
    {
        $entry = $this->expense();

        $this->actingAs($this->owner)
            ->post(route('admin.finance.journal.reverse', $entry->id), ['reason' => 'اتجاه معكوس']);

        $reversal = JournalEntry::where('reverses_id', $entry->id)->firstOrFail();

        $this->assertStringContainsString('حملة تسويقية', $reversal->description);
        $this->assertStringContainsString('اتجاه معكوس', $reversal->description);
    }

    /**
     * وبتاريخ أصله لا بتاريخ اليوم.
     *
     * خطأٌ في أغسطس يُصلَح في أغسطس — وإلّا خرج تقريرُ الشهرين خاطئًا: هذا
     * بزيادةٍ وذاك بنقص، ولا شيءَ في أيٍّ منهما يقول لماذا.
     */
    public function test_the_reversal_carries_the_original_date(): void
    {
        $entry = $this->expense(50, '2026-08-22');

        $this->travelTo(now()->addMonths(2));

        $this->actingAs($this->owner)->post(route('admin.finance.journal.reverse', $entry->id));

        $reversal = JournalEntry::where('reverses_id', $entry->id)->firstOrFail();

        $this->assertSame('2026-08-22', $reversal->entry_date->format('Y-m-d'));
    }

    /* ═══════════════ ما لا يُعكس ═══════════════ */

    /** ولا يُعكس القيد مرّتين — عكسان يقلبان الرصيد */
    public function test_an_entry_is_never_reversed_twice(): void
    {
        $entry = $this->expense();

        $this->actingAs($this->owner)->post(route('admin.finance.journal.reverse', $entry->id));

        $this->actingAs($this->owner)
            ->from(route('admin.finance.journal'))
            ->post(route('admin.finance.journal.reverse', $entry->id))
            ->assertSessionHasErrors('entry');

        $this->assertSame(1, JournalEntry::where('reverses_id', $entry->id)->count());
        $this->assertSame(0.0, $this->balance('5900'), 'ولا ينقلب الرصيد');
    }

    /**
     * ولا يُعكس العكس — ويُقال له لماذا بلفظه.
     *
     * والرسالةُ تُفحص لا وجودُ الخطأ وحده: قيدُ العكس مصدرُه «عكس يدوي»،
     * فلو سُئل عن المصدر أوّلًا لَرُدّ عليه بـ«يُلغى من مستنده» — كلامٌ لا
     * معنى له في عكسٍ لا مستندَ له، وفحصُ العكس يصير حينئذٍ شيفرةً ميّتة
     * تُنزع فلا يتغيّر شيء. وقد نجت طفرةٌ تنزعه، فشُدّ الحارس.
     */
    public function test_a_reversal_is_not_itself_reversible(): void
    {
        $entry = $this->expense();
        $this->actingAs($this->owner)->post(route('admin.finance.journal.reverse', $entry->id));

        $reversal = JournalEntry::where('reverses_id', $entry->id)->firstOrFail();

        $this->actingAs($this->owner)
            ->from(route('admin.finance.journal'))
            ->post(route('admin.finance.journal.reverse', $reversal->id))
            ->assertSessionHasErrors(['entry' => __('هذا قيدُ عكسٍ — ولا يُعكس العكس.')]);

        $this->assertNull($reversal->fresh()->reversed_at);
        $this->assertSame(2, JournalEntry::where('business_id', $this->shop->id)->count());
    }

    /**
     * وقيدُ المستند يُلغى من مستنده لا من هنا.
     *
     * عكسُ قيدِ فاتورةٍ من شاشة القيود يترك الفاتورةَ قائمةً بلا قيد:
     * تُقرأ مستحقّةً في شاشتها ولا أثرَ لها في الدفتر.
     */
    public function test_a_document_entry_is_voided_from_its_document(): void
    {
        $entry = Ledger::post(
            $this->shop->id,
            'فاتورة عميل',
            [
                ['account' => $this->account('1200'), 'debit' => 30.0, 'credit' => 0.0],
                ['account' => $this->account('4100'), 'debit' => 0.0, 'credit' => 30.0],
            ],
            now(),
            'مبيعات',
            null,
            $this->owner->id,
        );

        $this->actingAs($this->owner)
            ->from(route('admin.finance.journal'))
            ->post(route('admin.finance.journal.reverse', $entry->id))
            ->assertSessionHasErrors([
                'entry' => __('قيدُ :source يُلغى من مستنده لا من هنا.', ['source' => 'مبيعات']),
            ]);

        $this->assertNull($entry->fresh()->reversed_at);
    }

    /* ═══════════════ الجار ═══════════════ */

    public function test_a_shop_cannot_reverse_its_neighbours_entry(): void
    {
        $entry = $this->expense();

        $other = Business::create(['name' => 'الجار', 'type' => 'عام', 'status' => 'نشط']);
        Ledger::seedChart($other->id);
        $stranger = User::create([
            'business_id' => $other->id, 'name' => 'جار', 'email' => 'j@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);

        $this->actingAs($stranger)
            ->post(route('admin.finance.journal.reverse', $entry->id))
            ->assertNotFound();

        $this->assertNull($entry->fresh()->reversed_at);
    }

    /* ═══════════════ الشاشة ═══════════════ */

    /**
     * ولا يُعرض المقبضُ لمن لا يُدير شيئًا.
     *
     * زرٌّ يُعرض ويردّ برسالةٍ يُقرأ عطبًا في النظام لا منعًا مقصودًا.
     */
    public function test_the_screen_offers_the_handle_only_where_it_works(): void
    {
        $manual = $this->expense();

        $document = Ledger::post(
            $this->shop->id,
            'فاتورة عميل',
            [
                ['account' => $this->account('1200'), 'debit' => 30.0, 'credit' => 0.0],
                ['account' => $this->account('4100'), 'debit' => 0.0, 'credit' => 30.0],
            ],
            now(),
            'مبيعات',
            null,
            $this->owner->id,
        );

        $rows = collect(
            $this->actingAs($this->owner)
                ->get(route('admin.finance.journal'))
                ->viewData('page')['props']['entries']
        )->keyBy('id');

        $this->assertTrue($rows[$manual->id]['mayReverse'], 'اليدويُّ يُعكس');
        $this->assertFalse($rows[$document->id]['mayReverse'], 'وقيدُ المستند لا');

        /* وبعد العكس لا يبقى المقبض، ويُقال إنّه عُكس */
        $this->actingAs($this->owner)->post(route('admin.finance.journal.reverse', $manual->id));

        $after = collect(
            $this->actingAs($this->owner)
                ->get(route('admin.finance.journal'))
                ->viewData('page')['props']['entries']
        )->keyBy('id');

        $this->assertFalse($after[$manual->id]['mayReverse']);
        $this->assertTrue($after[$manual->id]['reversed'], 'ويُقال في الصفّ إنّه عُكس');
    }

    /* ═══════════════ الحالةُ التي وقعت ═══════════════ */

    /**
     * والعطبُ الذي وقع في متجرٍ حقيقيّ — يُصلَح بشوطين لا بشوط.
     *
     * قيدٌ بالبنك مدينًا والمصروف دائنًا. عكسُه وحدَه يُعيد الرصيدَ إلى
     * الصفر — والمصروفُ وقع فعلًا ومالُه خرج. فيُعكس الخطأ **ويُكتب
     * الصحيحُ مكانه**، والفارقُ في البنك ضِعفُ المبلغ لا مثلُه.
     */
    public function test_a_backwards_expense_is_undone_and_then_written_the_right_way(): void
    {
        /* الاتّجاه المعكوس كما وقع: البنك مدين والمصروف دائن */
        $wrong = Ledger::post(
            $this->shop->id,
            'حملة تسويقية',
            [
                ['account' => $this->account('1200'), 'debit' => 50.0, 'credit' => 0.0],
                ['account' => $this->account('5900'), 'debit' => 0.0, 'credit' => 50.0],
            ],
            Carbon::parse('2026-08-22'),
            'يدوي',
            null,
            $this->owner->id,
        );

        $this->assertSame(50.0, $this->balance('1200'), 'البنكُ مبالغٌ فيه');
        $this->assertSame(-50.0, $this->balance('5900'), 'ومصروفٌ سالبٌ لا وجودَ له');

        // ١ — عكسُ الخطأ
        $this->actingAs($this->owner)
            ->post(route('admin.finance.journal.reverse', $wrong->id), ['reason' => 'اتجاه معكوس']);

        $this->assertSame(0.0, $this->balance('1200'));
        $this->assertSame(0.0, $this->balance('5900'));

        // ٢ — والصحيحُ مكانه
        $this->expense(50, '2026-08-22');

        $this->assertSame(-50.0, $this->balance('1200'), 'المالُ خرج');
        $this->assertSame(50.0, $this->balance('5900'), 'والمصروفُ موجب');

        /* والدفترُ يحمل ثلاثةَ قيود: الخطأ وعكسُه والصحيح */
        $this->assertSame(3, JournalEntry::where('business_id', $this->shop->id)->count());
    }

    /** والميزانُ يبقى متوازنًا بعد كلّ ذلك */
    public function test_the_books_stay_balanced_through_the_correction(): void
    {
        $wrong = Ledger::post(
            $this->shop->id,
            'خطأ',
            [
                ['account' => $this->account('1200'), 'debit' => 10.0, 'credit' => 0.0],
                ['account' => $this->account('5900'), 'debit' => 0.0, 'credit' => 10.0],
            ],
            now(),
            'يدوي',
            null,
            $this->owner->id,
        );

        $this->actingAs($this->owner)->post(route('admin.finance.journal.reverse', $wrong->id));
        $this->expense(10);

        $debit = (float) JournalLine::sum('debit');
        $credit = (float) JournalLine::sum('credit');

        $this->assertSame(round($debit, 3), round($credit, 3), 'الميزان لا يتوازن');
    }
}
