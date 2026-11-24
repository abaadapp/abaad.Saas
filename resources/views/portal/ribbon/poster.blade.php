{{-- ورقةٌ بجانب الكاشير: الرابطُ العامّ لبوابة العملاء رمزًا — لا تكشف شيئًا وحدها --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $shop }}</title>
    <link rel="stylesheet" href="{{ \App\Support\Store\StoreAsset::url('/fonts/ibm-plex-arabic.css') }}">
    <style>
        @page { size: A5; margin: 10mm; }
        body { margin: 0; background: #efe8de; color: #2b2a20; font-family: 'IBM Plex Sans Arabic', system-ui, Tahoma, Arial, sans-serif; text-align: center; }
        .sheet { max-width: 480px; margin: 24px auto; background: #f7f2ec; border: 1px solid #e4dcd1; border-radius: 24px; padding: 34px 28px; }
        img { max-height: 92px; max-width: 260px; object-fit: contain; }
        h1 { font-size: 24px; margin: 18px 0 4px; color: #474530; } p { margin: 4px 0; line-height: 1.7; }
        .qr { background: #fff; border-radius: 18px; padding: 18px; margin: 20px auto; width: max-content; } .qr svg { display: block; width: 250px; height: 250px; }
        .en { direction: ltr; color: #7b776a; font-size: 14px; } .url { direction: ltr; font-size: 15px; font-weight: 600; } small { color: #7b776a; display: block; margin-top: 20px; font-size: 10px; }
        button { font: inherit; padding: 10px 22px; border-radius: 12px; border: 0; background: #58563c; color: #f7f2ec; cursor: pointer; margin: 0 0 24px; }
        @media print { body { background: none; } .sheet { border: 0; margin: 0 auto; } button { display: none; } * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
    <div class="sheet">
        <img src="{{ $logo }}" alt="{{ $shop }}">
        <h1>مشترياتك وفواتيرك ونقاطك</h1>
        <p>امسح الرمز لفتح بوابتك.<br>ليس لديك رابط؟ {{ \App\Support\RibbonPortal\Texts::say('ar', 'askStaff') }}</p>
        <p class="en">Scan to open your portal. No link yet? {{ \App\Support\RibbonPortal\Texts::say('en', 'askStaff') }}</p>
        <div class="qr">{!! $qr !!}</div>
        <p class="url">{{ \Illuminate\Support\Str::after($url, 'https://') }}</p>
        <small>Powered by ABAAD</small>
    </div>
    <button type="button" onclick="window.print()">طباعة</button>
</body>
</html>
