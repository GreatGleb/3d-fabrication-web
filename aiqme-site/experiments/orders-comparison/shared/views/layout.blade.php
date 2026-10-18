<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>AIQME comparison</title>
<style>body{font:16px Arial,Helvetica,sans-serif;background:#f4f5f7;color:#18232e;margin:0}nav{background:#18232e;color:white;padding:20px;display:flex;gap:24px}nav a{color:white}main{max-width:1100px;margin:32px auto;background:white;padding:28px}table{width:100%;border-collapse:collapse}th,td{padding:14px;text-align:left;border-bottom:1px solid #ddd}form{margin:18px 0}input,select,button{font:inherit;padding:8px}button{cursor:pointer}label{display:inline-block;margin:8px}small{color:#52606e}.status{display:inline-block;padding:8px;background:#edf1f4}a{color:#075ca8}@media(max-width:700px){main{margin:10px;padding:12px}nav{flex-wrap:wrap}table{font-size:13px}}</style>
<nav><strong>AIQME · {{ config('comparison.plain')?'Laravel':'Laravel + Lunar' }}</strong><a href="{{ route('comparison.orders') }}">Orders</a><a href="{{ route('comparison.customers') }}">Customers</a></nav><main>
<p><small>Isolated comparison · synthetic prices · no external payments connected</small></p>
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
@yield('content')
</main></html>
