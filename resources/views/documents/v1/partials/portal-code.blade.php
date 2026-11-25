{{--
    رمزُ دخول العميل إلى بوابته — لمتجر RIBBON وحده (`RibbonPortal\Code`)، ولا يُرسم لسواه شيء.

    على ورقة المشتري وإيصاله فقط: لا على سند التسليم ولا ورقة التجهيز ولا إشعارٍ ولا صفحة الرمز العامّة.

    المتغيّرات: `$portalCode` و`$align`.
--}}
@if (filled($portalCode ?? null))
    <div class="qr-cap" data-portal-code style="text-align:{{ $align ?? 'center' }}; margin-top:3pt">
        {{ __('بوابة العملاء') }} <span class="ltr" dir="ltr">{{ \Illuminate\Support\Str::after(\App\Support\RibbonPortal\Portal::url(), 'https://') }}</span><br>
        {{ __('رمز الدخول') }} <strong class="ltr b" dir="ltr">{{ $portalCode }}</strong>
    </div>
@endif
