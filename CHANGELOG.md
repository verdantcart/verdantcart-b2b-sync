# Changelog

## 1.0.0 (2026-11-19)

Ready for a first customer: a shop and its Fortnox company can be connected, priced and checked without the terminal.

### Prices in the shop
- **VerdantCart B2B Prices**, a WordPress plugin (now 1.0.0): logged-in wholesale customers see their tier price on product pages, in listings, the cart and the checkout, marked *Your price*. Guests and consumers keep the normal price.
- Storefront price API: `GET /api/woo-customers/{id}/prices`, up to 100 SKUs per call, read-only token. It uses the same price rules as order sync, so the shop and Fortnox always agree.
- The plugin caches prices for 5 minutes and never stops the shop: if B2B Sync doesn't answer, the normal price is shown and the order is still priced correctly when it syncs.
- The plugin leaves the WooCommerce REST API alone, so integrations (B2B Sync among them) always see the shop's own prices.

### Reliability
- **Price check:** every order compares the shop's price with the B2B price. Differences over 1 kr are flagged on the dashboard and e-mailed; Fortnox always gets the B2B price.
- **Alert e-mails** when an order or stock sync gives up, with de-duplication and at most 20 per hour.
- `erp:status` and the setup guide name the Fortnox licence a company is missing.

### One installation per customer
- `customer:provision` creates a customer's own Fly app, database connection and secrets in one command, with a dry run.
- **Setup guide** at `/setup`, in eight steps: WooCommerce (with a VAT check), webhooks, Fortnox login and licences, stock buffer, product import with article matching and suggestions, customer import, price tiers, and the plugin token.
- **Pages for price tiers and customers:** create and edit tiers, search and filter customers, link them to WooCommerce (*Find in WooCommerce*) and Fortnox, campaign tiers with dates, and each customer's prices and recent orders.
- The dashboard and every admin page need the admin login; only the public demo may open the dashboard without it.

### For people running it
- [Getting started](docs/getting-started.md) for the shop, and [operations](docs/operations.md) for whoever runs the installations.
- `scripts/local.ps1` starts the server, worker and scheduler on Windows, and runs a second installation side by side for testing from zero.
- Tested from an empty installation to an order in a Fortnox sandbox, with the same price in the shop, on the order and in Fortnox.

### Changed
- Setup step 1 stops a shop whose prices are entered including VAT: Fortnox orders are sent excluding VAT and would otherwise be 25 % too high.
- Error messages point to the page where the problem is fixed instead of to terminal commands.

## 0.1.0 (2026-11)

The 15-day sprint: WooCommerce webhooks, customer price tiers, order sync to Fortnox (OAuth with token rotation), buffered stock sync, retries with backoff, the dashboard, Horizon, and CI on SQLite and PostgreSQL.
