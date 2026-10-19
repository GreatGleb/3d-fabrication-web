{{-- Кнопка прототипа .btn: ссылка при href, иначе <button>. variant — secondary, white, ghost, danger; small — малый размер;
     arrow — стрелка после подписи, как у ссылок-кнопок прототипа. --}}
@props(['href' => null, 'variant' => null, 'small' => false, 'arrow' => false, 'type' => 'button'])
@php($classes = ['btn', $variant, 'small' => $small])
@if ($href)
<a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}@if ($arrow) <x-line-icon name="arrow" />@endif</a>
@else
<button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}@if ($arrow) <x-line-icon name="arrow" />@endif</button>
@endif
