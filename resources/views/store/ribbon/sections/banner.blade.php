{{--
    شريطُ المناسبات — ولا يُرسم على رفٍّ خالٍ.

    نصُّه وعدٌ: «نجهّز الباقة مع كرت هدية ونوصلها في الوقت الذي تحدده»،
    وزرُّه يقود إلى المتجر. ومتجرٌ لا صنفَ منشورًا فيه يردّ «لا منتجات هنا
    بعد» — فيقرأ الزبون وعدًا ثمّ يجد رفًّا فارغًا، ولا يعود.

    وكلُّ أخواته تفعل ذلك: «عنّا» لا تُرسم بلا نبذة، و«الآراء» بلا رأي،
    و«الفئات» و«الأكثر مبيعًا» بلا بضاعة. وكان هذا وحدَه يَعِد بلا أن يسأل.
--}}
    @if ($shelf)
    <div class="rb-section" data-testid="rb-sec-banner">
        <div style="background:var(--rb-olive);color:var(--rb-cream);border-radius:var(--rb-r-lg);padding:clamp(32px,4vw,56px) clamp(24px,4vw,56px);display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:32px;align-items:center">
            <div style="display:flex;flex-direction:column;gap:14px">
                <div style="font-size:12px;letter-spacing:.22em;color:#d9d4c0">{{ $t['bannerKicker'] }}</div>
                <h2 style="margin:0;font-size:clamp(24px,3vw,40px);font-weight:500;line-height:1.2">{{ $t['bannerTitle'] }}</h2>
                <p style="margin:0;color:#d9d4c0;line-height:1.8;max-width:520px">{{ $t['bannerSub'] }}</p>
                <div><a href="{{ $base }}/shop" style="display:inline-flex;align-items:center;height:48px;padding:0 24px;background:var(--rb-cream);color:var(--rb-olive);border-radius:var(--rb-r);font-size:14px">{{ $t['bannerBtn'] }}</a></div>
            </div>
            {{--
                وصورةُ الشريط — أو الخطوطُ المرسومة لمن لم يرفع.

                والشريطُ يَعِد بباقاتٍ وكرتِ هدية، وكان يُرسم إلى جانب وعده
                مستطيلٌ مخطَّط. فيقرأ الزبون وعدًا ولا يرى منه شيئًا.
            --}}
            <div style="aspect-ratio:4/3;border-radius:var(--rb-r-lg);overflow:hidden;background:repeating-linear-gradient(135deg,#6b694c 0 14px,#5f5c43 14px 28px)">
                @if ($bannerImage)
                    <img src="{{ $bannerImage }}" alt="" style="width:100%;height:100%;object-fit:cover;display:block" data-testid="rb-banner-image">
                @endif
            </div>
        </div>
    </div>
    @endif
