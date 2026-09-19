<?php

namespace App\Console\Commands;

use App\Models\WebsiteDomain;
use App\Support\Website\Domains;
use Illuminate\Console\Command;
use Throwable;

/**
 * حالُ النطاق تتبع السجلّ — لا الذاكرة.
 *
 * ═══ ما وقع ═══
 *
 * نطاقُ تاجرٍ حُفظ «متصلًا» يوم نُقل من حقلٍ نصّيّ إلى صفٍّ له حال، ثمّ زال
 * سجلُّه من DNS كلُّه — واللوحةُ تقول «متصل» بعد أسبوع، و«آخر فحص» فارغ.
 * لا شيء كان يعيد السؤال بعد الجواب الأوّل: `Domains::check` يُستدعى حين
 * يضغط التاجرُ زرًّا، ومن رأى «متصل» لا يضغط.
 *
 * تقريرُ حالٍ كاذب أسوأ من غياب التقرير: التاجرُ يعطي زبائنه عنوانًا لا
 * يفتح ويظنّ العطبَ عندهم.
 *
 * ═══ وما يفعل ═══
 *
 * يعيد فحصَ كلّ نطاقٍ عبر الباب نفسه الذي يفتحه الزرّ — فلا منطقَ ثانٍ
 * يفترق عن الأوّل يومًا. وعنوانُ أبعاد الفرعيّ يردّه ذلك البابُ بنفسه
 * (حالُه من `site_slug` لا من DNS)، فلا يُرشَّح هنا ثانيةً: فحصان لسؤالٍ
 * واحد يفترقان يوم يُبدَّل أحدُهما.
 *
 * ونطاقٌ يسقط فحصُه بخطأٍ لا يوقف البقيّة: كلٌّ في `try` وحدَه، والخطأ
 * يُقال في المخرج ويُرفع في رمز الخروج.
 */
class DomainsCheck extends Command
{
    protected $signature = 'domains:check';

    protected $description = 'إعادة فحص نطاقات التجّار الخاصّة وتحديث حالها من DNS';

    public function handle(): int
    {
        $failures = 0;

        WebsiteDomain::orderBy('id')
            ->each(function (WebsiteDomain $domain) use (&$failures) {
                $was = $domain->status;

                try {
                    $now = Domains::check($domain)->status;
                } catch (Throwable $e) {
                    $failures++;
                    $this->error(sprintf('  %-32s خطأ: %s', $domain->hostname, $e->getMessage()));

                    return;
                }

                $this->line(sprintf('  %-32s %s%s', $domain->hostname, $now, $was === $now ? '' : " (كان {$was})"));
            });

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
