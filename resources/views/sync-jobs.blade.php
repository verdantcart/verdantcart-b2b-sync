<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} · Failed jobs</title>
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
    <style>
        td.err { max-width:520px; font-size:13px; color:var(--muted); word-break:break-word; }
        button { font:inherit; padding:4px 12px; border-radius:6px; border:1px solid var(--green); background:var(--green); color:#fff; cursor:pointer; }
        .wrap { overflow-x:auto; }
    </style>
</head>
<body>
<main>
    <header>
        <h1><a href="{{ route('dashboard') }}" style="color:inherit;text-decoration:none"><span>VerdantCart</span> B2B Sync</a> · Failed jobs</h1>
        <span class="muted">Retried automatically with backoff up to {{ $maxAttempts }} attempts, then <span class="pill dead">dead</span></span>
    </header>

    @if (session('status'))<p class="card" style="margin:0 0 16px">{{ session('status') }}</p>@endif

    <section class="card wrap">
        <table>
            <thead><tr><th>#</th><th>Type</th><th>For</th><th>Status</th><th class="num">Attempts</th><th>Next retry</th><th>Error</th><th></th></tr></thead>
            <tbody>
            @forelse ($jobs as $job)
                <tr>
                    <td>{{ $job->id }}</td>
                    <td>{{ $job->type }}</td>
                    <td>
                        @if ($job->syncable instanceof \App\Models\OrderLog) Woo order #{{ $job->syncable->woo_order_id }}
                        @elseif ($job->syncable instanceof \App\Models\ProductSku) {{ $job->syncable->sku }}
                        @else <span class="muted">–</span> @endif
                    </td>
                    <td><span class="pill {{ $job->status }}">{{ $job->status }}</span></td>
                    <td class="num">{{ $job->attempts }}</td>
                    <td>@if ($job->status === 'dead') – @else {{ $job->next_retry_at?->timezone(config('app.display_timezone'))->format('H:i') ?? 'manual' }} @endif</td>
                    <td class="err">{{ $job->last_error }}</td>
                    <td>
                        <form method="post" action="{{ route('sync-jobs.retry', $job) }}">
                            @csrf
                            <button type="submit">Retry</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">No failed jobs.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
