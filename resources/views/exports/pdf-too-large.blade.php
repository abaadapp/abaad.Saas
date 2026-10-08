<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'en' ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $message }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f7f7f5; color: #111; font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; padding: 16px; box-sizing: border-box; }
        main { max-width: 480px; background: #fff; border: 1px solid #e5e5e2; border-radius: 12px; padding: 28px 24px; text-align: center; }
        h1 { font-size: 18px; margin: 0 0 12px; }
        p { font-size: 14px; color: #555; line-height: 1.7; margin: 0 0 20px; }
        button { font: inherit; font-size: 14px; padding: 8px 18px; border-radius: 8px; border: 1px solid #111; background: #111; color: #fff; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $message }}</h1>
        <p>{{ $detail }}</p>
        <button type="button" onclick="window.close()">{{ __('إغلاق') }}</button>
    </main>
</body>
</html>
