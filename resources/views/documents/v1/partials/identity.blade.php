{{--
    هويّةُ المتجر في ترويسة الورقة — الشعارُ والاسمُ وما تحته.

    وتُبنى من `Paper::brand` كبقيّة الورق: هي التي تقرأ الصفَّ والمصفوفةَ
    معًا (نصفُ المتحكّمات ترسل نموذجًا والنصفُ الآخر مصفوفة)، وهي التي
    ترتّب السطور. ونسخةٌ ثانيةٌ هنا تفترق عنها عند أوّل حقلٍ يُضاف.

    و«سطرٌ تحت اسم المتجر» من «قوالب الأوراق» يقع تحت الاسم لا في عمود
    الترقيم: التاجر يكتب فيه شعارَه أو تخصّصَه، ومكانُه حيث يقرأه من يقرأ
    الاسم.
--}}
@php
    $brand = \App\Support\Paper::brand($business ?? null, $vatNumber ?? '');
@endphp

@if ($brand['logo'])
    <img src="{{ $brand['logo'] }}"
         style="max-height:{{ round(\App\Support\Document\Theme::GEOMETRY['logo_height'], 2) }}pt; margin-bottom:4pt;"
         alt="">
@endif

<div class="shop">{{ $brand['name'] }}</div>

@if ($brand['sub'] !== '')
    <div class="sm muted">{{ $brand['sub'] }}</div>
@endif

@if (trim((string) ($headerNote ?? '')) !== '')
    <div class="sm muted">{{ $headerNote }}</div>
@endif

@foreach ($brand['lines'] as $line)
    <div class="sm faint">{{ $line }}</div>
@endforeach
