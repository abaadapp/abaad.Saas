{{--
    الترويسةُ التي تتكرّر على الصفحة الثانية وما بعدها.

    ═══ ولمَ سطرٌ واحد لا الترويسةُ كاملة ═══

    فاتورةٌ بخمسين صنفًا تمتدّ ثلاثَ صفحات، وصفحةٌ ثانيةٌ بلا اسمٍ ولا رقم
    ورقةٌ لا يُعرف إلى أيّ حزمةٍ تعود إن سقطت — وهو ما يقع فعلًا في مكاتب
    المحاسبة حين تُفكّ الحزمة وتُصوَّر.

    وتكرارُ الغلاف والشعار وسطورِ العنوان على كلّ صفحة يأكل ربعَها ويجعل
    المستندَ يبدو كأنّه بدأ ثلاثَ مرّات. فيتكرّر ما يُعرِّف وحده: أيُّ
    مستند، وبأيّ رقم، ولمن.

    وتُبنى نصًّا مستقلًّا لا قسمًا في القالب: mpdf يأخذها عبر
    `SetHTMLHeader` — أي خارج مجرى الرسم — فلا ترث أنماطَ الصفحة، وتحمل
    أنماطَها معها.
--}}
@php
    $t = $tokens;
    $rtl = \App\Support\Paper::rtl();
    $start = $rtl ? 'right' : 'left';
    $end = $rtl ? 'left' : 'right';
@endphp
<table style="width:100%; border-collapse:collapse; font-family:xbriyaz; font-size:8pt;
              color:{{ $t['muted'] }}; border-bottom:0.4pt solid {{ $t['border'] }}; padding-bottom:2mm;">
    <tr>
        <td style="border:none; padding:0 0 2mm; text-align:{{ $start }};">
            <span style="color:{{ $t['primary_ink'] }}; font-weight:bold;">{{ $type }}</span>
            <span style="direction:ltr; unicode-bidi:isolate;">{{ $number }}</span>
        </td>
        <td style="border:none; padding:0 0 2mm; text-align:{{ $end }};">{{ $shopName }}</td>
    </tr>
</table>
