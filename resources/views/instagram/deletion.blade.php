<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('حال طلب حذف البيانات') }}</title>
    <style>
        body { margin: 0; background: #f7f7f5; color: #111; font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif; }
        main { max-width: 520px; margin: 48px auto; padding: 24px 16px; }
        .card { background: #fff; border: 1px solid #e8e8e8; border-radius: 14px; padding: 24px; }
        h1 { font-size: 18px; margin: 0 0 12px; }
        p { font-size: 14px; line-height: 1.7; color: #4b4b4b; margin: 0 0 8px; }
        code { direction: ltr; display: inline-block; background: #f3f4f6; padding: 2px 8px; border-radius: 6px; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>{{ __('حال طلب حذف البيانات') }}</h1>
        <p>{{ __('رمز التأكيد') }}: <code>{{ $row->confirmation_code }}</code></p>
        @if ($row->status === \App\Models\InstagramDataDeletion::COMPLETED)
            <p>{{ __('اكتمل الحذف: لم تعد أبعاد تحتفظ برمز دخول حسابك في إنستقرام ولا بإشعاراته.') }}</p>
            <p>{{ __('تاريخ الإتمام') }}: {{ $row->completed_at?->format('Y-m-d H:i') }}</p>
        @else
            <p>{{ __('وصل طلبك ويُعالج الآن.') }}</p>
        @endif
    </div>
</main>
</body>
</html>
