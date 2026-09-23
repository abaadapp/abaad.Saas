{{--
    القسمُ الذي يكتبه صاحبُ المحلّ بنفسه.

    به يقول ما لا تقوله بضاعتُه: «اشتراك الورد الشهريّ»، «تنسيق الأعراس»،
    «نوصّل إلى صلالة الخميس». وكانت كلُّ واحدةٍ من هذه تعني رسالةً إلى من
    يبني له الموقع.

    وشكلُه شكلُ «عنّا» نفسُه — صفٌّ فيه نصٌّ وصورة. فلا يخرج عن هويّة
    الصفحة مهما كُتب فيه، ولا يحتاج صاحبُه أن يفهم تنسيقًا.
--}}
@if ($block)
<div class="rb-section" data-testid="rb-sec-block">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:40px;align-items:center">
        <div style="display:flex;flex-direction:column;gap:14px">
            <h2 class="rb-h2">{{ $block['title'] }}</h2>
            <p style="margin:0;font-size:15px;line-height:1.8;text-wrap:pretty;white-space:pre-line">{{ $block['text'] }}</p>
            @if ($block['cta'])
                <div><a class="rb-btn" href="{{ $block['href'] }}">{{ $block['cta'] }}</a></div>
            @endif
        </div>
        @if ($block['image'])
            <div style="aspect-ratio:4/3;border-radius:var(--rb-r-lg);overflow:hidden;background:var(--rb-soft)">
                <img src="{{ $block['image'] }}" alt="" style="width:100%;height:100%;object-fit:cover;display:block">
            </div>
        @endif
    </div>
</div>
@endif
