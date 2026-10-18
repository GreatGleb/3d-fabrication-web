@extends('comparison::layout')
@section('content')
<h1>STL basket {{ $basket['id'] }}</h1><p>Estimate EUR cents: <strong>{{ $basket['total'] }}</strong></p>
<table><tr><th>STL</th><th>Quantity</th><th>Estimate</th><th>Actions</th></tr>
@foreach($basket['lines'] as $line)<tr><td>{{ $line['name'] }}</td><td>{{ $line['quantity'] }}</td><td>{{ $line['total'] }}</td><td>
@if(!$basket['submitted'])
<form method="post" action="{{ route('comparison.change',[$basket['id'],$line['id']]) }}">@csrf<input aria-label="Quantity" name="quantity" type="number" min="1" max="100" value="{{ $line['quantity'] }}"><button>Update</button></form>
<form method="post" action="{{ route('comparison.remove',[$basket['id'],$line['id']]) }}">@csrf @method('delete')<button>Remove</button></form>
@endif</td></tr>@endforeach</table>
@if(!$basket['submitted'])
<form method="post" enctype="multipart/form-data" action="{{ route('comparison.add',$basket['id']) }}">@csrf
<label>STL <input type="file" name="file" accept=".stl" required></label><label>Material <select name="material"><option>PLA</option><option>PETG</option></select></label><label>Layer mm <select name="layer_mm"><option>0.2</option><option>0.1</option></select></label><label>Quantity <input type="number" name="quantity" min="1" max="100" value="1"></label><button>Add STL</button></form>
<form method="post" action="{{ route('comparison.submit',$basket['id']) }}">@csrf<button>Submit request without payment</button></form>
@else<a href="{{ route('probe.order',$basket['submitted']) }}">Open submitted order</a>@endif
@endsection
