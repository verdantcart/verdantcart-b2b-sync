<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates a Sanctum API token for an integration (e.g. "Woo storefront", "Postman").
 * Tokens belong to a dedicated service user, never to a person. The plain token is shown once.
 */
#[Signature('api:token {name : What the token is for} {--ability=* : Limit to abilities (tiers:read, tiers:write, customers:write, prices:read). Default: all}')]
#[Description('Create an API token for the tier management API')]
class ApiToken extends Command
{
    public const ABILITIES = ['tiers:read', 'tiers:write', 'customers:write', 'prices:read'];

    public function handle(): int
    {
        $abilities = $this->option('ability') ?: ['*'];
        $unknown = array_diff($abilities, [...self::ABILITIES, '*']);
        if ($unknown) {
            $this->components->error('Unknown ability: '.implode(', ', $unknown).'. Allowed: '.implode(', ', self::ABILITIES));

            return self::FAILURE;
        }

        $serviceUser = User::firstOrCreate(
            ['email' => 'api@verdantcart-b2b-sync.local'],
            ['name' => 'API service user', 'password' => Str::random(64)],
        );

        $token = $serviceUser->createToken($this->argument('name'), $abilities);

        $this->components->info("Token for \"{$this->argument('name')}\" (abilities: ".implode(', ', $abilities).')');
        $this->line('  '.$token->plainTextToken);
        $this->newLine();
        $this->components->warn('Copy it now; it is stored hashed and cannot be shown again.');

        return self::SUCCESS;
    }
}
