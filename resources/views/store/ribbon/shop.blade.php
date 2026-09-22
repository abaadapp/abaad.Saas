@extends('store.ribbon.layout')
@section('title', $t['shopTitle'].' — '.$business->name)
@section('content')
<section class="rb-screen rb-wrap" style="padding:40px 24px" data-testid="rb-shop">
    <div style="margin-bottom:24px">
        <h1 class="rb-h1">{{ $q !== '' ? $q : $t['shopTitle'] }}</h1>
        <p style="margin:6px 0 0;font-size:15px">{{ $t['shopSub'] }}</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:28px" data-testid="rb-cats">
        <a class="rb-pill {{ $cat === 0 ? 'on' : '' }}" href="{{ $base }}/shop">{{ $t['all'] }}</a>
        @foreach ($categories as $c)
            <a class="rb-pill {{ $cat === (int) $c['id'] ? 'on' : '' }}" href="{{ $base }}/shop?cat={{ $c['id'] }}">{{ $c['name'] }}</a>
        @endforeach
    </div>
    @if (count($products) === 0)
        <p style="padding:60px 0;text-align:center">{{ $t['noProducts'] }}</p>
    @else
        <div class="rb-grid">
            @foreach ($products as $p) @include('store.ribbon._card', ['p' => $p]) @endforeach
        </div>
    @endif
</section>
@endsection
