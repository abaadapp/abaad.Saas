{{-- Reviews — ما كُتب فعلًا، وإلّا لا شيء --}}
    @if (count($reviews))
    <div class="rb-section" data-testid="rb-sec-reviews">
        <div class="rb-section-head"><h2 class="rb-h2">{{ $t['reviewsTitle'] }}</h2></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:16px">
            @foreach ($reviews as $r)
                <div class="rb-box">
                    <div style="color:#b8860b;letter-spacing:2px;font-size:13px">{{ str_repeat('★', max(1, min(5, $r['rating']))) }}</div>
                    <p style="margin:10px 0;font-size:15px;line-height:1.7">{{ $r['text'] }}</p>
                    <div style="font-size:13px;color:#555">{{ $r['name'] }}</div>
                </div>
            @endforeach
        </div>
    </div>
    @endif
