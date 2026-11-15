@extends('layouts.admin')

@section('title', $customer->company_name)

@php
    $tz = config('app.display_timezone');
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone($tz)->format('Y-m-d') : null;
    // valid_until is stored as the start of the day after the last day, so show the day before.
    $lastDay = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone($tz)->subSecond()->format('Y-m-d') : null;
@endphp

@section('content')
    <header class="page">
        <h1><a href="{{ route('customers.index') }}" style="color:inherit;text-decoration:none">Customers</a> · {{ $customer->company_name }}</h1>
        <span>
            @if (! $customer->is_active)<span class="pill">inactive · pays list price</span>
            @elseif ($effective)<span class="pill done">{{ $effective->name }} · {{ rtrim(rtrim($effective->discount_percent, '0'), '.') }} % off</span>
            @else<span class="pill todo">no tier · pays list price</span>@endif
        </span>
    </header>

    <div class="cols">
        <div class="stack">
            <section class="card" id="tiers">
                <h2>Price tiers</h2>
                @if ($customer->priceTiers->isEmpty())
                    <p class="muted" style="margin:0 0 12px">Not in any tier yet, so this customer pays list price.</p>
                @else
                    <table style="margin-bottom:12px">
                        <thead><tr><th>Tier</th><th class="num">Discount</th><th>Valid</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($customer->priceTiers as $tier)
                            @php
                                $from = $tier->pivot->valid_from; $until = $tier->pivot->valid_until;
                                $expired = $until && \Illuminate\Support\Carbon::parse($until)->lte($now);
                                $future = $from && \Illuminate\Support\Carbon::parse($from)->gt($now);
                            @endphp
                            <tr>
                                <td><a href="{{ route('tiers.edit', $tier) }}">{{ $tier->name }}</a>
                                    @if ($effective && $effective->is($tier))<span class="pill done">in use</span>@endif
                                    @unless ($tier->is_active)<span class="pill">tier inactive</span>@endunless
                                    @if ($expired)<span class="pill">ended</span>@elseif ($future)<span class="pill todo">starts later</span>@endif</td>
                                <td class="num">{{ rtrim(rtrim($tier->discount_percent, '0'), '.') }} %</td>
                                <td>@if (! $from && ! $until)<span class="muted">always</span>
                                    @else {{ $date($from) ?? '…' }} – {{ $lastDay($until) ?? '…' }}@endif</td>
                                <td class="num">
                                    <form method="post" action="{{ route('customers.tiers.destroy', [$customer, $tier]) }}">@csrf @method('delete')
                                        <button class="link">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @if ($customer->priceTiers->count() > 1)<p class="muted" style="margin:0 0 12px">In several tiers: the one with the highest priority that is valid today is used.</p>@endif
                @endif

                @if ($availableTiers->isEmpty())
                    <p class="muted" style="margin:0">@if ($customer->priceTiers->isEmpty()) No tiers exist yet. <a href="{{ route('tiers.index') }}#new">Create one</a>.@else In every tier already.@endif</p>
                @else
                    <form method="post" action="{{ route('customers.tiers.store', $customer) }}" class="fields">@csrf
                        <div class="row">
                            <label class="field"><span>Add to tier</span>
                                <select name="tier_id" required>
                                    @foreach ($availableTiers as $tier)<option value="{{ $tier->id }}" @selected(old('tier_id') == $tier->id)>{{ $tier->name }} ({{ rtrim(rtrim($tier->discount_percent, '0'), '.') }} %)</option>@endforeach
                                </select>
                            </label>
                            <label class="field"><span>From (optional)</span><input type="date" name="valid_from" value="{{ old('valid_from') }}"></label>
                            <label class="field"><span>Until (optional)</span><input type="date" name="valid_until" value="{{ old('valid_until') }}"></label>
                        </div>
                        @foreach ($errors->tier->all() as $message)<span class="error">{{ $message }}</span>@endforeach
                        <div class="actions"><button>Add</button> <span class="muted">Leave the dates empty for a permanent tier. Use them for a campaign.</span></div>
                    </form>
                @endif
            </section>

            <section class="card" id="details">
                <h2>Details and links</h2>
                <form method="post" action="{{ route('customers.update', $customer) }}" class="fields">@csrf @method('put')
                    @include('customers._form')
                    <div class="actions"><button>Save</button></div>
                </form>
                @unless ($customer->woo_customer_id)
                    <form method="post" action="{{ route('customers.find-in-woo', $customer) }}" class="actions" style="margin-top:12px">@csrf
                        <button class="secondary">Find in WooCommerce</button>
                        <span class="muted">Looks up {{ $customer->email }} and fills in the customer ID.</span>
                    </form>
                @endunless
            </section>
        </div>

        <div class="stack">
            <section class="card">
                <h2>What they pay</h2>
                @if ($quotes === [])
                    <p class="muted" style="margin:0">No products yet.</p>
                @else
                    <table>
                        <thead><tr><th>SKU</th><th class="num">List</th><th class="num">Their price</th></tr></thead>
                        <tbody>
                        @foreach ($quotes as $quote)
                            <tr><td>{{ $quote->sku }}</td><td class="num">{{ $quote->basePrice }}</td><td class="num"><strong>{{ $quote->unitPrice }}</strong></td></tr>
                        @endforeach
                        </tbody>
                    </table>
                    <p class="muted" style="margin:8px 0 0">The same prices the shop shows them and Fortnox gets.</p>
                @endif
            </section>

            <section class="card">
                <h2>Recent orders</h2>
                <dl>
                    @forelse ($orders as $order)
                        <dt>#{{ $order->woo_order_id }} <span class="muted">{{ $order->received_at?->timezone($tz)->format('Y-m-d') }}</span></dt>
                        <dd><span class="pill {{ $order->status }}">{{ $order->status }}</span></dd>
                    @empty
                        <dt class="muted">No orders yet</dt><dd></dd>
                    @endforelse
                </dl>
            </section>
        </div>
    </div>
@endsection
