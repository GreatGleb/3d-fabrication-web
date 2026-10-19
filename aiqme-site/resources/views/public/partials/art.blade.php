{{-- Своя нейтральная заглушка иллюстрации до медиа заказчика (§7.2, F-47): линейная схема детали, не изображение прототипа. --}}
<svg class="placeholder-art" viewBox="0 0 480 270" preserveAspectRatio="xMidYMid {{ ($artCover ?? false) ? 'slice' : 'meet' }}" aria-hidden="true" focusable="false">
    <g fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path opacity=".35" d="M0 54h480M0 108h480M0 162h480M0 216h480M80 0v270M160 0v270M240 0v270M320 0v270M400 0v270"/>
        <path d="M150 196V112l42-32h96l42 32v84z"/>
        <path d="M192 80v32h96V80M150 112h180"/>
        <circle cx="200" cy="154" r="16"/>
        <circle cx="280" cy="154" r="16"/>
        <path opacity=".7" d="M150 222h180M150 214v16M330 214v16M356 112v84M348 112h16M348 196h16"/>
    </g>
</svg>
