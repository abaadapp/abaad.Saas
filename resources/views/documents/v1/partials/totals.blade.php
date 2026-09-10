{{--
    كتلةُ الإجماليّات — إلى الطرف الذي انتهى عنده عمودُ المبالغ.

    العينُ تتبع الأرقام حيث تركتها، فكتلةٌ في الطرف المقابل تجعلها تعود.
    والخليّةُ الفارغة إلى جانبها ليست حشوًا: بها تشغل الكتلةُ أربعين بالمئة
    من العرض بدل أن تمتدّ عبر الصفحة فتتباعد الكلمةُ عن رقمها.

    و«الإجمالي» يحمل `grand` — مقاسًا ولونًا وخلفيّة. وهو أقوى ما في
    الورقة بصريًّا لأنّه أوّلُ ما يُبحث عنه.

    المتغيّر: `$totals` قائمةُ `['label','value','grand'?,'due'?,'hint'?]`.
--}}
@php
    $rows = array_values(array_filter($totals ?? [], fn ($r) => filled($r['value'] ?? null)));
@endphp

@if (count($rows) > 0)
    <table class="totals-wrap">
        <tr>
            <td style="width:54%" class="aside">
                @if (filled($aside ?? null))
                    <div class="sm muted">{{ $aside }}</div>
                @endif
            </td>
            <td style="width:46%">
                <table class="totals">
                    @foreach ($rows as $row)
                        <tr class="{{ ($row['grand'] ?? false) ? 'grand' : (($row['due'] ?? false) ? 'due' : '') }}">
                            <td class="{{ ($row['grand'] ?? false) || ($row['due'] ?? false) ? '' : 'k' }}">
                                {{ $row['label'] }}
                                @if (filled($row['hint'] ?? null))
                                    <span class="xs faint">{{ $row['hint'] }}</span>
                                @endif
                            </td>
                            {{-- والمبلغُ معزول: انظر `partials/items` --}}
                            <td class="amt"><span dir="ltr">{{ $row['value'] }}</span></td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>
@endif
