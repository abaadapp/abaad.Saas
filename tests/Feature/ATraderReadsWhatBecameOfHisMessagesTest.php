<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Support\Demo;
use App\Support\OrderStatus;
use App\Support\WhatsAppEvent;
use App\Support\WhatsAppLog;
use App\Support\WhatsAppMode;
use App\Support\WhatsAppQuota;
use App\Support\WhatsAppStatus;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionClass;
use Tests\TestCase;

/**
 * التاجرُ يقرأ ما جرى لرسائله — لا يسأل عنه إنسانًا.
 *
 * ═══ العطب ═══
 *
 * `whatsapp_messages` مكتوبٌ في رأسه أنّه «دفتر الحقيقة الذي يُدقَّق»، وفيه
 * صفٌّ لكلّ رسالةٍ حتّى الممنوعةِ بسببها. ولم تكن تفتحه شاشةٌ واحدة. فكان
 * جرسُ اللوحة يقول «لم تصل ٣ رسائل إلى زبائنك» ويقود إلى **شاشة الربط** —
 * وهي تقول إنّ الرقم مربوطٌ وحصّتَك باقية، ولا تعرف أيَّ الثلاث ولا لمن ولا
 * لماذا.
 *
 * فيُقال للتاجر إنّ شيئًا انكسر ولا يُقال ماذا. وبابٌ معروضٌ لا يُفتح أسوأ
 * من بابٍ لا يُعرض.
 *
 * وهذه الحرّاسُ تحرس ثلاثةً: أنّ الدفتر يُفتح ولا يعبر حدَّ المتجر، وأنّ كلّ
 * حالٍ وكلّ سببٍ له كلمةٌ تُقرأ، وأنّ «خرجت» لا تُسمّى «وصلت».
 */
class ATraderReadsWhatBecameOfHisMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;

    private Business $theirs;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');

        $this->mine = $this->shop('محل ورد');
        $this->theirs = $this->shop('محل آخر');

        Setting::updateOrCreate(['business_id' => null, 'key' => 'whatsapp_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['business_id' => null, 'key' => WhatsAppQuota::DEFAULT_KEY], ['value' => '100']);

        WhatsAppConnection::create([
            'owner_type' => WhatsAppMode::OWNER_PLATFORM,
            'purpose' => WhatsAppMode::PURPOSE_NOTIFICATIONS,
            'phone_number_id' => 'PN', 'display_phone_number' => '+96890000000',
            'access_token' => 'tok-0123456789abcdef',
            'status' => WhatsAppConnection::ACTIVE, 'connected_at' => now(),
        ]);

        $this->owner = User::create([
            'business_id' => $this->mine->id, 'name' => 'المالك', 'email' => 'o@abaad.om',
            'password' => bcrypt('password'), 'role' => 'admin', 'status' => 'نشط',
        ]);
    }

    private function shop(string $name): Business
    {
        $b = Business::create([
            'name' => $name, 'type' => 'محل ورود', 'status' => 'نشط', 'whatsapp_enabled' => true,
        ]);
        Branch::create(['business_id' => $b->id, 'name' => 'الرئيسي']);

        return $b;
    }

    /** صفٌّ في الدفتر — يُكتب مباشرةً، فالمقصودُ قراءتُه لا طريقُ كتابته */
    private function row(Business $b, array $attrs = []): WhatsAppMessage
    {
        return WhatsAppMessage::create(array_merge([
            'business_id' => $b->id,
            'source_mode' => WhatsAppMode::ABAAD_SHARED,
            'event_type' => WhatsAppEvent::ORDER_READY,
            'direction' => 'outbound',
            'recipient_phone' => '96899887766',
            'dedupe_key' => 'k-'.$b->id.'-'.uniqid('', true),
            'status' => WhatsAppStatus::SENT,
            'created_at' => now(),
        ], $attrs));
    }

    /** فتحُ الدفتر — والفحصُ يُمرَّر إليه، فلا يُكرَّر سطرُ الفتح في كلّ حارس */
    private function open(array $query, Closure $check): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.marketing.whatsapp.log', $query))
            ->assertOk()
            ->assertInertia($check);
    }

    /* ═════════════ أوّلًا: الدفترُ يُفتح، ولا يعبر حدَّ المتجر ═════════════ */

    /** الصفوفُ تصل الشاشة — وهذا أصلُ الميزة كلِّها */
    public function test_the_book_opens_and_shows_what_went_out(): void
    {
        $this->row($this->mine, ['status' => WhatsAppStatus::DELIVERED]);

        $this->open([], fn (Assert $p) => $p->has('rows', 1));
    }

    /**
     * ولا يقرأ تاجرٌ دفترَ متجرٍ آخر — وفيه أرقامُ زبائنه.
     *
     * والفحصُ على الصفوف لا على رمز الرد: صفحةٌ تُردّ بـ٢٠٠ وقد عرضت ما لا
     * يجوز تبدو سليمةً وهي أسوأ ما في الباب.
     */
    public function test_another_shops_rows_never_reach_this_book(): void
    {
        $this->row($this->mine, ['recipient_phone' => '96891111111']);
        $this->row($this->theirs, ['recipient_phone' => '96892222222']);

        $this->open([], fn (Assert $p) => $p
            ->has('rows', 1)
            ->where('rows.0.phone', '96891111111'));
    }

    /**
     * ومعرّفُ المتجر من الجلسة لا من العنوان.
     *
     * لو قُرئ ممّا يصل لَكفى أن يُبدَّل رقمٌ في شريط العنوان ليُقرأ دفترُ
     * الجيران — ولا شيء في الشاشة يقول إنّ شيئًا تبدّل.
     */
    public function test_a_number_in_the_address_does_not_open_the_neighbours_book(): void
    {
        $this->row($this->mine, ['recipient_phone' => '96891111111']);
        $this->row($this->theirs, ['recipient_phone' => '96892222222']);

        $this->open(
            ['business_id' => $this->theirs->id, 'business' => $this->theirs->id],
            fn (Assert $p) => $p->has('rows', 1)->where('rows.0.phone', '96891111111'),
        );
    }

    /* ═════════════ ثانيًا: كلُّ حالٍ وكلُّ سببٍ له كلمة ═════════════ */

    /**
     * لا حالةَ بلا كلمةٍ تُقرأ.
     *
     * والحارسُ يقرأ `ALL` لا قائمةً مكتوبةً هنا: حالةٌ تُضاف غدًا تسقط في
     * هذا الفحص قبل أن تُعرض في عمودٍ عربيٍّ بحروفٍ إنجليزية.
     */
    public function test_no_status_reaches_a_screen_without_a_word(): void
    {
        foreach (WhatsAppStatus::ALL as $status) {
            $this->assertArrayHasKey($status, WhatsAppStatus::LABELS, 'حالةٌ بلا كلمة: '.$status);
            $this->assertNotSame($status, WhatsAppStatus::label($status), 'حالةٌ تُعرض باسم عمودها: '.$status);
        }
    }

    /**
     * ولا سببَ امتناعٍ بلا جملةٍ تقول ما جرى.
     *
     * والقائمةُ تُقرأ بالانعكاس من ثوابت `SKIP_*` نفسِها: سببٌ يُضاف في
     * `WhatsAppStatus` ثمّ يُكتب في `error_code` يظهر للتاجر
     * `own_mode_not_entitled` — وهو نصٌّ لا يعني له شيئًا.
     */
    public function test_no_refusal_reason_reaches_a_screen_without_a_sentence(): void
    {
        $skips = array_filter(
            (new ReflectionClass(WhatsAppStatus::class))->getConstants(),
            fn ($v, $k) => str_starts_with($k, 'SKIP_') && is_string($v),
            ARRAY_FILTER_USE_BOTH,
        );

        $this->assertNotEmpty($skips);

        foreach ($skips as $name => $code) {
            $this->assertArrayHasKey($code, WhatsAppStatus::SKIP_REASONS, 'سببٌ بلا جملة: '.$name);
            $this->assertNotSame($code, WhatsAppStatus::reason($code), 'سببٌ يُعرض برمزه: '.$name);
        }
    }

    /** والسببُ يصل الصفَّ نفسَه — لا يُترك للتاجر أن يخمّن */
    public function test_a_refused_row_says_why_in_words(): void
    {
        $this->row($this->mine, [
            'status' => WhatsAppStatus::SKIPPED,
            'error_code' => WhatsAppStatus::SKIP_EVENT_OFF,
        ]);

        $this->open([], fn (Assert $p) => $p->where(
            'rows.0.reason',
            WhatsAppStatus::SKIP_REASONS[WhatsAppStatus::SKIP_EVENT_OFF],
        ));
    }

    /**
     * ورقمُ ميتا لا يُسمّى سببًا مخترَعًا.
     *
     * `131047` ليست امتناعًا عندنا بل ردَّ المزوّد، ونصُّها يصل في
     * `error_message`. وإلصاقُ جملةٍ بها يعني أن نُسمّي عطبًا لا نعرفه.
     */
    public function test_a_meta_code_is_not_given_a_reason_we_invented(): void
    {
        $this->row($this->mine, [
            'status' => WhatsAppStatus::FAILED,
            'error_code' => '131047',
            'error_message' => 'Re-engagement message',
        ]);

        $this->open([], fn (Assert $p) => $p
            ->where('rows.0.reason', null)
            ->where('rows.0.error', 'Re-engagement message'));
    }

    /**
     * والعطبُ يُنسب إلى صاحبه — بالقائمة التي يقرؤها جرسُ اللوحة نفسِها.
     *
     * قولُ «راجع رقم زبونك» لتاجرٍ تطبيقُنا محجوب لومٌ في غير محلّه، ويجعله
     * يلاحق ما لا يملك إصلاحه.
     */
    public function test_a_failure_names_whose_fault_it_is(): void
    {
        /* والأحدثُ أوّلًا — الدفترُ يُقرأ من أعلاه */
        $this->row($this->mine, ['status' => WhatsAppStatus::FAILED, 'error_code' => '131047']);
        $this->row($this->mine, ['status' => WhatsAppStatus::FAILED, 'error_code' => '0']);

        $this->open([], fn (Assert $p) => $p
            ->where('rows.0.ours', true)
            ->where('rows.1.ours', false));
    }

    /* ═════════════ ثالثًا: «خرجت» ليست «وصلت» ═════════════ */

    /**
     * `sent` تعني أنّ واتساب قبِلها، لا أنّ الهاتف استلمها.
     *
     * وكتابةُ «وصلت» عليها تجعل التاجر يقسم لزبونه أنّ الرسالة وصلته وهي
     * راقدةٌ عند ميتا — وتقريرُ حالٍ كاذب أسوأ من غياب التقرير.
     */
    public function test_accepted_by_meta_is_never_called_arrived(): void
    {
        $sent = WhatsAppStatus::label(WhatsAppStatus::SENT);

        $this->assertStringNotContainsString('وصل', $sent, '«'.$sent.'» تُقرأ وصولًا ولم تصل');
        $this->assertStringContainsString('خرج', $sent);
        $this->assertStringContainsString('وصل', WhatsAppStatus::label(WhatsAppStatus::DELIVERED));
    }

    /* ═════════════ رابعًا: الحِزَمُ قائمةٌ واحدة ═════════════ */

    /**
     * اتّحادُ الحِزَم هو `ALL` تمامًا — لا زيادةَ ولا نقص.
     *
     * المرشِّحُ يسأل «أرِني ما لم يصل» والملخّصُ يعدّ «كم لم يصل»، وقائمتان
     * لسؤالٍ واحد تفترقان يوم تُضاف حالة: يعدّها الملخّص ولا يعرضها
     * المرشِّح — أو تسقط من كليهما فلا تُرى أبدًا.
     */
    public function test_every_status_sits_in_exactly_one_bucket(): void
    {
        $all = [];

        foreach (WhatsAppStatus::BUCKETS as $name => $statuses) {
            $this->assertArrayHasKey($name, WhatsAppStatus::BUCKET_LABELS, 'حزمةٌ بلا كلمة: '.$name);

            foreach ($statuses as $s) {
                $this->assertNotContains($s, $all, 'حالةٌ في حزمتين: '.$s);
                $all[] = $s;
            }
        }

        sort($all);
        $expected = WhatsAppStatus::ALL;
        sort($expected);

        $this->assertSame($expected, $all, 'حالةٌ خارج كلّ حزمة لا يعرضها مرشِّحٌ ولا يعدّها ملخّص');
    }

    /** والمرشِّحُ يضيق إلى حزمته — لا يعرض ما لا يُسأل عنه */
    public function test_the_filter_narrows_to_its_bucket(): void
    {
        $this->row($this->mine, ['status' => WhatsAppStatus::DELIVERED]);
        $this->row($this->mine, ['status' => WhatsAppStatus::FAILED, 'error_code' => '0']);

        $this->open(['filter' => 'failed'], fn (Assert $p) => $p
            ->has('rows', 1)
            ->where('rows.0.status', WhatsAppStatus::FAILED));
    }

    /**
     * والبحثُ يجد — صندوقٌ يُكتب فيه ولا يُضيّق شيئًا مقبضٌ لا يُدير شيئًا.
     *
     * والسؤالُ الذي يُفتح له الدفتر هو «أين رسالةُ هذا الزبون؟»، فالرقمُ
     * أوّلُ ما يُكتب فيه.
     */
    public function test_the_search_narrows_to_the_number_asked_for(): void
    {
        $this->row($this->mine, ['recipient_phone' => '96891111111']);
        $this->row($this->mine, ['recipient_phone' => '96892222222']);

        $this->open(['q' => '2222'], fn (Assert $p) => $p
            ->has('rows', 1)
            ->where('rows.0.phone', '96892222222'));
    }

    /**
     * ومرشِّحٌ لا نعرفه يعود إلى «الكلّ» لا إلى صفحةٍ فارغة.
     *
     * صفحةٌ فارغةٌ تُقرأ «لا رسائل لك» — وهي كذبةٌ يقودها حرفٌ في العنوان.
     */
    public function test_an_unknown_filter_does_not_empty_the_book(): void
    {
        $this->row($this->mine);
        $this->row($this->mine);

        $this->open(['filter' => 'nonsense'], fn (Assert $p) => $p
            ->has('rows', 2)
            ->where('params.filter', 'all'));
    }

    /** والملخّصُ يعدّ كلَّ صفٍّ عنده — لا صفَّ يسقط لأنّ حالتَه لم تُصنَّف */
    public function test_the_summary_counts_every_row_it_has(): void
    {
        foreach (WhatsAppStatus::ALL as $status) {
            $this->row($this->mine, ['status' => $status]);
        }

        $summary = WhatsAppLog::summary($this->mine->id);

        $this->assertSame(count(WhatsAppStatus::ALL), array_sum($summary));
    }

    /* ═════════════ خامسًا: الجرسُ يقود إلى الصفوف ═════════════ */

    /**
     * جرسُ «لم تصل ٣ رسائل» يفتح الصفوفَ الفاشلةَ نفسَها.
     *
     * وكان يقود إلى شاشة الربط: تقول إنّ الرقم مربوطٌ وحصّتَك باقية، ولا
     * تعرف أيَّ الثلاث ولا لمن. فيُقال إنّ شيئًا انكسر ولا يُقال ماذا.
     */
    public function test_the_bell_leads_to_the_failed_rows_themselves(): void
    {
        $this->row($this->mine, [
            'status' => WhatsAppStatus::FAILED,
            'error_code' => '0',
            'error_message' => 'API access blocked',
            'failed_at' => now(),
        ]);

        $this->actingAs($this->owner);

        $alert = collect(Demo::allNotifications())->firstWhere('key', 'wa-delivery');

        $this->assertNotNull($alert, 'لا جرسَ لفشلٍ وقع');
        $this->assertSame(
            route('admin.marketing.whatsapp.log', ['filter' => 'failed']),
            $alert['url'],
            'الجرسُ يقود إلى شاشةٍ لا تعرف أيَّ رسالةٍ فشلت',
        );
    }

    /* ═════════════ سادسًا: الصفُّ يُسمّي ورقته ═════════════ */

    /** ورسالةٌ عن طلبٍ تفتح الطلبَ نفسه — لا تُترك رقمًا في عمود */
    public function test_a_row_opens_the_paper_it_speaks_of(): void
    {
        $customer = Customer::create([
            'business_id' => $this->mine->id, 'name' => 'زبون', 'phone' => '99887766',
        ]);

        $order = Order::create([
            'business_id' => $this->mine->id,
            'branch_id' => Branch::where('business_id', $this->mine->id)->value('id'),
            'customer_id' => $customer->id, 'number' => 'ORD-9',
            'status' => OrderStatus::READY, 'total' => 10, 'subtotal' => 10,
        ]);

        $this->row($this->mine, ['order_id' => $order->id]);

        $this->open([], fn (Assert $p) => $p
            ->where('rows.0.subject.label', '#ORD-9')
            ->where('rows.0.subject.url', route('admin.orders.show', 'ORD-9')));
    }
}
