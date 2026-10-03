<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <title>Big Collection Filters</title>
    {{-- App Bridge adds the session token to same-origin fetch() calls automatically. --}}
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <style>
        body { margin: 0; font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f1f1; color: #303030; }
        main { max-width: 960px; margin: 0 auto; padding: 24px 16px; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 1px 0 rgba(0,0,0,.07), 0 0 0 1px rgba(0,0,0,.05); padding: 20px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 16px; }
        h2 { font-size: 15px; margin: 0 0 12px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
        .stat span { display: block; color: #616161; font-size: 12px; }
        .stat strong { font-size: 22px; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; background: #e3e3e3; }
        .badge.completed { background: #cdfee1; color: #0c5132; }
        .badge.running { background: #fff1c2; color: #5e4200; }
        .badge.failed { background: #fedad9; color: #8e1f0b; }
        button { background: #303030; color: #fff; border: 0; border-radius: 8px; padding: 8px 14px; font-weight: 600; cursor: pointer; }
        button:disabled { opacity: .5; cursor: default; }
        table { width: 100%; border-collapse: collapse; }
        td, th { text-align: left; padding: 8px 4px; border-bottom: 1px solid #ebebeb; }
        ol { padding-left: 20px; margin: 0; }
        li { margin-bottom: 6px; }
        .error { color: #8e1f0b; white-space: pre-wrap; }
        .row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    </style>
</head>
<body>
<main>
    <h1>Big Collection Filters</h1>

    <div class="card">
        <div class="row">
            <h2>Catalog sync <span id="status" class="badge">loading…</span></h2>
            <button id="sync" type="button">Resync products</button>
        </div>
        <div class="stats">
            <div class="stat"><span>Products synced</span><strong id="products">–</strong></div>
            <div class="stat"><span>Published to Online Store</span><strong id="published">–</strong></div>
            <div class="stat"><span>Collections</span><strong id="collections">–</strong></div>
            <div class="stat"><span>Last sync</span><strong id="synced" style="font-size:14px">–</strong></div>
        </div>
        <p id="error" class="error" hidden></p>
    </div>

    <div class="card">
        <h2>Collections where Shopify hides filters (&gt; <span id="threshold">5000</span> products)</h2>
        <table>
            <thead><tr><th>Collection</th><th>Handle</th><th>Products</th></tr></thead>
            <tbody id="large"><tr><td colspan="3">–</td></tr></tbody>
        </table>
    </div>

    <div class="card">
        <h2>Setup</h2>
        <ol>
            <li>Open <strong>Online Store → Themes → Customize</strong>.</li>
            <li>Under <strong>App embeds</strong>, turn on <strong>Big collection filters</strong> and save.</li>
            <li>Optional: add the <strong>Big collection filters</strong> app block to your collection template to control where the filters render.</li>
            <li>Visit a collection with more than 5,000 products — the app's filters replace the hidden native ones. <code>/collections/all</code> is supported too.</li>
        </ol>
    </div>
</main>

<script>
    const $ = (id) => document.getElementById(id);
    let timer;

    async function load() {
        const res = await fetch('/api/status');
        if (!res.ok) { $('status').textContent = 'error'; return; }
        const data = await res.json();

        $('status').textContent = data.sync_status;
        $('status').className = 'badge ' + data.sync_status;
        $('products').textContent = data.products.toLocaleString();
        $('published').textContent = data.published_products.toLocaleString();
        $('collections').textContent = data.collections.toLocaleString();
        $('synced').textContent = data.synced_at ? new Date(data.synced_at).toLocaleString() : 'never';
        $('threshold').textContent = data.threshold.toLocaleString();
        $('sync').disabled = data.sync_status === 'running';
        $('error').hidden = !data.sync_error;
        $('error').textContent = data.sync_error || '';

        const tbody = $('large');
        tbody.replaceChildren();
        if (!data.large_collections.length) {
            tbody.innerHTML = '<tr><td colspan="3">No collections above the limit yet.</td></tr>';
        }
        for (const c of data.large_collections) {
            const tr = document.createElement('tr');
            for (const v of [c.title, c.handle, Number(c.products_count).toLocaleString()]) {
                const td = document.createElement('td');
                td.textContent = v;
                tr.appendChild(td);
            }
            tbody.appendChild(tr);
        }

        clearTimeout(timer);
        if (data.sync_status === 'running') timer = setTimeout(load, 5000);
    }

    $('sync').addEventListener('click', async () => {
        $('sync').disabled = true;
        const res = await fetch('/api/sync', { method: 'POST' });
        const data = await res.json();
        shopify.toast.show(data.message, { isError: !res.ok });
        load();
    });

    load();
</script>
</body>
</html>
