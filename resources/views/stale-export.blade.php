{{--
    صفحةُ تبويبٍ قديم: تغيّرت الجلسةُ منذ رُسمت الشاشةُ التي طُلب منها الملف.

    مستقلّةٌ بلا تخطيطٍ ولا نصٍّ من النشاط: لا اسمُ نشاطٍ ولا شيءٌ من بيانه —
    لا للقديم ولا للجديد. وتحمل `data-stale-export` يقرؤها زرُّ التصدير من
    إطاره الخفيّ (`downloadFile`) فيقول السببَ تحت الزرّ.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'en' ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('حدّث الصفحة') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #f7f7f8; color: #111; font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; }
        main { max-width: 440px; margin: 16px; padding: 28px 24px; background: #fff; border: 1px solid #e8e8e8;
            border-radius: 14px; text-align: center; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        p { font-size: 14px; line-height: 1.7; color: #4b5563; margin: 0 0 20px; }
        a { display: inline-block; padding: 10px 18px; border-radius: 10px; background: #111; color: #fff;
            text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
    <main data-stale-export="{{ $reason }}">
        <h1>{{ __('حدّث الصفحة') }}</h1>
        <p>{{ $message }}</p>
        <a href="{{ $home }}">{{ __('العودة إلى اللوحة') }}</a>
    </main>
</body>
</html>
