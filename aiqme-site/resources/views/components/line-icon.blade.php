{{-- Линейные иконки прототипа (paths из app.js). Не x-icon: это имя занято blade-icons (Filament). --}}
@props(['name'])
@php($paths = [
    'upload' => 'M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6',
    'arrow' => 'M4 12h16m-6-6 6 6-6 6',
    'search' => 'M10 17a7 7 0 1 1 0-14 7 7 0 0 1 0 14zm5-2 6 6',
    'cart' => 'M2 3h3l3 13h11l3-10H6m3 14h1m8 0h1',
    'menu' => 'M3 6h18M3 12h18M3 18h18',
    'settings' => 'M3 6h18M3 12h18M3 18h18M8 3v6m9 0v6m-7 0v6',
    'close' => 'm5 5 14 14M19 5 5 19',
    'box' => 'm12 2 10 5v10l-10 5-10-5V7l10-5zm0 10v10M2 7l10 5 10-5M7 4.5l10 5',
    'check' => 'm5 12 4 4L20 5',
    'scanner' => 'M3 8h18v7H3zM7 11h1m8 0h1M12 15v7m-5 0 5-7 5 7',
    'printer' => 'M3 2h18v20H3zM6 5h12v12H6zM9 20h6M12 5v4m-3 5 3-5 3 5z',
])
<svg {{ $attributes->class('icon') }} viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $paths[$name] }}" stroke-linecap="round" stroke-linejoin="round"/></svg>
