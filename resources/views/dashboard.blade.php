@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')

    <header class="page">
        <h1>Dashboard</h1>
        <span class="muted">WooCommerce ↔ Fortnox · {{ now()->timezone(config('app.display_timezone'))->format('Y-m-d H:i') }}</span>
    </header>

    @if ($setup && $setup['done'] < $setup['total'])
        <p class="card" style="margin:0 0 16px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
            <span><strong>Setup: {{ $setup['done'] }} of {{ $setup['total'] }} steps done.</strong> <span class="muted">Orders and prices flow once every step is green.</span></span>
            <a href="{{ route('setup') }}" style="color:var(--green);font-weight:600">Continue setup →</a>
        </p>
    @endif

    <section class="grid">
        <div class="card stat"><span class="muted">Active customers</span><b>{{ $stats['customers'] }}</b>
            <span class="muted">of <a href="{{ route('customers.index') }}" style="color:inherit">{{ $stats['customers_total'] }}</a> · <a href="{{ route('customers.index', ['filter' => 'no-fortnox']) }}" style="color:inherit">{{ $stats['unmatched'] }} not in Fortnox</a></span></div>
        <div class="card stat"><span class="muted">Price tiers</span><b>{{ $stats['tiers'] }}</b></div>
        <div class="card stat"><span class="muted">Active SKUs</span><b>{{ $stats['skus'] }}</b></div>
        <div class="card stat"><span class="muted">Out of stock in Woo</span><b>{{ $stats['out_of_stock'] }}</b>
            <span class="muted">after safety buffer</span></div>
    </section>

    <div class="cols">
        <section class="card">
            <h2>Customers per tier <a class="muted" href="{{ route('tiers.index') }}" style="float:right;font-weight:400">Tiers →</a></h2>
            <table>
                <thead><tr><th>Tier</th><th class="num">Discount</th><th class="num">Customers</th></tr></thead>
                <tbody>
                @forelse ($tiers as $tier)
                    <tr>
                        <td><a href="{{ route('tiers.edit', $tier) }}">{{ $tier->name }}</a> @unless ($tier->is_active)<span class="pill">inactive</span>@endunless</td>
                        <td class="num">{{ rtrim(rtrim($tier->discount_percent, '0'), '.') }} %</td>
                        <td class="num"><a href="{{ route('customers.index', ['tier' => $tier->slug]) }}">{{ $tier->customers_count }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">No tiers yet. <a href="{{ route('tiers.index') }}#new">Create the first one</a>.</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>

        <div class="stack">
            <section class="card">
                <h2>Orders</h2>
                <dl>
                    @forelse ($ordersByStatus as $status => $total)
                        <dt><span class="pill {{ $status }}">{{ $status }}</span></dt><dd>{{ $total }}</dd>
                    @empty
                        <dt class="muted">No orders yet</dt><dd></dd>
                    @endforelse
                </dl>
            </section>
            <section class="card">
                <h2>Queue <a class="muted" href="{{ url(config('horizon.path', 'horizon')) }}" style="float:right;font-weight:400">Horizon →</a></h2>
                @if ($queue['available'])
                    <dl>
                        @foreach ($queue['sizes'] as $name => $size)
                            <dt>{{ $name }}</dt><dd>{{ $size }} waiting</dd>
                        @endforeach
                        <dt>Worker last seen</dt>
                        <dd>
                            @if ($queue['heartbeat_at'])
                                {{ $queue['heartbeat_at']->diffForHumans() }}
                            @else
                                <span class="pill pending">never</span>
                            @endif
                        </dd>
                    </dl>
                    <p class="muted" style="margin:8px 0 0">Check with <code>php artisan queue:heartbeat</code></p>
                @else
                    <p class="muted" style="margin:0"><span class="pill failed">Redis unavailable</span> Start Redis in Laragon.</p>
                @endif
            </section>
            <section class="card">
                <h2>Sync jobs <a class="muted" href="{{ route('sync-jobs.index') }}" style="float:right;font-weight:400">Failed jobs →</a></h2>
                <dl>
                    @forelse ($syncByStatus as $status => $total)
                        <dt><span class="pill {{ $status }}">{{ $status }}</span></dt><dd>{{ $total }}</dd>
                    @empty
                        <dt class="muted">No sync jobs yet</dt><dd></dd>
                    @endforelse
                </dl>
            </section>
        </div>
    </div>

    <section class="card" style="margin-top:16px">
        <h2>Price differences
            @if ($priceDifferenceCount > 0)<span class="pill failed" style="margin-left:6px">{{ $priceDifferenceCount }}</span>@endif
        </h2>
        @if ($priceDifferenceCount === 0)
            <p class="muted" style="margin:0">None. Every order was charged in the shop at the same price that went to Fortnox.</p>
        @else
            <p class="muted" style="margin:0 0 8px">The shop charged a different amount than the B2B price, for example because B2B Sync was unreachable at checkout. Fortnox got the B2B price; check whether the customer should be refunded or charged the difference.</p>
            <table>
                <thead><tr><th>Woo order</th><th>Customer</th><th>Received</th><th style="text-align:right">B2B subtotal</th><th style="text-align:right">Difference</th></tr></thead>
                <tbody>
                @foreach ($priceDifferences as $order)
                    <tr>
                        <td>#{{ $order->woo_order_id }}</td>
                        <td>@if ($order->customer)<a href="{{ route('customers.show', $order->b2b_customer_id) }}">{{ $order->customer->company_name }}</a>@else Guest @endif</td>
                        <td>{{ $order->received_at?->timezone(config('app.display_timezone'))->format('Y-m-d H:i') }}</td>
                        <td style="text-align:right">{{ $order->subtotal }}</td>
                        <td style="text-align:right">{{ $order->price_difference > 0 ? '+' : '' }}{{ $order->price_difference }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
