<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أسعارُ الباقات على الإنتاج هي الأسعار الرسميّة — التي يعرضها الموقع وتزرعها البذرة.
 *
 * ═══ ما كان ═══
 *
 * على الإنتاج (قراءةٌ فقط، 2026-10-17): الاحترافية 24.900 / 249.000،
 * والمؤسسات 59.900 / 599.000. والموقعُ و`SeedData::plans` يبيعان 29.900 /
 * 299 و69.900 / 699. و`Billing::renew` يقرأ سعرَ الباقة من القاعدة يومَ
 * التجديد (`Billing::price`) — فمن رأى على الموقع 29.900 صدرت له فاتورةٌ
 * بـ24.900.
 *
 * ═══ وما تفعله هذه ═══
 *
 * تكتب `monthly_price` و`yearly_price` وحدهما على الباقات الثلاث (`TARGET`،
 * قرارُ صاحب المنصّة 2026-10-17). لا حدودَ ولا نصوصَ ولا قدراتِ ولا رمزَ ولا
 * اسمَ ولا لونَ ولا «الأكثر طلبًا». ولا تمسّ فاتورةً ولا اشتراكًا ولا متجرًا:
 * مبلغُ الفاتورة والاشتراك كُتب فيهما يومَ صدرا، فما صدر بـ24.900 يبقى
 * 24.900. والسعرُ الجديد لما يصدر بعدها.
 *
 * ═══ سعرٌ ماليّ: تتحقّق كلُّها ثمّ تُكتب — أو لا شيء ═══
 *
 *   - الأساسيّةُ برمزها (`basic`)، والأخريان باسمَيهما — واحدةٌ بالضبط لكلٍّ.
 *   - وسعرُ كلٍّ إمّا القديم (`OLD`) أو الهدف (`TARGET`). وما سواهما عدّله
 *     المشغّل بيده، ولا يُكتب فوقه.
 *   - فإن غابت باقة، أو تكرّرت، أو حمل سعرُها غيرَ القيمتين: تتوقّف قبل أيّ
 *     كتابة. ويتوقّف النشرُ معها (`set -e` في `scripts/deploy.sh`) — وهذا
 *     المقصود: سعرٌ يُكتب نصفُه أسوأُ من سعرٍ لم يُكتب.
 *   - والكتابةُ نفسُها مشروطة (`WHERE` السعرُ القديم) داخل معاملة: صفٌّ تغيّر
 *     بين الفحص والكتابة يُسقطها كلَّها.
 *   - وما كان على الهدف أصلًا لا يُكتب: تشغيلُها ثانيةً لا يفعل شيئًا.
 *
 * والمقارنةُ بالبيسة (أجزاء الألف) عددًا صحيحًا من نصّ القيمة — لا بعشريّةٍ
 * تُقارَن: PostgreSQL تردّ «24.900» وSQLite ‎24.9.
 *
 * ═══ والتثبيتُ الجديد — وحده ═══
 *
 * لا باقةَ في القاعدة حين تجري الهجرات على قاعدةٍ فارغة (`migrate:fresh`،
 * وكلُّ اختبار، وكلُّ CI) — والبذرةُ تزرعها بعدها بالسعر الرسميّ. فلا شيء
 * يُفعل هناك.
 *
 * لكنّ «لا باقة» وحدها لا تعني تثبيتًا جديدًا: قاعدةٌ فيها متاجرُ أو فواتيرُ
 * منصّةٍ أو اشتراكاتٌ وغابت باقاتُها عطبٌ لا بداية. فالتخطّي مشروطٌ بأن تكون
 * الجداولُ الأربعة كلُّها فارغة (`UNTOUCHED_WHEN_EMPTY` — `isAnUntouchedInstall`)،
 * من حالة البيانات نفسِها لا من البيئة ولا اسم الخادم. وما سوى ذلك:
 *
 *   - غابت الثلاثُ كلُّها والقاعدةُ قائمة ← توقّف.
 *   - غاب بعضُها أو تكرّر ← توقّف.
 *
 * ═══ والرجوع ═══
 *
 * `down` يعيد `OLD` لباقةٍ ما زال سعرُها `TARGET` وحده — بالشرط نفسه في
 * `WHERE`. وما عدّله المشغّل بعدها من شاشة الباقات يبقى.
 *
 * ويُعرف الثلاثُ فيه كما في `up`: غيابُ واحدةٍ منها في قاعدةٍ قائمة يوقفه قبل
 * أيّ كتابة — لا رجوعَ يُعيد باقتين ويسكت عن الثالثة.
 */
return new class extends Migration
{
    /** [شهريّ، سنويّ] — ما كان على الإنتاج يوم 2026-10-17 */
    public const OLD = [
        'basic' => ['9.900', '99.000'],
        'الباقة الاحترافية' => ['24.900', '249.000'],
        'باقة المؤسسات' => ['59.900', '599.000'],
    ];

    /** [شهريّ، سنويّ] — الأسعار الرسميّة: الموقع و`SeedData::plans` */
    public const TARGET = [
        'basic' => ['9.900', '99.000'],
        'الباقة الاحترافية' => ['29.900', '299.000'],
        'باقة المؤسسات' => ['69.900', '699.000'],
    ];

    /**
     * ما يجعل القاعدةَ قائمةً لا جديدة — صفٌّ واحدٌ في أيٍّ منها يكفي.
     *
     * `invoices` فواتيرُ المنصّة (فواتيرُ التجّار في `customer_invoices`).
     */
    public const UNTOUCHED_WHEN_EMPTY = ['plans', 'businesses', 'invoices', 'subscriptions'];

    public function up(): void
    {
        $plans = $this->resolve();
        if ($plans === null) {
            return;
        }

        // كلُّها تُفحص أوّلًا — ولا كتابةَ ما لم تصلح كلُّها
        $writes = [];
        foreach ($plans as $which => $plan) {
            $now = $this->prices($plan);

            if ($now === $this->baisa(self::TARGET[$which])) {
                continue;
            }

            if ($now !== $this->baisa(self::OLD[$which])) {
                throw new RuntimeException(sprintf(
                    'أسعار الباقات: «%s» (id=%d) سعرُها %s / %s — لا القديم %s ولا الرسميّ %s. لم يُكتب شيء.',
                    $which, $plan->id, $this->shown($plan->monthly_price), $this->shown($plan->yearly_price),
                    implode(' / ', self::OLD[$which]), implode(' / ', self::TARGET[$which]),
                ));
            }

            $writes[$which] = $plan->id;
        }

        DB::transaction(function () use ($writes) {
            foreach ($writes as $which => $id) {
                $this->swap($id, self::OLD[$which], self::TARGET[$which], strict: true);
            }
        });
    }

    public function down(): void
    {
        $plans = $this->resolve();
        if ($plans === null) {
            return;
        }

        DB::transaction(function () use ($plans) {
            foreach ($plans as $which => $plan) {
                // ما عدّله المشغّل بعد الترحيل لا يُمسّ — والأساسيّةُ واحدةٌ قبلُ وبعد
                if (self::OLD[$which] !== self::TARGET[$which] && $this->prices($plan) === $this->baisa(self::TARGET[$which])) {
                    $this->swap($plan->id, self::TARGET[$which], self::OLD[$which], strict: false);
                }
            }
        });
    }

    /**
     * الباقاتُ الثلاث، كلٌّ بصفّه — أو null لتثبيتٍ لم يبدأ بعد.
     *
     * وفي كلّ قاعدةٍ سواه: غيابُ الثلاث، أو بعضِها، أو تكرارُ واحدةٍ — توقّف
     * قبل أيّ كتابة، في `up` و`down` كليهما.
     *
     * @return array<string, object>|null
     */
    private function resolve(): ?array
    {
        if ($this->isAnUntouchedInstall()) {
            return null;
        }

        $found = [];
        foreach (array_keys(self::TARGET) as $which) {
            $found[$which] = $which === Plan::CODE_BASIC
                ? DB::table('plans')->where('code', Plan::CODE_BASIC)->get(['id', 'name', 'monthly_price', 'yearly_price'])
                : DB::table('plans')->get(['id', 'name', 'monthly_price', 'yearly_price'])
                    ->filter(fn ($plan) => trim((string) $plan->name) === $which)
                    ->values();
        }

        if (collect($found)->every(fn ($rows) => $rows->isEmpty())) {
            $held = collect(self::UNTOUCHED_WHEN_EMPTY)
                ->filter(fn ($table) => Schema::hasTable($table) && DB::table($table)->exists())
                ->implode('، ');

            throw new RuntimeException("أسعار الباقات: لا توجد أيٌّ من الباقات الثلاث، والقاعدةُ قائمةٌ لا جديدة (فيها صفوف: {$held}). لم يُكتب شيء.");
        }

        $plans = [];
        foreach ($found as $which => $rows) {
            if ($rows->count() === 1) {
                $plans[$which] = $rows->first();
            } else {
                throw new RuntimeException(sprintf(
                    'أسعار الباقات: «%s» %s. لم يُكتب شيء.',
                    $which, $rows->isEmpty() ? 'غير موجودة' : 'موجودة '.$rows->count().' مرّات',
                ));
            }
        }

        return $plans;
    }

    /**
     * تثبيتٌ لم يبدأ بعد؟ — الجداولُ الأربعة كلُّها فارغة (أو لم تُنشأ).
     *
     * محافظ: صفٌّ واحدٌ في أيٍّ منها يجعلها قاعدةً قائمة. ومن البيانات وحدها —
     * لا `APP_ENV` ولا اسمُ الخادم ولا كشفُ بيئة الاختبار.
     */
    private function isAnUntouchedInstall(): bool
    {
        foreach (self::UNTOUCHED_WHEN_EMPTY as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return false;
            }
        }

        return true;
    }

    /** يكتب `$to` على صفٍّ ما زال سعرُه `$from` — والشرطُ في `WHERE` نفسِه */
    private function swap(int $id, array $from, array $to, bool $strict): void
    {
        $changed = DB::table('plans')
            ->where('id', $id)
            ->where('monthly_price', $from[0])
            ->where('yearly_price', $from[1])
            ->update(['monthly_price' => $to[0], 'yearly_price' => $to[1], 'updated_at' => now()]);

        if ($strict && $changed !== 1) {
            throw new RuntimeException("أسعار الباقات: الباقة id={$id} تغيّر سعرُها أثناء الترحيل. لم يُكتب شيء.");
        }
    }

    /** السعرُ كما يُقرأ في رسالة التوقّف — بثلاث خاناتٍ على القاعدتين */
    private function shown(mixed $value): string
    {
        $baisa = $this->baisa($value);

        return $baisa === null ? var_export($value, true) : sprintf('%d.%03d', intdiv($baisa, 1000), $baisa % 1000);
    }

    /** @return array{0: ?int, 1: ?int} */
    private function prices(object $plan): array
    {
        return [$this->baisa($plan->monthly_price), $this->baisa($plan->yearly_price)];
    }

    /**
     * القيمةُ بالبيسة عددًا صحيحًا — «24.900» و‎24.9 و«24.9» كلُّها 24900.
     *
     * وما لا يُقرأ رقمًا بثلاث خاناتٍ على الأكثر يردّ null — فلا يطابق شيئًا،
     * ويتوقّف الترحيل عنده.
     *
     * @param  array{0: string, 1: string}|string|int|float|null  $value
     * @return ($value is array ? array{0: ?int, 1: ?int} : ?int)
     */
    private function baisa(mixed $value): array|int|null
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->baisa($v), $value);
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        if (! preg_match('/^(\d+)(?:\.(\d{1,3})0*)?$/', trim((string) $value), $m)) {
            return null;
        }

        return (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
    }
};
