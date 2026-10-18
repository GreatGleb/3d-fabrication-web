@extends('comparison::layout')
@section('content')
<h1>Orders</h1><form method="get"><label>Reference <input name="q" value="{{ request('q') }}"></label><button>Search</button></form>
<form method="post" action="{{ route('comparison.create') }}">@csrf<button>New STL basket</button></form>
<table><tr><th>Reference</th><th>Production</th><th>Payment</th><th>Total EUR cents</th><th>Due</th></tr>
@foreach($orders as $order)@php($balance=app(\App\Services\OrderWorkflow::class)->balance($order))<tr><td><a href="{{ route('probe.order',$order) }}">{{ $order->reference }}</a></td><td>{{ \Comparison\ReadModel::state($order)->state }}</td><td>{{ $balance['payment_state'] }}</td><td>{{ $balance['total'] }}</td><td>{{ $balance['due'] }}</td></tr>@endforeach</table>
{{ $orders->links() }}
@endsection
