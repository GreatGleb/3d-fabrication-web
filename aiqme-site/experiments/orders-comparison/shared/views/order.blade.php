@extends('comparison::layout')
@section('content')
@php($production=\Comparison\ReadModel::state($order))
<h1>{{ $order->reference }}</h1><p class="status">Production: {{ $production->state }}</p> <p class="status">Payment: {{ $balance['payment_state'] }}</p>
<p>Total EUR cents: <strong>{{ $balance['total'] }}</strong> · Paid: <strong>{{ $balance['paid'] }}</strong> · Due: <strong>{{ $balance['due'] }}</strong></p>
<table><tr><th>STL</th><th>Parameters</th><th>Quantity</th><th>Estimate</th><th>Current total</th></tr>
@foreach($order->lines as $line)<tr><td><a href="{{ route('probe.file',$line->purchasable->id) }}">{{ $line->description }}</a></td><td>{{ $line->option }}</td><td>{{ $line->quantity }}</td><td>{{ $line->meta['estimate'] }}</td><td>{{ \Comparison\ReadModel::cents($line->total) }}</td></tr>@endforeach</table>
@if(auth()->user()->is_admin)
<h2>Confirm agreed prices</h2><form method="post" action="{{ route('probe.confirm',$order) }}">@csrf
@foreach($order->lines as $line)<label>{{ $line->description }} · unit EUR cents<input type="number" min="1" name="prices[{{ $line->id }}]" value="{{ \Comparison\ReadModel::cents($line->unit_price) }}" required></label>@endforeach
<label>Reason<input name="reason" required></label><label>Agreement reference<input name="agreement" required></label><button>Confirm</button></form>
<h2>Request full amount / advance / balance</h2><form method="post" action="{{ route('probe.payment',$order) }}">@csrf<input type="number" name="amount" min="1" value="{{ $balance['due'] }}"><button>Request amount</button></form>
<form method="post" action="{{ route('probe.start',$order) }}">@csrf<button>Start production independently of payment</button></form>
<form method="post" action="{{ route('comparison.cancel',$order) }}">@csrf<button>Cancel manually without refund</button></form>
@endif
<h2>Payment requests</h2>
@foreach(\App\Models\PaymentRequest::whereOrderId($order->id)->get() as $payment)
<p>{{ $payment->amount }} EUR cents · {{ $payment->state }}</p>
@if($payment->state==='pending')<form method="post" action="{{ route('comparison.checkout',$payment->public_id) }}">@csrf<button>Create test-mode Stripe intent (credentials required)</button></form>@endif
@endforeach
@endsection
