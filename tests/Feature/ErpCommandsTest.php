<?php

namespace Tests\Feature;

use App\Erp\Fortnox\FortnoxClient;
use App\Models\B2bCustomer;
use App\Models\ErpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ErpCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function useFortnox(): void
    {
        config(['erp.driver' => 'fortnox', 'erp.fortnox.client_id' => 'c', 'erp.fortnox.client_secret' => 's']);
        $this->app->forgetInstance(FortnoxClient::class);
        ErpConnection::create(['driver' => 'fortnox', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'connected_at' => now()]);
    }

    public function test_status_with_fake_driver(): void
    {
        $this->artisan('erp:status')->expectsOutputToContain('fake ERP')->assertSuccessful();
    }

    public function test_status_with_fortnox_shows_the_company(): void
    {
        $this->useFortnox();
        Http::fake(['api.fortnox.se/3/companyinformation' => Http::response(['CompanyInformation' => ['CompanyName' => 'Sandbox AB', 'OrganizationNumber' => '556000-0000']])]);

        $this->artisan('erp:status')->expectsOutputToContain('Sandbox AB')->assertSuccessful();
    }

    public function test_pull_customers_links_by_org_number_and_email_and_creates_new_ones(): void
    {
        $this->useFortnox();
        $byOrg = B2bCustomer::factory()->create(['fortnox_customer_number' => null, 'org_number' => '556677-8899', 'email' => 'x@x.se']);
        $byEmail = B2bCustomer::factory()->create(['fortnox_customer_number' => null, 'email' => 'inkop@lundberg.se']);
        Http::fake(['api.fortnox.se/3/customers*' => Http::response(['Customers' => [
            ['CustomerNumber' => '10', 'Name' => 'Org AB', 'OrganisationNumber' => '5566778899'],
            ['CustomerNumber' => '11', 'Name' => 'Lundberg AB', 'Email' => 'INKOP@lundberg.se'],
            ['CustomerNumber' => '12', 'Name' => 'Ny Kund AB', 'Email' => 'ny@kund.se', 'CountryCode' => 'se'],
            ['CustomerNumber' => '13', 'Name' => 'Utan Mejl AB'],
        ], 'MetaInformation' => ['@TotalPages' => 1]])]);

        $this->artisan('erp:pull-customers')->expectsOutputToContain('1 created, 2 newly linked')->assertSuccessful();

        $this->assertSame('10', $byOrg->fresh()->fortnox_customer_number);
        $this->assertSame('11', $byEmail->fresh()->fortnox_customer_number);
        $this->assertSame('SE', B2bCustomer::where('fortnox_customer_number', '12')->sole()->country_code);
        $this->assertSame(0, B2bCustomer::where('fortnox_customer_number', '13')->count());
    }

    public function test_pull_customers_dry_run_changes_nothing(): void
    {
        $this->useFortnox();
        Http::fake(['api.fortnox.se/3/customers*' => Http::response(['Customers' => [['CustomerNumber' => '12', 'Name' => 'Ny', 'Email' => 'ny@kund.se']], 'MetaInformation' => ['@TotalPages' => 1]])]);

        $this->artisan('erp:pull-customers', ['--dry-run' => true])->expectsOutputToContain('[dry run] 1 created')->assertSuccessful();

        $this->assertSame(0, B2bCustomer::count());
    }
}
