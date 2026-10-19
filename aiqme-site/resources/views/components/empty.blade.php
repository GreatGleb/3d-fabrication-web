{{-- Пустое состояние прототипа (.empty): иконка, заголовок, пояснение и необязательное действие в слоте. --}}
@props(['title', 'text' => null, 'icon' => 'box'])
<div {{ $attributes->class('empty') }}>
    <x-line-icon :name="$icon" />
    <h3 class="gap">{{ $title }}</h3>
    @if ($text)
        <p>{{ $text }}</p>
    @endif
    {{ $slot }}
</div>
