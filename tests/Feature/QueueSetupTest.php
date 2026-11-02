<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeat;
use App\Jobs\SyncInventory;
use App\Jobs\SyncOrderToErp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class QueueSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_supervisor_covers_every_queue_the_app_uses(): void
    {
        $queues = config('horizon.defaults.b2b-sync.queue');

        $this->assertSame(['orders', 'inventory', 'default'], $queues, 'orders must come first (priority)');
        foreach ([new SyncOrderToErp(1), new SyncInventory(1), new QueueHeartbeat('0')] as $job) {
            $this->assertContains($job->queue, $queues, get_class($job).' would never be processed');
        }
        $this->assertLessThan(
            config('queue.connections.redis.retry_after'),
            config('horizon.defaults.b2b-sync.timeout'),
            'job timeout must be below retry_after or jobs run twice'
        );
    }

    public function test_heartbeat_job_records_when_it_ran(): void
    {
        (new QueueHeartbeat((string) (microtime(true) - 0.2)))->handle();

        $beat = Cache::get(QueueHeartbeat::CACHE_KEY);
        $this->assertNotNull($beat['ran_at']);
        $this->assertGreaterThanOrEqual(200, $beat['latency_ms']);
    }

    public function test_heartbeat_command_reports_a_processed_job(): void
    {
        // QUEUE_CONNECTION=sync in tests: the job runs immediately, like an instant worker.
        $this->artisan('queue:heartbeat', ['--wait' => 1])
            ->expectsOutputToContain('A worker processed it')
            ->assertSuccessful();
    }

    public function test_dashboard_shows_the_queue_tile_and_survives_redis_being_down(): void
    {
        config(['database.redis.default.port' => 1, 'database.redis.default.timeout' => 0.2]);
        app('redis')->purge();

        $this->get('/')->assertOk()->assertSee('Redis unavailable');
    }

    public function test_horizon_dashboard_is_open_locally_without_a_password(): void
    {
        app()->detectEnvironment(fn () => 'local');
        config(['horizon.basic_auth.password' => null]);

        $this->get('/horizon')->assertOk();
    }

    public function test_horizon_dashboard_is_closed_in_production_without_a_password(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['horizon.basic_auth.password' => null]);

        $this->get('/horizon')->assertForbidden();
    }

    public function test_horizon_dashboard_asks_for_and_accepts_basic_auth_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['horizon.basic_auth.user' => 'admin', 'horizon.basic_auth.password' => 's3cret']);

        $this->get('/horizon')->assertUnauthorized()->assertHeader('WWW-Authenticate');
        $this->withHeader('Authorization', 'Basic '.base64_encode('admin:wrong'))->get('/horizon')->assertUnauthorized();
        $this->withHeader('Authorization', 'Basic '.base64_encode('admin:s3cret'))->get('/horizon')->assertOk();
    }
}
