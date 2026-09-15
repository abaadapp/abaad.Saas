{{--
    ورقةُ تعريف الأرشيف — تُقرأ بعد سنة، على جهازٍ آخر، وربّما بيد غيرِ صاحبه.

    ═══ ولمَ ليست في `documents/v1` ═══

    تلك أوراقُ مستنداتٍ رسميّة لها نسخةٌ محفوظة (`Version::CURRENT`) لأنّها
    تُصدَر إلى جهاتٍ خارجيّة ويُحتجّ بها. وهذه ورقةُ شرحٍ داخل ملفّ: لا
    تُرسَل، ولا يُحتجّ بها، ولا لقطةَ تُختم عليها. وإدخالُها سجلَّ نسخ
    المستندات يُلزمنا أن نُصدر نسخةً جديدةً من كلّ فاتورةٍ في النظام يومَ
    نُصلح سطرًا هنا.

    ═══ وأهمُّ سطرٍ فيها ═══

    أنّ هذا **ليس** نسخةً تقنيّةً تُستعاد بها القاعدة. وبلا ذلك يُنزّل
    التاجرُ الملفَّ كلَّ شهر ويظنّ أنّه أمّن نفسه — ويكتشف يومَ العطب أنّه
    يملك أوراقَ إكسل لا قاعدةَ بيانات. وطمأنينةٌ كاذبة أسوأ من تحذيرٍ كاذب.

    واللغتان في الورقة نفسِها لا في ورقتين: من يفتح الملفَّ بعد سنةٍ قد لا
    يكون من ضبط لغةَ النظام.
--}}
@php
    use App\Support\Paper;

    $labels = [
        'المتجر' => Paper::pair('المتجر'),
        'فترة الأرشيف' => Paper::pair('فترة الأرشيف'),
        'تاريخ الإنشاء' => Paper::pair('تاريخ الإنشاء'),
        'المنطقة الزمنية' => Paper::pair('المنطقة الزمنية'),
        'العملة' => Paper::pair('العملة'),
        'إصدار الأرشيف' => Paper::pair('إصدار الأرشيف'),
    ];

    $rows = [
        'المتجر' => $business->name,
        'فترة الأرشيف' => $period->start()->format('Y-m-d').' — '.$period->end()->format('Y-m-d'),
        'تاريخ الإنشاء' => $generatedAt->format('Y-m-d H:i'),
        'المنطقة الزمنية' => $timezone,
        'العملة' => (string) ($currency['code'] ?? ''),
        'إصدار الأرشيف' => (string) $version,
    ];

    [$titleAr, $titleEn] = Paper::pair('الأرشيف الشهري للبيانات');
    [$contentsAr, $contentsEn] = Paper::pair('محتويات الأرشيف');
    [$sectionAr, $sectionEn] = Paper::pair('القسم');
    [$countAr, $countEn] = Paper::pair('عدد السجلات');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10pt; color: #111; }
        h1 { font-size: 16pt; margin: 0 0 2pt; }
        h2 { font-size: 11pt; margin: 18pt 0 6pt; }
        .sub { font-size: 10pt; color: #555; margin: 0 0 14pt; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4pt 6pt; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
        th { background: #111; color: #fff; text-align: start; font-size: 9pt; }
        .lbl { width: 38%; color: #555; }
        .lbl2 { font-size: 8pt; color: #999; }
        .warn { border: 1px solid #111; padding: 8pt 10pt; margin-top: 18pt; font-size: 9pt; line-height: 1.6; }
    </style>
</head>
<body>

<h1>{{ $titleAr }}</h1>
@if ($titleEn !== '')
    <p class="sub">{{ $titleEn }}</p>
@endif

<table>
    @foreach ($rows as $key => $value)
        @php([$first, $second] = $labels[$key])
        <tr>
            <td class="lbl">
                {{ $first }}
                @if ($second !== '')<br><span class="lbl2">{{ $second }}</span>@endif
            </td>
            <td>{{ $value }}</td>
        </tr>
    @endforeach
</table>

<h2>{{ $contentsAr }}@if ($contentsEn !== '') <span class="lbl2">— {{ $contentsEn }}</span>@endif</h2>

<table>
    <tr>
        <th>{{ $sectionAr }}@if ($sectionEn !== '') <span style="font-size:8pt">/ {{ $sectionEn }}</span>@endif</th>
        <th>{{ $countAr }}@if ($countEn !== '') <span style="font-size:8pt">/ {{ $countEn }}</span>@endif</th>
    </tr>
    @foreach ($sections as $name => $count)
        @php([$secAr, $secEn] = Paper::pair(\App\Support\Archive\Sheets::FILES[$name] ?? $name))
        <tr>
            <td>
                {{ $secAr }}
                @if ($secEn !== '')<br><span class="lbl2">{{ $secEn }}</span>@endif
            </td>
            <td>{{ $count }}</td>
        </tr>
    @endforeach
</table>

{{--
    والتحذيرُ بالعربيّة والإنجليزيّة نصًّا لا بمفتاحٍ مترجَم.

    `Paper::pair` تصلح للتسميات القصيرة؛ وفقرةٌ كاملة تدخل `lang/en.json`
    مفتاحًا طويلًا يُكسر عند أوّل تصحيحٍ إملائيّ. والنصّان هنا يُقرآن معًا.
--}}
<div class="warn">
    <strong>تنبيه:</strong>
    هذا الأرشيف الشهري تصديرٌ مقروء لبيانات النشاط، وليس نسخةً احتياطيةً تقنيةً
    كاملةً تُستعاد بها قاعدة البيانات. يمثّل ما كان متاحًا من بيانات هذه الفترة
    لحظةَ إنشائه.
    <br><br>
    <strong>Notice:</strong>
    This monthly archive is a readable business data export and is not a complete
    technical database restore backup. It reflects the data available for this
    period at the time it was generated.
</div>

</body>
</html>
