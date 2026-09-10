<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * العنوانُ كِيانٌ له حال — لا حقلٌ نصّيّ في الإعدادات.
 *
 * ═══ ما كان ═══
 *
 * حالُ عنوان المتجر موزّعةٌ على ثلاثة مواضع لا يعرف أحدُها الآخر:
 *
 * · `businesses.site_slug` — الاسم المحجوز، ومنه يُبنى `متجري.abaadapp.om`.
 * · `settings.site_domain` — النطاق الذي يملكه التاجر، صفٌّ في جدول مفاتيح.
 * · `settings.site_path` — أيَّ بطاقةٍ تعرض الشاشة.
 *
 * وثمنُ ذلك ثلاثة، وكلُّها وقعت أو تقع:
 *
 * ١) **لا تفرّدَ في القاعدة.** الفحصُ في المتحكّم وحده (`domainTakenBySomeoneElse`)،
 *    فطلبان متزامنان يمرّان كلاهما ويصير للعنوان صاحبان. والقارئ ينتقي صفًّا
 *    من صفّين بترتيبٍ لا يضمنه محرّكٌ لأحد — فيرى تاجرٌ موقعَ جاره على نطاقه.
 *
 * ٢) **ولا حالَ للربط.** النطاق إمّا مكتوبٌ أو لا. فالتاجر يكتبه ويرى «حُفظ»
 *    ثمّ يفتحه فلا يعمل — ولا شيء في اللوحة يقول: أوجّهتَ سجلَّك؟ أوصل
 *    التوجيه؟ أجهزت الشهادة؟ فيراسل الدعم، ويسأله الدعم أسئلةً لا جواب لها
 *    في النظام.
 *
 * ٣) **ولا موضعَ لمزوّد.** ربطُ نطاقٍ في منصّةٍ حقيقية يمرّ بمزوّد يُصدر
 *    الشهادة ويتحقّق من التوجيه. وحقلٌ نصّيٌّ لا مكان فيه لمعرّفٍ عنده ولا
 *    لحالِ إصدارٍ ولا لسبب فشل.
 *
 * ═══ وما صار ═══
 *
 * صفٌّ لكلّ عنوانٍ يفتح موقعًا: نطاقُ أبعاد الفرعيّ (`platform`) والنطاقُ الذي
 * يملكه التاجر (`custom`) كلاهما صفٌّ في هذا الجدول، لأنّ كليهما عنوانٌ يُحلّ
 * ويُخدَم ويُفهرس. والتفرّدُ في الفهرس لا في المتحكّم.
 *
 * ولا يُمسّ ما كان: `site_slug` يبقى **الاسمَ المحجوز** الذي يختاره التاجر
 * ويُفحص تفرّدُه، و`site_domain` يبقى مقروءًا لمن يقرؤه اليوم. وهذا الجدول
 * يُبنى منهما ويُكتب معهما من كاتبٍ واحد (`App\Support\Website\Domains`) —
 * فلا يفترقان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            /*
             * والموقعُ اختياريّ: التاجر يحجز عنوانه قبل أن يبني موقعه.
             *
             * ولو كان مطلوبًا لَما أمكن حجزُ الاسم إلا بعد بناء الموقع —
             * وهو ترتيبٌ يعاكس ما يفعله الناس.
             */
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();

            // كما كتبه صاحبُه — يُعرض له بحروفه
            $table->string('hostname', 255);
            /*
             * وكما يُطابَق: صغيرُ الحروف، بلا بادئةٍ ولا مسارٍ ولا منفذ،
             * والعربيُّ بترميز `xn--`. والتفرّد على هذا لا على المكتوب:
             * `WROOD.OM` و`wrood.om` و`https://wrood.om/` عنوانٌ واحد.
             */
            $table->string('normalized_hostname', 255)->unique();

            // platform: نطاق أبعاد الفرعيّ · custom: نطاقٌ يملكه التاجر
            $table->string('type', 20)->default('custom');
            /*
             * الأصلُ من بين عناوينه — وهو ما يُكتب في `canonical`.
             *
             * متجرٌ يُفتح من عنوانين ولا أصلَ معلوم يجعل محرّك البحث يفهرس
             * نسختين لصفحةٍ واحدة، فتتنافسان وتخسران معًا.
             */
            $table->boolean('is_primary')->default(false);

            // pending · verifying · active · failed
            $table->string('status', 20)->default('pending');
            $table->string('verification_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            // ما لم يصحّ، بكلامٍ يُعرض للتاجر لا برمزٍ يُبحث عنه
            $table->string('failure_reason', 255)->nullable();

            /*
             * والمزوّدُ يُسمّى ويُحفظ معرّفُه عنده.
             *
             * ولا يُربط النموذجُ بمزوّدٍ بعينه (انظر `CustomDomainProvider`):
             * اليوم التوجيهُ يدويٌّ ونحن نتحقّق، وغدًا قد يتولّاه مزوّدٌ
             * يُصدر الشهادات. وعمودان يكفيان للانتقال بلا هجرةٍ ثانية.
             */
            $table->string('provider', 40)->nullable();
            $table->string('provider_id', 191)->nullable();

            $table->timestamps();

            $table->index(['business_id', 'type']);
            $table->index(['website_id', 'is_primary']);
            $table->index(['status', 'last_checked_at']);
        });

        $this->backfill();
    }

    /**
     * وما كان يعمل يبقى يعمل.
     *
     * كلُّ نطاقٍ مكتوبٍ في `settings.site_domain` يصير صفًّا **نشطًا** — لا
     * «بانتظار التوجيه». هذه نطاقاتٌ تفتح مواقعَ الآن، وإنزالُها إلى «قيد
     * الربط» يُطفئها في اللحظة التي تُنفَّذ فيها الهجرة.
     *
     * ولا صفَّ لعنوان أبعاد الفرعيّ: هو مشتقٌّ من `businesses.site_slug`،
     * وعمودُه فريدٌ وحالُه واحدة. انظر `App\Support\Website\Domains`.
     */
    private function backfill(): void
    {
        $now = now();
        $seen = [];
        $websites = DB::table('websites')->pluck('id', 'business_id');

        $domains = DB::table('settings')->where('key', 'site_domain')
            ->whereNotNull('business_id')->orderBy('business_id')->get();

        foreach ($domains as $row) {
            $host = mb_strtolower(trim((string) $row->value));
            $host = preg_replace('#^[a-z]+://#', '', $host) ?? $host;
            $host = trim(explode('/', $host)[0], '.');

            /*
             * والمكرّرُ يُترك للأوّل — وهو ما يفعله القارئُ اليوم بالمصادفة.
             *
             * صفّان بالعنوان نفسه بقيا من قبل أن يُفحص التفرّد. وإسقاطُ
             * الهجرة عليهما يوقف النشر كلَّه، فيُنتقى الأسبقُ ويبقى الثاني
             * على `site_domain` بلا صفّ — يراسل صاحبُه الدعم كما اليوم.
             */
            if ($host === '' || isset($seen[$host])) {
                continue;
            }

            $seen[$host] = true;

            DB::table('website_domains')->insert([
                'business_id' => $row->business_id,
                'website_id' => $websites[$row->business_id] ?? null,
                'hostname' => $host,
                'normalized_hostname' => $host,
                'type' => 'custom',
                /*
                 * وليس أصلًا بعد.
                 *
                 * `is_primary` يقرؤه `canonical`، ولا يُنتخب عنوانٌ أصلًا
                 * قبل أن يخدمه الخادم — والعلَم `storefront.custom_domains`
                 * مطفأٌ حتى تُضبط كتلةُ nginx وشهادتُه.
                 */
                'is_primary' => false,
                'status' => 'active',
                'verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('website_domains');
    }
};
