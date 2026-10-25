{{--
    مستندُ أبعاد مع عميلها — ورقةُ المنصّة كما وُقِّعت.

    تُطبع من نسخة المستند لا من قالبه: البنودُ والباقةُ وسعرُها كما كانت ساعةَ
    الإصدار. والموقَّعُ يحمل صورةَ التوقيع ووقتَه وعنوانَ الجهاز وبصمةَ sha256
    لما وُقِّع عليه — فمن شكّ في ورقةٍ يُعيد حسابَ البصمة من الصفّ.

    جداولُ وحدها: محرّكُ الورق لا يقرأ flex ولا grid.
--}}
@extends('pdf.layout')

@php
    $signed = $doc->status === \App\Models\PlatformDocument::SIGNED;
    $client = $doc->client ?? [];
    $plan = $doc->plan;

    /*
     * ترويسةُ المنصّة كما نُسخت في المستند — انظر PlatformDocuments::provider.
     * ما لم يُكتب منها لا يُطبع، ولا يُستعار له اسمُ النظام ($unnamed).
     */
    $shown = \App\Support\PlatformDocuments::providerName($provider);
    $unnamed = '';
    $business = [
        'name' => $shown,
        'phone' => $provider['phone'],
        'email' => $provider['email'],
        'address' => $provider['website'],
    ];
    $section = 1;
    $form = $doc->form ?: null;
    $asksClient = \App\Support\DocumentForms::asksClient($form);
    $amountHere = $doc->amount !== null && ! \App\Support\DocumentForms::showsAmount($form);
@endphp

@section('title', $doc->title)

@section('meta')
    <div>{{ __('رقم المستند') }}: <strong dir="ltr">{{ $doc->number }}</strong></div>
    <div><span class="k">{{ __('التاريخ') }}:</span> <span dir="ltr">{{ $doc->created_at->format('Y-m-d') }}</span></div>
    <div style="margin-top:4pt">
        @if ($preview ?? false)
            <span class="pill expense b">{{ __('معاينة — ليست مستندًا صادرًا') }}</span>
        @else
            <span class="pill {{ $signed ? 'income' : 'expense' }} b">{{ __(\App\Models\PlatformDocument::LABELS[$doc->state()] ?? $doc->state()) }}</span>
        @endif
    </div>
@endsection

@section('body')
    <table class="grid" style="margin-bottom:10pt">
        <tr>
            <td style="width:50%; border-bottom:none; vertical-align:top">
                <div class="faint small">{{ __('الطرف الأول · مزوّد الخدمة') }}</div>
                {{-- ما كُتب منه وحده: لا سطرٌ فارغ ولا وصفٌ لم يكتبه --}}
                @if (trim((string) $provider['name']) !== '')<div class="b">{{ $provider['name'] }}</div>@endif
                @if ($provider['trade_name'])<div class="muted small">{{ __('الاسم التجاري للمنصة') }}: {{ $provider['trade_name'] }}</div>@endif
                @if ($provider['cr'])<div class="muted small">{{ __('السجل التجاري رقم') }} <span dir="ltr">{{ $provider['cr'] }}</span></div>@endif
                @if ($provider['address'])<div class="muted small">{{ $provider['address'] }}</div>@endif
            </td>
            <td style="border-bottom:none; vertical-align:top">
                <div class="faint small">{{ __('الطرف الثاني · العميل') }}</div>
                <div class="b">{{ $client['name'] ?? '—' }}</div>
                <div class="muted small">{{ $client['biz'] ?? '' }}</div>
            </td>
        </tr>
    </table>

    @if ($form && (! empty($form['intro']) || ! empty($form['highlights'])))
        @if (! empty($form['intro']))<div style="margin-bottom:6pt">{{ $form['intro'] }}</div>@endif
        @if (! empty($form['highlights']))
            <table class="grid" style="margin-bottom:8pt">
                @foreach ($form['highlights'] as $h)
                    <tr><td style="width:22%" class="muted small">{{ $h['label'] }}</td><td>{{ $h['text'] }}</td></tr>
                @endforeach
            </table>
        @endif
    @endif

    @unless ($asksClient)
    <h2>{{ str_pad((string) $section++, 2, '0', STR_PAD_LEFT) }} — {{ __('بيانات العميل') }}</h2>
    <table class="grid">
        @foreach (array_chunk($fields, 2) as $pair)
            <tr>
                @foreach ($pair as $f)
                    <td style="width:20%" class="muted small">{{ __($f['ar']) }}</td>
                    <td style="width:30%" @if (($f['dir'] ?? '') === 'ltr') dir="ltr" @endif>{{ $client[$f['k']] ?? '—' }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>
    @endunless

    @if ($plan || $amountHere)
        <h2>{{ str_pad((string) $section++, 2, '0', STR_PAD_LEFT) }} — {{ $plan ? __('الباقة ومدة الاشتراك') : __('الخدمة والمبلغ') }}</h2>
        <table class="grid">
            <thead>
                <tr>
                    <th>{{ __('البيان') }}</th>
                    <th class="amt" style="width:30%">{{ __('المبلغ') }}</th>
                </tr>
            </thead>
            <tbody>
                @if ($plan)
                    <tr>
                        <td>
                            <div class="b">{{ __('الباقة') }}: {{ $plan['name'] }} · {{ $plan['cycle_label'] }}</div>
                            <div class="muted small">{{ $plan['limits'] }}</div>
                            @if ($doc->term_starts_on)
                                <div class="small">{{ __('مدة الاشتراك') }}: {{ $plan['duration'] ?? $plan['cycle_label'] }} ·
                                    {{ __('من') }} <span dir="ltr">{{ \App\Support\PlatformDocuments::day($doc->term_starts_on) }}</span>
                                    {{ __('إلى') }} <span dir="ltr">{{ \App\Support\PlatformDocuments::day($doc->term_ends_on) }}</span></div>
                            @endif
                        </td>
                        <td class="amt"><span dir="ltr">{{ $plan['price'] }}</span> {{ __('ر.ع') }} / {{ $plan['unit'] }}</td>
                    </tr>
                @endif
                @if ($amountHere)
                    <tr>
                        <td class="b">{{ $doc->amount_label }}</td>
                        <td class="amt"><span dir="ltr">{{ number_format((float) $doc->amount, 3) }}</span> {{ __('ر.ع') }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    @endif

    @if ($form)
        @include('pdf.partials.document-form', ['sectionNo' => $section])
        @php $section += count($form['sections']); @endphp
    @else
    <h2>{{ str_pad((string) $section++, 2, '0', STR_PAD_LEFT) }} — {{ __('الشروط والأحكام') }}</h2>
    <table class="grid">
        @foreach ($doc->terms as $i => $term)
            <tr>
                <td style="width:6%; vertical-align:top" class="muted">{{ $i + 1 }}.</td>
                <td>@if ($term['h'] ?? '')<span class="b">{{ $term['h'] }}</span> @endif{{ $term['b'] }}</td>
            </tr>
        @endforeach
    </table>
    @endif

    <h2>{{ str_pad((string) $section++, 2, '0', STR_PAD_LEFT) }} — {{ __('التوقيع') }}</h2>
    @if ($signed)
        <table class="grid">
            <tr>
                <td style="width:50%; vertical-align:top; border-bottom:none">
                    <div class="muted small">{{ $doc->consent_text ?: __('أقر بأن البيانات أعلاه صحيحة، وأوافق على الشروط والأحكام الواردة في هذا المستند.') }}</div>
                    @if ($signature)
                        <img src="{{ $signature }}" style="max-height:60pt; max-width:200pt; margin-top:6pt" alt="">
                    @endif
                    <div class="b" style="margin-top:4pt">{{ $client['name'] ?? '' }}</div>
                </td>
                <td style="vertical-align:top; border-bottom:none">
                    <div class="small"><span class="muted">{{ __('وقت التوقيع') }}:</span> <span dir="ltr">{{ \App\Support\PlatformDocuments::date($doc->signed_at) }}</span></div>
                    <div class="small"><span class="muted">{{ __('وقت الموافقة على الشروط') }}:</span> <span dir="ltr">{{ \App\Support\PlatformDocuments::date($doc->agreed_at) }}</span></div>
                    <div class="small"><span class="muted">{{ __('عنوان الجهاز') }}:</span> <span dir="ltr">{{ $doc->signer_ip ?: '—' }}</span></div>
                    <div class="small"><span class="muted">{{ __('المتصفح') }}:</span> <span dir="ltr">{{ \Illuminate\Support\Str::limit((string) $doc->signer_agent, 90) ?: '—' }}</span></div>
                </td>
            </tr>
        </table>
        <div class="note tiny">
            {{ __('بصمة المستند (SHA-256)') }}: <span dir="ltr">{{ $doc->content_hash }}</span>
        </div>
    @else
        <div class="note muted">{{ __('لم يُوقَّع هذا المستند بعد.') }}</div>
    @endif
    @if ($form && ! empty($form['footer']))
        <div class="muted small" style="margin-top:6pt">{{ $form['footer'] }}</div>
    @endif
@endsection

@section('foot')
    @if ($shown !== '')
        {{ __('صادر عن :name — يُوقَّع إلكترونيًا عبر رابطٍ خاصّ بالعميل.', ['name' => $shown]) }}
    @else
        {{ __('يُوقَّع إلكترونيًا عبر رابطٍ خاصّ بالعميل.') }}
    @endif
    @if ($doc->renews_document_id && $doc->renews)
        · {{ __('تجديد للعقد :n', ['n' => $doc->renews->number]) }}
    @endif
@endsection
