<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} · Dashboard</title>
    <style>
        :root { --bg:#f6f7f4; --card:#fff; --ink:#1d2a22; --muted:#66736b; --line:#e3e7e1; --green:#2f6b4f; --red:#b42318; --amber:#b54708; }
        * { box-sizing:border-box; }
        body { margin:0; font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; background:var(--bg); color:var(--ink); }
        main { max-width:1040px; margin:0 auto; padding:32px 16px 64px; }
        header { display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px; margin-bottom:24px; }
        h1 { font-size:22px; margin:0; } h1 span { color:var(--green); }
        h2 { font-size:15px; margin:0 0 12px; }
        .muted { color:var(--muted); font-size:13px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:24px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; }
        .stat b { display:block; font-size:28px; font-variant-numeric:tabular-nums; }
        .cols { display:grid; grid-template-columns:2fr 1fr; gap:12px; }
        @media (max-width:760px) { .cols { grid-template-columns:1fr; } }
        table { width:100%; border-collapse:collapse; font-variant-numeric:tabular-nums; }
        th, td { text-align:left; padding:8px 4px; border-bottom:1px solid var(--line); }
        th { font-size:12px; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.03em; }
        td.num, th.num { text-align:right; }
        .pill { display:inline-block; padding:1px 8px; border-radius:99px; font-size:12px; background:#eef2ee; }
        .pill.failed, .pill.dead { background:#fde8e6; color:var(--red); }
        .pill.pending, .pill.received, .pill.priced, .pill.running { background:#fef0e1; color:var(--amber); }
        .pill.synced, .pill.succeeded { background:#e3f1e9; color:var(--green); }
        dl { margin:0; display:grid; grid-template-columns:1fr auto; gap:6px 12px; }
        dd { margin:0; text-align:right; font-variant-numeric:tabular-nums; }
        .stack { display:grid; gap:12px; align-content:start; }
    </style>
</head>
<body>
<main>
    @if (session('status'))<p class="card" style="margin:0 0 16px">{{ session('status') }}</p>@endif
    <header>
        <h1><span>VerdantCart</span> B2B Sync</h1>
        <span class="muted">WooCommerce ↔ Fortnox · {{ now()->timezone(config('app.display_timezone'))->format('Y-m-d H:i') }}</span>
    </header>

    <section class="grid">
        <div class="card stat"><span class="muted">Active customers</span><b>{{ $stats['customers'] }}</b>
            <span class="muted">of {{ $stats['customers_total'] }} · {{ $stats['unmatched'] }} not in Fortnox</span></div>
        <div class="card stat"><span class="muted">Price tiers</span><b>{{ $stats['tiers'] }}</b></div>
        <div class="card stat"><span class="muted">Active SKUs</span><b>{{ $stats['skus'] }}</b></div>
        <div class="card stat"><span class="muted">Out of stock in Woo</span><b>{{ $stats['out_of_stock'] }}</b>
            <span class="muted">after safety buffer</span></div>
    </section>

    <div class="cols">
        <section class="card">
            <h2>Customers per tier</h2>
            <table>
                <thead><tr><th>Tier</th><th class="num">Discount</th><th class="num">Customers</th></tr></thead>
                <tbody>
                @forelse ($tiers as $tier)
                    <tr>
                        <td>{{ $tier->name }} @unless ($tier->is_active)<span class="pill">inactive</span>@endunless</td>
                        <td class="num">{{ rtrim(rtrim($tier->discount_percent, '0'), '.') }} %</td>
                        <td class="num">{{ $tier->customers_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">No tiers yet. Run <code>php artisan db:seed</code>.</td></tr>
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
                        <td>{{ $order->customer?->company_name ?? 'Guest' }}</td>
                        <td>{{ $order->received_at?->timezone(config('app.display_timezone'))->format('Y-m-d H:i') }}</td>
                        <td style="text-align:right">{{ $order->subtotal }}</td>
                        <td style="text-align:right">{{ $order->price_difference > 0 ? '+' : '' }}{{ $order->price_difference }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
</main>
</body>
</html>
