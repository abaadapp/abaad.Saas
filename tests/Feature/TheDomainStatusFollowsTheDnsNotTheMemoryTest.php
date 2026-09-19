<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\WebsiteDomain;
use App\Support\Website\Domain\CustomDomainProvider;
use App\Support\Website\Domain\DomainCheck;
use App\Support\Website\Domains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * حالُ النطاق تتبع السجلّ — لا الذاكرة.
 *
 * قيس على الإنتاج: نطاقُ تاجرٍ «متصل» في لوحته منذ أسبوع، ولا سجلَّ له في
 * DNS أصلًا، و«آخر فحص» فارغ. الجوابُ الأوّل صار الجوابَ الأبديّ لأنّ لا
 * أحد يسأل ثانيةً. انظر `DomainsCheck`.
 */
class TheDomainStatusFollowsTheDnsNotTheMemoryTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'متجري', 'type' => 'عام', 'status' => 'نشط', 'site_slug' => 'mine',
        ]);
    }

    /** نطاقٌ بحالٍ مكتوبة — كما تركته هجرةُ النقل: «متصل» بلا فحصٍ قطّ */
    private function domain(string $host, string $type = Domains::CUSTOM, string $status = Domains::ACTIVE): WebsiteDomain
    {
        return WebsiteDomain::create([
            'business_id' => $this->business->id,
            'hostname' => $host,
            'normalized_hostname' => $host,
            'type' => $type,
            'status' => $status,
            'verified_at' => now()->subWeek(),
            'is_primary' => false,
        ]);
    }

    /** مزوّدٌ يقول ما نريد ويعدّ من سُئل عنه — فلا يخرج اختبارٌ إلى الشبكة */
    private function provider(DomainCheck $answer, \ArrayObject $asked): void
    {
        $this->app->bind(CustomDomainProvider::class, fn () => new class($answer, $asked) implements CustomDomainProvider
        {
            public function __construct(private DomainCheck $answer, private \ArrayObject $asked) {}

            public function name(): string
            {
                return 'fake';
            }

            public function instructions(WebsiteDomain $domain): array
            {
                return [];
            }

            public function register(WebsiteDomain $domain): ?string
            {
                return null;
            }

            public function check(WebsiteDomain $domain): DomainCheck
            {
                $this->asked[] = $domain->hostname;

                return $this->answer;
            }

            public function forget(WebsiteDomain $domain): void {}
        });
    }

    /** نطاقٌ «متصل» زال سجلُّه يعود «جارٍ الربط» — ولا يبقى على كلمته القديمة */
    public function test_an_active_domain_whose_record_vanished_is_no_longer_active(): void
    {
        $asked = new \ArrayObject;
        $this->provider(DomainCheck::waiting('لم يظهر السجلّ بعد'), $asked);
        $domain = $this->domain('mystore.om');

        $this->artisan('domains:check')->assertSuccessful();

        $domain->refresh();
        $this->assertSame(['mystore.om'], $asked->getArrayCopy(), 'النطاقُ الخاصّ لم يُسأل عنه');
        $this->assertSame(Domains::VERIFYING, $domain->status, 'بقي «متصلًا» بعد أن زال سجلُّه');
        $this->assertNull($domain->verified_at, 'تاريخُ التحقّق بقي على جوابٍ لم يعد صحيحًا');
        $this->assertNotNull($domain->last_checked_at, 'الفحصُ لم يُؤرَّخ فلا يعرف التاجر متى سُئل');
    }

    /** وعنوانُ أبعاد الفرعيّ لا يُسأل عنه — حالُه من `site_slug` لا من DNS */
    public function test_the_platform_address_is_not_asked_about(): void
    {
        $asked = new \ArrayObject;
        $this->provider(DomainCheck::waiting(), $asked);
        $platform = $this->domain('mine.abaadapp.om', Domains::PLATFORM);

        $this->artisan('domains:check')->assertSuccessful();

        $this->assertSame([], $asked->getArrayCopy(), 'عنوانُ أبعاد أُرسل إلى مزوّد DNS');
        $this->assertSame(Domains::ACTIVE, $platform->fresh()->status);
    }

    /** ونطاقٌ عاد يشير إلينا يعود «متصلًا» — الاتّجاهان لا اتّجاهٌ واحد */
    public function test_a_domain_that_points_back_becomes_active_again(): void
    {
        $asked = new \ArrayObject;
        $this->provider(DomainCheck::active(), $asked);
        $domain = $this->domain('mystore.om', Domains::CUSTOM, Domains::FAILED);

        $this->artisan('domains:check')->assertSuccessful();

        $this->assertSame(Domains::ACTIVE, $domain->fresh()->status);
    }

    /** والفحصُ مجدولٌ — أمرٌ لا يشغّله أحد كزرٍّ لا يضغطه أحد */
    public function test_the_check_is_on_the_daily_schedule(): void
    {
        $commands = collect(Schedule::events())
            ->map(fn ($e) => (string) $e->command)
            ->filter(fn ($c) => str_contains($c, 'domains:check'));

        $this->assertCount(1, $commands, 'domains:check ليس في الجدول');
    }
}
