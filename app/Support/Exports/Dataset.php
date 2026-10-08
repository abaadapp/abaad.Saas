<?php

namespace App\Support\Exports;

use Closure;

/**
 * ما يُصدَّر من شاشة — عنوانُه ومرشِّحاتُه وأعمدتُه وصفوفُه ومجاميعُه.
 *
 * تبنيه الشاشةُ من استعلامها نفسِه (`App\Support\Lists\*`)، ويكتبه كلُّ
 * كاتبٍ بصيغته (`Exports::xlsx` و`Exports::csv`). فالملفّان من مصدرٍ واحد:
 * لا يقول Excel مئةً وعشرين ويقول CSV مئةً وثمانية عشر.
 */
final class Dataset
{
    /**
     * @param  string  $title  عنوانُ الورقة بلغة القارئ
     * @param  string  $file  أصلُ اسم الملفّ بالإنجليزيّة — `expenses`
     * @param  list<string|null>  $fileParts  ما يُلحق به — الشهرُ أو الفترة
     * @param  array<string, string|null>  $filters  المرشِّحاتُ الفعّالة للترويسة
     * @param  bool|null  $perBranch  انظر `Workbook::__construct`
     * @param  array<string, string>  $columns  عنوانٌ ← نوع (`Workbook::MONEY`…) بترتيب الشاشة
     * @param  Closure(): iterable<list<mixed>|array{cells: list<mixed>, fill: ?string}>  $rows  الصفوفُ كلُّها — بلا ترقيم
     * @param  int|null  $total  كم صفًّا طابق المرشِّحات
     * @param  list<array{0: string, 1: int|float, 2?: string}>  $totals  مجاميعُ الشاشة على النتائج كلِّها
     * @param  int  $totalsColumn  عمودُ عناوين المجاميع
     */
    public function __construct(
        public readonly string $title,
        public readonly string $file,
        public readonly array $fileParts,
        public readonly array $filters,
        public readonly ?bool $perBranch,
        public readonly array $columns,
        public readonly Closure $rows,
        public readonly ?int $total = null,
        public readonly array $totals = [],
        public readonly int $totalsColumn = 1,
    ) {}
}
