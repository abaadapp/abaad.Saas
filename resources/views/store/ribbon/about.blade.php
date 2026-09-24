{{--
    «من نحن» — صفحةٌ قائمةٌ بذاتها لا قسمًا في الرئيسية.

    وقسمُ «عن المتجر» يبقى في الرئيسية كما كان: من نزل الصفحةَ كلَّها يقرأ
    سطرين عنه، ومن سأل «من هؤلاء؟» قبل أن يدفع يجد بابًا باسم السؤال.

    والنصُّ هو الصفحة: ما لا نبذةَ فيه لا يُخدَم أصلًا (انظر `StoreNav::has`)،
    فلا فحصَ هنا على فراغٍ لا يصل.
--}}
@extends('store.ribbon.layout')
@section('title', $seo['title'])
@section('content')
<section class="rb-screen" data-testid="rb-about">
    <div style="background:var(--rb-soft)">
        <div class="rb-wrap" style="padding:clamp(32px,4.5vw,64px) 24px;display:flex;flex-direction:column;gap:14px">
            <div style="font-size:12px;letter-spacing:.22em">{{ $t['aboutKicker'] }}</div>
            <h1 class="rb-h1" style="margin:0">{{ $t['aboutTitle'] }}</h1>
        </div>
    </div>

    <div class="rb-wrap" style="padding:clamp(32px,4.5vw,64px) 24px">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:clamp(28px,4vw,52px);align-items:center">
            <div style="display:flex;flex-direction:column;gap:20px">
                {{-- ولا اسمُ المحلّ ثالثةً: هو في الشعار فوقه وفي التذييل تحته --}}
                <p style="margin:0;font-size:16px;line-height:1.9;text-wrap:pretty;white-space:pre-line">{{ $aboutText }}</p>
                {{--
                    ولا يُرسم زرُّ التسوّق على رفٍّ خالٍ — قاعدةُ الواجهة نفسُها.

                    ومن قرأ عنّا ثمّ ضغط «تسوّق الآن» فوجد «لا منتجات هنا بعد»
                    خسرناه مرّتين: مرّةً بالصفحة الفارغة ومرّةً بالوعد.
                --}}
                @if ($shelf)
                    <div>
                        <a class="rb-btn" href="{{ $base }}/shop" data-testid="rb-about-shop">{{ $t['shopNow'] }}</a>
                    </div>
                @endif
            </div>
            {{--
                والصورةُ زينةٌ — ما رفعه لهذه الصفحة، وإلّا شعارُه، وإلّا
                لا شيء. ولا يُرسم إطارٌ فارغٌ ينتظر صورة.
            --}}
            @php $rbAbout = $aboutImage ?: $logo; @endphp
            @if ($rbAbout)
                <div style="aspect-ratio:4/3;border-radius:var(--rb-r-lg);overflow:hidden;background:var(--rb-soft)">
                    <img src="{{ $rbAbout }}" alt="" style="width:100%;height:100%;object-fit:cover;display:block" data-testid="rb-about-image">
                </div>
            @endif
        </div>
    </div>
</section>
@endsection
