{{--
    هيكلُ ورقة A4 — `abaad-modern-v1`.

    ═══ ترتيبُ الورقة، ولمَ هو هكذا ═══

        [ الغلاف — صورةُ التاجر أو شريطٌ بلونه ]

        نوعُ المستند              [ الشعار ]
        رقمُه                      اسمُ المتجر
                                   سطورُ التعريف

        ─────────────────────────────────────

        من                        إلى
        ...                       ...

        التواريخُ والمراجع

        ─────────────────────────────────────

        الأصناف

        ─────────────────────────────────────

                                  الإجماليّات

        السدادُ والملاحظاتُ والشروط

                       [ رمزٌ ]

    نوعُ المستند أوّلُ ما يُقرأ لا اسمُ المتجر: من يفتح الورقة يريد أن يعرف
    **ما هي** قبل أن يعرف ممّن. والاسمُ في الطرف المقابل مع الشعار — حيث
    تعوّدت العينُ أن تجد الهويّة.

    والأقسامُ تُفصل بالفراغ لا بالخطوط: خطٌّ تحت كلّ كتلةٍ يجعل الورقة
    شبكةً، والفراغُ يفعل الشيء نفسه ويترك الورقة تتنفّس.

    ═══ وما يملؤه القالبُ الوارث ═══

    `type` نوعُ المستند · `number` رقمُه · `meta` سطورُ التعريف ·
    `parties` الأطراف · `body` الجسد · `foot` التذييل.
    وما عدا ذلك يقع هنا مرّةً واحدة لكلّ الأنواع.
--}}
@php
    $t = $tokens;
    $rtl = \App\Support\Paper::rtl();
    $headEnd = $rtl ? 'left' : 'right';
@endphp
@include('documents.v1.partials.tokens')

@include('documents.v1.partials.cover')

<table class="head">
    <tr>
        <td style="width:56%">
            <div class="doctype">@yield('type')</div>
            {{--
                والرقمُ يحمل مسمّاه.

                رقمٌ عارٍ تحت العنوان يُقرأ بالتخمين: أهو رقمُ الفاتورة أم
                رقمُ الطلب أم مرجعُ المورّد؟ ومن يبحث عنه في بريدٍ أو
                يذكره في مكالمةٍ يحتاج أن يعرف عمّ يتكلّم.
            --}}
            @hasSection('number')
                <div class="docnum">
                    <span class="faint">{{ $numberLabel ?? __('رقم المستند') }}</span>
                    <span class="b">@yield('number')</span>
                </div>
            @endif
        </td>
        <td style="width:44%; text-align:{{ $headEnd }}">
            @include('documents.v1.partials.identity')
        </td>
    </tr>
</table>

@yield('parties')

@hasSection('meta')
    <table class="meta">
        @yield('meta')
    </table>
@endif

@yield('body')

@hasSection('foot')
    <div class="foot">@yield('foot')</div>
@endif
