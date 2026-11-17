@extends('layouts.admin')

@section('title', 'Customers')

@section('content')
    <header class="page">
        <h1>Customers</h1>
        <a class="btn" href="{{ route('customers.create') }}">New customer</a>
    </header>
    <p class="muted" style="margin:-12px 0 16px">Open a customer to choose their price tier and link them to their WooCommerce login and Fortnox customer.</p>

    <form method="get" action="{{ route('customers.index') }}" class="card actions" style="margin-bottom:12px" role="search">
        <input type="search" name="q" value="{{ $search }}" placeholder="Company, e-mail, org. number, Woo ID or Fortnox no." aria-label="Search" style="flex:3 1 260px">
        <select name="tier" aria-label="Tier" style="flex:1 1 140px">
            <option value="">All tiers</option>
            @foreach ($tiers as $tier)<option value="{{ $tier->slug }}" @selected($tierSlug === $tier->slug)>{{ $tier->name }}</option>@endforeach
        </select>
        <select name="filter" aria-label="Filter" style="flex:1 1 180px">
            <option value="">Everyone</option>
            @foreach ($filters as $key => $label)<option value="{{ $key }}" @selected($filter === $key)>{{ $label }}</option>@endforeach
        </select>
        <button>Search</button>
        @if ($search !== '' || $filter !== '' || $tierSlug !== '')<a href="{{ route('customers.index') }}" class="muted">Clear</a>@endif
    </form>

    <section class="card wrap">
        <table>
            <thead><tr><th>Company</th><th>Price tier</th><th>WooCommerce</th><th>Fortnox</th></tr></thead>
            <tbody>
            @forelse ($customers as $customer)
                @php($tier = $customer->is_active ? \App\Services\Pricing\PriceResolver::pickTier($customer->priceTiers, $now) : null)
                <tr>
                    <td><a href="{{ route('customers.show', $customer) }}"><strong>{{ $customer->company_name }}</strong></a>
                        @unless ($customer->is_active)<span class="pill">inactive</span>@endunless
                        <div class="muted">{{ $customer->email }}</div></td>
                    <td>@if ($tier){{ $tier->name }} <span class="muted">{{ rtrim(rtrim($tier->discount_percent, '0'), '.') }} %</span>
                        @elseif ($customer->is_active)<span class="pill todo">list price</span>
                        @else <span class="muted">–</span>@endif</td>
                    <td>@if ($customer->woo_customer_id)<span class="ok">✓</span> #{{ $customer->woo_customer_id }}@else<span class="pill todo">not linked</span>@endif</td>
                    <td>@if ($customer->fortnox_customer_number)<span class="ok">✓</span> {{ $customer->fortnox_customer_number }}@else<span class="pill todo">missing</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">
                    @if ($search !== '' || $filter !== '' || $tierSlug !== '') Nobody matches. <a href="{{ route('customers.index') }}">Show everyone</a>.
                    @else No customers yet. <a href="{{ route('setup') }}#customers">Import them from Fortnox</a> on the setup page, or <a href="{{ route('customers.create') }}">add one</a>.
                    @endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
        @if ($customers->hasPages())
            <div class="actions" style="justify-content:space-between;margin-top:12px">
                <span class="muted">{{ $customers->firstItem() }}–{{ $customers->lastItem() }} of {{ $customers->total() }}</span>
                <span class="actions">
                    @if ($customers->previousPageUrl())<a class="btn secondary" href="{{ $customers->previousPageUrl() }}">← Previous</a>@endif
                    @if ($customers->nextPageUrl())<a class="btn secondary" href="{{ $customers->nextPageUrl() }}">Next →</a>@endif
                </span>
            </div>
        @else
            <p class="muted" style="margin:8px 0 0">{{ $customers->total() }} {{ Str::plural('customer', $customers->total()) }}</p>
        @endif
    </section>
@endsection
