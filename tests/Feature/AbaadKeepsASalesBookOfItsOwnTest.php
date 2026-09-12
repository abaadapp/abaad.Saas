<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CrmLead;
use App\Models\CrmNote;
use App\Models\CrmStageEvent;
use App\Models\CrmTask;
use App\Models\User;
use App\Support\Crm;
use App\Support\CrmLeads;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دفترُ مبيعات أبعاد — ما يُكتب فيه، ومن يكتب، وما لا يُكتب بحال.
 *
 * وأخطرُ ما يُحرَس هنا ثلاثة: أنّ رقمًا واحدًا لا يصير عميلَين، وأنّ
 * الملاحظات الداخليّة لا يبلغها تاجر، وأنّ متجرًا واحدًا لا يُحسب اشتراكَين.
 */
class AbaadKeepsASalesBookOfItsOwnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'سالم', 'email' => 'salem@abaad.om',
            'password' => bcrypt('secret'), 'role' => 'super_admin', 'status' => 'active',
        ]);

        $this->otherAdmin = User::create([
            'name' => 'ريم', 'email' => 'reem@abaad.om',
            'password' => bcrypt('secret'), 'role' => 'super_admin', 'status' => 'active',
        ]);
    }

    /* ═══════════════════ الباب ═══════════════════ */

    public function test_platform_admin_reaches_the_crm(): void
    {
        $this->actingAs($this->admin)->get(route('super-admin.crm.dashboard'))->assertOk();
        $this->actingAs($this->admin)->get(route('super-admin.crm.leads.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('super-admin.crm.pipeline'))->assertOk();
        $this->actingAs($this->admin)->get(route('super-admin.crm.tasks'))->assertOk();
        $this->actingAs($this->admin)->get(route('super-admin.crm.reports'))->assertOk();
    }

    /**
     * وموظّفُ المتجر لا يبلغ الدفتر — على كلّ مسارٍ فيه.
     *
     * والفحصُ على المسارات كلِّها لا على واحدٍ منها: حارسُ المجموعة يُكتب
     * مرّةً ويُنسى في مسارٍ يُضاف بعد سنة، خارجَ القوسين.
     */
    public function test_a_merchant_employee_reaches_nothing_in_the_crm(): void
    {
        $business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'active']);

        $cashier = User::create([
            'name' => 'كاشير', 'email' => 'c@shop.om', 'password' => bcrypt('secret'),
            'role' => 'cashier', 'status' => 'active', 'business_id' => $business->id,
        ]);

        $lead = $this->lead('91234567');

        $gets = [
            route('super-admin.crm.dashboard'),
            route('super-admin.crm.leads.index'),
            route('super-admin.crm.leads.show', $lead->id),
            route('super-admin.crm.pipeline'),
            route('super-admin.crm.tasks'),
            route('super-admin.crm.reports'),
        ];

        foreach ($gets as $url) {
            $this->actingAs($cashier)->get($url)
                ->assertStatus(403, 'بابٌ مفتوحٌ لموظّف متجر: '.$url);
        }

        $posts = [
            route('super-admin.crm.leads.store') => ['phone' => '99887766', 'source' => 'manual'],
            route('super-admin.crm.leads.note', $lead->id) => ['body' => 'اخترقت'],
            route('super-admin.crm.leads.stage', $lead->id) => ['stage' => 'interested'],
            route('super-admin.crm.leads.assign', $lead->id) => ['assigned_to' => null],
        ];

        foreach ($posts as $url => $payload) {
            $this->actingAs($cashier)->post($url, $payload)
                ->assertStatus(403, 'كتابةٌ مفتوحةٌ لموظّف متجر: '.$url);
        }

        $this->assertSame(1, CrmLead::count(), 'كُتب صفٌّ من طلبٍ كان يجب أن يُردّ');
        $this->assertSame(0, CrmNote::count(), 'كُتبت ملاحظةٌ من طلبٍ كان يجب أن يُردّ');
    }

    /* ═══════════════════ الرقم هويّة ═══════════════════ */

    /**
     * رقمٌ واحد كُتب بثلاث صيغٍ — صفٌّ واحد.
     *
     * وهذا ما يمنع أن يردّ موظّفان على نصفَي محادثةٍ واحدة.
     */
    public function test_one_number_written_three_ways_is_one_lead(): void
    {
        $this->actingAs($this->admin);

        $a = CrmLeads::findOrCreateByPhone('91234567');
        $b = CrmLeads::findOrCreateByPhone('+968 9123 4567');
        $c = CrmLeads::findOrCreateByPhone('0096891234567');

        $this->assertTrue($a['created']);
        $this->assertFalse($b['created'], 'الصيغة الدولية صنعت صفًّا ثانيًا');
        $this->assertFalse($c['created'], 'بادئة 00 صنعت صفًّا ثالثًا');

        $this->assertSame($a['lead']->id, $b['lead']->id);
        $this->assertSame($a['lead']->id, $c['lead']->id);
        $this->assertSame(1, CrmLead::count());
    }

    /**
     * والحارسُ الأخير في القاعدة لا في الكود.
     *
     * ═══ وهذا ما أثبتته الطفرة ═══
     *
     * جرّبتُ أن أجعل القراءةَ السابقة تبحث بالرقم الخام بدل المطبَّع —
     * فنجت كلُّ الحرّاس. والسببُ أنّ تلك القراءة **ليست** ما يمنع التكرار:
     * هي اختصارُ طريق. الذي يمنعه فهرسُ التفرّد على العمود المطبَّع، ومعه
     * سقوطُ `Contention` إلى الصفّ الذي كتبه السابق.
     *
     * فالحارسُ يُكتب على ما يحرس فعلًا: القاعدةُ نفسُها تردّ الثاني. وبلا
     * ذلك تبقى بين القراءة والكتابة نافذةٌ يمرّ منها إشعارٌ متزامنٌ من ميتا
     * فيصير للرقم الواحد صفّان — ثمّ يردّ موظّفان على نصفَي محادثة.
     */
    public function test_the_database_itself_refuses_a_second_row_for_one_number(): void
    {
        $this->actingAs($this->admin);
        $this->lead('91234567');

        $this->expectException(UniqueConstraintViolationException::class);

        CrmLead::create([
            'phone' => '96891234567',
            'source' => 'manual',
            'status' => Crm::ACTIVE,
            'stage' => Crm::NEW,
        ]);
    }

    /** والرقمُ يُحفظ كما كتبه صاحبه — ويُقارَن مطبَّعًا */
    public function test_the_raw_number_is_kept_as_written(): void
    {
        $this->actingAs($this->admin);

        $lead = CrmLeads::findOrCreateByPhone('+968 9123 4567')['lead'];

        $this->assertSame('96891234567', $lead->phone);
        $this->assertSame('+968 9123 4567', $lead->phone_raw);
    }

    /** ورقمٌ مكرَّر عبر الشاشة لا يقول «تمّ» — يُفتح ملفُّه ويُقال إنّه قائم */
    public function test_a_duplicate_number_does_not_claim_it_was_added(): void
    {
        $this->actingAs($this->admin);
        $first = $this->lead('91234567');

        $this->actingAs($this->admin)
            ->post(route('super-admin.crm.leads.store'), ['phone' => '+968 9123 4567', 'source' => 'manual'])
            ->assertRedirect(route('super-admin.crm.leads.show', $first->id))
            ->assertSessionHas('toast', fn ($toast) => $toast['type'] === 'warning');

        $this->assertSame(1, CrmLead::count());
    }

    /** ورقمٌ لا يصلح يُردّ برسالةٍ تُقرأ لا بصفحةِ خمسمئة */
    public function test_an_unusable_number_is_refused_in_words(): void
    {
        $this->actingAs($this->admin)
            ->post(route('super-admin.crm.leads.store'), ['phone' => 'abc', 'source' => 'manual'])
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, CrmLead::count());
    }

    /* ═══════════════════ المرحلة وتاريخها ═══════════════════ */

    public function test_a_stage_change_is_written_in_its_history(): void
    {
        $this->actingAs($this->admin);
        $lead = $this->lead('91234567');

        $this->post(route('super-admin.crm.leads.stage', $lead->id), ['stage' => Crm::INTERESTED]);

        $this->assertSame(Crm::INTERESTED, $lead->fresh()->stage);

        $events = CrmStageEvent::where('lead_id', $lead->id)->orderBy('id')->get();
        $this->assertCount(2, $events, 'الإنشاء والانتقال سطران في التاريخ');
        $this->assertSame(Crm::NEW, $events[1]->from_stage);
        $this->assertSame(Crm::INTERESTED, $events[1]->to_stage);
        $this->assertSame('سالم', $events[1]->user_name);
    }

    /**
     * والتاريخُ يبقى وإن مضت المراحل.
     *
     * وهو ما يجعل مسارَ التحويل قابلًا للحساب: عميلٌ صار مشتركًا لا يُعدّ في
     * «مهتمّ» بعمود المرحلة، ويُعدّ في تاريخه.
     */
    public function test_the_funnel_reads_history_not_the_current_stage(): void
    {
        $this->actingAs($this->admin);
        $lead = $this->lead('91234567');

        foreach ([Crm::CONTACTED, Crm::INTERESTED, Crm::QUALIFIED] as $stage) {
            CrmLeads::moveStage($lead, $stage, $this->admin);
        }

        $this->assertSame(Crm::QUALIFIED, $lead->fresh()->stage);

        $reached = CrmStageEvent::where('lead_id', $lead->id)->pluck('to_stage')->all();
        $this->assertContains(Crm::INTERESTED, $reached, 'مرحلةٌ مرّ بها ضاعت من التاريخ');
    }

    /**
     * و«تم الاشتراك» لا تُكتب من قائمة المراحل.
     *
     * لأنّها تعني متجرًا حقيقيًّا؛ وكتابتُها بلا متجرٍ تصنع في التقرير
     * مشتركين لا متاجر لهم.
     */
    public function test_won_cannot_be_reached_from_the_stage_dropdown(): void
    {
        $this->actingAs($this->admin);
        $lead = $this->lead('91234567');

        $this->post(route('super-admin.crm.leads.stage', $lead->id), ['stage' => Crm::WON])
            ->assertSessionHasErrors('stage');

        $this->assertSame(Crm::NEW, $lead->fresh()->stage);
    }

    /** والحالُ يُشتقّ من المرحلة ولا يُكتب باليد */
    public function test_status_follows_the_stage(): void
    {
        $this->actingAs($this->admin);
        $lead = $this->lead('91234567');

        CrmLeads::moveStage($lead, Crm::INTERESTED, $this->admin);
        $this->assertSame(Crm::ACTIVE, $lead->fresh()->status);

        CrmLeads::lose($lead, 'price', null, $this->admin);
        $this->assertSame(Crm::LOST_STATUS, $lead->fresh()->status);
    }

    /* ═══════════════════ الخسارة ═══════════════════ */

    public function test_a_loss_is_refused_without_a_reason(): void
    {
        $this->actingAs($this->admin);
        $lead = $this->lead('91234567');

        $this->post(route('super-admin.crm.leads.lose', $lead->id), [])
            ->assertSessionHasErrors('lost_reason');

        $this->assertSame(Crm::NEW, $lead->fresh()->stage);
    }

    public function test_a_reason_outside_the_list_is_refused(): void
    {
        $this->actingAs($this->admin);
        $lead = $this->lead('91234567');

        $this->post(route('super-admin.crm.leads.lose', $lead->id), ['lost_reason' => 'غالي شوي'])
            ->assertSessionHasErrors('lost_reason');
    }

    /**
     * وإعادةُ الفتح تمحو السبب.
     *
     * سببٌ يبقى على صفٍّ عاد نشطًا يُعدّ في تقرير الأسباب مرّةً ثانية —
     * فيقول التقريرُ إنّنا خسرنا بالسعر عميلًا هو اليوم في المسار.
     */
    public function test_reopening_erases_the_lost_reason(): void
    {
        $this->actingAs($this->admin);
        $lead = $this->lead('91234567');

        CrmLeads::lose($lead, 'price', 'قال غالي', $this->admin);
        $this->assertSame('price', $lead->fresh()->lost_reason);

        $this->post(route('super-admin.crm.leads.reopen', $lead->id));

        $fresh = $lead->fresh();
        $this->assertNull($fresh->lost_reason, 'سببُ الخسارة بقي على صفٍّ عاد نشطًا');
        $this->assertNull($fresh->lost_note);
        $this->assertSame(Crm::ACTIVE, $fresh->status);
    }

    /* ═══════════════════ الإسناد ═══════════════════ */

    public function test_a_lead_is_assigned_and_the_assigner_is_recorded(): void
    {
        $this->actingAs($this->admin)
            ->post(route('super-admin.crm.leads.assign', $this->lead('91234567')->id), [
                'assigned_to' => $this->otherAdmin->id,
            ])->assertSessionHasNoErrors();

        $lead = CrmLead::first();
        $this->assertSame($this->otherAdmin->id, $lead->assigned_to);
        $this->assertSame($this->admin->id, $lead->assigned_by);
        $this->assertNotNull($lead->assigned_at);
    }

    /**
     * ولا يُسنَد إلى موظّف متجر — ولو مرَّ `exists:users,id`.
     *
     * وهو يمرّ: الجدول يحمل كلَّ كاشيرٍ في كلّ متجر.
     */
    public function test_a_lead_is_never_assigned_to_a_tenant_employee(): void
    {
        $business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'active']);

        $cashier = User::create([
            'name' => 'كاشير', 'email' => 'c@shop.om', 'password' => bcrypt('secret'),
            'role' => 'cashier', 'status' => 'active', 'business_id' => $business->id,
        ]);

        $lead = $this->lead('91234567');

        $this->actingAs($this->admin)
            ->post(route('super-admin.crm.leads.assign', $lead->id), ['assigned_to' => $cashier->id])
            ->assertSessionHasErrors('assigned_to');

        $this->assertNull($lead->fresh()->assigned_to);
    }

    /** ولا تُسنَد مهمّةٌ إليه كذلك — البابان اثنان ويُغلقان معًا */
    public function test_a_task_is_never_assigned_to_a_tenant_employee(): void
    {
        $business = Business::create(['name' => 'محل ورد', 'type' => 'محل ورود', 'status' => 'active']);

        $cashier = User::create([
            'name' => 'كاشير', 'email' => 'c2@shop.om', 'password' => bcrypt('secret'),
            'role' => 'cashier', 'status' => 'active', 'business_id' => $business->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('super-admin.crm.leads.tasks.store', $this->lead('91234567')->id), [
                'title' => 'اتصل به',
                'due_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'assigned_to' => $cashier->id,
                'priority' => 'normal',
            ])->assertSessionHasErrors('assigned_to');

        $this->assertSame(0, CrmTask::count());
    }

    /* ═══════════════════ المهامّ ═══════════════════ */

    /**
     * ومهمّةٌ بلا موعدٍ تُردّ.
     *
     * لأنّها لا تتأخّر أبدًا، فلا تظهر في «المتأخّرة» ولا تُنبّه أحدًا — سطرٌ
     * يُكتب ليُنسى.
     */
    public function test_a_task_without_a_due_date_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('super-admin.crm.leads.tasks.store', $this->lead('91234567')->id), [
                'title' => 'اتصل به', 'priority' => 'normal',
            ])->assertSessionHasErrors('due_at');

        $this->assertSame(0, CrmTask::count());
    }

    public function test_a_completed_task_records_who_and_when(): void
    {
        $this->actingAs($this->admin);
        $lead = $this->lead('91234567');

        $this->post(route('super-admin.crm.leads.tasks.store', $lead->id), [
            'title' => 'أرسل عرض السعر',
            'due_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'priority' => 'high',
        ])->assertSessionHasNoErrors();

        $task = CrmTask::firstOrFail();
        $this->actingAs($this->otherAdmin)
            ->post(route('super-admin.crm.tasks.update', $task->id), ['status' => 'done']);

        $done = $task->fresh();
        $this->assertSame('done', $done->status);
        $this->assertSame($this->otherAdmin->id, $done->completed_by);
        $this->assertNotNull($done->completed_at);
    }

    /* ═══════════════════ الملاحظات ═══════════════════ */

    /**
     * الملاحظةُ الداخليّة لا تخرج من المنصّة — ولا جدولَ تاجرٍ يمسّها.
     *
     * والفحصُ على اسم الكاتب أيضًا: حسابٌ يُحذف بعد سنةٍ لا يجوز أن يُمحى
     * معه من الدفتر أنّ أحدًا كتب هذا.
     */
    public function test_a_note_stays_inside_the_platform(): void
    {
        $this->actingAs($this->admin);
        $lead = $this->lead('91234567');

        $this->post(route('super-admin.crm.leads.note', $lead->id), ['body' => 'يريد خصمًا — لا تعرضه']);

        $note = CrmNote::firstOrFail();
        $this->assertSame('سالم', $note->user_name);
        $this->assertSame($lead->id, $note->lead_id);

        /*
         * ولا تظهر في أيّ جدولٍ يقرؤه تاجر.
         *
         * والفحصُ بالنصّ في القاعدة كلِّها لا بالنموذج: نسخةٌ تُكتب في جدولٍ
         * آخر سهوًا هي ما يُبحث عنه، ولا يعرفه النموذج.
         */
        $this->assertDatabaseMissing('support_messages', ['body' => 'يريد خصمًا — لا تعرضه']);
        $this->assertDatabaseMissing('whatsapp_messages', ['recipient_phone' => '96891234567']);
    }

    /* ═══════════════════ التحويل ═══════════════════ */

    public function test_conversion_links_a_business_and_keeps_the_history(): void
    {
        $this->actingAs($this->admin);
        $lead = $this->lead('91234567');
        CrmLeads::moveStage($lead, Crm::INTERESTED, $this->admin);

        $business = Business::create(['name' => 'ورد السيب', 'type' => 'محل ورود', 'status' => 'active']);

        $this->post(route('super-admin.crm.leads.convert', $lead->id), ['business_id' => $business->id])
            ->assertSessionHasNoErrors();

        $fresh = $lead->fresh();
        $this->assertSame($business->id, $fresh->converted_business_id);
        $this->assertSame(Crm::WON, $fresh->stage);
        $this->assertSame(Crm::CONVERTED, $fresh->status);
        $this->assertNotNull($fresh->converted_at);

        /* والتاريخُ لم يُمحَ: أربعةُ أسطرٍ — إنشاء، مهتمّ، مشترك */
        $this->assertGreaterThanOrEqual(3, CrmStageEvent::where('lead_id', $lead->id)->count());
    }

    /**
     * ولا متجرَ يُربط بعميلَين.
     *
     * وإلّا صار متجرٌ واحدٌ محسوبًا اشتراكَين في تقرير التحويل، ونسبةُ
     * التحويل تُقرأ أعلى ممّا هي.
     */
    public function test_one_business_is_never_linked_to_two_leads(): void
    {
        $this->actingAs($this->admin);
        $business = Business::create(['name' => 'ورد السيب', 'type' => 'محل ورود', 'status' => 'active']);

        $first = $this->lead('91234567');
        $second = $this->lead('99887766');

        $this->post(route('super-admin.crm.leads.convert', $first->id), ['business_id' => $business->id])
            ->assertSessionHasNoErrors();

        $this->post(route('super-admin.crm.leads.convert', $second->id), ['business_id' => $business->id])
            ->assertSessionHasErrors('business_id');

        $this->assertNull($second->fresh()->converted_business_id);
        $this->assertSame(1, CrmLead::whereNotNull('converted_business_id')->count());
    }

    /** ولا يُنشئ التحويلُ متجرًا — البابُ للربط لا للإنشاء */
    public function test_conversion_creates_no_business(): void
    {
        $this->actingAs($this->admin);
        $business = Business::create(['name' => 'ورد السيب', 'type' => 'محل ورود', 'status' => 'active']);
        $before = Business::count();

        $this->post(route('super-admin.crm.leads.convert', $this->lead('91234567')->id), [
            'business_id' => $business->id,
        ]);

        $this->assertSame($before, Business::count(), 'التحويلُ صنع متجرًا ثانيًا');
    }

    /* ═══════════════════ نسبةُ التحويل ═══════════════════ */

    /**
     * ولا نسبةَ تُعرض قبل أن يُحسم شيء.
     *
     * «0%» عن دفترٍ لم يُغلق فيه صفٌّ تقريرُ حالٍ كاذب: يقرؤه صاحبُه على
     * أنّنا نخسر الجميع.
     */
    public function test_no_conversion_rate_is_shown_before_anything_closes(): void
    {
        $this->actingAs($this->admin);
        $this->lead('91234567');

        $props = $this->get(route('super-admin.crm.dashboard'))->viewData('page')['props'];

        $this->assertNull($props['metrics']['conversionRate'], 'نسبةٌ عُرضت ولا شيء حُسم');
    }

    /** والمقامُ ما حُسم لا الدفترُ كلُّه */
    public function test_the_conversion_rate_divides_by_what_closed(): void
    {
        $this->actingAs($this->admin);

        $won = $this->lead('91234567');
        $lost = $this->lead('99887766');
        $this->lead('92223333');   // نشطٌ لم يُحسم — لا يدخل المقام

        $business = Business::create(['name' => 'ورد', 'type' => 'محل ورود', 'status' => 'active']);
        CrmLeads::convert($won, $business, $this->admin);
        CrmLeads::lose($lost, 'price', null, $this->admin);

        $props = $this->get(route('super-admin.crm.dashboard'))->viewData('page')['props'];

        $this->assertSame(50.0, $props['metrics']['conversionRate'], 'النشطُ دخل المقام فخفّضت النسبة');
    }

    /* ═══════════════════ أدواتٌ ═══════════════════ */

    private function lead(string $phone): CrmLead
    {
        return CrmLeads::findOrCreateByPhone($phone)['lead'];
    }
}
