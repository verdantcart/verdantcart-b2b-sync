@extends('layouts.admin')

@section('title', 'Setup')
@section('width', '760px')

@push('styles')
<style>
        .progress { height:6px; background:var(--line); border-radius:99px; overflow:hidden; margin:16px 0 24px; }
        .progress span { display:block; height:100%; background:var(--green); }
        .step { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:18px; margin-bottom:12px; scroll-margin-top:16px; }
        .step.problem { border-color:#f4c7c2; }
        .step-head { display:flex; align-items:center; gap:12px; }
        .num { flex:none; width:28px; height:28px; border-radius:50%; display:grid; place-items:center; font-weight:600; font-size:14px; background:#eef2ee; }
        .done .num { background:var(--green); color:#fff; }
        .problem .num { background:var(--red); color:#fff; }
        .step-head .pill { margin-left:auto; }
        .body { margin:12px 0 0 40px; }
        @media (max-width:520px) { .body { margin-left:0; } }
        .body p { margin:0 0 10px; }
        ul.checks { list-style:none; padding:0; margin:0 0 12px; font-size:14px; }
        ul.checks li { padding:2px 0; }
        .step h2 { font-size:16px; margin:0; }
        .step dl { margin:0 0 12px; display:grid; grid-template-columns:auto 1fr; gap:4px 16px; font-size:14px; }
        .step dt { color:var(--muted); } .step dd { margin:0; text-align:left; overflow-wrap:anywhere; }
        .step input[type=number] { width:110px; }
        .step label.check { align-items:flex-start; margin:10px 0 12px; }
        .token { display:block; font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:13px; background:#f1f3ef; padding:10px; margin:8px 0; border-radius:4px; overflow-wrap:anywhere; user-select:all; }
        .body .flash { margin:0 0 12px; }
</style>
@endpush

@section('content')

    <header class="page" style="margin-bottom:8px">
        <h1>Setup</h1>
    </header>
    <p class="muted" style="margin:0">{{ $done }} of {{ count($steps) }} steps done. Each check button talks to WooCommerce or Fortnox once and remembers the answer.</p>
    <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="{{ count($steps) }}" aria-valuenow="{{ $done }}"><span style="width:{{ round($done / max(count($steps), 1) * 100) }}%"></span></div>

    @php
        $labels = ['done' => 'Done', 'todo' => 'To do', 'problem' => 'Needs attention'];
        $when = fn ($at) => $at?->timezone(config('app.display_timezone'))->format('Y-m-d H:i');
    @endphp

    @foreach ($steps as $i => $step)
        <section class="step {{ $step['state'] }}" id="{{ $step['key'] }}">
            <div class="step-head">
                <span class="num">{{ $step['state'] === 'done' ? '✓' : $i + 1 }}</span>
                <h2>{{ $step['title'] }}</h2>
                <span class="pill {{ $step['state'] }}">{{ $labels[$step['state']] }}</span>
            </div>
            <div class="body">
                @if (session('step') === $step['key'])
                    @if (session('status'))<p class="flash status" role="status">{{ session('status') }}</p>@endif
                    @if (session('error'))<p class="flash error" role="alert">{{ session('error') }}</p>@endif
                @endif

                @switch($step['key'])
                    @case('woo')
                        @if (! $step['configured'])
                            <p>The store's address and REST API keys are missing. They are set when the installation is created (<code>customer:provision</code>), or in <code>.env</code> locally: <code>WOO_BASE_URL</code>, <code>WOO_CONSUMER_KEY</code>, <code>WOO_CONSUMER_SECRET</code>.</p>
                            <p class="muted">Create the key in WooCommerce → Settings → Advanced → REST API, with Read/Write access.</p>
                        @else
                            <dl>
                                <dt>Store</dt><dd>{{ $step['store_url'] }}</dd>
                                @if ($step['check'] && $step['check']['ok'])
                                    <dt>Products</dt><dd>{{ $step['check']['products'] }}</dd>
                                    <dt>Currency</dt><dd>{{ $step['check']['currency'] ?? '?' }}</dd>
                                @endif
                                @if ($step['check'])<dt>Checked</dt><dd>{{ $when($step['check']['checked_at']) }}</dd>@endif
                            </dl>
                            @if ($step['check'] && ! $step['check']['ok'] && session('step') !== 'woo')
                                <p class="flash error">{{ $step['check']['error'] }}</p>
                            @endif
                            <form method="post" action="{{ route('setup.woo.check') }}" class="actions">@csrf
                                <button>{{ $step['check'] ? 'Check again' : 'Check the connection' }}</button>
                            </form>
                        @endif
                        @break

                    @case('webhooks')
                        <p>WooCommerce tells B2B Sync about new orders and changed products through webhooks.</p>
                        <dl>
                            <dt>Sent to</dt><dd><code>{{ $step['delivery_url'] }}</code></dd>
                            <dt>Topics</dt><dd>
                                @foreach ($step['topics'] as $topic)
                                    <span class="{{ is_array($step['missing']) ? (in_array($topic, $step['missing'], true) ? 'bad' : 'ok') : '' }}">{{ $topic }}</span>@if (! $loop->last), @endif
                                @endforeach
                            </dd>
                        </dl>
                        @if (! $step['ready'])
                            <p class="muted">Check the WooCommerce connection first.</p>
                        @elseif (! $step['secret_set'])
                            <p class="flash error">WOO_WEBHOOK_SECRET is not set, so webhooks cannot be signed. Locally, run <code>php artisan woo:webhooks</code> once.</p>
                        @else
                            <form method="post" action="{{ route('setup.woo.webhooks') }}" class="actions">@csrf
                                <button @class(['secondary' => $step['state'] === 'done'])>{{ $step['state'] === 'done' ? 'Register again' : 'Register the webhooks' }}</button>
                            </form>
                        @endif
                        @break

                    @case('fortnox')
                        @if (! $step['uses_fortnox'])
                            <p>This installation uses the fake ERP (demo data, no Fortnox). Set <code>ERP_DRIVER=fortnox</code> to connect a real Fortnox company.</p>
                        @elseif (! $step['configured'])
                            <p>The Fortnox integration keys are missing: <code>FORTNOX_CLIENT_ID</code> and <code>FORTNOX_CLIENT_SECRET</code>.</p>
                        @else
                            <dl>
                                <dt>Status</dt><dd>{{ $step['connected_at'] ? 'Logged in since '.$when($step['connected_at']) : 'Not logged in' }}</dd>
                                @if ($step['check'] && $step['check']['company'])
                                    <dt>Company</dt><dd>{{ $step['check']['company'] }} ({{ $step['check']['organisation_number'] ?? '?' }})</dd>
                                @endif
                                @if ($step['check'])<dt>Checked</dt><dd>{{ $when($step['check']['checked_at']) }}</dd>@endif
                            </dl>
                            @if ($step['check'] && $step['check']['checks'])
                                <ul class="checks">
                                    @foreach ($step['check']['checks'] as $check)
                                        <li>
                                            @if ($check['ok'])<span class="ok">✓</span> {{ $check['label'] }}
                                            @elseif ($check['error'])<span class="bad">✕</span> {{ $check['label'] }}: {{ $check['error'] }}
                                            @else<span class="bad">✕</span> {{ $check['label'] }}: needs <strong>{{ $check['licence'] }}</strong> in Fortnox
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($step['check'] && $step['check']['error'] && session('step') !== 'fortnox')
                                <p class="flash error">{{ $step['check']['error'] }}</p>
                            @endif
                            @unless ($step['connected_at'])
                                <p>The company's Fortnox administrator logs in and approves the integration. Fortnox must know this return address: <code>{{ $step['redirect_uri'] }}</code></p>
                            @endunless
                            <div class="actions">
                                <a class="btn @if ($step['connected_at']) secondary @endif" href="{{ route('fortnox.connect') }}">{{ $step['connected_at'] ? 'Log in again' : 'Log in to Fortnox' }}</a>
                                @if ($step['connected_at'])
                                    <form method="post" action="{{ route('setup.fortnox.check') }}">@csrf
                                        <button>{{ $step['check'] ? 'Check again' : 'Check licences' }}</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                        @break

                    @case('buffer')
                        <p>WooCommerce shows Fortnox stock minus this many units per product, so a reseller can't order the last items while stock is being counted or moved.</p>
                        <form method="post" action="{{ route('setup.buffer') }}">@csrf
                            <div class="actions">
                                <label for="buffer">Units held back</label>
                                <input type="number" id="buffer" name="buffer" min="0" max="{{ $step['max'] }}" value="{{ old('buffer', $step['value']) }}" required>
                            </div>
                            @error('buffer')<p class="flash error" style="margin-top:8px">{{ $message }}</p>@enderror
                            <label class="check"><input type="checkbox" name="apply_to_all" value="1"> <span>Also use it for products that already have a buffer, and update their stock in WooCommerce now</span></label>
                            <button>Save buffer</button>
                        </form>
                        @break

                    @case('token')
                        <p>The <strong>VerdantCart B2B Prices</strong> plugin shows each reseller their own price in the shop. In WordPress, go to WooCommerce → B2B Prices and enter:</p>
                        <dl>
                            <dt>API address</dt><dd><code>{{ $step['api_url'] }}</code></dd>
                            <dt>Token</dt><dd>{{ $step['tokens'] > 0 ? $step['tokens'].' active' : 'none yet' }}</dd>
                        </dl>
                        @if (session('new_token'))
                            <p class="flash status" role="status">Copy this token into the plugin now. It is stored hashed and cannot be shown again.</p>
                            <code class="token">{{ session('new_token') }}</code>
                        @endif
                        <form method="post" action="{{ route('setup.token') }}" class="actions">@csrf
                            <button @class(['secondary' => $step['tokens'] > 0])>{{ $step['tokens'] > 0 ? 'Create another token' : 'Create the token' }}</button>
                            @if ($step['tokens'] > 0)<span class="muted">Only needed for a new shop or if the old token is lost.</span>@endif
                        </form>
                        @break
                @endswitch
            </div>
        </section>
    @endforeach

    @if ($done === count($steps))
        <p class="step done" style="text-align:center">All set. Place a test order in the shop and watch it arrive on the <a href="{{ route('dashboard') }}">dashboard</a>.</p>
    @endif
@endsection
