<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\StoreSite;
use App\Support\Store\StoreContent;
use App\Support\Store\ThemePublisher;
use Illuminate\Console\Command;

/**
 * يفتح نظامَ المسوّدات لمتجرٍ قائم — متجرًا متجرًا، لا دفعةً واحدة.
 *
 * ═══ وما يقع لحظةَ الترحيل ═══
 *
 * تُلتقط حالُ المتجر المنشورة الآن كما هي، وتُكتب مسوّدتُه نسخةً منها،
 * وتُسجَّل نشرةٌ أولى تصفها. **ولا يُمسّ مفتاحٌ حيٌّ واحد** — فالزائر يفتح
 * الصفحةَ بعد الترحيل فيراها كما كانت قبله حرفًا بحرف.
 *
 * ولأنّ المسوّدة تساوي المنشور لحظتَها، تقول اللوحةُ «الموقع محدّث» — لا
 * «تغييراتٌ غير منشورة» عن تغييرٍ لم يقع.
 *
 * ═══ ويُعاد بلا ضرر ═══
 *
 * من له صفٌّ يُترك كما هو — لا تُكتب مسوّدتُه من جديد ولا تُمحى. فتشغيلُه
 * مرّتين كتشغيله مرّة.
 *
 * ═══ والتراجعُ حذفُ صفّ ═══
 *
 * `--undo` يحذف الصفَّ فيعود المتجرُ إلى ما كان: يُحفظ فيظهر. والمفاتيحُ
 * الحيّة لم تتبدّل، فلا شيءَ يُستعاد — والنشراتُ تبقى مكتوبةً في التاريخ
 * لا تُحذف.
 */
class OpenStorePublishing extends Command
{
    protected $signature = 'store:publishing
                            {business : معرّفُ النشاط}
                            {--undo : يُغلق النظامَ ويعيد المتجر إلى الحفظ المباشر}';

    protected $description = 'يفتح المسوّدات والنشر لمتجر واجهةٍ خاصّة — أو يُغلقها';

    public function handle(): int
    {
        $business = Business::find((int) $this->argument('business'));

        if ($business === null) {
            $this->error('لا نشاطَ بهذا المعرّف.');

            return self::FAILURE;
        }

        if ($business->storefrontTheme() === null) {
            $this->error('هذا النشاط لا يلبس واجهةً خاصّة — ونظامُ المسوّدات لها وحدها.');

            return self::FAILURE;
        }

        $bid = (int) $business->id;

        if ($this->option('undo')) {
            $rows = StoreSite::where('business_id', $bid)->delete();

            $this->info($rows > 0
                ? 'أُغلق النظامُ لـ«'.$business->name.'» — والمفاتيحُ الحيّة كما هي، والنشراتُ محفوظة.'
                : 'لم يكن مفتوحًا أصلًا.');

            return self::SUCCESS;
        }

        if (StoreContent::usesDrafts($bid)) {
            $this->info('مفتوحٌ أصلًا لـ«'.$business->name.'» — لم يُكتب شيء.');

            return self::SUCCESS;
        }

        $before = StoreContent::live($bid);
        $site = ThemePublisher::enable($business, null);

        /*
         * والتحقّقُ شرطُ النجاح لا زينتُه.
         *
         * لو تبدّل مفتاحٌ حيٌّ واحدٌ في الترحيل لَتبدّل ما يراه الزبون —
         * فيُتراجَع فورًا ويُقال ما وقع، ولا يُترك متجرٌ نصفَ مُرحَّل.
         */
        if (! ThemePublisher::migratedCleanly($bid, $before)) {
            $site->delete();
            $this->error('تبدّل شيءٌ في الترحيل — فتُرك المتجرُ كما كان. لا تُعد المحاولة قبل الفحص.');

            return self::FAILURE;
        }

        $this->info('فُتح النظامُ لـ«'.$business->name.'» — نشرةٌ أولى، ومسوّدةٌ تساوي المنشور.');
        $this->line('  المفاتيحُ المُدارة: '.count(StoreContent::VERSIONED));
        $this->line('  ولم يتبدّل للزائر شيء.');

        return self::SUCCESS;
    }
}
