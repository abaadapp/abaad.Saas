@props(['type' => 'gray', 'text' => null, 'dot' => false])

@php
    $map = [
        'gray' => 'bg-gray-100 text-gray-600',
        'primary' => 'bg-primary-50 text-primary-700',
        'secondary' => 'bg-secondary-50 text-secondary-700',
        'success' => 'bg-success-50 text-success-700',
        'warning' => 'bg-warning-50 text-warning-600',
        'danger' => 'bg-danger-50 text-danger-600',
        'info' => 'bg-info-50 text-info-600',
    ];

    // ربط الحالات العربية بالألوان تلقائيًا
    $statusMap = [
        'نشط' => 'success', 'مكتمل' => 'success', 'مدفوع' => 'success', 'مدفوعة' => 'success', 'متوفر' => 'success', 'جاهز' => 'success',
        'منتهي' => 'danger', 'ملغي' => 'danger', 'معطل' => 'danger', 'موقوف' => 'danger', 'نفد المخزون' => 'danger', 'غير مدفوع' => 'danger', 'غير مدفوعة' => 'danger',
        'منخفض' => 'warning', 'قيد التجهيز' => 'warning', 'آجل' => 'warning', 'تنبيه' => 'warning',
        'جديد' => 'info', 'خرج للتوصيل' => 'info', 'فيزا' => 'info', 'بطاقة' => 'info', 'نقدي' => 'success', 'تحويل بنكي' => 'primary',
    ];
    $label = $text ?? trim($slot);
    $resolved = $statusMap[$label] ?? $type;
    $classes = $map[$resolved] ?? $map['gray'];
    $dotColor = str_replace(['bg-', 'text-'], '', explode(' ', $classes)[1]);
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ' . $classes]) }}>
    @if ($dot)<span class="w-1.5 h-1.5 rounded-full bg-current"></span>@endif
    {{ $text !== null ? __($text) : $slot }}
</span>
