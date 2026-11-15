@extends('layouts.admin')

@section('title', 'Price tiers')

@section('content')
    <header class="page">
        <h1>Price tiers</h1>
        <span class="muted">Price = list price minus the tier's discount, rounded to whole öre.</span>
    </header>

    <section class="card wrap" style="margin-bottom:16px">
        <table>
            <thead><tr><th>Tier</th><th class="num">Discount</th><th class="num">Priority</th><th class="num">Customers</th>@if ($example)<th class="num">{{ $example->sku }}</th>@endif<th></th></tr></thead>
            <tbody>
            @forelse ($tiers as $tier)
                <tr>
                    <td><a href="{{ route('tiers.edit', $tier) }}"><strong>{{ $tier->name }}</strong></a>
                        @unless ($tier->is_active)<span class="pill">inactive</span>@endunless
                        @if ($tier->description)<div class="muted">{{ $tier->description }}</div>@endif</td>
                    <td class="num">{{ rtrim(rtrim($tier->discount_percent, '0'), '.') }} %</td>
                    <td class="num">{{ $tier->priority }}</td>
                    <td class="num"><a href="{{ route('customers.index', ['tier' => $tier->slug]) }}">{{ $tier->customers_count }}</a></td>
                    @if ($example)<td class="num">{{ $prices->quoteForTier($example, $tier)->unitPrice }}</td>@endif
                    <td class="num"><a href="{{ route('tiers.edit', $tier) }}">Edit</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No tiers yet. Create the first one below, for example "Reseller" with 20 %.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if ($example)<p class="muted" style="margin:8px 0 0">Last column: what each tier pays for {{ $example->name }} (list price {{ $example->base_price }} {{ $example->currency }}).</p>@endif
    </section>

    <section class="card" id="new">
        <h2>New tier</h2>
        <form method="post" action="{{ route('tiers.store') }}" class="fields">@csrf
            @include('tiers._form', ['tier' => new \App\Models\B2bPriceTier(['is_active' => true])])
            <div class="actions"><button>Create tier</button></div>
        </form>
    </section>
@endsection
