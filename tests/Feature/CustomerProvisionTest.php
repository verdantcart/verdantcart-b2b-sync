<?php

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class CustomerProvisionTest extends TestCase
{
    private string $toml;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'erp.fortnox.client_id' => 'fortnox-client-id',
            'erp.fortnox.client_secret' => 'fortnox-client-secret',
            'b2bsync.alerts.mail_to' => 'support@verdantcart.ai',
            'mail.default' => 'log',
        ]);
        $this->toml = base_path('deploy/customers/acme.fly.toml');
        File::delete($this->toml);
    }

    protected function tearDown(): void
    {
        File::delete($this->toml);
        parent::tearDown();
    }

    private function provision(array $extra = []): PendingCommand
    {
        return $this->artisan('customer:provision', array_merge([
            'slug' => 'acme',
            '--woo-url' => 'https://shop.acme.se/',
            '--database-url' => 'postgresql://acme:db-secret@ep-acme.eu-central-1.aws.neon.tech/acme?sslmode=require',
        ], $extra))
            ->expectsQuestion('WooCommerce consumer key (ck_…)', 'ck_live_123')
            ->expectsQuestion('WooCommerce consumer secret (cs_…)', 'cs_live_456');
    }

    public function test_dry_run_shows_the_plan_and_creates_nothing(): void
    {
        Process::fake();

        $this->artisan('customer:provision', ['slug' => 'acme', '--dry-run' => true])
            ->expectsOutputToContain('Dry run for b2b-acme')
            ->expectsOutputToContain('fly apps create b2b-acme --org personal')
            ->expectsOutputToContain('fly deploy --config')
            ->expectsOutputToContain('APP_KEY, DB_URL, WOO_BASE_URL')
            ->assertSuccessful();

        Process::assertNothingRan();
        $this->assertFileDoesNotExist($this->toml);
    }

    public function test_it_creates_the_app_stores_secrets_through_stdin_and_deploys(): void
    {
        Process::fake();

        $this->provision()
            ->expectsOutputToContain('b2b-acme is live at https://b2b-acme.fly.dev')
            ->expectsOutputToContain('https://b2b-acme.fly.dev/fortnox/callback')
            ->assertSuccessful();

        Process::assertRan(fn (PendingProcess $p) => $p->command === ['fly', 'apps', 'create', 'b2b-acme', '--org', 'personal']);
        Process::assertRan(fn (PendingProcess $p) => $p->command === ['fly', 'deploy', '--config', $this->toml, '--app', 'b2b-acme', '--remote-only', '--ha=false']);
        Process::assertRan(function (PendingProcess $p) {
            if ($p->command !== ['fly', 'secrets', 'import', '--app', 'b2b-acme', '--stage']) {
                return false;
            }

            return str_contains($p->input, 'APP_KEY="base64:')
                && str_contains($p->input, 'DB_URL="postgresql://acme:db-secret@')
                && str_contains($p->input, 'WOO_BASE_URL="https://shop.acme.se"')
                && str_contains($p->input, 'WOO_CONSUMER_SECRET="cs_live_456"')
                && str_contains($p->input, 'FORTNOX_REDIRECT_URI="https://b2b-acme.fly.dev/fortnox/callback"')
                && str_contains($p->input, 'B2B_ALERT_EMAIL="support@verdantcart.ai"')
                && preg_match('/HORIZON_BASIC_AUTH_PASSWORD="[A-Za-z0-9]{24}"/', $p->input) === 1;
        });

        // No secret ever appears as a command-line argument (visible in the process list).
        foreach (['db-secret', 'cs_live_456', 'ck_live_123', 'fortnox-client-secret'] as $secret) {
            Process::assertDidntRun(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), $secret));
        }
    }

    public function test_the_generated_fly_config_is_for_this_customer_and_uses_fortnox(): void
    {
        Process::fake();
        $this->provision(['--region' => 'ams'])->assertSuccessful();

        $toml = File::get($this->toml);
        $this->assertStringContainsString('app = "b2b-acme"', $toml);
        $this->assertStringContainsString('primary_region = "ams"', $toml);
        $this->assertStringContainsString('APP_URL = "https://b2b-acme.fly.dev"', $toml);
        $this->assertStringContainsString('ERP_DRIVER = "fortnox"', $toml);
        $this->assertStringNotContainsString('verdantcart-b2b-sync', $toml);
        $this->assertStringNotContainsString('db-secret', $toml, 'secrets never go into the file');
    }

    public function test_it_stops_at_the_first_failing_step_and_says_how_to_start_over(): void
    {
        Process::fake([
            '*version*' => Process::result('flyctl v0.3'),
            '*apps*create*' => Process::result(errorOutput: 'Name has already been taken', exitCode: 1),
        ]);

        $this->provision()
            ->expectsOutputToContain('Name has already been taken')
            ->expectsOutputToContain('fly apps destroy b2b-acme')
            ->assertFailed();

        Process::assertDidntRun(fn (PendingProcess $p) => ($p->command[1] ?? null) === 'secrets');
        Process::assertDidntRun(fn (PendingProcess $p) => ($p->command[1] ?? null) === 'deploy');
    }

    public function test_it_explains_when_flyctl_is_missing(): void
    {
        Process::fake(['*version*' => Process::result(exitCode: 1)]);

        $this->provision()->expectsOutputToContain('flyctl is not installed')->assertFailed();
    }

    public function test_it_validates_the_input_before_doing_anything(): void
    {
        Process::fake();

        $this->artisan('customer:provision', ['slug' => 'Acme AB!', '--dry-run' => true])
            ->expectsOutputToContain('lowercase letters')->assertFailed();
        $this->artisan('customer:provision', ['slug' => 'acme', '--woo-url' => 'http://shop.acme.se', '--database-url' => 'postgresql://x'])
            ->expectsOutputToContain('must start with https://')->assertFailed();
        $this->artisan('customer:provision', ['slug' => 'acme', '--woo-url' => 'https://shop.acme.se', '--database-url' => 'mysql://x'])
            ->expectsOutputToContain('must start with postgresql://')->assertFailed();

        config(['erp.fortnox.client_id' => '']);
        $this->artisan('customer:provision', ['slug' => 'acme', '--dry-run' => true])
            ->expectsOutputToContain('FORTNOX_CLIENT_ID')->assertFailed();

        Process::assertNothingRan();
    }
}
