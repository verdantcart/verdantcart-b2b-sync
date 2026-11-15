@extends('layouts.admin')

@section('title', $tier->name)

@section('content')
    <header class="page">
        <h1><a href="{{ route('tiers.index') }}" style="color:inherit;text-decoration:none">Price tiers</a> · {{ $tier->name }}</h1>
        <span class="muted">{{ $tier->customers_count }} {{ Str::plural('customer', $tier->customers_count) }}</span>
    </header>

    <div class="cols">
        <section class="card">
            <form method="post" action="{{ route('tiers.update', $tier) }}" class="fields">@csrf @method('put')
                @include('tiers._form')
                <div class="actions"><button>Save</button></div>
            </form>
        </section>

        <div class="stack">
            <section class="card">
                <h2>Example prices</h2>
                @if ($quotes->isEmpty())
                    <p class="muted" style="margin:0">No products yet. They appear when WooCommerce sends its first product webhook.</p>
                @else
                    <table>
                        <thead><tr><th>SKU</th><th class="num">List</th><th class="num">{{ $tier->name }}</th></tr></thead>
                        <tbody>
                        @foreach ($quotes as $quote)
                            <tr><td>{{ $quote->sku }}</td><td class="num">{{ $quote->basePrice }}</td><td class="num"><strong>{{ $quote->unitPrice }}</strong></td></tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            <section class="card">
                <h2>Customers</h2>
                @forelse ($customers as $customer)
                    <div><a href="{{ route('customers.show', $customer) }}">{{ $customer->company_name }}</a>@unless ($customer->is_active) <span class="pill">inactive</span>@endunless</div>
                @empty
                    <p class="muted" style="margin:0">Nobody yet. Open a customer and add them to this tier.</p>
                @endforelse
                @if ($tier->customers_count > $customers->count())
                    <p style="margin:8px 0 0"><a href="{{ route('customers.index', ['tier' => $tier->slug]) }}">All {{ $tier->customers_count }} →</a></p>
                @endif
            </section>

            <section class="card">
                <h2>Delete</h2>
                @if ($tier->customers_count > 0)
                    <p class="muted" style="margin:0">A tier with customers can't be deleted. Untick Active to stop using it, or move its customers first.</p>
                @else
                    <form method="post" action="{{ route('tiers.destroy', $tier) }}" onsubmit="return confirm(@js('Delete '.$tier->name.'?'))">@csrf @method('delete')
                        <button class="danger">Delete this tier</button>
                    </form>
                @endif
            </section>
        </div>
    </div>
@endsection
