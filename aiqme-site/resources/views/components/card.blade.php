{{-- Карточка прототипа .card с .card-body; при href вся карточка — ссылка. --}}
@props(['href' => null])
@if ($href)
<a href="{{ $href }}" {{ $attributes->class('card') }}><div class="card-body">{{ $slot }}</div></a>
@else
<div {{ $attributes->class('card') }}><div class="card-body">{{ $slot }}</div></div>
@endif
