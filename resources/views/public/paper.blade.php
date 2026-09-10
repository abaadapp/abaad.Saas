{{--
    الفاتورة كما يراها من مسح رمزها.

    صفحةٌ واحدة بلا أصولٍ خارجية: تُفتح على هاتفٍ داخل محلٍّ بشبكةٍ ضعيفة،
    ومَن ينتظر تحميل حزمةِ جافاسكربت ليقرأ فاتورته يغلقها قبل أن تصل.

    و`noindex`: الفاتورة ليست صفحةً تُبحث. رابطُها لا يُخمَّن، لكنّ محرّك
    بحثٍ يزحف إليه من مشاركةٍ عابرة يجعله مفهرسًا للجميع.

    ═══ والبيانُ من `DocumentPaper::forSale` لا من الطلب رأسًا ═══

    وكانت هذه الصفحة تجمع المستندَ بيدها ثانيةً: تقرأ `$order->subtotal`
    و`$order->tax` وتقرّر أيَّ سطرٍ يُطبع، وتكتب `number_format($v, 3)`
    و«ر.ع» **مثبَّتتين**. فصارت نسخةً ثانيةً من المستند تفترق عن المطبوع:
    سطرُ سدادٍ يُضاف إلى الورقة ولا يبلغ هنا، وتاجرٌ عملتُه الدرهم يطبع
    «5.25 د.إ» ويقرأ زبونُه «5.250 ر.ع» للمبلغ نفسه.

    فبقي **الوسطُ** مختلفًا — هذه صفحةُ هاتفٍ لا ورقةُ A4، وبطاقةٌ بعرض
    ٥٢٠ بكسل خيرٌ من ٢١٠ مليمترًا يُزحلق عرضًا — وصار **البيانُ** واحدًا.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('فاتورة') }} {{ $order->number }} — {{ $brand['name'] }}</title>
    <style>
        :root {
            --ink: #111; --muted: #6b7280; --faint: #9ca3af;
            --rule: #e8e8e8; --wash: #fafafa; --card: #fff;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 16px 12px 40px;
            background: #f5f5f4; color: var(--ink);
            font: 15px/1.6 -apple-system, "Segoe UI", system-ui, "Helvetica Neue", Arial, sans-serif;
        }
        .sheet {
            max-width: 520px; margin: 0 auto; background: var(--card);
            border: 1px solid var(--rule); border-radius: 16px; overflow: hidden;
        }
        .pad { padding: 18px 18px; }
        .head { border-bottom: 1px solid var(--rule); text-align: center; }
        .logo { max-height: 56px; margin-bottom: 8px; }
        .shop { font-size: 20px; font-weight: 700; margin: 0 0 2px; }
        .muted { color: var(--muted); }
        .faint { color: var(--faint); }
        .sm { font-size: 13px; }
        .xs { font-size: 12px; }
        .row { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; }
        .row + .row { border-top: 1px solid #f5f5f4; }
        .ltr { direction: ltr; unicode-bidi: isolate; }

        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: right; font-size: 12px; font-weight: 600; color: var(--muted);
            padding: 8px 0; border-bottom: 1px solid var(--rule);
        }
        td { padding: 9px 0; border-bottom: 1px solid #f5f5f4; vertical-align: top; }
        .amt { text-align: left; direction: ltr; white-space: nowrap; }
        .qty { text-align: center; direction: ltr; width: 48px; }

        .totals { background: var(--wash); border-top: 1px solid var(--rule); }
        .grand { font-size: 19px; font-weight: 700; padding-top: 10px; margin-top: 4px; border-top: 2px solid var(--ink); }

        /* ————— ختم أبعاد ————— */
        .stamp {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 18px; border-top: 1px solid var(--rule); background: var(--wash);
        }
        .seal {
            flex: 0 0 auto; width: 54px; height: 54px; border-radius: 50%;
            border: 2px solid var(--ink); display: flex; align-items: center; justify-content: center;
            /* ميلٌ خفيف: الختم يُضرب باليد لا يُطبع مستويًا */
            transform: rotate(-8deg);
        }
        .seal svg { width: 26px; height: 26px; fill: var(--ink); }
        .stamp .t { font-weight: 700; }

        footer { max-width: 520px; margin: 14px auto 0; text-align: center; }
    </style>
</head>
<body>
<main class="sheet">
    <div class="pad head">
        @if ($brand['logo'])
            <img class="logo" src="{{ $brand['logo'] }}" alt="">
        @endif
        <h1 class="shop">{{ $brand['name'] }}</h1>
        @if ($brand['sub'] !== '')
            <div class="muted sm">{{ $brand['sub'] }}</div>
        @endif
        @foreach ($brand['lines'] as $l)
            <div class="muted xs ltr">{{ $l }}</div>
        @endforeach
    </div>

    <div class="pad">
        <div class="row"><span class="muted sm">{{ __('رقم الفاتورة') }}</span><strong class="ltr">{{ $paper['number'] }}</strong></div>
        <div class="row"><span class="muted sm">{{ __('التاريخ') }}</span><span class="ltr">{{ $paper['date'] ?: '—' }}</span></div>
        @if ($order->customer_name)
            <div class="row"><span class="muted sm">{{ __('العميل') }}</span><span>{{ $order->customer_name }}</span></div>
        @endif
        {{-- ووسيلةُ الدفع وشروطُه واستحقاقُه من البيان — وهي تُطفأ حيث لا معنى لها --}}
        @foreach ($paper['meta'] as $m)
            <div class="row"><span class="muted sm">{{ $m['label'] }}</span><span>{{ $m['value'] }}</span></div>
        @endforeach
    </div>

    <div class="pad" style="padding-top:0">
        <table>
            <thead>
                <tr>
                    <th>{{ __('الصنف') }}</th>
                    <th class="qty">{{ __('الكمية') }}</th>
                    <th class="amt">{{ __('الإجمالي') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($paper['items'] as $it)
                    <tr>
                        <td>
                            {{ $it['name'] }}
                            <div class="faint xs ltr">{{ $it['unit'] }}</div>
                        </td>
                        <td class="qty">{{ $it['qty'] }}</td>
                        <td class="amt"><span class="ltr">{{ $it['total'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{--
        والمجاميعُ تُقرأ كما بُنيت للورقة — لا تُبنى هنا ثانيةً.

        أيُّ سطرٍ يُطبع وأيُّه يُطفأ قرارٌ في `DocumentPaper::forSale`: الخصمُ
        صفرًا لا يُطبع، والضريبةُ تحمل نسبتَها، والبيعُ الآجل يُظهر المسدَّدَ
        والباقي. وتكرارُ الشرط هنا يعني سطرًا يُضاف هناك ولا يبلغ الزبون.
    --}}
    <div class="pad totals">
        @foreach ($paper['totals'] as $t)
            <div class="row @if (! empty($t['grand'])) grand @endif">
                <span class="@if (empty($t['grand'])) muted sm @endif">
                    {{ $t['label'] }}@if (! empty($t['hint'])) <span class="faint xs ltr">{{ $t['hint'] }}</span>@endif
                </span>
                <span class="ltr">{{ $t['value'] }}</span>
            </div>
        @endforeach
    </div>

    {{--
        الختمُ هنا وحده لا على الورقة المطبوعة.

        الورقة تخرج من طابعة التاجر، فختمُ أبعاد عليها لا يُثبت شيئًا: من
        يزوّر الورقة يزوّر الختم معها. وهذه تُقرأ من خادم أبعاد بعنوانٍ لا
        يُخمَّن، فالختمُ فيها يقول ما يقدر أن يقوله: أنّ ما تراه هو ما في
        الدفتر لحظةَ فتحتَه.
    --}}
    <div class="stamp">
        <div class="seal" aria-hidden="true">
            <svg viewBox="11.3 67.79 232 219.76" role="img">
                <path d="M152.16,67.8h-53.97c-3.17,0-6.06,1.8-7.47,4.64L12.16,231.65c-1.49,3.01-1.02,6.62,1.19,9.15l37.9,43.56c2.33,2.68,6.11,3.58,9.41,2.25,15.99-6.43,66.03-22.25,128.68.44,3.25,1.18,6.88.2,9.13-2.42l39.1-45.67c2.18-2.55,2.62-6.16,1.12-9.15l-79.08-157.42c-1.41-2.81-4.29-4.59-7.44-4.59ZM49.91,242.96L121.96,93.4c1.11-2.31,4.4-2.32,5.53-.02l73.39,149.77c1.26,2.58-1.37,5.32-4,4.18-64.55-28.09-122.64-8.85-143.01-.2-2.61,1.11-5.2-1.61-3.97-4.17Z"/>
            </svg>
        </div>
        <div>
            <div class="t">{{ __('موثّقة عبر أبعاد') }}</div>
            <div class="muted xs">{{ __('قُرئت من دفتر المتجر') }} <span class="ltr">{{ $stampedAt }}</span></div>
        </div>
    </div>
</main>

<footer class="faint xs">{{ __('نسخةٌ حيّة من فاتورتك — احتفظ بالرابط') }}</footer>
</body>
</html>
