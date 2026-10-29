# VerdantCart B2B Sync

> Open-source WooCommerce ↔ ERP middleware for Nordic B2B wholesale brands. Tiered customer pricing, inventory buffering, and Fortnox order sync. Laravel 13 + PostgreSQL + Horizon queues.

**Status**: Day 1 scaffold. Not production-ready.

## What it does

VerdantCart B2B Sync is a standalone Laravel application that sits between a WooCommerce store and a Fortnox ERP, handling the operational gaps standard WooCommerce doesn't address for B2B wholesale:

- **Customer-tier pricing** — Customer A sees Tier 1 prices (€10/unit), Customer B sees Tier 2 prices (€8/unit)
- **Real-time inventory buffering** — syncs warehouse stock so high-volume buyers don't buy out-of-stock inventory
- **Automatic order forwarding** — pushes WooCommerce orders directly into Fortnox without manual data entry

```
┌─────────────────────────┐     Webhooks + REST      ┌─────────────────────────┐
│  WooCommerce            │ ───────────────────────▶ │  VerdantCart B2B Sync   │
│  (B2B wholesale store)  │ ◀─────────────────────── │  (Laravel + Postgres)   │
└─────────────────────────┘                          └───────────┬─────────────┘
                                                                 │
                                                                 ▼
                                                     ┌─────────────────────────┐
                                                     │  Fortnox ERP            │
                                                     │  (customers, invoices)  │
                                                     └─────────────────────────┘
```

## Stack

- Laravel 13 + PHP 8.3+
- PostgreSQL 16
- Redis 7 (via `predis`) + Laravel Horizon *(Horizon lands on day 8)*
- PHPUnit for tests
- GitHub Actions CI (PHP 8.3 / 8.4 matrix)
- Fly.io for the live demo deployment

## Local development

Requirements: PHP 8.3+ with `pdo_pgsql`, Composer 2, Docker.

```bash
# 1. Clone
git clone https://github.com/verdantcart/verdantcart-b2b-sync.git
cd verdantcart-b2b-sync

# 2. Install
composer install
cp .env.example .env
php artisan key:generate

# 3. Start Postgres 16 + Redis 7
docker-compose up -d

# 4. Migrate
php artisan migrate

# 5. Run
php artisan serve          # http://localhost:8000
```

Ports 5432 (Postgres) and 6379 (Redis) must be free. Stop any locally installed Postgres/Redis first.

Demo: coming (Fly.io deployment at the end of the build sprint).

## License

[MIT](LICENSE). Use it, fork it, ship it.

## Part of the VerdantCart product family

- **VerdantCart Carbon Reports** — free WooCommerce carbon reporting plugin (GPL-2.0, wp.org, ~1,000 downloads)
- **VerdantCart Pro** — commercial Pro extension with audit-ready reports and /carbon.txt publishing (Freemius)
- **VerdantCart B2B Sync** — this project (standalone Laravel middleware, MIT)

Website: https://verdantcart.ai
