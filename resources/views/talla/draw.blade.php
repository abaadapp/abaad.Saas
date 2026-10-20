<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>سحب طلّة — اللوحة</title>
    @include('talla.style')
    <style>
        body { align-items: flex-start; }
        .wrap { width: 100%; max-width: 900px; display: grid; gap: 18px; }
        .card { max-width: none; }
        .stage { text-align: center; padding: 40px 20px; }
        #display { font-size: clamp(36px, 8vw, 76px); font-weight: 800; min-height: 1.4em; margin: 10px 0 24px;
            transition: transform .15s; }
        #display.win { color: #f5b83d; animation: pop .6s ease; }
        @keyframes pop { 0% { transform: scale(.6); } 60% { transform: scale(1.15); } 100% { transform: scale(1); } }
        #go { max-width: 320px; font-size: 22px; padding: 18px; }
        .count { color: #b8c4d9; }
        table { width: 100%; border-collapse: collapse; }
        td, th { text-align: right; padding: 10px 8px; border-bottom: 1px solid rgba(255,255,255,.1); vertical-align: top; }
        th { color: #b8c4d9; font-weight: 600; }
        .row { display: flex; gap: 10px; align-items: center; }
        .row input { flex: 1; }
        .row button { width: auto; margin: 0; white-space: nowrap; }
        .ghost { background: transparent; border: 1px solid rgba(255,255,255,.3); color: inherit; font-size: 15px; width: auto; padding: 10px 16px; }
        .link { direction: ltr; text-align: center; color: #b8c4d9; font-size: 15px; word-break: break-all; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card stage">
        <h1>سحب طلّة</h1>
        <p class="count">عدد المشاركين: <b id="count">{{ count($data['entries']) }}</b></p>
        <div id="display">{{ $data['winner']['name'] ?? '؟' }}</div>
        <button id="go" @disabled($data['entries'] === [])>اسحب</button>
        <p class="link">{{ route('talla') }}</p>
    </div>

    <div class="card">
        <form method="post" action="{{ route('talla.question') }}" class="row">
            @csrf
            <input name="question" value="{{ $data['question'] }}" maxlength="500" required>
            <button type="submit">حفظ السؤال</button>
        </form>
    </div>

    <div class="card">
        <table>
            <thead><tr><th>#</th><th>الاسم</th><th>الإجابة</th></tr></thead>
            <tbody>
            @forelse ($data['entries'] as $i => $entry)
                <tr><td>{{ $i + 1 }}</td><td>{{ $entry['name'] }}</td><td>{{ $entry['answer'] }}</td></tr>
            @empty
                <tr><td colspan="3" style="text-align:center;color:#b8c4d9">لا مشاركين بعد</td></tr>
            @endforelse
            </tbody>
        </table>
        <form method="post" action="{{ route('talla.reset') }}" style="margin-top:16px"
              onsubmit="return confirm('مسح كل المشاركين؟')">
            @csrf
            <button type="submit" class="ghost">مسح المشاركين</button>
        </form>
    </div>
</div>

<script>
    const names = @json(array_column($data['entries'], 'name'));
    const display = document.getElementById('display');
    const go = document.getElementById('go');

    go.addEventListener('click', async () => {
        go.disabled = true;
        display.classList.remove('win');

        const request = fetch(@json(route('talla.pick')), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
            },
        }).then(r => r.json());

        // الأسماء تدور وتتباطأ، ثم تقف على من اختاره الخادم
        let delay = 50;
        const started = Date.now();
        while (Date.now() - started < 4000 || delay < 300) {
            display.textContent = names[Math.floor(Math.random() * names.length)];
            await new Promise(r => setTimeout(r, delay));
            delay = Math.min(delay * 1.07, 320);
        }

        const { winner } = await request;
        display.textContent = winner ? winner.name : '؟';
        display.classList.add('win');
        go.disabled = false;
    });
</script>
</body>
</html>
