@extends('comparison::layout')
@section('content')
<h1>Isolated comparison login</h1><form method="post" action="/login">@csrf
<label>Email <input name="email" type="email" required></label><label>Password <input name="password" type="password" required></label><button>Sign in</button></form>
<p>Synthetic accounts: buyer@example.test, admin@example.test · local-probe-only.</p>
@if(!config('comparison.plain'))<a href="/lunar/login">Native Lunar admin</a>@endif
@endsection
