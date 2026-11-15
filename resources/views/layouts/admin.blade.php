<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} · @yield('title')</title>
    <style>
        :root { --bg:#f6f7f4; --card:#fff; --ink:#1d2a22; --muted:#66736b; --line:#e3e7e1; --green:#2f6b4f; --red:#b42318; --amber:#b54708; }
        * { box-sizing:border-box; }
        body { margin:0; font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; background:var(--bg); color:var(--ink); }
        a { color:var(--green); }
        main { max-width:@yield('width', '1040px'); margin:0 auto; padding:24px 16px 64px; }
        .topbar { background:var(--card); border-bottom:1px solid var(--line); }
        .topbar-inner { max-width:1040px; margin:0 auto; padding:10px 16px; display:flex; align-items:center; gap:4px 18px; flex-wrap:wrap; }
        .brand { font-weight:700; color:var(--ink); text-decoration:none; margin-right:auto; } .brand span { color:var(--green); }
        .topbar nav { display:flex; gap:2px 14px; flex-wrap:wrap; font-size:14px; }
        .topbar nav a { color:var(--muted); text-decoration:none; padding:4px 0; border-bottom:2px solid transparent; }
        .topbar nav a.on { color:var(--ink); border-color:var(--green); }
        header.page { display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:8px; margin-bottom:20px; }
        h1 { font-size:22px; margin:0; } h1 span { color:var(--green); }
        h2 { font-size:15px; margin:0 0 12px; }
        .muted { color:var(--muted); font-size:13px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:24px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; }
        .stat b { display:block; font-size:28px; font-variant-numeric:tabular-nums; }
        .cols { display:grid; grid-template-columns:2fr 1fr; gap:12px; }
        @media (max-width:760px) { .cols { grid-template-columns:1fr; } }
        .stack { display:grid; gap:12px; align-content:start; }
        .wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-variant-numeric:tabular-nums; }
        th, td { text-align:left; padding:8px 4px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { font-size:12px; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.03em; }
        td.num, th.num { text-align:right; }
        .pill { display:inline-block; padding:1px 8px; border-radius:99px; font-size:12px; background:#eef2ee; white-space:nowrap; }
        .pill.failed, .pill.dead, .pill.problem { background:#fde8e6; color:var(--red); }
        .pill.pending, .pill.received, .pill.priced, .pill.running, .pill.todo { background:#fef0e1; color:var(--amber); }
        .pill.synced, .pill.succeeded, .pill.done { background:#e3f1e9; color:var(--green); }
        dl { margin:0; display:grid; grid-template-columns:1fr auto; gap:6px 12px; }
        dd { margin:0; text-align:right; font-variant-numeric:tabular-nums; }
        dl.facts { grid-template-columns:auto 1fr; gap:4px 16px; font-size:14px; }
        dl.facts dt { color:var(--muted); } dl.facts dd { text-align:left; overflow-wrap:anywhere; }
        button, .btn { font:inherit; font-size:14px; padding:6px 14px; border-radius:8px; border:1px solid var(--green); background:var(--green); color:#fff; cursor:pointer; text-decoration:none; display:inline-block; line-height:1.4; }
        button.secondary, .btn.secondary { background:#fff; color:var(--green); }
        button.danger { background:#fff; color:var(--red); border-color:#f4c7c2; }
        button.link { background:none; border:0; color:var(--red); padding:0; font-size:13px; }
        .actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        form.fields { display:grid; gap:12px; }
        .row { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; }
        label.field { display:grid; gap:4px; align-content:start; font-size:13px; color:var(--muted); }
        label.field > span { font-weight:600; color:var(--ink); }
        input[type=text], input[type=email], input[type=number], input[type=search], input[type=date], select, textarea {
            font:inherit; font-size:14px; width:100%; padding:7px 9px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--ink);
        }
        textarea { min-height:64px; resize:vertical; }
        label.check { display:flex; gap:8px; align-items:center; font-size:14px; }
        .error { color:var(--red); font-size:13px; }
        .flash { border-radius:8px; padding:10px 12px; margin:0 0 16px; font-size:14px; }
        .flash.status { background:#e3f1e9; } .flash.error { background:#fde8e6; color:var(--red); }
        code { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:13px; background:#f1f3ef; padding:1px 5px; border-radius:4px; overflow-wrap:anywhere; }
        .ok { color:var(--green); } .bad { color:var(--red); }
    </style>
    @stack('styles')
</head>
<body>
@php($showNav = ! config('b2bsync.public_dashboard'))
<div class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="{{ route('dashboard') }}"><span>VerdantCart</span> B2B Sync</a>
        @if ($showNav)
            <nav aria-label="Main">
                @foreach ([
                    'dashboard' => ['Dashboard', 'dashboard'],
                    'customers.index' => ['Customers', 'customers.*'],
                    'tiers.index' => ['Tiers', 'tiers.*'],
                    'sync-jobs.index' => ['Failed jobs', 'sync-jobs.*'],
                    'setup' => ['Setup', 'setup*'],
                ] as $route => [$label, $pattern])
                    <a href="{{ route($route) }}" @class(['on' => request()->routeIs($pattern)]) @if (request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        @endif
    </div>
</div>
<main>
    @unless (session('step')) {{-- the setup guide shows its messages inside the step --}}
        @if (session('status'))<p class="flash status" role="status">{{ session('status') }}</p>@endif
        @if (session('error'))<p class="flash error" role="alert">{{ session('error') }}</p>@endif
    @endunless
    @yield('content')
</main>
</body>
</html>
