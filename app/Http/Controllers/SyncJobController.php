<?php

namespace App\Http\Controllers;

use App\Models\SyncJob;
use App\Services\Sync\SyncJobDispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class SyncJobController extends Controller
{
    /** GET /sync-jobs — failed and dead jobs, newest first. */
    public function index(): View
    {
        return view('sync-jobs', [
            'jobs' => SyncJob::with('syncable')
                ->whereIn('status', [SyncJob::STATUS_FAILED, SyncJob::STATUS_DEAD])
                ->orderByDesc('updated_at')
                ->limit(200)
                ->get(),
            'maxAttempts' => SyncJob::MAX_ATTEMPTS,
        ]);
    }

    /** POST /sync-jobs/{syncJob}/retry */
    public function retry(SyncJob $syncJob, SyncJobDispatcher $dispatcher): RedirectResponse
    {
        if (! in_array($syncJob->status, [SyncJob::STATUS_FAILED, SyncJob::STATUS_DEAD], true)) {
            return back()->with('status', "Job #{$syncJob->id} is {$syncJob->status}; nothing to retry.");
        }

        $dispatcher->retryNow($syncJob);

        return back()->with('status', "Job #{$syncJob->id} queued again.");
    }
}
