<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Plan;
use App\Models\PlatformDocument;
use App\Models\PlatformDocumentTemplate;
use App\Models\User;
use App\Support\DocumentForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AbaadSubscriptionContract;
use Tests\TestCase;

/**
 * مستندُ الشركة المسجّلة يُرى في ملفّها — ولا يضيع بين مستندات العملاء كلّهم.
 *
 * ═══ ما يُحرس ═══
 *
 *   - ما صدر لشركةٍ مسجّلة يظهر في تبويب «المستندات» من ملفّها، وما صدر لغيرها
 *     أو لعميلٍ جديد لا يظهر فيه.
 *   - ومن ملفّها يُفتح قسمُ المستندات مقصورًا عليها، ونافذةُ الإصدار مفتوحةٌ بها.
 *   - ونافذةُ الإصدار تعرض القوالب كلَّها: ما يُصدر منه، وما ينتظر باسمه وسببه
 *     — والمنتظرُ لا يُصدر منه ولو أُرسل رقمُه.
 */
class ACompanyKeepsItsDocumentsTest extends TestCase
{
    use AbaadSubscriptionContract;
    use RefreshDatabase;

    private User $super;

    private Business $shop;

    private Business $other;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        Storage::fake('local');
        $this->travelTo('2026-10-25 10:00:00');

        $this->plan = $plan = Plan::create(['name' => 'الباقة الاحترافية', 'monthly_price' => 29.9, 'yearly_price' => 299, 'max_branches' => 3, 'max_employees' => 15, 'max_products' => 10000]);
        $this->shop = Business::create(['name' => 'زهور الريان', 'owner_name' => 'سالم الهنائي', 'phone' => '91234567', 'status' => 'نشط', 'plan_id' => $plan->id]);
        $this->other = Business::create(['name' => 'مخبز الرحمة', 'owner_name' => 'خالد', 'phone' => '92345678', 'status' => 'نشط', 'plan_id' => $plan->id]);
        $this->super = User::create(['name' => 'مدير المنصة', 'email' => 'super@abaadapp.test', 'password' => bcrypt('password'), 'role' => 'super_admin', 'status' => 'نشط']);
        DocumentForms::seedTemplates();
        $this->subscriptionContract();
    }

    private function issue(array $data): PlatformDocument
    {
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), $data + [
            'template_id' => $this->subscriptionContract()->id, 'cycle' => 'yearly',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return PlatformDocument::latest('id')->firstOrFail();
    }

    public function test_a_document_issued_to_a_registered_company_is_in_its_file(): void
    {
        $mine = $this->issue(['business_id' => $this->shop->id]);
        $theirs = $this->issue(['business_id' => $this->other->id]);
        $prospect = $this->issue(['client' => ['name' => 'عميل جديد', 'biz' => 'محل جديد', 'phone' => '93456789'], 'plan_id' => $this->plan->id]);

        $this->assertSame($this->shop->id, $mine->client_business_id);
        $this->actingAs($this->super)->get(route('super-admin.businesses.show', $this->shop->id))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.count', 1)
                ->has('documents.rows', 1)
                ->where('documents.rows.0.id', $mine->id)
                ->where('documents.rows.0.number', $mine->number)
                ->where('documents.rows.0.state', PlatformDocument::SENT)
                ->where('documents.new_url', route('super-admin.documents.index', ['business' => $this->shop->id, 'new' => 1]))
                ->where('documents.all_url', route('super-admin.documents.index', ['business' => $this->shop->id])));

        // وشركةٌ بلا مستند تقول ذلك، ومستندُ العميل الجديد في قسم المستندات وحده
        $empty = Business::create(['name' => 'بلا مستندات', 'owner_name' => 'س', 'phone' => '94567890', 'status' => 'نشط']);
        $this->actingAs($this->super)->get(route('super-admin.businesses.show', $empty->id))
            ->assertInertia(fn (Assert $page) => $page->where('documents.count', 0)->has('documents.rows', 0));
        $this->assertNull($prospect->client_business_id);
        $this->assertSame($this->other->id, $theirs->client_business_id);
    }

    public function test_the_company_link_lists_its_documents_and_opens_the_dialog_with_it(): void
    {
        $mine = $this->issue(['business_id' => $this->shop->id]);
        $this->issue(['business_id' => $this->other->id]);

        $this->actingAs($this->super)->get(route('super-admin.documents.index', ['business' => $this->shop->id, 'new' => 1]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents', 1)
                ->where('documents.0.id', $mine->id)
                ->where('company.id', $this->shop->id)
                ->where('company.name', 'زهور الريان')
                ->where('opening', true)
                ->where('filters.business', (string) $this->shop->id));

        $this->actingAs($this->super)->get(route('super-admin.documents.index', ['business' => $this->shop->id]))
            ->assertInertia(fn (Assert $page) => $page->where('opening', false)->has('documents', 1));
        $this->actingAs($this->super)->get(route('super-admin.documents.index'))
            ->assertInertia(fn (Assert $page) => $page->where('company', null)->where('opening', false)->has('documents', 2));
    }

    public function test_the_dialog_names_every_template_and_issues_from_the_ready_ones_only(): void
    {
        $drafts = PlatformDocumentTemplate::whereNull('approved_hash')->orderBy('id')->get();
        $this->assertCount(5, $drafts, 'الاتفاقياتُ الخمس مسوّدات');
        // واحدةٌ اعتُمدت ولم تُفعَّل
        $approved = $drafts->first();
        $approved->forceFill(['approved_hash' => $approved->contentHash(), 'approval_basis' => PlatformDocumentTemplate::BY_OWNER, 'approved_at' => now()])->save();

        $this->actingAs($this->super)->get(route('super-admin.documents.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('templates', 1)
                ->where('templates.0.id', $this->subscriptionContract()->id)
                ->has('waiting', 5)
                ->where('waiting.0.id', $approved->id)
                ->where('waiting.0.reason', 'معتمد — غير مفعّل')
                ->where('waiting.1.reason', 'بانتظار الاعتماد')
                ->where('waiting.4.reason', 'بانتظار الاعتماد'));

        // والمنتظرُ لا يُصدر منه ولو أُرسل رقمُه
        $count = PlatformDocument::count();
        $this->actingAs($this->super)->post(route('super-admin.documents.store'), [
            'template_id' => $drafts->last()->id, 'business_id' => $this->shop->id, 'amount' => $drafts->last()->default_amount,
        ])->assertSessionHasErrors();
        $this->assertSame($count, PlatformDocument::count());
    }
}
