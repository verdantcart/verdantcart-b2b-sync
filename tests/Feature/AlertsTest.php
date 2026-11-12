<?php

namespace Tests\Feature;

use App\Erp\ErpAdapter;
use App\Erp\ErpException;
use App\Jobs\SyncOrderToErp;
use App\Models\B2bCustomer;
use App\Models\B2bPriceTier;
use App\Models\OrderLog;
use App\Models\ProductSku;
use App\Models\SyncJob;
use App\Notifications\SyncProblem;
use App\Services\WooCommerce\Webhooks\OrderCreatedHandler;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['b2bsync.alerts.mail_to' => 'yonas@example.test, ops@example.test', 'b2bsync.alerts.max_per_hour' => 20]);

        ProductSku::factory()->create(['sku' => 'VC-KAF-4735', 'base_price' => '180.00', 'fortnox_article_number' => 'KAF4735']);
        ProductSku::factory()->create(['sku' => 'VC-TEA-1627', 'base_price' => '54.17', 'fortnox_article_number' => 'TEA1627', 'woo_product_id' => 17]);
        B2bCustomer::factory()->create(['woo_customer_id' => 3, 'company_name' => 'Mraz Kaffe AB', 'is_active' => true]);
    }

    private function receive(array $overrides = []): SyncJob
    {
        Queue::fake();
        $order = array_replace_recursive(json_decode(file_get_contents(base_path('tests/Fixtures/woocommerce/order-created.json')), true), $overrides);
        $result = app(OrderCreatedHandler::class)->handle($order);

        return SyncJob::findOrFail($result['sync_job_id']);
    }

    private function process(SyncJob $job): void
    {
        app()->call([new SyncOrderToErp($job->id), 'handle']);
    }

    private function failingErp(bool $retryable): void
    {
        $this->app->bind(ErpAdapter::class, fn () => new class($retryable) implements ErpAdapter
        {
            public function __construct(private bool $retryable) {}

            public function name(): string
            {
                return 'failing';
            }

            public function pushOrder(OrderLog $order): string
            {
                throw new ErpException('Fortnox said no: customer 1042 is inactive', $this->retryable);
            }

            public function findOrder(OrderLog $order): ?string
            {
                return null;
            }

            public function pullCustomers(): iterable
            {
                return [];
            }

            public function stockLevels(array $articleNumbers): array
            {
                return [];
            }
        });
    }

    private function assertAlerted(string $subjectContains, int $times = 1): void
    {
        Notification::assertSentOnDemandTimes(SyncProblem::class, $times);
        Notification::assertSentOnDemand(SyncProblem::class, function (SyncProblem $n, array $channels, AnonymousNotifiable $to) use ($subjectContains) {
            return str_contains($n->subject, $subjectContains)
                && $to->routes['mail'] === ['yonas@example.test', 'ops@example.test'];
        });
    }

    public function test_an_order_that_used_all_its_retries_is_mailed_once(): void
    {
        $this->failingErp(retryable: true);
        $job = $this->receive();

        for ($i = 1; $i < SyncJob::MAX_ATTEMPTS; $i++) {
            $this->process($job);
            $job->refresh()->update(['next_retry_at' => now()->subMinute()]);
        }
        Notification::assertNothingSent(); // still retrying: no mail yet

        $this->process($job);
        $this->assertSame(SyncJob::STATUS_DEAD, $job->refresh()->status);
        $this->assertAlerted('Order #727 could not be sent to Fortnox');

        $job->recordFailure('same problem reported again', true);
        Notification::assertSentOnDemandTimes(SyncProblem::class, 1);
    }

    public function test_a_problem_retries_cannot_fix_is_mailed_straight_away(): void
    {
        $this->failingErp(retryable: false);
        $this->process($this->receive());

        $this->assertAlerted('Order #727 could not be sent to Fortnox');
    }

    public function test_an_order_with_an_unknown_sku_is_mailed(): void
    {
        $this->process($this->receive(['line_items' => [0 => ['sku' => 'NOT-IN-CATALOGUE', 'product_id' => 999]]]));

        $this->assertAlerted('Order #727 could not be sent to Fortnox');
        Notification::assertSentOnDemand(SyncProblem::class, fn (SyncProblem $n) => str_contains($n->details['Problem'], 'NOT-IN-CATALOGUE'));
    }

    public function test_an_order_charged_at_the_wrong_price_is_mailed_with_the_lines(): void
    {
        $this->process($this->receive()); // the fixture order was charged list price; Mraz has no tier yet → no difference
        Notification::assertNothingSent();

        $customer = B2bCustomer::sole();
        $customer->priceTiers()->attach(B2bPriceTier::factory()->create(['discount_percent' => 10]));
        $this->process($this->receive(['id' => 728]));

        $this->assertAlerted('Order #728 was charged -497.00 SEK off the B2B price');
        Notification::assertSentOnDemand(SyncProblem::class, fn (SyncProblem $n) => $n->details['Customer'] === 'Mraz Kaffe AB'
            && str_contains($n->details['Lines'], 'VC-KAF-4735: shop 4320.00, B2B 3888.00'));
    }

    public function test_the_mail_says_what_happened_and_where_to_fix_it(): void
    {
        $this->failingErp(retryable: false);
        $this->process($this->receive());

        Notification::assertSentOnDemand(SyncProblem::class, function (SyncProblem $n, array $channels, AnonymousNotifiable $to) {
            $mail = $n->toMail($to);

            return $mail->subject === '[B2B Sync] Order #727 could not be sent to Fortnox'
                && $mail->actionUrl === route('sync-jobs.index')
                && in_array('**Problem:** Fortnox said no: customer 1042 is inactive', $mail->introLines, true)
                && in_array('**Customer:** Mraz Kaffe AB', $mail->introLines, true);
        });
    }

    public function test_nothing_is_sent_without_a_recipient(): void
    {
        config(['b2bsync.alerts.mail_to' => '']);
        $this->failingErp(retryable: false);
        $this->process($this->receive());

        Notification::assertNothingSent();
    }

    public function test_at_most_the_hourly_limit_is_mailed(): void
    {
        config(['b2bsync.alerts.max_per_hour' => 2]);
        Log::spy();
        $this->failingErp(retryable: false);

        foreach ([801, 802, 803] as $id) {
            $this->process($this->receive(['id' => $id]));
        }

        Notification::assertSentOnDemandTimes(SyncProblem::class, 2);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'Alert e-mail suppressed: hourly limit reached');
    }

    public function test_a_broken_mail_setup_never_stops_the_sync(): void
    {
        $this->app->instance(Dispatcher::class, new class
        {
            public function send(...$args): void
            {
                throw new \RuntimeException('SMTP connection refused');
            }
        });
        Log::spy();
        $this->failingErp(retryable: false);
        $job = $this->receive();

        $this->process($job);

        $this->assertSame(SyncJob::STATUS_FAILED, $job->refresh()->status, 'the job finished normally');
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'Alert e-mail could not be sent'
            && $context['error'] === 'SMTP connection refused');
    }

    public function test_alerts_test_command(): void
    {
        $this->artisan('alerts:test')->expectsOutputToContain('Test alert queued for yonas@example.test, ops@example.test')->assertSuccessful();
        $this->artisan('alerts:test')->assertSuccessful(); // the test mail is never de-duplicated
        Notification::assertSentOnDemandTimes(SyncProblem::class, 2);

        config(['b2bsync.alerts.mail_to' => '']);
        $this->artisan('alerts:test')->expectsOutputToContain('No recipient')->assertFailed();
    }
}
