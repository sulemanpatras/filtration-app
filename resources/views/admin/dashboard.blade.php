<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Filteration · Shopify admin</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,sans-serif;color:#172033;background:#f6f7fb}
        *{box-sizing:border-box}body{margin:0}.shell{max-width:1120px;margin:auto;padding:28px 20px 56px}
        .top{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:28px}
        .brand{display:flex;align-items:center;gap:12px}.mark{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:#0f172a;color:#fff;font-weight:800}
        h1,h2,p{margin:0}.top h1{font-size:23px}.muted{color:#6b7280;font-size:14px;margin-top:4px}
        .status{padding:9px 13px;border-radius:999px;background:#e6f7ed;color:#167647;font-size:13px;font-weight:700}
        .status.off{background:#fff1f0;color:#c0342b}.grid{display:grid;grid-template-columns:1.4fr .8fr;gap:18px}
        .card{background:#fff;border:1px solid #e6e8ef;border-radius:16px;padding:22px;box-shadow:0 5px 20px #15213d08}
        .card h2{font-size:17px;margin-bottom:5px}.card>p{color:#6b7280;font-size:13px;margin-bottom:20px}
        .fields{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.field{display:flex;flex-direction:column;gap:7px}
        label{font-size:13px;font-weight:700}input[type=text],input[type=number],select{border:1px solid #d9dce5;border-radius:9px;padding:10px 11px;font:inherit;background:#fff}
        .checks{display:grid;grid-template-columns:repeat(2,1fr);gap:11px;margin:4px 0 18px}.check{display:flex;align-items:center;gap:9px;font-weight:500}
        input[type=checkbox]{width:17px;height:17px;accent-color:#0f172a}.actions{display:flex;align-items:center;gap:10px;margin-top:20px}
        button{border:0;border-radius:9px;padding:11px 15px;background:#0f172a;color:#fff;font-weight:700;cursor:pointer}button.secondary{background:#eef0f5;color:#172033}
        #message{font-size:13px;color:#167647}.stats{display:grid;gap:12px}.stat{padding:15px;border-radius:12px;background:#f7f8fb}.stat b{display:block;font-size:22px}.stat span{font-size:12px;color:#6b7280}
        .collections{margin-top:18px}.collection{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #edf0f4;font-size:14px}.collection:last-child{border:0}
        @media(max-width:760px){.grid{grid-template-columns:1fr}.fields,.checks{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
<main class="shell">
    <header class="top">
        <div class="brand"><div class="mark">F</div><div><h1>Filteration</h1><p class="muted">Search & Discovery style filters for your theme</p></div></div>
        @if($shop && $shop->is_active)<span class="status">Connected2 · {{ $shop->shop_domain }}</span>
        @else<span class="status off">Store not connected</span>@endif
    </header>
    @if(!$shop)
        <section class="card"><h2>Connect your Shopify store</h2><p>Open the app with <code>?shop=your-store.myshopify.com</code> after installing it.</p></section>
    @else
        <div class="grid">
            <section class="card">
                <h2>Filter controls</h2><p>Choose which conditions shoppers see in the theme's native collection filter layout.</p>
                <form id="settings-form" method="post" action="{{ route('dashboard.settings') }}">
                    @csrf<input type="hidden" name="shop_id" value="{{ $shop->id }}">
                    <div class="checks">
                        @foreach(['price'=>'Price','vendor'=>'Vendor','type'=>'Product type','tags'=>'Tags','availability'=>'Availability'] as $key=>$label)
                            <label class="check"><input type="checkbox" name="enable_{{ $key }}" value="1" @checked($settings->{'enable_'.$key})> {{ $label }}</label>
                        @endforeach
                    </div>
                    <div class="fields">
                        <div class="field"><label for="per_page">Products per page</label><input id="per_page" type="number" name="per_page" min="8" max="100" value="{{ $settings->per_page }}"></div>
                        <div class="field"><label for="layout">Filter layout hint</label><select id="layout" name="filter_layout"><option value="sidebar" @selected($settings->filter_layout === 'sidebar')>Sidebar</option><option value="drawer" @selected($settings->filter_layout === 'drawer')>Drawer</option></select></div>
                        <div class="field"><label for="accent">Accent color</label><input id="accent" type="text" name="theme_accent_color" value="{{ $settings->theme_accent_color }}"></div>
                    </div>
                    <div class="actions"><button type="submit">Save settings</button><span id="message" role="status"></span></div>
                </form>
            </section>
            <aside class="card">
                <h2>Catalog overview</h2><p>Synced data powers counts and conditions in the storefront.</p>
                <div class="stats"><div class="stat"><b>{{ number_format($shop->products()->count()) }}</b><span>Products indexed</span></div><div class="stat"><b>{{ number_format($shop->collections()->count()) }}</b><span>Collections available</span></div><div class="stat"><b>{{ $shop->last_synced_at?->diffForHumans() ?? 'Never' }}</b><span>Last catalog sync</span></div></div>
                <form id="sync-form" method="post" action="{{ route('dashboard.sync') }}"><input type="hidden" name="shop_id" value="{{ $shop->id }}">@csrf<div class="actions"><button class="secondary" type="submit">Sync Shopify catalog</button></div></form>
            </aside>
        </div>
        <section class="card collections"><h2>Collections</h2><p>Use the snippet or app block on any collection template.</p>
            @forelse($collections as $collection)<div class="collection"><span>{{ $collection->title }}</span><span class="muted">{{ number_format($collection->products_count) }} products</span></div>@empty<p>No collections synced yet.</p>@endforelse
        </section>
    @endif
</main>
<script>
const csrf=document.querySelector('meta[name="csrf-token"]')?.content||document.querySelector('input[name="_token"]')?.value;
for(const form of document.querySelectorAll('form')) form.addEventListener('submit',async e=>{if(form.id==='settings-form'||form.id==='sync-form'){e.preventDefault();const message=document.getElementById('message');message.textContent='Saving…';const response=await fetch(form.action,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},body:new FormData(form)});const data=await response.json();message.textContent=data.message|| (data.status==='ok'?'Saved':'Something went wrong');if(response.ok&&form.id==='sync-form')setTimeout(()=>location.reload(),700);}});
</script>
</body>
</html>
