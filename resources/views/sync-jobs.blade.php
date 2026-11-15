@extends('layouts.admin')

@section('title', 'Failed jobs')

@push('styles')
<style> td.err { max-width:520px; font-size:13px; color:var(--muted); word-break:break-word; } </style>
@endpush

@section('content')

    <header class="page">
        <h1>Failed jobs</h1>
        <span class="muted">Retried automatically with backoff up to {{ $maxAttempts }} attempts, then <span class="pill dead">dead</span></span>
    </header>


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
                            <button type="submit" class="secondary">Retry</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">No failed jobs.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
@endsection
