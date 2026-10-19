{{-- Постоянный блок статуса (§7.2): результат критичной операции остаётся на странице, а не только во всплывающем
     сообщении. По умолчанию — session('status'); type success → role="status", error → role="alert". --}}
@props(['message' => session('status'), 'type' => 'success'])
@if (filled($message))
<div {{ $attributes->class(['notice', $type]) }} role="{{ $type === 'error' ? 'alert' : 'status' }}">{{ $message }}</div>
@endif
