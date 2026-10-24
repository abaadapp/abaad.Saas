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

    /* ترويسةُ المنصّة من إعداداتها — انظر PlatformDocuments::provider */
    $business = [
        'name' => $provider['name'],
        'phone' => $provider['phone'],
        'email' => $provider['email'],
        'address' => $provider['website'],
    ];
    $section = 1;
@endphp

@section('title', $doc->title)

@section('meta')
    <div>{{ __('رقم المستند') }}: <strong dir="ltr">{{ $doc->number }}</strong></div>
    <div><span class="k">{{ __('التاريخ') }}:</span> <span dir="ltr">{{ $doc->created_at->format('Y-m-d') }}</span></div>
    <div style="margin-top:4pt">
        <span class="pill {{ $signed ? 'income' : 'expense' }} b">{{ __(\App\Models\PlatformDocument::LABELS[$doc->state()] ?? $doc->state()) }}</span>
    </div>
@endsection

@section('body')
    <table class="grid" style="margin-bottom:10pt">
        <tr>
            <td style="width:50%; border-bottom:none; vertical-align:top">
                <div class="faint small">{{ __('الطرف الأول · مزوّد الخدمة') }}</div>
                <div class="b">{{ $provider['name'] }}</div>
                <div class="muted small">{{ __('نظام سحابي متكامل لإدارة الأعمال') }}</div>
            </td>
            <td style="border-bottom:none; vertical-align:top">
                <div class="faint small">{{ __('الطرف الثاني · العميل') }}</div>
                <div class="b">{{ $client['name'] ?? '—' }}</div>
                <div class="muted small">{{ $client['biz'] ?? '' }}</div>
            </td>
        </tr>
    </table>

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

    @if ($plan || $doc->amount !== null)
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
                @if ($doc->amount !== null)
                    <tr>
                        <td class="b">{{ $doc->amount_label }}</td>
                        <td class="amt"><span dir="ltr">{{ number_format((float) $doc->amount, 3) }}</span> {{ __('ر.ع') }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    @endif

    <h2>{{ str_pad((string) $section++, 2, '0', STR_PAD_LEFT) }} — {{ __('الشروط والأحكام') }}</h2>
    <table class="grid">
        @foreach ($doc->terms as $i => $term)
            <tr>
                <td style="width:6%; vertical-align:top" class="muted">{{ $i + 1 }}.</td>
                <td>@if ($term['h'] ?? '')<span class="b">{{ $term['h'] }}</span> @endif{{ $term['b'] }}</td>
            </tr>
        @endforeach
    </table>

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
@endsection

@section('foot')
    {{ __('صادر عن :name — يُوقَّع إلكترونيًا عبر رابطٍ خاصّ بالعميل.', ['name' => $provider['name']]) }}
    @if ($doc->renews_document_id && $doc->renews)
        · {{ __('تجديد للعقد :n', ['n' => $doc->renews->number]) }}
    @endif
@endsection
