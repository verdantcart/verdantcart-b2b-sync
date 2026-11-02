<?php

namespace App\Http\Controllers;

use App\Jobs\QueueHeartbeat;
use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Models\InventoryCache;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\SyncJob;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $tiers = B2bPriceTier::query()
            ->withCount(['customers' => fn ($q) => $q
                ->where('b2b_customers.is_active', true)
                ->where(fn ($q) => $q
                    ->whereNull('b2b_customer_tier_assignments.valid_until')
                    ->orWhere('b2b_customer_tier_assignments.valid_until', '>', now()))])
            ->orderByDesc('priority')
            ->get();

        return view('dashboard', [
            'stats' => [
                'customers' => B2bCustomer::where('is_active', true)->count(),
                'customers_total' => B2bCustomer::count(),
                'unmatched' => B2bCustomer::whereNull('fortnox_customer_number')->count(),
                'tiers' => $tiers->where('is_active', true)->count(),
                'skus' => ProductSku::where('is_active', true)->count(),
                'out_of_stock' => InventoryCache::where('available_quantity', 0)->count(),
            ],
            'tiers' => $tiers,
            'ordersByStatus' => $this->countByStatus(OrderLog::class, [
                OrderLog::STATUS_RECEIVED, OrderLog::STATUS_PRICED, OrderLog::STATUS_SYNCED, OrderLog::STATUS_FAILED,
            ]),
            'queue' => $this->queueStatus(),
            'syncByStatus' => $this->countByStatus(SyncJob::class, [
                SyncJob::STATUS_PENDING, SyncJob::STATUS_RUNNING, SyncJob::STATUS_SUCCEEDED,
                SyncJob::STATUS_FAILED, SyncJob::STATUS_DEAD,
            ]),
        ]);
    }

    /**
     * Status => count, in lifecycle order, omitting statuses with no rows.
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $order
     * @return array<string, int>
     */
    private function countByStatus(string $model, array $order): array
    {
        // Plain query builder: avoids model casts (OrderLog casts a `total` column).
        $counts = $model::query()->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect($order)
            ->merge($counts->keys())->unique()
            ->filter(fn (string $status) => $counts->has($status))
            ->mapWithKeys(fn (string $status) => [$status => (int) $counts[$status]])
            ->all();
    }

    /**
     * Waiting jobs per Redis queue and when a worker last ran the heartbeat job.
     * Never breaks the dashboard: if Redis is down that is what the tile shows.
     */
    private function queueStatus(): array
    {
        try {
            $sizes = collect(['orders', 'inventory', 'default'])
                ->mapWithKeys(fn (string $q) => [$q => Queue::connection('redis')->size($q)])
                ->all();
            $heartbeat = Cache::get(QueueHeartbeat::CACHE_KEY);
        } catch (Throwable $e) {
            return ['available' => false, 'error' => class_basename($e).': '.$e->getMessage()];
        }

        return [
            'available' => true,
            'sizes' => $sizes,
            'heartbeat_at' => isset($heartbeat['ran_at']) ? Carbon::parse($heartbeat['ran_at']) : null,
            'heartbeat_latency_ms' => $heartbeat['latency_ms'] ?? null,
        ];
    }
}
