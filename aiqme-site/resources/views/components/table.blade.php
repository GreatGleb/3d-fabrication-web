{{-- Таблица прототипа: обёртка .data-table прокручивается сама, документ не расширяется (§7.2). --}}
<div {{ $attributes->class('data-table') }}><table>{{ $slot }}</table></div>
