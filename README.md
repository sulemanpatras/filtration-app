# Big Collection Filters (Laravel + Shopify CLI)

Shopify turns off native storefront filters on any collection with **more than 5,000 products**,
and no API can turn them back on. This app provides its own filter engine for those collections,
including `/collections/all`:

1. **Sync.** On install, a GraphQL Bulk Operation copies every product (variants, tags, options,
   collection membership) into the Laravel database. Product and collection webhooks keep it up to date.
2. **Filter API.** `GET /apps/big-filters/products` (Shopify App Proxy → `/proxy/products`) returns
   filtered, sorted, paginated products and facet counts.
3. **Storefront UI.** A theme app extension shows a filter sidebar, sort, active-filter chips, a product grid
   and pagination. It only appears on collections whose `all_products_count` is above the threshold.
   Smaller collections keep Shopify's native filters.

Filters: price range, availability, vendor, product type, tags, and every variant option (Size, Color, …).

## Requirements

- PHP 8.3+, Composer, Node 20+
- Shopify CLI: `npm install` (installed locally as a dev dependency) or `npm i -g @shopify/cli`
- A Shopify Partner / Dev Dashboard account and a development store

## Local development

```bash
composer install
cp .env.example .env && php artisan key:generate
npm install

# Connect to (or create) the app in your Dev Dashboard. This fills client_id in shopify.app.toml
npx shopify app config link

# Starts a tunnel, updates app URLs, injects SHOPIFY_API_KEY / SHOPIFY_API_SECRET,
# and runs `php artisan shopify:serve` (migrations + queue worker + web server).
npx shopify app dev
```

Press `p` in the CLI to open the app in your dev store. The first time the admin page loads, the backend
swaps the App Bridge session token for an offline access token and starts the catalog sync.

Then, in the theme editor:

1. **App embeds → Big collection filters → On**. The embed finds the theme's product grid using the
   "Product grid selector" setting and replaces it.
2. Optional: add the **Big collection filters** app block to the collection template for exact placement.
   When the block is present it takes priority over the selector.

## Production

- Set `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `APP_URL`, and a MySQL/Postgres `DB_*` in `.env`.
- Run a queue worker (`php artisan queue:work --timeout=3600`) under Supervisor or similar. The sync jobs need it.
- Set `application_url` and the `app_proxy.url` host in `shopify.app.toml` to your domain, then run
  `npx shopify app deploy`. This pushes the config, webhooks and theme extension.

## Configuration

| env | default | |
| --- | --- | --- |
| `SHOPIFY_FILTER_THRESHOLD` | 5000 | Used by the admin "large collections" report (the storefront threshold is a theme setting) |
| `SHOPIFY_FACET_LIMIT` | 100 | Max values returned per facet |
| `SHOPIFY_API_VERSION` | 2026-07 | Admin API version |

## Layout

```
app/Services/Shopify/ShopifyAuth.php      session token (JWT), webhook HMAC, proxy signature, token exchange
app/Services/Shopify/CatalogImporter.php  normalizes products (bulk JSONL + webhooks) into the DB
app/Services/ProductFilter.php            filtering + disjunctive facet counts
app/Jobs/                                 StartCatalogSync → PollBulkOperation, SyncProduct
app/Http/Controllers/Shopify/             admin page/API, webhooks, app proxy
extensions/big-filters/                   theme app extension (embed, block, JS, CSS)
shopify.app.toml / shopify.web.toml       Shopify CLI config
```

## Known limitations

- Sort options: newest/oldest, price, and title. "Best selling" and manual collection order aren't in the
  bulk export, so they aren't offered.
- Prices are in the shop's base currency. Market or multi-currency price adjustments aren't applied.
- Price filtering uses each product's lowest variant price.
- Webhook updates are near real-time. Use **Resync products** in the admin for a full refresh.
