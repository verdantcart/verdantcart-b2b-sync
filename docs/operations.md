# Running B2B Sync: operations guide

For whoever runs the installations: setting up a customer, updating them, watching them, and fixing what breaks. The customer's side is in [getting-started.md](getting-started.md).

## What one installation is

Every customer gets their own installation, and no data is shared between them.

| Part | Where | Notes |
|---|---|---|
| App, queue workers, scheduler, Redis | One Fly app, `b2b-<name>`, region `arn` (Stockholm) | `fly.toml` and `Dockerfile`; Horizon runs the workers. Redis lives inside the machine and is emptied on restart, which is fine because the database is the source of truth. |
| Database | Its own PostgreSQL database, e.g. one Neon project per customer in an **EU region** | Migrated by Fly's release step on every deploy. |
| Secrets | Fly secrets on that app | Never in the repo, chat or e-mail. |
| Fly config | `deploy/customers/<name>.fly.toml` | Kept out of Git, so customer names never reach the public repo. Back the folder up yourself. |

## Working locally (Windows / Laragon)

Start Laragon (PostgreSQL and Redis), then from the project folder:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\local.ps1
```

This opens the **worker** and the **scheduler** in their own windows and runs the **web server** in the current one. Open http://127.0.0.1:8000.

- **No worker:** orders and stock updates wait in the queue; the dashboard shows *orders: n waiting*.
- **No scheduler:** failed syncs are never retried, and the dashboard shows *no heartbeat yet*.
- **"Not running: Redis / PostgreSQL":** the script checks both first. Open Laragon and click **Start All** (needed after every restart of the computer).
- **After changing code:** close the worker window and start the script again. The worker keeps the old code in memory; the web server doesn't.
- Horizon can't run on Windows (no `pcntl`), which is why the script uses `queue:work`. Fly runs Horizon.

### A second, empty installation (testing from zero)

Use this to test the customer setup without touching your normal data.

```powershell
# once: create the database as the postgres admin user (the app user may not create databases)
php artisan tinker --execute="config(['database.connections.pgsql.database'=>'postgres','database.connections.pgsql.username'=>'postgres','database.connections.pgsql.password'=>'']); DB::purge('pgsql'); DB::statement('create database b2b_fromzero owner b2bsync'); echo 'created';"

# once: migrate it
$env:DB_DATABASE="b2b_fromzero"; $env:REDIS_DB="5"; $env:REDIS_CACHE_DB="6"; php artisan migrate

# every time
powershell -ExecutionPolicy Bypass -File scripts\local.ps1 -Database b2b_fromzero -RedisDb 5
```

Separate Redis numbers keep the two installations' queues apart. Both use port 8000, so run only one at a time. The B2B Prices plugin in your local shop talks to whichever is running, but each has its own token: create one under **Setup** and paste it into **WooCommerce → B2B Prices** when you switch.

`verdantcart-b2b-sync.test` (Laragon's address) always uses `.env` as it is, never the test database.

## Setting up a new customer

### 1. Collect from the customer

- [ ] The shop address, and a WooCommerce REST API key with **Read/Write** access (see the customer guide), received through a secure channel.
- [ ] The answers to the customer guide's *Before you start* checklist: Fortnox licences, prices excluding VAT, SKUs, e-mails, shop live.
- [ ] An e-mail address for alerts, if the customer wants them as well as you.

### 2. Prepare

- [ ] An empty database in an EU region, and its connection string.
- [ ] `flyctl` installed and logged in (`fly auth whoami`).
- [ ] `FORTNOX_CLIENT_ID` and `FORTNOX_CLIENT_SECRET` in your local `.env`: the same integration serves every customer.
- [ ] In the **Fortnox Developer Portal**, add the redirect URI `https://b2b-<name>.fly.dev/fortnox/callback` to the integration. Without it the customer's Fortnox login fails.

### 3. Create the installation

```bash
php artisan customer:provision acme --woo-url=https://shop.acme.se --database-url="postgresql://…" --dry-run
php artisan customer:provision acme --woo-url=https://shop.acme.se --database-url="postgresql://…" --alert-email=ops@acme.se
```

The dry run shows the plan with secrets hidden and creates nothing. The real run asks for the WooCommerce keys, creates the Fly app, stores the secrets (through stdin, never as command-line arguments), deploys, and prints the **admin password once**. Store it in your password manager straight away.

If a step fails it stops and says what to do. A half-created app is removed with `fly apps destroy b2b-acme`.

### 4. Hand over

1. Build the plugin zip: `Compress-Archive -Path wordpress\verdantcart-b2b-prices -DestinationPath verdantcart-b2b-prices.zip -Force`.
2. Send the customer the zip, the installation address and the admin login (the password through a secure channel).
3. Go through the setup page with them, ideally on a call: the Fortnox login in step 3 must be done by their Fortnox administrator.
4. Place a test order together (*Your first test order* in the customer guide), and check the order in their Fortnox.

## Updating installations

After pushing a change to `main` with CI green:

```bash
fly deploy --config deploy/customers/acme.fly.toml --app b2b-acme --remote-only --ha=false
```

Repeat for each customer. Deploy to one, check its dashboard and `/setup`, then do the rest.

Migrations run as Fly's release step, before the new version starts: if they fail, the deploy stops and the old version keeps running. If the new version starts but fails its health check (`/up`), look at `fly logs`, then roll back by redeploying the previous image: `fly releases --image -a b2b-acme` lists them, and `fly deploy --image <image> --config … --app b2b-acme` puts one back.

If the B2B Prices plugin changed, send customers the new zip. WordPress replaces the old version on upload.

## Watching

| Signal | Where | Act when |
|---|---|---|
| Alert e-mail | `B2B_ALERT_EMAIL` | An order or stock sync gave up, or a price differed by more than 1 kr. At most 20 alerts per hour per installation. |
| Dashboard | `https://b2b-<name>.fly.dev` | *orders waiting* keeps growing, *Worker last seen* is older than 10 minutes, or there are failed sync jobs. |
| Failed jobs | `/sync-jobs` | Anything listed: the error says why, and **Retry** sends it again. |
| Fortnox status | `fly ssh console -a b2b-acme -C "php artisan erp:status"` | After a Fortnox error: shows the company and any missing licence. |
| Errors | Sentry, if `SENTRY_LARAVEL_DSN` is set | New issue types. |
| Logs | `fly logs -a b2b-acme` | Investigating anything above. |

Test the alert mail on a new installation: `fly ssh console -a b2b-acme -C "php artisan alerts:test"`.

## Common tasks

| Task | How |
|---|---|
| Re-send a failed order | **Failed jobs → Retry**, after fixing the cause. |
| An order never arrived (webhook lost) | `php artisan woo:pull-order 1234` |
| Re-queue everything waiting or failed | `php artisan sync:dispatch-pending --include-failed` |
| New plugin token (lost, or a person left) | **Setup → Create another token**, paste it into the plugin. Delete old tokens with `php artisan tinker` if they may be misused. |
| Fortnox login expired or wrong company | The customer logs in again from **Setup → step 3**. Refresh tokens last 45 days and are renewed automatically while the app runs. |
| Change the alert address | `fly secrets set B2B_ALERT_EMAIL=… -a b2b-acme` (restarts the app). |
| New products not linked | **Setup → Bring in your products → Import again**. |

On Fly, run artisan commands with `fly ssh console -a b2b-<name> -C "php artisan …"`.

## Backups and removal

- **Database backups** are the database provider's: with Neon, point-in-time restore within the plan's history window. Check the window and region in the Neon console when you create the database, and write them down.
- **Removing a customer:** `fly apps destroy b2b-<name>`, delete their database, delete `deploy/customers/<name>.fly.toml`, remove their redirect URI from the Fortnox integration, and ask them to delete the WooCommerce API key and the plugin.

## Known limits

- **Prices excluding VAT only.** Fortnox orders are sent with `VATIncluded = false`. Shops that enter prices including VAT are stopped in setup step 1.
- **One shop and one Fortnox company per installation.**
- **New products need linking.** A product created after setup is linked to its Fortnox article by *Import again*; until then, orders containing it fail with a clear error.
- **Customers come from Fortnox by e-mail.** Fortnox customers without an e-mail are skipped and must be added by hand.
- **The plugin caches prices for 5 minutes,** including "not a B2B customer". A newly linked customer may see normal prices for up to 5 minutes.
- **A shop in *Coming soon* mode** hides products from customers, so they can't see their prices.

## Troubleshooting (from real runs)

| Symptom | Cause | Fix |
|---|---|---|
| Setup step 1 times out, but `php artisan woo:ping` works | Plugin older than 0.2.1 pricing the REST API, deadlocking a single-process local server | Update the plugin to 0.2.1 or newer. |
| Import stays at *Waiting for the queue worker* | No worker running, or a worker with different Redis settings | Start `scripts\local.ps1` with the same `-Database` and `-RedisDb`. |
| Customer sees normal prices | Not linked to WooCommerce, not in a tier, 5-minute cache, or shop in *Coming soon* | See the customer guide's table. The server log shows `/api/woo-customers/<id>/prices` when the plugin asks. |
| Fortnox order total ≠ shop total | Shop doesn't add VAT at checkout; Fortnox adds it | Expected if invoicing from Fortnox. Otherwise enable taxes in WooCommerce. |
| `password authentication failed for user "postgres"` | Laragon's postgres user has a password | Put it in the tinker command above. Never paste it in chat. |
