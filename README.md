# Shopify 5,000+ Products Collection Filtration App (Laravel)

## Why This App Exists
In Shopify Online Store 2.0 (themes like Dawn, Prestige, Impulse), Shopify's native collection filtering engine **automatically disables and hides filters** whenever a collection contains **more than 5,000 products**. 

Shopify's Liquid `collection.filters` returns empty `[]` on large collections due to server-side facet computing limits.

This Laravel application completely solves this limitation by:
1. **Catalog Indexing**: Syncing products, variants, tags, vendors, types, prices, and collections into an indexed SQLite/MySQL database.
2. **High-Speed Facet Aggregator**: Dynamically computing facet counts (Price Min/Max, Brands, Product Types, Availability, Tags) across **5,000 to 100,000+ products** in under 50ms.
3. **AJAX Storefront Widget**: A zero-dependency, modern JavaScript widget (`/storefront/shopify-filtration.js`) that renders the filter sidebar and updates the collection grid without page reloads.
4. **Merchant Admin Dashboard**: Configure filter display options, sync catalog, and manage store settings.

---

## Quick Start

### 1. Configure Your Shopify Credentials
Open `.env` in this directory:
```env
SHOPIFY_CLIENT_ID=your_shopify_client_id_here
SHOPIFY_CLIENT_SECRET=your_shopify_client_secret_here
SHOPIFY_APP_URL=http://localhost:8000
```
> If using ngrok or cloudflare tunnel for live testing with Shopify OAuth:
> `SHOPIFY_APP_URL=https://your-tunnel-subdomain.ngrok-free.app`

### 2. Start the Laravel App
```bash
php artisan serve
```
Open **http://localhost:8000** in your browser.

### 3. Immediate Testing (No Live Store Needed!)
In the dashboard, click **"Generate 5,200 Demo Products"**. 
The app will batch-create 5,200 realistic products under the `huge-catalog` collection. You will immediately see:
- Live faceted sidebar (Price range slider, brand checkboxes with counts, product types, tags, in-stock toggle).
- Fast sorting (Price Low-to-High, High-to-Low, A-Z, Date).
- Instant AJAX product grid and pagination.

---

## Connecting to Your Real Shopify Store (OAuth)

1. In your **Shopify Partner Dashboard** (or Store Admin -> Apps -> Develop apps):
   - Set **App URL**: `https://your-tunnel-domain.com`
   - Set **Allowed redirection URL(s)**: `https://your-tunnel-domain.com/auth/shopify/callback`
   - Scopes: `read_products,read_product_listings,read_collections`
2. In the app dashboard, enter your store domain (e.g. `your-store.myshopify.com`) and click **"Connect with Shopify OAuth"**.
3. Once authorized, click **"Sync Products & Collections"** to index your catalog.

---

## Theme Integration (Shopify Storefront)

Add this drop-in snippet into your theme's collection template (e.g., `sections/main-collection-product-grid.liquid` or `snippets/shopify-filtration.liquid`):

```liquid
<div id="shopify-filtration-container"></div>
<script>
  window.ShopifyFiltrationApiUrl = "{{ shopify_filtration_app_url | default: 'https://your-app-domain.com' }}";
  window.ShopifyFiltrationShop = "{{ shop.permanent_domain }}";
</script>
<script src="https://your-app-domain.com/storefront/shopify-filtration.js" defer></script>
```

---

## API Endpoints Reference

- `GET /api/storefront/facets?shop={domain}&collection_handle={handle}`: Returns dynamic facet counts and price bounds.
- `GET /api/storefront/products?shop={domain}&collection_handle={handle}&price_min={min}&price_max={max}&vendors[]={vendor}&page={page}&sort_by={sort}`: Returns filtered, paginated products.
- `POST /api/webhooks/shopify`: Receives product and collection webhooks to keep the catalog fresh in real time.
