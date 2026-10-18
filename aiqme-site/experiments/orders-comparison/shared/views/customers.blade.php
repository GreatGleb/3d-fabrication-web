@extends('comparison::layout')
@section('content')
<h1>Customers</h1><table><tr><th>Name</th><th>Email</th><th>Orders</th></tr>
@foreach($customers as $customer)<tr><td>{{ $customer->name }}</td><td>{{ $customer->email }}</td><td>{{ \Comparison\ReadModel::orderClass()::whereUserId($customer->id)->count() }}</td></tr>@endforeach</table>{{ $customers->links() }}
@endsection
