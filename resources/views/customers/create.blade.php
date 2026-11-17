@extends('layouts.admin')

@section('title', 'New customer')
@section('width', '840px')

@section('content')
    <header class="page">
        <h1><a href="{{ route('customers.index') }}" style="color:inherit;text-decoration:none">Customers</a> · New</h1>
        <span class="muted">Customers already in Fortnox are imported on the <a href="{{ route('setup') }}#customers">setup page</a>.</span>
    </header>
    <section class="card">
        <form method="post" action="{{ route('customers.store') }}" class="fields">@csrf
            @include('customers._form')
            <div class="actions"><button>Add customer</button> <a href="{{ route('customers.index') }}" class="muted">Cancel</a></div>
        </form>
    </section>
@endsection
