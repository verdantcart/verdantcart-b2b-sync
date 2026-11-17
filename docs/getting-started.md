# Getting started with VerdantCart B2B Sync

This guide is for the person who runs the shop. It takes you from a new installation to your first wholesale order in Fortnox, and covers what you do day to day afterwards.

You need about an hour, admin access to WooCommerce, and someone who can log in to Fortnox as an administrator.

## What B2B Sync does

- **Prices:** each wholesale customer belongs to a price tier, for example *Gold, 10 % off*. When they log in to your shop they see their own price, marked *Your price*. Everyone else sees your normal prices.
- **Orders:** every WooCommerce order becomes an order in Fortnox within seconds, on the right Fortnox customer, at the same price the customer paid.
- **Stock:** WooCommerce shows Fortnox's stock minus a safety buffer, so nobody orders the last units while they are being counted or moved.
- **Nothing gets lost:** if Fortnox or the shop doesn't answer, B2B Sync tries again by itself and e-mails you if it gives up.

## Before you start

Check these first. Each one has stopped a real setup.

| | What | Why |
|---|---|---|
| ☐ | **Fortnox licences:** *Offert & Order*, and *Lager* if Fortnox should control stock | Without them Fortnox refuses the orders. The setup page tells you which one is missing. |
| ☐ | **Prices in WooCommerce are entered excluding VAT** (WooCommerce → Settings → Tax → *Prices entered with tax: No*) | Fortnox receives the prices excluding VAT. Prices entered including VAT would make every Fortnox order 25 % too high. |
| ☐ | **Every product has a SKU**, preferably the same as its Fortnox article number | The SKU is how a product is matched with its Fortnox article. Products without a SKU are skipped. |
| ☐ | **Your wholesale customers have an e-mail address in Fortnox** | Customers are imported from Fortnox by e-mail. Those without one are skipped (you can add them by hand). |
| ☐ | **The shop is live**, not *Coming soon* (WooCommerce → Settings → Site visibility) | In *Coming soon* mode only admins see products, so your customers can't see their prices. |

### The WooCommerce API key

B2B Sync talks to your shop with an API key that you create:

1. WooCommerce → **Settings → Advanced → REST API → Add key**.
2. Description: `VerdantCart B2B Sync`. User: an administrator. Permissions: **Read/Write**.
3. Click **Generate API key** and send the *consumer key* and *consumer secret* to us through the secure link we give you, never in a regular e-mail. They are shown only once.

We then set up your installation and send you its address, for example `https://b2b-yourshop.fly.dev`, and a login.

## Install the B2B Prices plugin

The plugin shows each wholesale customer their own price in the shop.

1. WordPress → **Plugins → Add New Plugin → Upload Plugin**, choose the file `verdantcart-b2b-prices.zip` we sent you, then **Install Now** and **Activate**.
2. A yellow bar says the plugin is not connected yet. You connect it in step 8 below.

## The setup page

Open `https://<your-installation>/setup` and log in. The page has eight steps. Work from the top: each one turns green when it's done, and a red step tells you what to fix.

1. **Connect the WooCommerce store:** press *Check the connection*. You should see your number of products, your currency and *Prices: excluding VAT ✓*.
2. **Send new orders and product changes here:** press *Register the webhooks*. It can take up to a minute.
3. **Log in to Fortnox:** press *Log in to Fortnox*. A Fortnox administrator logs in and approves the integration. **Choose the right company** if you have more than one. Back on the setup page, press *Check licences*. You should see your company name and three green ticks.
4. **Choose a stock buffer:** the number of units held back per product, for example 2. Leave the box unticked the first time.
5. **Bring in your products:** press *Import products*. Products whose SKU matches a Fortnox article number are linked automatically. For the rest the page suggests an article number when it can, for example `KAF4735` for `VC-KAF-4735`. Check the suggestions, fill in any that are empty, and press *Link these products*.
6. **Bring in your customers:** press *Import customers from Fortnox*.
7. **Set your price tiers:** create a tier, for example *Gold, 10 %*. Then put each wholesale customer in one (see *Adding a wholesale customer* below).
8. **Connect the B2B Prices plugin:** press *Create the token* and copy it straight away; it's shown only once. In WordPress go to **WooCommerce → B2B Prices**, enter the API address shown on the setup page and paste the token. Press *Save settings*, then use *Test* with a customer's WooCommerce ID and a SKU. The answer should be their tier price.

When all eight are green the page says **All set**.

### Your first test order

1. In WordPress, give one wholesale customer a password you know (Users → the customer → *Set New Password*).
2. Log out, or open a private window, and log in to the shop as that customer.
3. The shop should show the normal price crossed out and *Your price* next to it.
4. Order one product. Within a few seconds the order appears in Fortnox under **Order**, on that customer, with the same price.

## Day to day

### Adding a wholesale customer

1. **Customers → New customer**, or open one that was imported from Fortnox.
2. Under **Price tiers**, choose the tier and press *Add*.
3. Press **Find in WooCommerce**. It looks the customer up by e-mail and links their shop login. If they have no shop account yet, ask them to register first, then press it again.
4. Check that the **Fortnox customer number** is filled in. Their orders are created on that customer.

The customer list shows at a glance who has no tier, who isn't linked to WooCommerce and who isn't in Fortnox. Use the filter to find them.

### Campaigns

Add a customer to a second tier with a **From** and **Until** date, for example *Christmas campaign, 15 %, 1–24 December*. While it's valid the customer gets the tier with the highest priority. The end date includes that whole day. Leave both dates empty for a permanent tier.

### Changing a discount

Edit the tier under **Tiers**. New orders use the new discount right away; the shop shows it within 5 minutes. Orders already placed keep the price they were placed at.

### New products

Give the product the same SKU as its Fortnox article number. Then press **Import again** under *Bring in your products* on the setup page, so it is linked to its article. Until it is linked, an order containing that product can't be sent to Fortnox: you'll get an e-mail, and the product is listed on the setup page.

## When something goes wrong

You get an e-mail when an order can't be sent to Fortnox after several tries, or when an order's price differs from the B2B price by more than 1 kr.

- **Failed jobs** shows everything that didn't go through, with the reason. Fix the reason, then press **Retry**.
- **Dashboard → Price differences** lists orders where the shop charged something other than the B2B price. Fortnox always gets the B2B price.

| You see | Check |
|---|---|
| A wholesale customer sees normal prices | They're logged in; they're in a tier; they're linked to WooCommerce (✓ #id in the customer list); the shop isn't in *Coming soon*. After a change, wait up to 5 minutes. |
| *Missing licence* in step 3 | Add the named product in Fortnox, then *Check licences* again. |
| *Prices are entered including VAT* in step 1 | See *Before you start*. Changing it also changes what consumers see, so plan it. |
| An order isn't in Fortnox | **Failed jobs** says why. The most common reasons are a product that isn't linked to an article and a customer without a Fortnox customer number. |
| *The shop didn't answer within 8 seconds* | The shop was slow or asleep. Check again. If it keeps happening, make sure the B2B Prices plugin is version 0.2.1 or newer. |
| Fortnox says the login has expired | Log in to Fortnox again from step 3 on the setup page. |

Questions: support@verdantcart.ai
