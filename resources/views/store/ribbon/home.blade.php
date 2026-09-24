@extends('store.ribbon.layout')
@section('title', $seo['title'])
@section('content')
<section class="rb-screen" data-testid="rb-landing">
    {{-- Hero --}}
    <div style="background:var(--rb-soft)">
        <div class="rb-wrap" style="padding:clamp(32px,5vw,72px) 24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:clamp(28px,4vw,56px);align-items:center">
            <div style="display:flex;flex-direction:column;gap:20px">
                <div style="font-size:12px;letter-spacing:.22em">{{ $t['heroKicker'] }}@if ($identity['city'] !== '') · {{ $identity['city'] }}@endif</div>
                <h1 style="margin:0;font-size:clamp(32px,4.4vw,58px);line-height:1.15;font-weight:500;text-wrap:balance">{{ $identity['tagline'] !== '' ? $identity['tagline'] : $t['heroTitle'] }}</h1>
                <p style="margin:0;font-size:clamp(15px,1.3vw,18px);line-height:1.7;max-width:520px;text-wrap:pretty">{{ $t['heroSub'] }}</p>
                {{--
                    و«استكشف مجموعاتنا» قفزةٌ إلى قسمٍ في هذه الصفحة — لا
                    وجهةٌ أخرى. فإن لم يكن القسمُ عليها لم يُرسم الزرّ.

                    وكان يُرسم دائمًا ويشير إلى `#rb-cats`: يُضغط فلا يقع
                    شيء. ويقع ذلك في حالين — متجرٌ لا فئةَ فيه ذاتُ بضاعة،
                    وصاحبٌ أطفأ القسمَ بيده من «صفحة متجرك».
                --}}
                {{--
                    ولا يُرسَم زرُّ التسوّق على رفٍّ خالٍ.

                    «تسوّق الآن» يقود إلى `‎/shop‎`، وهي تردّ «لا منتجات هنا
                    بعد» حين لا صنفَ معروضًا. فالزائر يضغط الوعدَ الوحيد على
                    الصفحة فيجد صفحةً فارغة — وهو أسوأُ من صفحةٍ تقول الحقّ
                    من أوّلها: من خُدع مرّةً لا يعود.

                    وهي قاعدةُ الشريط نفسُها فوقه (`$shelf`)، وقاعدةُ
                    «استكشف مجموعاتنا» إلى جانبه.
                --}}
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px">
                    @if ($shelf)
                        <a class="rb-btn" href="{{ $base }}/shop">{{ $t['shopNow'] }}</a>
                        @if (count($categories) && in_array('cats', $sections, true))
                            <a class="rb-btn-ghost" href="#rb-cats">{{ $t['explore'] }}</a>
                        @endif
                    @else
                        <p style="margin:0;font-size:15px" data-testid="rb-empty-shelf">{{ $t['noProducts'] }}</p>
                    @endif
                </div>
            </div>
            <div style="aspect-ratio:5/4;border-radius:var(--rb-r-lg);overflow:hidden;background:repeating-linear-gradient(135deg,#e6dcc8 0 14px,#f3efe9 14px 28px);min-height:240px">
                {{--
                    صورةُ الواجهة: ما اختاره صاحبُ المحلّ، وإلّا أوّلُ ما يُعرض.

                    كانت تُؤخذ من الصفّ المحسوب وحدَه — فأوّلُ ما تقع عليه
                    عينُ الزبون صدفة. والقاعدةُ القديمة تبقى بديلًا لمن لم
                    يختر، فلا تنقلب واجهةُ متجرٍ قائمٍ إلى فراغ.
                --}}
                @php $heroImage = $hero ?: ($best[0]['image'] ?? null); @endphp
                @if ($heroImage)
                    <img src="{{ $heroImage }}" alt="" style="width:100%;height:100%;object-fit:cover;display:block" data-testid="rb-hero-image">
                @endif
            </div>
        </div>
    </div>

    {{--
    ═══ وأقسامُ الصفحة تُبنى بالترتيب الذي اختاره صاحبُها ═══

    والقائمةُ من `StorePage::order` لا من هنا: هي الترتيبُ والظهورُ معًا،
    وما ليس فيها لا يُضمّ أصلًا. والأسماءُ محصورةٌ هناك في قائمةٍ مغلقة،
    فلا يبلغ `@include` اسمٌ يأتي من إعدادٍ حُرّ.

    والواجهةُ فوقها دائمًا: هي هويّةُ الصفحة لا قسمًا يُطفأ.
--}}
@foreach ($sections as $rbSection)
    @include('store.ribbon.sections.'.$rbSection)
@endforeach
</section>
@endsection
