/**
 * Shopify Filtration App - Storefront Engine
 * High-performance filtration for collections with 5,000+ products.
 *
 * IMPORTANT — about the product card markup below:
 * This no longer builds its own boxed "app" card design. It renders each
 * product using markup/class names that match Shopify's default Dawn theme
 * (card, card__media, card__content, price, etc.) so results inherit the
 * store's own CSS instead of looking like a bolted-on widget.
 *
 * If hussainstore-8482.myshopify.com is NOT running Dawn (or a Dawn-based
 * theme), the classes below won't match your theme's stylesheet. Edit
 * `renderProductCard()` and swap in your theme's real product-card HTML
 * (copy it from your theme's `snippets/card-product.liquid` or equivalent).
 * That's the only way to get a byte-for-byte match — we can't read your
 * theme automatically from here.
 */
(function () {
    'use strict';

    const DEFAULT_CONFIG = {
        apiBase: window.ShopifyFiltrationApiUrl || window.location.origin,
        shop: window.Shopify ? window.Shopify.shop : (window.ShopifyFiltrationShop || ''),
        collectionHandle: extractCollectionHandle(),
        // Only the real grid container is used. We never create our own wrapper.
        targetSelector: '#shopify-filtration-container, #ProductGridContainer, .facets-container, .collection__grid, #main-collection-product-grid, #product-grid',
        currencySymbol: '$',
        perPage: 24
    };

    let state = {
        facets: null,
        products: [],
        pagination: {},
        appliedFilters: {
            price_min: null,
            price_max: null,
            vendors: [],
            types: [],
            tags: [],
            availability: null
        },
        sortBy: 'featured',
        currentPage: 1,
        isLoading: false
    };

    let gridEl = null; // the store's own product-grid element — content only, never replaced with a shell

    function extractCollectionHandle() {
        if (window.ShopifyFiltrationCollectionHandle) return window.ShopifyFiltrationCollectionHandle;
        const match = window.location.pathname.match(/\/collections\/([^\/\?#]+)/);
        return match ? match[1] : 'all';
    }

    function findTargetContainer() {
        const selectors = DEFAULT_CONFIG.targetSelector.split(',').map(s => s.trim());
        for (const sel of selectors) {
            const el = document.querySelector(sel);
            if (el && !el.closest('pre, code, textarea')) return el;
        }
        return null;
    }

    function init() {
        parseUrlParams();

        gridEl = findTargetContainer();
        if (!gridEl) {
            console.warn('[Shopify Filtration] No product grid container found on this page.');
            return;
        }

        injectStyles();
        mountFilterBar();
        fetchFacets();
        fetchProducts();
    }

    function parseUrlParams() {
        const params = new URLSearchParams(window.location.search);
        if (params.get('price_min')) state.appliedFilters.price_min = parseFloat(params.get('price_min'));
        if (params.get('price_max')) state.appliedFilters.price_max = parseFloat(params.get('price_max'));
        if (params.getAll('vendors[]').length) state.appliedFilters.vendors = params.getAll('vendors[]');
        if (params.getAll('types[]').length) state.appliedFilters.types = params.getAll('types[]');
        if (params.getAll('tags[]').length) state.appliedFilters.tags = params.getAll('tags[]');
        if (params.get('availability')) state.appliedFilters.availability = params.get('availability');
        if (params.get('sort_by')) state.sortBy = params.get('sort_by');
        if (params.get('page')) state.currentPage = parseInt(params.get('page'), 10) || 1;
    }

    function updateUrl() {
        const params = new URLSearchParams();
        if (state.appliedFilters.price_min) params.set('price_min', state.appliedFilters.price_min);
        if (state.appliedFilters.price_max) params.set('price_max', state.appliedFilters.price_max);
        state.appliedFilters.vendors.forEach(v => params.append('vendors[]', v));
        state.appliedFilters.types.forEach(t => params.append('types[]', t));
        state.appliedFilters.tags.forEach(t => params.append('tags[]', t));
        if (state.appliedFilters.availability) params.set('availability', state.appliedFilters.availability);
        if (state.sortBy !== 'featured') params.set('sort_by', state.sortBy);
        if (state.currentPage > 1) params.set('page', state.currentPage);

        const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
        window.history.pushState({}, '', newUrl);
    }

    async function fetchFacets() {
        try {
            const url = new URL(`${DEFAULT_CONFIG.apiBase}/api/storefront/facets`);
            url.searchParams.set('shop', DEFAULT_CONFIG.shop);
            url.searchParams.set('collection_handle', DEFAULT_CONFIG.collectionHandle);

            const res = await fetch(url.toString());
            const data = await res.json();
            state.facets = data;
            if (data.currency_symbol) DEFAULT_CONFIG.currencySymbol = data.currency_symbol;
            renderFilterPanel();
        } catch (err) {
            console.error('[Shopify Filtration] Failed to load facets:', err);
        }
    }

    async function fetchProducts() {
        state.isLoading = true;
        renderGrid();

        try {
            const url = new URL(`${DEFAULT_CONFIG.apiBase}/api/storefront/products`);
            url.searchParams.set('shop', DEFAULT_CONFIG.shop);
            url.searchParams.set('collection_handle', DEFAULT_CONFIG.collectionHandle);
            url.searchParams.set('sort_by', state.sortBy);
            url.searchParams.set('page', state.currentPage);
            url.searchParams.set('limit', DEFAULT_CONFIG.perPage);

            if (state.appliedFilters.price_min) url.searchParams.set('price_min', state.appliedFilters.price_min);
            if (state.appliedFilters.price_max) url.searchParams.set('price_max', state.appliedFilters.price_max);
            state.appliedFilters.vendors.forEach(v => url.searchParams.append('vendors[]', v));
            state.appliedFilters.types.forEach(t => url.searchParams.append('types[]', t));
            state.appliedFilters.tags.forEach(t => url.searchParams.append('tags[]', t));
            if (state.appliedFilters.availability) url.searchParams.set('availability', state.appliedFilters.availability);

            const res = await fetch(url.toString());
            const data = await res.json();

            state.products = data.products || [];
            state.pagination = data.pagination || {};
        } catch (err) {
            console.error('[Shopify Filtration] Failed to load products:', err);
            state.products = [];
        } finally {
            state.isLoading = false;
            renderGrid();
            renderPills();
        }
    }

    // ---------------------------------------------------------------------
    // Minimal, unobtrusive filter bar — one small div, not an app shell.
    // ---------------------------------------------------------------------
    function mountFilterBar() {
        const bar = document.createElement('div');
        bar.className = 'sf-bar';
        bar.innerHTML = `
            <button type="button" class="sf-filter-toggle" id="sf-filter-toggle">Filter</button>
            <span class="sf-count" id="sf-product-count"></span>
            <label class="sf-sort-label" for="sf-sort">Sort by
                <select class="sf-sort" id="sf-sort">
                    <option value="featured">Featured</option>
                    <option value="price-asc">Price: Low to High</option>
                    <option value="price-desc">Price: High to Low</option>
                    <option value="title-asc">Alphabetically: A-Z</option>
                    <option value="title-desc">Alphabetically: Z-A</option>
                    <option value="created-desc">Date: New to Old</option>
                </select>
            </label>
        `;
        const pills = document.createElement('div');
        pills.className = 'sf-pills';
        pills.id = 'sf-pills-bar';

        gridEl.parentNode.insertBefore(bar, gridEl);
        gridEl.parentNode.insertBefore(pills, gridEl);

        const drawer = document.createElement('div');
        drawer.className = 'sf-drawer';
        drawer.id = 'sf-drawer';
        drawer.innerHTML = `
            <div class="sf-drawer-header">
                <span>Filter</span>
                <button type="button" class="sf-drawer-close" id="sf-drawer-close" aria-label="Close">&times;</button>
            </div>
            <div class="sf-drawer-body" id="sf-drawer-body">Loading filters&hellip;</div>
        `;
        document.body.appendChild(drawer);

        document.getElementById('sf-filter-toggle').addEventListener('click', () => drawer.classList.add('open'));
        document.getElementById('sf-drawer-close').addEventListener('click', () => drawer.classList.remove('open'));

        const sortSelect = document.getElementById('sf-sort');
        sortSelect.value = state.sortBy;
        sortSelect.addEventListener('change', (e) => {
            state.sortBy = e.target.value;
            state.currentPage = 1;
            updateUrl();
            fetchProducts();
        });
    }

    function renderFilterPanel() {
        const container = document.getElementById('sf-drawer-body');
        if (!container || !state.facets) return;

        const { facets } = state.facets;
        let html = '';

        if (facets.price && facets.price.enabled) {
            const min = facets.price.min || 0;
            const max = facets.price.max || 1000;
            const currentMin = state.appliedFilters.price_min !== null ? state.appliedFilters.price_min : min;
            const currentMax = state.appliedFilters.price_max !== null ? state.appliedFilters.price_max : max;
            html += `
                <div class="sf-group">
                    <div class="sf-group-title">Price (${DEFAULT_CONFIG.currencySymbol})</div>
                    <div class="sf-price-row">
                        <input type="number" id="sf-price-min" placeholder="${min}" value="${currentMin}" min="${min}" max="${max}">
                        <span>&ndash;</span>
                        <input type="number" id="sf-price-max" placeholder="${max}" value="${currentMax}" min="${min}" max="${max}">
                        <button type="button" id="sf-apply-price">Go</button>
                    </div>
                </div>`;
        }

        if (facets.availability && facets.availability.enabled && facets.availability.items.length) {
            html += `<div class="sf-group"><div class="sf-group-title">Availability</div>` +
                facets.availability.items.map(item => `
                    <label class="sf-check">
                        <input type="radio" name="sf-avail" value="${item.value}" ${state.appliedFilters.availability === item.value ? 'checked' : ''} class="sf-avail-radio">
                        ${escapeHtml(item.label)} <span class="sf-check-count">${item.count}</span>
                    </label>`).join('') + `</div>`;
        }

        if (facets.vendors && facets.vendors.enabled && facets.vendors.items.length) {
            html += `<div class="sf-group"><div class="sf-group-title">Brand</div>` +
                facets.vendors.items.map(v => `
                    <label class="sf-check">
                        <input type="checkbox" value="${escapeHtml(v.value)}" ${state.appliedFilters.vendors.includes(v.value) ? 'checked' : ''} class="sf-vendor-check">
                        ${escapeHtml(v.label)} <span class="sf-check-count">${v.count}</span>
                    </label>`).join('') + `</div>`;
        }

        if (facets.types && facets.types.enabled && facets.types.items.length) {
            html += `<div class="sf-group"><div class="sf-group-title">Product Type</div>` +
                facets.types.items.map(t => `
                    <label class="sf-check">
                        <input type="checkbox" value="${escapeHtml(t.value)}" ${state.appliedFilters.types.includes(t.value) ? 'checked' : ''} class="sf-type-check">
                        ${escapeHtml(t.label)} <span class="sf-check-count">${t.count}</span>
                    </label>`).join('') + `</div>`;
        }

        if (facets.tags && facets.tags.enabled && facets.tags.items.length) {
            html += `<div class="sf-group"><div class="sf-group-title">Tags</div>` +
                facets.tags.items.map(t => `
                    <label class="sf-check">
                        <input type="checkbox" value="${escapeHtml(t.value)}" ${state.appliedFilters.tags.includes(t.value) ? 'checked' : ''} class="sf-tag-check">
                        ${escapeHtml(t.label)} <span class="sf-check-count">${t.count}</span>
                    </label>`).join('') + `</div>`;
        }

        container.innerHTML = html || '<p>No filters available.</p>';
        bindFilterEvents();
    }

    function bindFilterEvents() {
        const priceBtn = document.getElementById('sf-apply-price');
        if (priceBtn) {
            priceBtn.addEventListener('click', () => {
                const minInput = document.getElementById('sf-price-min');
                const maxInput = document.getElementById('sf-price-max');
                state.appliedFilters.price_min = minInput.value ? parseFloat(minInput.value) : null;
                state.appliedFilters.price_max = maxInput.value ? parseFloat(maxInput.value) : null;
                applyAndRefetch();
            });
        }

        document.querySelectorAll('.sf-vendor-check').forEach(input => {
            input.addEventListener('change', () => toggleListFilter('vendors', input));
        });
        document.querySelectorAll('.sf-type-check').forEach(input => {
            input.addEventListener('change', () => toggleListFilter('types', input));
        });
        document.querySelectorAll('.sf-tag-check').forEach(input => {
            input.addEventListener('change', () => toggleListFilter('tags', input));
        });
        document.querySelectorAll('.sf-avail-radio').forEach(input => {
            input.addEventListener('click', () => {
                state.appliedFilters.availability = (state.appliedFilters.availability === input.value) ? null : input.value;
                if (state.appliedFilters.availability === null) input.checked = false;
                applyAndRefetch();
            });
        });
    }

    function toggleListFilter(key, input) {
        const val = input.value;
        if (input.checked) {
            if (!state.appliedFilters[key].includes(val)) state.appliedFilters[key].push(val);
        } else {
            state.appliedFilters[key] = state.appliedFilters[key].filter(v => v !== val);
        }
        applyAndRefetch();
    }

    function applyAndRefetch() {
        state.currentPage = 1;
        updateUrl();
        fetchProducts();
    }

    function renderPills() {
        const bar = document.getElementById('sf-pills-bar');
        if (!bar) return;

        let pills = [];
        if (state.appliedFilters.price_min || state.appliedFilters.price_max) {
            pills.push({ type: 'price', label: `Price: ${DEFAULT_CONFIG.currencySymbol}${state.appliedFilters.price_min || 0} - ${DEFAULT_CONFIG.currencySymbol}${state.appliedFilters.price_max || 'max'}` });
        }
        state.appliedFilters.vendors.forEach(v => pills.push({ type: 'vendor', value: v, label: v }));
        state.appliedFilters.types.forEach(t => pills.push({ type: 'type', value: t, label: t }));
        state.appliedFilters.tags.forEach(t => pills.push({ type: 'tag', value: t, label: t }));
        if (state.appliedFilters.availability) {
            pills.push({ type: 'availability', label: state.appliedFilters.availability === 'in_stock' ? 'In Stock' : 'Out of Stock' });
        }

        if (pills.length === 0) {
            bar.innerHTML = '';
            return;
        }

        bar.innerHTML = pills.map((p, idx) => `<span class="sf-pill" data-idx="${idx}">${escapeHtml(p.label)} &times;</span>`).join('') +
            `<span class="sf-pill sf-pill-clear" id="sf-clear-all">Clear all</span>`;

        bar.querySelectorAll('.sf-pill').forEach(pill => {
            if (pill.id === 'sf-clear-all') {
                pill.addEventListener('click', clearAllFilters);
            } else {
                const idx = parseInt(pill.getAttribute('data-idx'), 10);
                pill.addEventListener('click', () => removeFilter(pills[idx]));
            }
        });
    }

    function removeFilter(pill) {
        if (pill.type === 'price') {
            state.appliedFilters.price_min = null;
            state.appliedFilters.price_max = null;
        } else if (pill.type === 'availability') {
            state.appliedFilters.availability = null;
        } else {
            state.appliedFilters[pill.type + 's'] = state.appliedFilters[pill.type + 's'].filter(v => v !== pill.value);
        }
        applyAndRefetch();
        renderFilterPanel();
    }

    function clearAllFilters() {
        state.appliedFilters = { price_min: null, price_max: null, vendors: [], types: [], tags: [], availability: null };
        applyAndRefetch();
        renderFilterPanel();
    }

    // ---------------------------------------------------------------------
    // Grid rendering — writes ONLY into the store's real grid container,
    // using the store's own theme classes (see file header note).
    // ---------------------------------------------------------------------
    function renderGrid() {
        const countDisplay = document.getElementById('sf-product-count');
        const total = state.pagination.total || 0;
        if (countDisplay && !state.isLoading) countDisplay.innerText = `${total.toLocaleString()} products`;

        if (state.isLoading) {
            gridEl.setAttribute('aria-busy', 'true');
            return;
        }
        gridEl.removeAttribute('aria-busy');

        if (state.products.length === 0) {
            gridEl.innerHTML = `<p class="sf-empty">No products found. Try clearing some filters.</p>`;
            renderPagination();
            return;
        }

        gridEl.innerHTML = state.products.map(renderProductCard).join('');
        renderPagination();
    }

    function renderProductCard(product) {
        // Dawn-theme-style markup. Replace with your theme's real card HTML
        // if hussainstore-8482.myshopify.com isn't running Dawn.
        return `
            <li class="grid__item">
                <div class="card-wrapper product-card-wrapper">
                    <div class="card card--standard card--media">
                        <div class="card__inner">
                            <div class="card__media">
                                <div class="media media--transparent">
                                    <img src="${product.featured_image}" alt="${escapeHtml(product.title)}" loading="lazy" class="motion-reduce">
                                </div>
                                ${product.is_on_sale ? `<span class="card__badge badge badge--bottom-left">Sale</span>` : ''}
                            </div>
                        </div>
                        <div class="card__content">
                            <div class="card__information">
                                <h3 class="card__heading">
                                    <a href="${product.url}" class="full-unstyled-link">${escapeHtml(product.title)}</a>
                                </h3>
                                <div class="card-information">
                                    ${product.vendor ? `<span class="caption-large light">${escapeHtml(product.vendor)}</span>` : ''}
                                    <div class="price">
                                        <div class="price__container">
                                            ${product.compare_at_price_formatted ? `<s class="price-item price-item--regular">${product.compare_at_price_formatted}</s>` : ''}
                                            <span class="price-item price-item--${product.compare_at_price_formatted ? 'sale' : 'regular'}">${product.price_formatted}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </li>`;
    }

    function renderPagination() {
        let nav = document.getElementById('sf-pagination');
        if (!state.pagination || state.pagination.last_page <= 1) {
            if (nav) nav.remove();
            return;
        }
        if (!nav) {
            nav = document.createElement('nav');
            nav.id = 'sf-pagination';
            nav.className = 'sf-pagination';
            gridEl.parentNode.insertBefore(nav, gridEl.nextSibling);
        }

        const { current_page, last_page } = state.pagination;
        const startPage = Math.max(1, current_page - 2);
        const endPage = Math.min(last_page, current_page + 2);

        let html = `<button class="sf-page-btn" ${current_page <= 1 ? 'disabled' : ''} id="sf-page-prev">&laquo; Prev</button>`;
        for (let p = startPage; p <= endPage; p++) {
            html += `<button class="sf-page-btn ${p === current_page ? 'active' : ''}" data-page="${p}">${p}</button>`;
        }
        html += `<button class="sf-page-btn" ${current_page >= last_page ? 'disabled' : ''} id="sf-page-next">Next &raquo;</button>`;
        nav.innerHTML = html;

        const prev = document.getElementById('sf-page-prev');
        const next = document.getElementById('sf-page-next');
        if (prev && current_page > 1) prev.addEventListener('click', () => goToPage(current_page - 1));
        if (next && current_page < last_page) next.addEventListener('click', () => goToPage(current_page + 1));
        nav.querySelectorAll('.sf-page-btn[data-page]').forEach(btn => {
            btn.addEventListener('click', () => goToPage(parseInt(btn.getAttribute('data-page'), 10)));
        });
    }

    function goToPage(page) {
        state.currentPage = page;
        updateUrl();
        fetchProducts();
        gridEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function escapeHtml(str) {
        return (str || '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    }

    // Only the app's own small controls get CSS — the product grid itself
    // relies entirely on the theme's existing stylesheet.
    function injectStyles() {
        if (document.getElementById('sf-styles')) return;
        const style = document.createElement('style');
        style.id = 'sf-styles';
        style.textContent = `
            .sf-bar { display: flex; align-items: center; gap: 1rem; margin: 1rem 0; font-family: inherit; }
            .sf-filter-toggle { padding: 0.5rem 1rem; border: 1px solid currentColor; background: none; cursor: pointer; font: inherit; }
            .sf-count { color: inherit; opacity: 0.7; font-size: 0.9em; }
            .sf-sort-label { margin-left: auto; font-size: 0.9em; }
            .sf-sort { margin-left: 0.5rem; font: inherit; }
            .sf-pills { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 1rem; }
            .sf-pill { display: inline-flex; align-items: center; gap: 0.35rem; border: 1px solid currentColor; opacity: 0.85; padding: 0.25rem 0.65rem; border-radius: 999px; font-size: 0.8em; cursor: pointer; }
            .sf-pill-clear { opacity: 1; font-weight: 600; }
            .sf-empty { padding: 3rem 0; text-align: center; opacity: 0.7; }
            .sf-pagination { display: flex; justify-content: center; gap: 0.5rem; margin: 2rem 0; }
            .sf-page-btn { padding: 0.4rem 0.8rem; border: 1px solid currentColor; background: none; cursor: pointer; font: inherit; }
            .sf-page-btn.active { font-weight: 700; }
            .sf-page-btn:disabled { opacity: 0.35; cursor: not-allowed; }
            .sf-drawer { position: fixed; top: 0; right: -320px; width: 300px; max-width: 90vw; height: 100vh; background: #fff; color: #111; box-shadow: -2px 0 12px rgba(0,0,0,0.15); transition: right 0.25s ease; z-index: 99999; overflow-y: auto; }
            .sf-drawer.open { right: 0; }
            .sf-drawer-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem; border-bottom: 1px solid #e5e5e5; font-weight: 700; }
            .sf-drawer-close { background: none; border: none; font-size: 1.4rem; cursor: pointer; line-height: 1; }
            .sf-drawer-body { padding: 1rem; }
            .sf-group { margin-bottom: 1.25rem; }
            .sf-group-title { font-weight: 700; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.5rem; }
            .sf-check { display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; margin-bottom: 0.4rem; }
            .sf-check-count { margin-left: auto; opacity: 0.6; font-size: 0.8em; }
            .sf-price-row { display: flex; align-items: center; gap: 0.4rem; }
            .sf-price-row input { width: 70px; padding: 0.3rem; }
        `;
        document.head.appendChild(style);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
(() => {
  const root = document.getElementById('shopify-filtration-container');
  if (!root || !root.dataset.apiBase) return;

  const params = new URLSearchParams(location.search);
  const values = (key) => params.getAll(key);
  const esc = (value) => String(value).replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[char]));
  const checked = (key, value) => values(key).includes(value) ? ' checked' : '';
  const facet = (title, key, items) => items.length ? `
    <fieldset class="sf-group"><legend>${esc(title)}</legend>
      ${items.map(item => `<label class="sf-option"><input type="checkbox" name="${key}" value="${esc(item.value)}"${checked(key, item.value)}><span>${esc(item.label)}</span><small>${item.count}</small></label>`).join('')}
    </fieldset>` : '';

  const render = async () => {
    root.innerHTML = '<div class="sf-loading" aria-live="polite">Loading filters…</div>';
    try {
      const url = new URL(`${root.dataset.apiBase}/facets`);
      url.searchParams.set('shop', root.dataset.shop);
      url.searchParams.set('collection_handle', root.dataset.collection || 'all');
      const response = await fetch(url);
      if (!response.ok) throw new Error(`Filter request failed (${response.status})`);
      const data = await response.json();
      const f = data.facets;
      const style = data.settings?.accent_color || '#0f172a';
      const price = f.price.enabled ? `<fieldset class="sf-group"><legend>Price</legend><div class="sf-price"><input name="filter.v.price.gte" type="number" min="0" placeholder="${Math.floor(f.price.min)}" value="${esc(params.get('filter.v.price.gte') || '')}" aria-label="Minimum price"><span>to</span><input name="filter.v.price.lte" type="number" min="0" placeholder="${Math.ceil(f.price.max)}" value="${esc(params.get('filter.v.price.lte') || '')}" aria-label="Maximum price"></div></fieldset>` : '';
      const availability = f.availability.enabled ? facet('Availability', 'filter.v.availability', f.availability.items) : '';
      root.innerHTML = `<style>
        #shopify-filtration-container{--sf-accent:${style};margin:0 0 28px;font:inherit;color:inherit}
        .sf-shell{border:1px solid rgba(0,0,0,.12);border-radius:var(--inputs-radius,8px);padding:20px;background:var(--color-background,#fff)}
        .sf-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.sf-title{font-size:1.1em;font-weight:600}
        .sf-count{opacity:.65;font-size:.9em}.sf-groups{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px}
        .sf-group{border:0;padding:0;margin:0;min-width:0}.sf-group legend{font-weight:600;margin-bottom:10px}.sf-option{display:flex;align-items:center;gap:8px;padding:5px 0;cursor:pointer;font-size:.94em}.sf-option small{margin-left:auto;opacity:.55}
        .sf-option input{accent-color:var(--sf-accent)}.sf-price{display:flex;align-items:center;gap:7px}.sf-price input{width:100%;min-width:0;padding:8px;border:1px solid rgba(0,0,0,.2);border-radius:5px;background:transparent;color:inherit}
        .sf-actions{display:flex;gap:10px;margin-top:18px}.sf-actions button{background:var(--sf-accent);color:#fff;border:0;border-radius:5px;padding:10px 15px;cursor:pointer;font:inherit}.sf-clear{padding:10px 0;color:inherit}
        .sf-loading{opacity:.7;padding:14px 0}@media(max-width:600px){.sf-groups{grid-template-columns:1fr}}
      </style><form class="sf-shell" aria-label="Filter products"><div class="sf-head"><span class="sf-title">Filter products</span><span class="sf-count">${data.total_products} products</span></div><div class="sf-groups">${price}${facet('Vendor', 'filter.p.vendor', f.vendors.items)}${facet('Product type', 'filter.p.product_type', f.types.items)}${facet('Tags', 'filter.p.tag', f.tags.items)}${availability}</div><div class="sf-actions"><button type="submit">Apply filters</button><a class="sf-clear" href="${esc(location.pathname)}">Clear all</a></div></form>`;
      root.querySelector('form').addEventListener('submit', (event) => {
        event.preventDefault();
        const next = new URL(location.href);
        next.search = new URLSearchParams(new FormData(event.currentTarget)).toString();
        location.href = next.toString();
      });
    } catch (error) {
      root.innerHTML = '<div class="sf-loading" role="status">Filters are temporarily unavailable.</div>';
      console.error('[Filteration]', error);
    }
  };
  render();
})();
