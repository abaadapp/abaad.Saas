<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>سحب طلّة</title>
    @include('talla.style')
</head>
<body>
<div class="card">
    <h1>طلّة</h1>

    @if ($step === 'name')
        <p class="sub">سجّل اسمك وادخل السحب</p>
        <form method="post" action="{{ route('talla.name') }}">
            @csrf
            <label for="name">الاسم</label>
            <input id="name" name="name" value="{{ old('name') }}" maxlength="80" required autofocus autocomplete="name">
            @error('name') <div class="err">{{ $message }}</div> @enderror
            <button type="submit">التالي</button>
        </form>
    @elseif ($step === 'question')
        <p class="sub">أهلًا {{ $name }}، بقي سؤالٌ واحد</p>
        <form method="post" action="{{ route('talla.answer') }}">
            @csrf
            <p class="q">{{ $question }}</p>
            <textarea name="answer" maxlength="500" required autofocus>{{ old('answer') }}</textarea>
            @error('answer') <div class="err">{{ $message }}</div> @enderror
            <button type="submit">إرسال</button>
        </form>
    @else
        <p class="sub" style="font-size:20px;margin:18px 0 0">تمّ تسجيلك {{ $name }} 🎉<br>بالتوفيق في السحب</p>
    @endif
</div>
</body>
</html>
