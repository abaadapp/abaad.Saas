@php
    /**
     * إنذار الاشتراك بأربع نبرات — النصّ يتصاعد والحقائق واحدة.
     *
     * والغرض من الرسالة فعلٌ واحد: أن يتّصل ليجدّد. فالهاتف والبريد في متنها
     * لا في تذييلها، والجملة الأولى تقول ما سيحدث ومتى لا ما حدث.
     */
    $tone = match ($stage) {
        'before' => ['#f59e0b', __('تذكير بتجديد الاشتراك')],
        'today'  => ['#f59e0b', __('اشتراكك ينتهي اليوم')],
        'grace'  => ['#dc2626', __('انتهى اشتراكك')],
        'locked' => ['#991b1b', __('توقّف النظام')],
    };
    [$color, $title] = $tone;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"></head>
<body style="font-family: Tahoma, Arial, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937;">
    <div style="max-width:560px; margin:0 auto; background:#fff; border-radius:16px; overflow:hidden; border:1px solid #eee;">
        <div style="background:{{ $color }}; color:#fff; padding:20px 24px;">
            <h1 style="margin:0; font-size:18px;">{{ $title }}</h1>
            <p style="margin:6px 0 0; font-size:13px; opacity:.9;">{{ $businessName }} — Abad POS</p>
        </div>

        <div style="padding:24px; font-size:15px; line-height:1.9;">
            <p style="margin:0 0 14px;">
                @if ($stage === 'before')
                    {{ __('ينتهي اشتراك متجرك خلال :n يومًا، بتاريخ :date.', ['n' => $days, 'date' => $endsAt]) }}
                @elseif ($stage === 'today')
                    {{ __('ينتهي اشتراك متجرك اليوم :date. يبقى النظام يعمل اليوم كاملًا.', ['date' => $endsAt]) }}
                @elseif ($stage === 'grace')
                    {{ __('انتهى اشتراك متجرك بتاريخ :date، ويتوقّف النظام بعد :n يومًا.', ['date' => $endsAt, 'n' => $days]) }}
                @else
                    {{ __('توقّف النظام لانتهاء الاشتراك بتاريخ :date.', ['date' => $endsAt]) }}
                @endif
            </p>

            {{-- أوّل ما يخطر لمن أُقفل عليه أن بياناته ضاعت — يُقال قبل أن يسأل --}}
            <p style="margin:0 0 18px; padding:12px 14px; background:#f0fdf4; color:#166534; border-radius:10px; font-size:14px;">
                {{ __('بياناتك محفوظة كما هي — المنتجات والطلبات والعملاء والتقارير. لا يُحذف شيء.') }}
            </p>

            @if (! empty($contact['phone']) || ! empty($contact['email']))
                <p style="margin:0 0 8px; font-weight:bold; font-size:14px;">
                    {{ __('للتجديد تواصل مع :company', ['company' => $contact['company'] ?: 'أبعاد']) }}
                </p>
                @if (! empty($contact['phone']))
                    <p style="margin:0 0 4px; font-size:14px;">
                        {{ __('هاتف') }}: <a href="tel:{{ str_replace(' ', '', $contact['phone']) }}" style="color:#111; text-decoration:none;" dir="ltr">{{ $contact['phone'] }}</a>
                    </p>
                @endif
                @if (! empty($contact['email']))
                    <p style="margin:0; font-size:14px;">
                        {{ __('البريد') }}: <a href="mailto:{{ $contact['email'] }}" style="color:#111; text-decoration:none;" dir="ltr">{{ $contact['email'] }}</a>
                    </p>
                @endif
            @endif
        </div>

        <div style="padding:16px 24px; background:#f9fafb; font-size:12px; color:#9ca3af; text-align:center;">
            {{ __('رسالة آلية من نظام Abad POS') }}
        </div>
    </div>
</body>
</html>
